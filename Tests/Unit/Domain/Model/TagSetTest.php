<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TagSet::class)]
final class TagSetTest extends TestCase
{
    public function testTagsAreUniqueAndSorted(): void
    {
        $set = new TagSet(['b', 'a', 'b', 'c']);
        self::assertSame(['a', 'b', 'c'], $set->toArray());
    }

    public function testWithReturnsNewInstance(): void
    {
        $set = new TagSet(['a']);
        $extended = $set->with('b');
        self::assertSame(['a'], $set->toArray());
        self::assertSame(['a', 'b'], $extended->toArray());
    }

    public function testWithoutReturnsNewInstance(): void
    {
        $set = new TagSet(['a', 'b', 'c']);
        $reduced = $set->without('b');
        self::assertSame(['a', 'b', 'c'], $set->toArray());
        self::assertSame(['a', 'c'], $reduced->toArray());
    }

    public function testContains(): void
    {
        $set = new TagSet(['a', 'b']);
        self::assertTrue($set->contains('a'));
        self::assertFalse($set->contains('z'));
    }

    public function testMaxTagsLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TagSet(array_map(static fn(int $i): string => 'tag_' . $i, range(1, 65)));
    }

    public function testInvalidTagPatternRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TagSet(['has space']);
    }

    public function testIsEmpty(): void
    {
        self::assertTrue(new TagSet()->isEmpty());
        self::assertFalse(new TagSet(['a'])->isEmpty());
    }
}
