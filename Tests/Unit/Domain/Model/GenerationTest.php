<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Model\Generation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Generation::class)]
final class GenerationTest extends TestCase
{
    public function testNextProducesIncrement(): void
    {
        self::assertSame(8, new Generation(7)->next()->value);
    }

    public function testIsAtLeast(): void
    {
        self::assertTrue(new Generation(7)->isAtLeast(new Generation(7)));
        self::assertTrue(new Generation(8)->isAtLeast(new Generation(7)));
        self::assertFalse(new Generation(6)->isAtLeast(new Generation(7)));
    }

    public function testNegativeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Generation(-1);
    }
}
