<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

interface CompressorPort
{
    public function name(): string;

    public function isAvailable(): bool;

    public function compress(string $bytes): string;

    public function decompress(string $bytes): string;
}
