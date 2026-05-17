<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Observability;

use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;

final class NullMetrics implements MetricsPort
{
    public function counter(string $name, array $labels = [], int $delta = 1): void
    {
        // No-Op — Production-Default ist PrometheusMetrics (Phase 7).
    }

    public function histogram(string $name, array $labels, float $value): void
    {
        // No-Op
    }
}
