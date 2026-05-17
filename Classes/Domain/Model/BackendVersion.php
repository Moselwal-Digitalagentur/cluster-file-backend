<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\BackendVersionInfo;

final readonly class BackendVersion
{
    public function __construct(
        public int $value,
    ) {
        if ($value < 1) {
            throw new \InvalidArgumentException(\sprintf('BackendVersion must be >= 1, got %d', $value));
        }
    }

    public static function current(): self
    {
        return new self(BackendVersionInfo::CURRENT);
    }
}
