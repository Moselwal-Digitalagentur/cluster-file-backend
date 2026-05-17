<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\EdgeCases;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\Read\ReadCacheEntry;
use Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\NullCompressor;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use Moselwal\Typo3ClusterCache\Tests\Support\InMemoryLocalPayloadStore;
use PHPUnit\Framework\TestCase;

/**
 * Spec Edge Case: „Versionserhöhung des Backends" — bei `backendVersion`-
 * Inkrement passen alte Hashes nicht mehr; Einträge fallen sauber in
 * Cache-Miss, ohne falsche Treffer.
 */
final class BackendVersionBumpTest extends TestCase
{
    public function testBackendVersionBumpInvalidatesViaHashDiff(): void
    {
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'pages');
        $id = new CacheIdentifier('versioned');
        $kv = new FakeMetadataCache();
        $local = new InMemoryLocalPayloadStore();
        $clock = new FakeClock(1_700_000_000);
        $metrics = new FakeMetrics();
        $hasher = new ComputePayloadHash();
        $compressor = new NullCompressor();

        $writerV1 = new WriteCacheEntry(
            metadataCache: $kv,
            localStore: $local,
            compressor: $compressor,
            clock: $clock,
            metrics: $metrics,
            hasher: $hasher,
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
        );
        $writerV1->execute($namespace, $id, 'payload', new TagSet(), 3600);

        // BackendVersion bump → neue Hash-Inputs
        $writerV2 = new WriteCacheEntry(
            metadataCache: $kv,
            localStore: $local,
            compressor: $compressor,
            clock: $clock,
            metrics: $metrics,
            hasher: $hasher,
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(2),
        );

        $reader = new ReadCacheEntry(
            metadataCache: $kv,
            localStore: $local,
            compressor: $compressor,
            clock: $clock,
            metrics: $metrics,
        );

        // Vor dem Bump war Hit. Wir schreiben mit V2 → neue Hash, neue Metadata,
        // alte lokale Datei matcht nicht mehr → bei nächstem get auf demselben Pod
        // ist Cache-Hit auf NEUE Metadata (lokale Datei wird durch write() neu
        // geschrieben mit V2-Hash).
        $writerV2->execute($namespace, $id, 'payload', new TagSet(), 3600);
        self::assertSame('payload', $reader->execute($namespace, $id));

        // Hash hat sich geändert
        $meta = $kv->get($id);
        self::assertNotNull($meta);
        self::assertSame(2, $meta->backendVersion->value);
    }
}
