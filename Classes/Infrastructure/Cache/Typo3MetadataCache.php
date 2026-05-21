<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Cache;

use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
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
    /**
     * Tag prefix used to namespace every entry by its owning cache. Every
     * `set()` writes an additional `__cfb_ns__{cacheName}` tag; `flush()`
     * then targets exactly those entries via `flushByTag(...)` instead of
     * the underlying frontend's `flush()` — which would otherwise wipe
     * sibling caches that share the same metadata-cache backend.
     */
    private const string NAMESPACE_TAG_PREFIX = '__cfb_ns__';

    /**
     * Separator between the cache-name prefix and a user tag. Belongs to
     * the TYPO3 tag-pattern character set `[a-zA-Z0-9_%\-&]` so any chosen
     * separator is itself a legal tag character.
     */
    private const string USER_TAG_SEPARATOR = '__';

    public function __construct(
        private FrontendInterface $cache,
        private CacheNamespace $namespace,
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
            $this->tagsForPersist($tags),
            $ttlSeconds,
        );
    }

    public function remove(CacheIdentifier $identifier): bool
    {
        return $this->cache->remove($identifier->value);
    }

    public function flush(): void
    {
        // Do NOT call $this->cache->flush() — that would wipe entries of
        // sibling caches that share this metadata-cache frontend. Instead
        // delete exactly our namespaced entries via the namespace tag.
        $this->cache->flushByTag($this->namespaceTag());
    }

    public function flushByTag(string $tag): void
    {
        // Validate via TagSet's pattern. Even if the chosen TYPO3 backend
        // is safe against injection (Typo3DatabaseBackend uses prepared
        // statements, KeyValueBackend uses Redis-protocol), we keep the
        // domain-pattern enforcement at every cache-API entry point.
        new TagSet([$tag]);
        $this->cache->flushByTag($this->namespacedUserTag($tag));
    }

    public function flushByTags(array $tags): void
    {
        new TagSet($tags);
        $namespaced = [];
        foreach ($tags as $tag) {
            $namespaced[] = $this->namespacedUserTag($tag);
        }
        $this->cache->flushByTags($namespaced);
    }

    public function findIdentifiersByTag(string $tag): array
    {
        new TagSet([$tag]);
        $backend = $this->cache->getBackend();
        if (!$backend instanceof TaggableBackendInterface) {
            return [];
        }

        return array_values($backend->findIdentifiersByTag($this->namespacedUserTag($tag)));
    }

    public function collectGarbage(): void
    {
        $this->cache->collectGarbage();
    }

    /**
     * @param list<string> $userTags
     *
     * @return list<string>
     */
    private function tagsForPersist(array $userTags): array
    {
        $result = [$this->namespaceTag()];
        foreach ($userTags as $tag) {
            $result[] = $this->namespacedUserTag($tag);
        }

        return $result;
    }

    private function namespaceTag(): string
    {
        return self::NAMESPACE_TAG_PREFIX . $this->namespace->cacheName;
    }

    private function namespacedUserTag(string $userTag): string
    {
        return self::NAMESPACE_TAG_PREFIX . $this->namespace->cacheName . self::USER_TAG_SEPARATOR . $userTag;
    }
}
