<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\LocalStore;

use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadIntegrityException;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadNotFoundException;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Infrastructure\LocalStore\EmptyDirPayloadStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmptyDirPayloadStore::class)]
final class EmptyDirPayloadStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cfb-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->tmpDir);
    }

    public function testWriteAndReadVerifiedRoundtrip(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $bytes = 'hello world';
        $hash = new PayloadHash(hash('sha256', $bytes));
        $checksum = PayloadChecksum::ofBytes($bytes);

        $store->write($hash, $bytes);

        self::assertTrue($store->exists($hash));
        self::assertSame($bytes, $store->readVerified($hash, $checksum));
    }

    public function testReadVerifiedThrowsOnMissingFile(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $hash = new PayloadHash(str_repeat('e', 64));
        $checksum = PayloadChecksum::ofBytes('x');

        $this->expectException(PayloadNotFoundException::class);
        $store->readVerified($hash, $checksum);
    }

    public function testReadVerifiedThrowsOnChecksumMismatch(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $bytes = 'original';
        $hash = new PayloadHash(hash('sha256', $bytes));
        $store->write($hash, $bytes);

        $wrongChecksum = PayloadChecksum::ofBytes('different');

        $this->expectException(PayloadIntegrityException::class);
        $store->readVerified($hash, $wrongChecksum);
    }

    public function testDeleteIsIdempotent(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $hash = new PayloadHash(str_repeat('a', 64));
        $store->delete($hash);
        self::assertFalse($store->exists($hash));
    }

    public function testRequiresAbsolutePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmptyDirPayloadStore('relative/path');
    }

    private function removeRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
