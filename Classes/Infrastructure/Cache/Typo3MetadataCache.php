<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Cache;

use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Adapter that maps the {@see MetadataCachePort} interface onto an arbitrary
 * TYPO3 cache frontend. The choice of the persistent backend (Redis via
 * {@see \Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend},
 * {@see \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend}, etc.) is therefore
 * purely a configuration concern for the TYPO3 consumer.
 *
 * Values are stored as arrays (`CacheMetadata::toKvPayload()` /
 * `::fromKvPayload()`) through the frontend; a
 * {@see \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend} is the natural choice.
 */
final readonly class Typo3MetadataCache implements MetadataCachePort
{
    public function __construct(
        private FrontendInterface $cache,
    ) {}

    public function get(CacheIdentifier $identifier): ?CacheMetadata
    {
        $raw = $this->cache->get($identifier->value);
        if (false === $raw || !\is_array($raw)) {
            return null;
        }
        try {
            return CacheMetadata::fromKvPayload($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    public function set(CacheIdentifier $identifier, CacheMetadata $metadata, array $tags, int $ttlSeconds): void
    {
        // TYPO3 cache-frontend convention: lifetime === 0 → forever,
        // lifetime === null → use the frontend's default. We propagate
        // 0 explicitly so the consumer's `defaultLifetime` cannot
        // accidentally shorten an entry that the caller marked as
        // unlimited.
        $this->cache->set(
            $identifier->value,
            $metadata->toKvPayload(),
            $tags,
            $ttlSeconds,
        );
    }

    public function remove(CacheIdentifier $identifier): bool
    {
        return $this->cache->remove($identifier->value);
    }

    public function flush(): void
    {
        $this->cache->flush();
    }

    public function flushByTag(string $tag): void
    {
        $this->cache->flushByTag($tag);
    }

    public function flushByTags(array $tags): void
    {
        $this->cache->flushByTags($tags);
    }

    public function findIdentifiersByTag(string $tag): array
    {
        $backend = $this->cache->getBackend();
        if (!$backend instanceof TaggableBackendInterface) {
            return [];
        }

        return array_values($backend->findIdentifiersByTag($tag));
    }

    public function collectGarbage(): void
    {
        $this->cache->collectGarbage();
    }
}
