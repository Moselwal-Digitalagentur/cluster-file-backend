<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;

interface PayloadRebuilderPort
{
    /**
     * Optionaler Hinweis-Hook für Caller, der Rebuild-Konditionen kommuniziert.
     * Aktuell ist Rebuild eine Caller-Verantwortung (siehe research.md R-011);
     * dieses Interface ist Reserve für spätere Erweiterung.
     */
    public function getRebuildHint(CacheIdentifier $identifier): ?string;
}
