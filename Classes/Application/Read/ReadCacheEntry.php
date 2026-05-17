<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\Read;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\CompressorPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\LocalPayloadStorePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Domain\Enum\CacheState;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadIntegrityException;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadNotFoundException;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;

final readonly class ReadCacheEntry
{
    public function __construct(
        private MetadataCachePort $metadataCache,
        private LocalPayloadStorePort $localStore,
        private CompressorPort $compressor,
        private ClockPort $clock,
        private MetricsPort $metrics,
    ) {}

    public function execute(CacheNamespace $namespace, CacheIdentifier $identifier): ?string
    {
        $labels = $this->labels($namespace);
        try {
            $metadata = $this->metadataCache->get($identifier);
        } catch (\Throwable) {
            // Metadata backend is unreachable (Redis outage, DB lock,
            // network blip). Treat as cache miss so the TYPO3 frontend
            // can trigger a caller rebuild instead of crashing the
            // request — at the cost of higher upstream load until the
            // backend recovers.
            $this->metrics->counter('cache_miss_total', $labels + ['reason' => 'metadata-error']);

            return null;
        }
        if (null === $metadata) {
            $this->metrics->counter('cache_miss_total', $labels + ['reason' => 'no-metadata']);

            return null;
        }
        if (CacheState::Broken === $metadata->state) {
            $this->metrics->counter('cache_miss_total', $labels + ['reason' => 'broken']);

            return null;
        }
        $now = $this->clock->now();
        if ($metadata->lifetime->isExpired($now)) {
            $this->metrics->counter('cache_miss_total', $labels + ['reason' => 'expired']);

            return null;
        }

        try {
            $bytes = $this->localStore->readVerified($metadata->hash, $metadata->checksum);
        } catch (PayloadNotFoundException) {
            $this->metrics->counter('blob_miss_total', $labels);

            return null;
        } catch (PayloadIntegrityException) {
            $this->localStore->delete($metadata->hash);
            $brokenMeta = new CacheMetadata(
                identifier: $metadata->identifier,
                hash: $metadata->hash,
                checksum: $metadata->checksum,
                generation: $metadata->generation,
                lifetime: $metadata->lifetime,
                serializer: $metadata->serializer,
                compression: $metadata->compression,
                payloadSize: $metadata->payloadSize,
                tags: $metadata->tags,
                state: CacheState::Broken,
                backendVersion: $metadata->backendVersion,
            );
            // Persist the broken state with at least 60s TTL so that other
            // pods do not immediately re-touch the corrupt entry — even if the
            // original lifetime was about to expire. Cap at 3600s so broken
            // markers do not linger forever.
            $brokenTtl = max(60, min(3600, $metadata->lifetime->remainingSeconds($now)));
            $this->metadataCache->set(
                $identifier,
                $brokenMeta,
                $metadata->tags->toArray(),
                $brokenTtl,
            );
            $this->metrics->counter('cache_miss_total', $labels + ['reason' => 'broken']);

            return null;
        }

        $this->metrics->counter('cache_hit_total', $labels);
        $this->metrics->counter('local_payload_hit_total', $labels);
        $this->metrics->histogram(
            'payload_size_bytes',
            $labels + ['direction' => 'read'],
            (float) \strlen($bytes),
        );

        return $this->compressor->decompress($bytes);
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
