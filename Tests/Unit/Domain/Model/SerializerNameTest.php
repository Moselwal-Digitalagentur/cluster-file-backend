<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SerializerName::class)]
final class SerializerNameTest extends TestCase
{
    public function testPhpNativeFactory(): void
    {
        $name = SerializerName::phpNative();
        self::assertSame('php', $name->name);
        self::assertSame('php:native', $name->version);
    }

    public function testIgbinaryFactoryProducesPrefixedVersion(): void
    {
        $name = SerializerName::igbinary();
        self::assertSame('igbinary', $name->name);
        self::assertStringStartsWith('igbinary:', $name->version);
    }

    public function testIgbinaryVersionContainsOnlyMajorNotPatch(): void
    {
        if (!\extension_loaded('igbinary')) {
            self::markTestSkipped('igbinary extension not loaded');
        }
        $name = SerializerName::igbinary();
        // Regression: vor dem Fix war hier z. B. "igbinary:3.2.16" — jeder
        // Patch-Bump des Moduls hätte den gesamten Cluster-Cache invalidiert.
        // Jetzt: "igbinary:3" (nur Major-Version).
        self::assertMatchesRegularExpression(
            '/^igbinary:(\d+|unknown)$/',
            $name->version,
            'igbinary version-tag must contain only the major version (or "unknown")',
        );
    }

    public function testUnknownNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SerializerName('msgpack', 'msgpack:1.0');
    }

    public function testEmptyVersionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SerializerName(SerializerName::PHP_NATIVE, '');
    }

    public function testDetectReturnsAvailableSerializer(): void
    {
        $detected = SerializerName::detect();
        self::assertContains($detected->name, [SerializerName::IGBINARY, SerializerName::PHP_NATIVE]);
    }
}
