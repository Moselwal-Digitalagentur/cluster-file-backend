<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

final readonly class Generation
{
    public function __construct(
        public int $value,
    ) {
        if ($value < 0) {
            throw new \InvalidArgumentException(\sprintf('Generation value must be >= 0, got %d', $value));
        }
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function isAtLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }
}
