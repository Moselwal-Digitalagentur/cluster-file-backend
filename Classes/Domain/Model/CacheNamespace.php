<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;

/**
 * Logischer Namespace eines Cache-Eintrags. Wird ausschließlich für
 * Observability-Labels (Metric-Tags, Strukturierte-Log-Felder) verwendet —
 * KEINE Persistenz-Key-Erzeugung, da das die Aufgabe des konfigurierten
 * TYPO3-Cache-Backends ist.
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
     * Liefert das logische Namespace-Label `cfb:{env}:{instance}:{cacheName}`
     * für Observability-Zwecke (Metriken, Logs, CLI-Argument). Dies ist KEIN
     * Persistenz-Key — die Schlüssel im Metadata-Cache-Backend werden vom
     * TYPO3-Cache-Frontend selbst gemanagt.
     */
    public function toKvKeyPrefix(): string
    {
        return \sprintf('cfb:%s:%s:%s', $this->environment->value, $this->instance, $this->cacheName);
    }
}
