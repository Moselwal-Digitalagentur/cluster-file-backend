<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

interface ClockPort
{
    /**
     * Returns the current time as a Unix timestamp (seconds since epoch).
     */
    public function now(): int;
}
