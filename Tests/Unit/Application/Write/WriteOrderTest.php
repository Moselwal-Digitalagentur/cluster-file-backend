<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Application\Write;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry;
use Moselwal\Typo3ClusterCache\Domain\Contract\LocalPayloadStorePort;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Exception\LocalStoreWriteException;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadNotFoundException;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\NullCompressor;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Regression: vor dem Fix wurde Metadata VOR dem lokalen Schreiben committed.
 * Bei einem Disk-Fail (ENOSPC, EACCES) blieb dann inkonsistente Metadata
 * in der Cluster-Wahrheit, alle anderen Pods erlebten endlos Blob-Miss.
 *
 * Erwartetes Verhalten nach dem Fix: lokal zuerst — wenn das failt, wird
 * KEINE Metadata committed.
 */
#[CoversClass(WriteCacheEntry::class)]
final class WriteOrderTest extends TestCase
{
    public function testLocalWriteFailureLeavesMetadataUntouched(): void
    {
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'pages');
        $id = new CacheIdentifier('order_test');
        $metadataCache = new FakeMetadataCache();
        $localStore = new class implements LocalPayloadStorePort {
            public function pathFor(PayloadHash $hash): string
            {
                return '/full/' . $hash->digest;
            }

            public function exists(PayloadHash $hash): bool
            {
                return false;
            }

            public function readVerified(PayloadHash $hash, PayloadChecksum $checksum): string
            {
                throw new PayloadNotFoundException('n/a');
            }

            public function write(PayloadHash $hash, string $bytes): void
            {
                throw new LocalStoreWriteException('ENOSPC');
            }

            public function delete(PayloadHash $hash): void {}

            public function iterateAll(): iterable
            {
                yield from [];
            }
        };
        $writer = new WriteCacheEntry(
            metadataCache: $metadataCache,
            localStore: $localStore,
            compressor: new NullCompressor(),
            clock: new FakeClock(1_700_000_000),
            metrics: new FakeMetrics(),
            hasher: new ComputePayloadHash(),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
        );

        try {
            $writer->execute($namespace, $id, 'payload', new TagSet(), 3600);
            self::fail('LocalStoreWriteException expected');
        } catch (LocalStoreWriteException) {
            // expected
        }

        // CRITICAL: Metadata darf NICHT geschrieben worden sein.
        // Sonst sehen alle anderen Pods "valid metadata" und erleben endlos Blob-Miss.
        self::assertNull(
            $metadataCache->get($id),
            'Metadata must not be committed when local write fails',
        );
    }
}
