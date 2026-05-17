<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Enum\CacheState;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\Generation;
use Moselwal\Typo3ClusterCache\Domain\Model\Lifetime;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheMetadata::class)]
final class CacheMetadataTest extends TestCase
{
    public function testToKvPayloadAndFromKvPayloadRoundtrip(): void
    {
        $metadata = $this->buildMetadata();
        $kv = $metadata->toKvPayload();
        $restored = CacheMetadata::fromKvPayload($kv);

        self::assertTrue($metadata->identifier->equals($restored->identifier));
        self::assertTrue($metadata->hash->equals($restored->hash));
        self::assertTrue($metadata->checksum->equals($restored->checksum));
        self::assertSame($metadata->generation->value, $restored->generation->value);
        self::assertSame($metadata->lifetime->createdAt, $restored->lifetime->createdAt);
        self::assertSame($metadata->lifetime->expiresAt, $restored->lifetime->expiresAt);
        self::assertSame($metadata->payloadSize, $restored->payloadSize);
        self::assertSame($metadata->tags->toArray(), $restored->tags->toArray());
        self::assertSame($metadata->state, $restored->state);
        self::assertSame($metadata->backendVersion->value, $restored->backendVersion->value);
    }

    public function testIsValidRequiresValidStateAndUnexpiredAndAtLeastCurrentGeneration(): void
    {
        $metadata = $this->buildMetadata();
        $currentGen = new Generation(7);

        self::assertTrue($metadata->isValid(now: 1500, currentNamespaceGeneration: $currentGen));
        self::assertFalse($metadata->isValid(now: 9999, currentNamespaceGeneration: $currentGen));
        self::assertFalse($metadata->isValid(now: 1500, currentNamespaceGeneration: new Generation(99)));
    }

    public function testIsValidReturnsFalseForBrokenState(): void
    {
        $broken = new CacheMetadata(
            identifier: new CacheIdentifier('x'),
            hash: new PayloadHash(str_repeat('a', 64)),
            checksum: new PayloadChecksum(str_repeat('b', 64)),
            generation: new Generation(7),
            lifetime: new Lifetime(1000, 2000),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            payloadSize: 0,
            tags: new TagSet(),
            state: CacheState::Broken,
            backendVersion: new BackendVersion(1),
        );
        self::assertFalse($broken->isValid(1500, new Generation(7)));
    }

    public function testFromKvPayloadFailsOnMissingField(): void
    {
        $this->expectException(\RuntimeException::class);
        CacheMetadata::fromKvPayload(['identifier' => 'a']);
    }

    private function buildMetadata(): CacheMetadata
    {
        return new CacheMetadata(
            identifier: new CacheIdentifier('page_42'),
            hash: new PayloadHash(str_repeat('a', 64)),
            checksum: new PayloadChecksum(str_repeat('b', 64)),
            generation: new Generation(7),
            lifetime: new Lifetime(1000, 2000),
            serializer: new SerializerName(SerializerName::IGBINARY, 'igbinary:3.2.16'),
            compression: CompressionName::zstd(),
            payloadSize: 4096,
            tags: new TagSet(['pages_42', 'site_1']),
            state: CacheState::Valid,
            backendVersion: new BackendVersion(1),
        );
    }
}
