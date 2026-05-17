<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

return [
    'clusterfilebackend:gc' => [
        'class' => Moselwal\Typo3ClusterCache\Presentation\Command\GarbageCollectCommand::class,
        'schedulable' => true,
    ],
];
