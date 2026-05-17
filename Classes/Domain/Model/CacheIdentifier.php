<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

final readonly class CacheIdentifier
{
    private const string PATTERN = '/^[a-zA-Z0-9_.-]{1,250}$/';

    public function __construct(
        public string $value,
    ) {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException(\sprintf('Cache identifier "%s" violates required pattern %s', $value, self::PATTERN));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
