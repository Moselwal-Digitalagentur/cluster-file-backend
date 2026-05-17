<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\LocalStore;

use Moselwal\Typo3ClusterCache\Domain\Contract\LocalPayloadStorePort;
use Moselwal\Typo3ClusterCache\Domain\Exception\LocalStoreWriteException;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadIntegrityException;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadNotFoundException;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadReference;

final class EmptyDirPayloadStore implements LocalPayloadStorePort
{
    public function __construct(
        private readonly string $localPath,
    ) {
        if ('' === $localPath || '/' !== $localPath[0]) {
            throw new \InvalidArgumentException(\sprintf('EmptyDirPayloadStore.localPath must be an absolute path, got "%s"', $localPath));
        }
    }

    public function pathFor(PayloadHash $hash): string
    {
        return PayloadReference::build($this->localPath, $hash)->path;
    }

    public function exists(PayloadHash $hash): bool
    {
        return is_file($this->pathFor($hash));
    }

    public function readVerified(PayloadHash $hash, PayloadChecksum $checksum): string
    {
        $path = $this->pathFor($hash);
        if (!is_file($path)) {
            throw new PayloadNotFoundException(\sprintf('Payload file not found: %s', $path));
        }
        $bytes = @file_get_contents($path);
        if (false === $bytes) {
            throw new PayloadNotFoundException(\sprintf('Payload file unreadable: %s', $path));
        }
        $actual = PayloadChecksum::ofBytes($bytes);
        if (!$actual->equals($checksum)) {
            throw new PayloadIntegrityException(\sprintf('Checksum mismatch for payload at %s (expected %s, got %s)', $path, $checksum->digest, $actual->digest));
        }

        return $bytes;
    }

    public function write(PayloadHash $hash, string $bytes): void
    {
        $reference = PayloadReference::build($this->localPath, $hash);
        $directory = $reference->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new LocalStoreWriteException(\sprintf('Failed to create directory %s', $directory));
        }
        $tmp = @tempnam($directory, '.cfb.tmp.');
        if (false === $tmp) {
            throw new LocalStoreWriteException(\sprintf('tempnam() failed in %s', $directory));
        }
        $written = @file_put_contents($tmp, $bytes, \LOCK_EX);
        if (false === $written || $written !== \strlen($bytes)) {
            @unlink($tmp);
            throw new LocalStoreWriteException(\sprintf('file_put_contents() wrote %d of %d bytes to %s', (int) $written, \strlen($bytes), $tmp));
        }
        @chmod($tmp, 0o640);
        if (!@rename($tmp, $reference->path)) {
            @unlink($tmp);
            throw new LocalStoreWriteException(\sprintf('rename() from %s to %s failed', $tmp, $reference->path));
        }
    }

    public function delete(PayloadHash $hash): void
    {
        $path = $this->pathFor($hash);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function iterateAll(): iterable
    {
        if (!is_dir($this->localPath)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->localPath,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );
        $hexPattern = '/^[a-f0-9]{64}$/';
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (1 !== preg_match($hexPattern, $name)) {
                continue;
            }
            yield new PayloadHash($name);
        }
    }
}
