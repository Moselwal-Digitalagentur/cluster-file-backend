<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\GarbageCollect;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;

/**
 * Orchestriert die Garbage Collection des Metadata-Caches. Die eigentliche
 * Räumlogik delegiert an das konfigurierte TYPO3-Cache-Backend
 * (z. B. {@see \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::collectGarbage()},
 * Redis/Valkey expiration, etc.). Diese Klasse ist eine dünne Hülle, die
 * zusätzlich Lauf-Reports liefert.
 */
final readonly class RunGarbageCollection
{
    public function __construct(
        private MetadataCachePort $metadataCache,
        private ClockPort $clock,
    ) {}

    public function execute(CacheNamespace $namespace, bool $dryRun = false): GarbageCollectionReport
    {
        $start = $this->clock->now();
        if (!$dryRun) {
            $this->metadataCache->collectGarbage();
        }

        return new GarbageCollectionReport(
            namespace: $namespace->toKvKeyPrefix(),
            dryRun: $dryRun,
            durationMs: ($this->clock->now() - $start) * 1000,
        );
    }
}
