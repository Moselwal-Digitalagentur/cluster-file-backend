<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;

/**
 * Schmaler Vertrag für das Lesen/Schreiben/Invalidieren der zentralen
 * Cache-Metadaten. Die einzige im Paket erlaubte Sicht auf den
 * Metadata-Storage; die konkrete Implementation kapselt ein beliebiges
 * TYPO3-Cache-Frontend (z. B. {@see \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend}).
 *
 * Dieses Interface kennt KEIN Redis, KEIN Lua, KEIN Connection-Handling —
 * nur Cache-Semantik im Sinne der TYPO3-Cache-API.
 */
interface MetadataCachePort
{
    public function get(CacheIdentifier $identifier): ?CacheMetadata;

    /**
     * @param list<string> $tags
     */
    public function set(CacheIdentifier $identifier, CacheMetadata $metadata, array $tags, int $ttlSeconds): void;

    public function remove(CacheIdentifier $identifier): bool;

    public function flush(): void;

    public function flushByTag(string $tag): void;

    /**
     * @param list<string> $tags
     */
    public function flushByTags(array $tags): void;

    /**
     * @return list<string>
     */
    public function findIdentifiersByTag(string $tag): array;

    public function collectGarbage(): void;
}
