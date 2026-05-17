<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Observability;

use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Emittiert Metriken als strukturierte Logger-Records auf dem `monitoring`-Channel.
 * Ein Prometheus-Exporter kann diese Records via Logstash/Loki/Fluentd auslesen,
 * ohne harte Prometheus-Client-Dependency in dieser Extension.
 */
final readonly class PrometheusMetrics implements MetricsPort
{
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function counter(string $name, array $labels = [], int $delta = 1): void
    {
        $this->logger->info('cfb.metric.counter', [
            'metric' => $name,
            'kind' => 'counter',
            'delta' => $delta,
            'labels' => $labels,
        ]);
    }

    public function histogram(string $name, array $labels, float $value): void
    {
        $this->logger->info('cfb.metric.histogram', [
            'metric' => $name,
            'kind' => 'histogram',
            'value' => $value,
            'labels' => $labels,
        ]);
    }
}
