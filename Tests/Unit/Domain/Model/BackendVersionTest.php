<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\BackendVersionInfo;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackendVersion::class)]
final class BackendVersionTest extends TestCase
{
    public function testMinimumIsOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BackendVersion(0);
    }

    public function testCurrentReflectsInfoConstant(): void
    {
        self::assertSame(BackendVersionInfo::CURRENT, BackendVersion::current()->value);
    }
}
