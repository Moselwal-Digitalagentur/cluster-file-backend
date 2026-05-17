<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheNamespace::class)]
final class CacheNamespaceTest extends TestCase
{
    public function testToKvKeyPrefixForObservability(): void
    {
        $ns = new CacheNamespace(EnvironmentName::Production, 'website-a', 'pages');
        self::assertSame('cfb:prod:website-a:pages', $ns->toKvKeyPrefix());
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
