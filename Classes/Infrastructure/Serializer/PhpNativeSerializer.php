<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Serializer;

use Moselwal\Typo3ClusterCache\Domain\Contract\SerializerPort;
use Moselwal\Typo3ClusterCache\Domain\Exception\DeserializationFailedException;
use Moselwal\Typo3ClusterCache\Domain\Exception\SerializationFailedException;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;

final class PhpNativeSerializer implements SerializerPort
{
    public function name(): string
    {
        return SerializerName::PHP_NATIVE;
    }

    public function version(): string
    {
        return 'php:native';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function serialize(mixed $value): string
    {
        try {
            return serialize($value);
        } catch (\Throwable $e) {
            throw new SerializationFailedException('serialize() failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function deserialize(string $bytes): mixed
    {
        $result = @unserialize($bytes, ['allowed_classes' => true]);
        if (false === $result && $bytes !== serialize(false)) {
            throw new DeserializationFailedException('unserialize() failed');
        }

        return $result;
    }
}
