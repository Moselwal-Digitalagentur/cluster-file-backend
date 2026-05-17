<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;

final readonly class Lifetime
{
    public function __construct(
        public int $createdAt,
        public int $expiresAt,
    ) {
        if ($createdAt <= 0) {
            throw new \InvalidArgumentException(\sprintf('createdAt must be > 0, got %d', $createdAt));
        }
        if ($expiresAt <= $createdAt) {
            throw new \InvalidArgumentException(\sprintf('expiresAt (%d) must be > createdAt (%d)', $expiresAt, $createdAt));
        }
    }

    public static function fromSeconds(int $seconds, ClockPort $clock): self
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException(\sprintf('Lifetime duration must be >= 1 second, got %d', $seconds));
        }
        $now = $clock->now();

        return new self($now, $now + $seconds);
    }

    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function remainingSeconds(int $now): int
    {
        return max(0, $this->expiresAt - $now);
    }
}
