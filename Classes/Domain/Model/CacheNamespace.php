<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;

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

    public function toKvKeyPrefix(): string
    {
        return \sprintf('cfb:%s:%s:%s', $this->environment->value, $this->instance, $this->cacheName);
    }

    public function metadataKey(CacheIdentifier $identifier): string
    {
        return \sprintf(
            'cfb:meta:%s:%s:%s:%s',
            $this->environment->value,
            $this->instance,
            $this->cacheName,
            $identifier->value,
        );
    }

    public function generationKey(): string
    {
        return \sprintf('cfb:gen:%s:%s:%s', $this->environment->value, $this->instance, $this->cacheName);
    }

    public function tagForwardKey(string $tag): string
    {
        return \sprintf(
            'cfb:tag:%s:%s:%s:%s',
            $this->environment->value,
            $this->instance,
            $this->cacheName,
            $tag,
        );
    }

    public function tagReverseKey(CacheIdentifier $identifier): string
    {
        return \sprintf(
            'cfb:identifier-tags:%s:%s:%s:%s',
            $this->environment->value,
            $this->instance,
            $this->cacheName,
            $identifier->value,
        );
    }

    public function lockKey(CacheIdentifier $identifier): string
    {
        return \sprintf(
            'cfb:lock:%s:%s:%s:%s',
            $this->environment->value,
            $this->instance,
            $this->cacheName,
            $identifier->value,
        );
    }

    public function frozenKey(): string
    {
        return \sprintf('cfb:frozen:%s:%s:%s', $this->environment->value, $this->instance, $this->cacheName);
    }

    public function gcRunningKey(): string
    {
        return \sprintf(
            'cfb:gc-running:%s:%s:%s',
            $this->environment->value,
            $this->instance,
            $this->cacheName,
        );
    }
}
