<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\Write;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\CompressorPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\LocalPayloadStorePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Domain\Enum\CacheState;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\Lifetime;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;

final class WriteCacheEntry
{
    public function __construct(
        private readonly MetadataCachePort $metadataCache,
        private readonly LocalPayloadStorePort $localStore,
        private readonly CompressorPort $compressor,
        private readonly ClockPort $clock,
        private readonly MetricsPort $metrics,
        private readonly ComputePayloadHash $hasher,
        private readonly SerializerName $serializer,
        private readonly CompressionName $compression,
        private readonly BackendVersion $backendVersion,
    ) {}

    public function execute(
        CacheNamespace $namespace,
        CacheIdentifier $identifier,
        string $rawBytes,
        TagSet $tags,
        int $lifetimeSeconds,
    ): void {
        $compressed = $this->compressor->compress($rawBytes);
        $hash = $this->hasher->fromRawBytes(
            $rawBytes,
            $this->serializer,
            $this->compression,
            $this->backendVersion,
        );
        $checksum = PayloadChecksum::ofBytes($compressed);
        $lifetime = 0 === $lifetimeSeconds
            ? Lifetime::unlimited($this->clock)
            : Lifetime::fromSeconds($lifetimeSeconds, $this->clock);

        $existing = $this->metadataCache->get($identifier);
        if (null !== $existing && $existing->hash->equals($hash)) {
            // Repair path: same order — local first, then metadata.
            $this->localStore->write($hash, $compressed);
            $this->metadataCache->set(
                $identifier,
                $this->buildMetadata($identifier, $hash, $checksum, $lifetime, $tags, $compressed),
                $tags->toArray(),
                $this->ttlForBackend($lifetime),
            );
            $this->metrics->counter('payload_rebuild_total', $this->labels($namespace));
            $this->metrics->counter('repair_success_total', $this->labels($namespace));

            return;
        }

        // Write local FIRST: on disk-failure (ENOSPC, lost permissions) no
        // inconsistent metadata cluster state appears. If the metadata set
        // then fails, at worst an orphan local file remains, which the next
        // pod restart (emptyDir reset) or GC removes. Both kinds of orphan
        // are harmless compared to "metadata says valid, every pod sees an
        // endless blob-miss".
        $metadata = $this->buildMetadata($identifier, $hash, $checksum, $lifetime, $tags, $compressed);
        $this->localStore->write($hash, $compressed);
        $this->metadataCache->set(
            $identifier,
            $metadata,
            $tags->toArray(),
            $this->ttlForBackend($lifetime),
        );
        $this->metrics->counter('cache_write_total', $this->labels($namespace));
        $this->metrics->histogram(
            'payload_size_bytes',
            $this->labels($namespace) + ['direction' => 'write'],
            (float) \strlen($compressed),
        );
    }

    private function buildMetadata(
        CacheIdentifier $identifier,
        PayloadHash $hash,
        PayloadChecksum $checksum,
        Lifetime $lifetime,
        TagSet $tags,
        string $compressed,
    ): CacheMetadata {
        return new CacheMetadata(
            identifier: $identifier,
            hash: $hash,
            checksum: $checksum,
            lifetime: $lifetime,
            serializer: $this->serializer,
            compression: $this->compression,
            payloadSize: \strlen($compressed),
            tags: $tags,
            state: CacheState::Valid,
            backendVersion: $this->backendVersion,
        );
    }

    /**
     * Translates the domain Lifetime into the integer TTL semantics of the
     * TYPO3 metadata cache API: `0` means "cache forever", `>0` means
     * "expire after N seconds". Matches `Typo3DatabaseBackend::set()`.
     */
    private function ttlForBackend(Lifetime $lifetime): int
    {
        if ($lifetime->isUnlimited()) {
            return 0;
        }

        return $lifetime->remainingSeconds($this->clock->now());
    }

    /**
     * @return array<string, string>
     */
    private function labels(CacheNamespace $namespace): array
    {
        return [
            'cacheName' => $namespace->cacheName,
            'namespace' => $namespace->toKvKeyPrefix(),
        ];
    }
}
