<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Model\Lifetime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Lifetime::class)]
final class LifetimeTest extends TestCase
{
    public function testIsExpired(): void
    {
        $lifetime = new Lifetime(createdAt: 1000, expiresAt: 2000);
        self::assertFalse($lifetime->isExpired(1500));
        self::assertTrue($lifetime->isExpired(2000));
        self::assertTrue($lifetime->isExpired(3000));
    }

    public function testRemainingSeconds(): void
    {
        $lifetime = new Lifetime(createdAt: 1000, expiresAt: 2000);
        self::assertSame(500, $lifetime->remainingSeconds(1500));
        self::assertSame(0, $lifetime->remainingSeconds(2500));
    }

    public function testFromSecondsUsesClock(): void
    {
        $clock = new class implements ClockPort {
            public function now(): int
            {
                return 5000;
            }
        };
        $lifetime = Lifetime::fromSeconds(60, $clock);
        self::assertSame(5000, $lifetime->createdAt);
        self::assertSame(5060, $lifetime->expiresAt);
    }

    public function testExpiresAtMustBeAfterCreatedAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Lifetime(createdAt: 2000, expiresAt: 1000);
    }
}
