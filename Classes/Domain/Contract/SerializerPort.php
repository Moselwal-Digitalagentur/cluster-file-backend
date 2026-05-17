<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

interface SerializerPort
{
    public function name(): string;

    public function version(): string;

    public function isAvailable(): bool;

    /**
     * @throws \Moselwal\Typo3ClusterCache\Domain\Exception\SerializationFailedException
     */
    public function serialize(mixed $value): string;

    /**
     * @throws \Moselwal\Typo3ClusterCache\Domain\Exception\DeserializationFailedException
     */
    public function deserialize(string $bytes): mixed;
}
