<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Deployment;

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
 * Spec SC-005 / T162: Während eines Rolling Deploys treten KEINE Cache-
 * Inkonsistenzen zwischen alten und neuen Pods auf. Simuliert über zwei
 * Backend-Instanzen mit identischer Konfiguration (gleiche BackendVersion,
 * gleicher Serializer, gleiche Compression), die sich denselben Metadata-
 * Cache teilen — Stand-in für „Pod A (alt) + Pod B (neu) mit identischem
 * Image".
 */
final class RollingDeployTest extends TestCase
{
    public function testTwoBackendInstancesShareConsistentReads(): void
    {
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'rolling', 'pages');
        $sharedCache = new FakeMetadataCache();
        $podALocal = new InMemoryLocalPayloadStore();
        $podBLocal = new InMemoryLocalPayloadStore();
        $clock = new FakeClock(1_700_000_000);
        $metrics = new FakeMetrics();
        $compressor = new NullCompressor();
        $hasher = new ComputePayloadHash();

        $writerA = new WriteCacheEntry(
            metadataCache: $sharedCache,
            localStore: $podALocal,
            compressor: $compressor,
            clock: $clock,
            metrics: $metrics,
            hasher: $hasher,
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
        );
        $readerA = new ReadCacheEntry(
            metadataCache: $sharedCache,
            localStore: $podALocal,
            compressor: $compressor,
            clock: $clock,
            metrics: $metrics,
        );
        $readerB = new ReadCacheEntry(
            metadataCache: $sharedCache,
            localStore: $podBLocal,
            compressor: $compressor,
            clock: $clock,
            metrics: $metrics,
        );

        $id = new CacheIdentifier('rolling_page');
        $writerA->execute($namespace, $id, 'shared_payload', new TagSet(), 3600);

        // Pod A liefert aus lokalem Store
        self::assertSame('shared_payload', $readerA->execute($namespace, $id));
        // Pod B (frisch deployed, leerer Local-Store) erlebt Blob-Miss
        self::assertNull($readerB->execute($namespace, $id));
        self::assertSame(1, $metrics->counterTotal('blob_miss_total'));
    }

    public function testBlobMissOnNewPodIsRecoveredByDeterministicWrite(): void
    {
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'rolling', 'pages');
        $sharedCache = new FakeMetadataCache();
        $podALocal = new InMemoryLocalPayloadStore();
        $podBLocal = new InMemoryLocalPayloadStore();
        $clock = new FakeClock(1_700_000_000);
        $metrics = new FakeMetrics();
        $compressor = new NullCompressor();
        $hasher = new ComputePayloadHash();

        $build = fn(InMemoryLocalPayloadStore $store): WriteCacheEntry => new WriteCacheEntry(
            metadataCache: $sharedCache,
            localStore: $store,
            compressor: $compressor,
            clock: $clock,
            metrics: $metrics,
            hasher: $hasher,
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
        );

        $writerA = $build($podALocal);
        $writerB = $build($podBLocal);
        $readerB = new ReadCacheEntry(
            metadataCache: $sharedCache,
            localStore: $podBLocal,
            compressor: $compressor,
            clock: $clock,
            metrics: $metrics,
        );

        $id = new CacheIdentifier('rolling_recover');
        $writerA->execute($namespace, $id, 'payload', new TagSet(), 3600);
        $metaA = $sharedCache->get($id);

        // Pod B sieht Blob-Miss, Caller schreibt mit identischem Payload neu
        self::assertNull($readerB->execute($namespace, $id));
        $writerB->execute($namespace, $id, 'payload', new TagSet(), 3600);
        $metaB = $sharedCache->get($id);

        // Hash bleibt identisch — Pod B repariert ohne Identitätswechsel
        self::assertNotNull($metaA);
        self::assertNotNull($metaB);
        self::assertTrue($metaA->hash->equals($metaB->hash));
        self::assertSame('payload', $readerB->execute($namespace, $id));
        self::assertSame(1, $metrics->counterTotal('repair_success_total'));
    }
}
