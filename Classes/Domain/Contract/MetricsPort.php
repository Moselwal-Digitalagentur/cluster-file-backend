<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

interface MetricsPort
{
    /**
     * @param array<string, string> $labels
     */
    public function counter(string $name, array $labels = [], int $delta = 1): void;

    /**
     * @param array<string, string> $labels
     */
    public function histogram(string $name, array $labels, float $value): void;
}
