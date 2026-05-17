<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Cache\Backend;

use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Exception\InvalidCacheException;

/**
 * Regression: das vorherige `parent::__construct($options)` warf bei jeder
 * TYPO3-Cache-Konfiguration sofort `\InvalidArgumentException`, weil
 * AbstractBackend für jeden Options-Key einen Setter erwartet — und
 * ClusterFileBackend hat keine.
 *
 * Dieser Test instantiiert das Backend mit dem typischen Options-Layout aus
 * `$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']`.
 * Wenn er fehlschlägt, würde TYPO3 das Backend in Production sofort
 * zerlegen — selbst bevor der erste `set()` läuft.
 *
 * Hinweis: Der Konstruktor instanziiert per `GeneralUtility::makeInstance`
 * Default-Services für `ClockPort` und `MetricsPort` sowie holt ein
 * TYPO3-Cache-Frontend via `CacheManager::getCache(...)`. In dieser
 * Unit-Test-Umgebung ohne TYPO3-Bootstrap schlägt das beim ersten
 * Service-Lookup mit einer ungeladenen Klasse fehl — daher fangen wir
 * \Throwable und verifizieren nur den Vor-Throw-Punkt:
 * `OptionsValidator` MUSS die Schema-Verstöße werfen, bevor irgendetwas
 * anderes versucht wird.
 */
#[CoversClass(ClusterFileBackend::class)]
final class ClusterFileBackendConstructorTest extends TestCase
{
    public function testMissingLocalPathThrowsInvalidCacheException(): void
    {
        $this->expectException(InvalidCacheException::class);
        new ClusterFileBackend([
            // 'localPath' fehlt
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
        ]);
    }

    public function testMissingMetadataCacheIdentifierThrowsInvalidCacheException(): void
    {
        $this->expectException(InvalidCacheException::class);
        new ClusterFileBackend([
            'localPath' => '/tmp/cfb-test',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
        ]);
    }

    public function testUnknownOptionKeyRejected(): void
    {
        // Spezifischer Regression-Test: TYPO3-AbstractBackend würde hier
        // Setter aufrufen wollen, die nicht existieren. Unser eigener
        // Validator MUSS das vorher fangen.
        $this->expectException(InvalidCacheException::class);
        new ClusterFileBackend([
            'localPath' => '/tmp/cfb-test',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
            'thisOptionDoesNotExist' => 'hello',
        ]);
    }

    public function testInvalidEnvironmentRejected(): void
    {
        $this->expectException(InvalidCacheException::class);
        new ClusterFileBackend([
            'localPath' => '/tmp/cfb-test',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'unknown', 'instance' => 'site'],
        ]);
    }
}
