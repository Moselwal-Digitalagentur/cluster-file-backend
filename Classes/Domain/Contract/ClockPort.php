<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

interface ClockPort
{
    /**
     * Liefert die aktuelle Zeit als Unix-Timestamp (Sekunden seit Epoch).
     */
    public function now(): int;
}
