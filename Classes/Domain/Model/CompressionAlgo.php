<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

enum CompressionAlgo: string
{
    case Zstd = 'zstd';
    case Gzip = 'gzip';
    case None = 'none';
}
