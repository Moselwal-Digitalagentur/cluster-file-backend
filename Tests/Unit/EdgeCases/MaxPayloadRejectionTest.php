<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\EdgeCases;

use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\OptionsValidator;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Exception\InvalidCacheException;

/**
 * Spec Edge Case T165: Sehr große Payloads — das Schema-validierte
 * `maxPayloadBytes` setzt die obere Grenze. Hier auf der Konfig-Ebene
 * geprüft; die `set()`-Pfad-Prüfung selbst lebt im ClusterFileBackend
 * und ist nur mit TYPO3-Bootstrap testbar (Functional-Test).
 */
final class MaxPayloadRejectionTest extends TestCase
{
    public function testTooSmallMaxPayloadBytesRejectedBySchema(): void
    {
        $this->expectException(InvalidCacheException::class);
        new OptionsValidator()->validateAndApplyDefaults([
            'localPath' => '/app/var/cache/cluster/pages',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'testing', 'instance' => 'site'],
            'maxPayloadBytes' => 512,
        ]);
    }

    public function testTooLargeMaxPayloadBytesRejectedBySchema(): void
    {
        $this->expectException(InvalidCacheException::class);
        new OptionsValidator()->validateAndApplyDefaults([
            'localPath' => '/app/var/cache/cluster/pages',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'testing', 'instance' => 'site'],
            'maxPayloadBytes' => 2_000_000_000,
        ]);
    }

    public function testValidMaxPayloadBytesPasses(): void
    {
        $normalized = new OptionsValidator()->validateAndApplyDefaults([
            'localPath' => '/app/var/cache/cluster/pages',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'testing', 'instance' => 'site'],
            'maxPayloadBytes' => 5_242_880,
        ]);
        self::assertSame(5_242_880, $normalized['maxPayloadBytes']);
    }
}
