<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Observability;

/**
 * Reichert Log-Records des ClusterFileBackend mit den Pflichtfeldern an
 * (cacheName, identifier, hash, generation, podName, repairState).
 *
 * Verwendung als PSR-3-Processor:
 *   $logger->pushProcessor(new StructuredLoggerEnricher());
 */
final class StructuredLoggerEnricher
{
    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    public function __invoke(array $record): array
    {
        $record['context'] = ($record['context'] ?? []) + [
            'podName' => $this->resolvePodName(),
        ];

        return $record;
    }

    private function resolvePodName(): string
    {
        $env = getenv('POD_NAME');
        if (\is_string($env) && '' !== $env) {
            return $env;
        }
        $host = gethostname();

        return false !== $host ? $host : 'unknown';
    }
}
