<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;

interface LocalPayloadStorePort
{
    public function pathFor(PayloadHash $hash): string;

    public function exists(PayloadHash $hash): bool;

    /**
     * Liest die Bytes und validiert sie gegen die Checksum.
     *
     * @throws \Moselwal\Typo3ClusterCache\Domain\Exception\PayloadIntegrityException
     * @throws \Moselwal\Typo3ClusterCache\Domain\Exception\PayloadNotFoundException
     */
    public function readVerified(PayloadHash $hash, PayloadChecksum $checksum): string;

    /**
     * Atomar schreiben.
     *
     * @throws \Moselwal\Typo3ClusterCache\Domain\Exception\LocalStoreWriteException
     */
    public function write(PayloadHash $hash, string $bytes): void;

    public function delete(PayloadHash $hash): void;

    /**
     * @return iterable<int, PayloadHash>
     */
    public function iterateAll(): iterable;
}
