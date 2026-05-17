<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;

/**
 * Logical namespace of a cache entry. Used exclusively for observability
 * labels (metric tags, structured log fields) — NOT for persistence-key
 * generation, since that is the job of the configured TYPO3 cache backend.
 */
final readonly class CacheNamespace
{
    private const string INSTANCE_PATTERN = '/^[a-z0-9-]{1,64}$/';
    private const string CACHE_NAME_PATTERN = '/^[a-zA-Z0-9_]{1,64}$/';

    public function __construct(
        public EnvironmentName $environment,
        public string $instance,
        public string $cacheName,
    ) {
        if (1 !== preg_match(self::INSTANCE_PATTERN, $instance)) {
            throw new \InvalidArgumentException(\sprintf('Instance slug "%s" violates pattern %s', $instance, self::INSTANCE_PATTERN));
        }
        if (1 !== preg_match(self::CACHE_NAME_PATTERN, $cacheName)) {
            throw new \InvalidArgumentException(\sprintf('Cache name "%s" violates pattern %s', $cacheName, self::CACHE_NAME_PATTERN));
        }
    }

    /**
     * Returns the logical namespace label `cfb:{env}:{instance}:{cacheName}`
     * for observability purposes (metrics, logs, CLI arguments). This is NOT
     * a persistence key — the keys in the metadata cache backend are managed
     * by the TYPO3 cache frontend itself.
     */
    public function toKvKeyPrefix(): string
    {
        return \sprintf('cfb:%s:%s:%s', $this->environment->value, $this->instance, $this->cacheName);
    }
}
