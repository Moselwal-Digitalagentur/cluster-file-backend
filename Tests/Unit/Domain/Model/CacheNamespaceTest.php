<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheNamespace::class)]
final class CacheNamespaceTest extends TestCase
{
    public function testValidNamespaceProducesExpectedKvKeys(): void
    {
        $ns = new CacheNamespace(EnvironmentName::Production, 'website-a', 'pages');
        $id = new CacheIdentifier('page_42');

        self::assertSame('cfb:prod:website-a:pages', $ns->toKvKeyPrefix());
        self::assertSame('cfb:meta:prod:website-a:pages:page_42', $ns->metadataKey($id));
        self::assertSame('cfb:gen:prod:website-a:pages', $ns->generationKey());
        self::assertSame('cfb:tag:prod:website-a:pages:my_tag', $ns->tagForwardKey('my_tag'));
        self::assertSame('cfb:identifier-tags:prod:website-a:pages:page_42', $ns->tagReverseKey($id));
        self::assertSame('cfb:lock:prod:website-a:pages:page_42', $ns->lockKey($id));
        self::assertSame('cfb:frozen:prod:website-a:pages', $ns->frozenKey());
        self::assertSame('cfb:gc-running:prod:website-a:pages', $ns->gcRunningKey());
    }

    public function testInstancePatternIsEnforced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CacheNamespace(EnvironmentName::Production, 'INVALID_UPPERCASE', 'pages');
    }

    public function testCacheNamePatternIsEnforced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CacheNamespace(EnvironmentName::Production, 'site-a', 'invalid-with-dash');
    }
}
