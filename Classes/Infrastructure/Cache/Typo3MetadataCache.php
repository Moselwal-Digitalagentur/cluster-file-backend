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
 * Adapter, der die {@see MetadataCachePort}-Schnittstelle auf ein beliebiges
 * TYPO3-Cache-Frontend abbildet. Damit ist die Wahl des persistenten
 * Backends (Redis via {@see \Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend},
 * {@see \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend}, etc.) reine
 * Konfigurationssache des TYPO3-Konsumenten.
 *
 * Werte werden als Array (`CacheMetadata::toKvPayload()` / `::fromKvPayload()`)
 * über das Frontend gespeichert; ein {@see \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend}
 * ist die natürliche Wahl.
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
        $this->cache->set(
            $identifier->value,
            $metadata->toKvPayload(),
            $tags,
            $ttlSeconds > 0 ? $ttlSeconds : null,
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
