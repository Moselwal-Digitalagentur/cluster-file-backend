<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Compression;

use Moselwal\Typo3ClusterCache\Domain\Contract\CompressorPort;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionAlgo;

final class GzipCompressor implements CompressorPort
{
    public function name(): string
    {
        return CompressionAlgo::Gzip->value;
    }

    public function isAvailable(): bool
    {
        return \function_exists('gzdeflate');
    }

    public function compress(string $bytes): string
    {
        $result = gzdeflate($bytes, 6);
        if (false === $result) {
            throw new \RuntimeException('gzdeflate() failed');
        }

        return $result;
    }

    public function decompress(string $bytes): string
    {
        $result = gzinflate($bytes);
        if (false === $result) {
            throw new \RuntimeException('gzinflate() failed');
        }

        return $result;
    }
}
