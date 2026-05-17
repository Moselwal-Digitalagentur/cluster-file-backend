<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Serializer;

use Moselwal\Typo3ClusterCache\Domain\Contract\SerializerPort;
use Moselwal\Typo3ClusterCache\Domain\Exception\DeserializationFailedException;
use Moselwal\Typo3ClusterCache\Domain\Exception\SerializationFailedException;
use Moselwal\Typo3ClusterCache\Domain\Exception\SerializerUnavailableException;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;

final class IgbinarySerializer implements SerializerPort
{
    public function name(): string
    {
        return SerializerName::IGBINARY;
    }

    public function version(): string
    {
        $version = phpversion('igbinary');

        return false !== $version ? 'igbinary:' . $version : 'igbinary:unknown';
    }

    public function isAvailable(): bool
    {
        return \extension_loaded('igbinary');
    }

    public function serialize(mixed $value): string
    {
        if (!$this->isAvailable()) {
            throw new SerializerUnavailableException('PHP extension "igbinary" is not loaded');
        }
        $result = @igbinary_serialize($value);
        if (!\is_string($result)) {
            throw new SerializationFailedException('igbinary_serialize() returned non-string result');
        }

        return $result;
    }

    public function deserialize(string $bytes): mixed
    {
        if (!$this->isAvailable()) {
            throw new SerializerUnavailableException('PHP extension "igbinary" is not loaded');
        }
        try {
            return @igbinary_unserialize($bytes);
        } catch (\Throwable $e) {
            throw new DeserializationFailedException('igbinary_unserialize() failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
