<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

final readonly class SerializerName
{
    public const string IGBINARY = 'igbinary';
    public const string PHP_NATIVE = 'php';

    public function __construct(
        public string $name,
        public string $version,
    ) {
        if (self::IGBINARY !== $name && self::PHP_NATIVE !== $name) {
            throw new \InvalidArgumentException(\sprintf('Unknown serializer "%s" — expected "%s" or "%s"', $name, self::IGBINARY, self::PHP_NATIVE));
        }
        if ('' === $version) {
            throw new \InvalidArgumentException('SerializerName.version must not be empty');
        }
    }

    public static function igbinary(): self
    {
        $version = phpversion('igbinary');

        return new self(self::IGBINARY, false !== $version ? 'igbinary:' . $version : 'igbinary:unknown');
    }

    public static function phpNative(): self
    {
        return new self(self::PHP_NATIVE, 'php:native');
    }

    public static function detect(): self
    {
        if (\extension_loaded('igbinary')) {
            return self::igbinary();
        }

        return self::phpNative();
    }
}
