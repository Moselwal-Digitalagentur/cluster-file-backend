<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Time;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;

final class SystemClock implements ClockPort
{
    public function now(): int
    {
        return time();
    }
}
