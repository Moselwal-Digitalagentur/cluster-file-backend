<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Compression;

use Moselwal\Typo3ClusterCache\Domain\Contract\CompressorPort;
use Moselwal\Typo3ClusterCache\Domain\Exception\CompressorUnavailableException;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionAlgo;

final class ZstdCompressor implements CompressorPort
{
    public function name(): string
    {
        return CompressionAlgo::Zstd->value;
    }

    public function isAvailable(): bool
    {
        return \extension_loaded('zstd');
    }

    public function compress(string $bytes): string
    {
        if (!$this->isAvailable()) {
            throw new CompressorUnavailableException('PHP extension "zstd" is not loaded');
        }
        $result = @zstd_compress($bytes);
        if (!\is_string($result)) {
            throw new \RuntimeException('zstd_compress() returned non-string result');
        }

        return $result;
    }

    public function decompress(string $bytes): string
    {
        if (!$this->isAvailable()) {
            throw new CompressorUnavailableException('PHP extension "zstd" is not loaded');
        }
        $result = @zstd_uncompress($bytes);
        if (!\is_string($result)) {
            throw new \RuntimeException('zstd_uncompress() failed');
        }

        return $result;
    }
}
