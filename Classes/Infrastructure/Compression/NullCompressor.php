<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Compression;

use Moselwal\Typo3ClusterCache\Domain\Contract\CompressorPort;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionAlgo;

final class NullCompressor implements CompressorPort
{
    public function name(): string
    {
        return CompressionAlgo::None->value;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function compress(string $bytes): string
    {
        return $bytes;
    }

    public function decompress(string $bytes): string
    {
        return $bytes;
    }
}
