<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

/*
 * Beispiel: vollständige TYPO3-14-Cache-Konfiguration für stateless
 * Kubernetes-Pods mit moselwal/cluster-file-backend.
 *
 * Kopiere diesen Block in deine `config/system/settings.php` und passe
 * `environment`, `instance` sowie die `cluster_meta`-Backend-Optionen an
 * deine Infrastruktur an.
 *
 * Hinweis: Die Reihenfolge ist wichtig — `cluster_meta` MUSS vor allen
 * ClusterFileBackend-Caches definiert sein, damit der Konstruktor des
 * ClusterFileBackend das Frontend via `CacheManager::getCache(...)` finden
 * kann. PHP-Arrays bewahren Insertion-Order; diese Datei hält die korrekte
 * Reihenfolge ein.
 *
 * Diese Datei wird NICHT automatisch geladen — Konsumenten müssen sie
 * explizit in ihre TYPO3-System-Konfiguration übernehmen, weil
 * Hostnamen, Ports, Pfade und TLS-Zertifikate site-spezifisch sind.
 */

// ---------------------------------------------------------------------------
// 1) ZENTRALE METADATA-CACHE: das Cluster-Backend (Redis/Valkey via
//    moselwal/keyvalue-store). Alternativ Typo3DatabaseBackend, Memcached, ...
// ---------------------------------------------------------------------------

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['cluster_meta'] = [
    'frontend' => TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend'  => Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend::class,
    'options'  => [
        // === Verbindung ===
        'hostname' => getenv('VALKEY_HOST') ?: 'valkey',
        'port'     => (int) (getenv('VALKEY_PORT') ?: 6379),
        'database' => 0,

        // === Optionale Sentinel-Konfiguration ===
        // 'sentinel'         => true,
        // 'sentinel_host'    => 'redis-sentinel',
        // 'sentinel_service' => 'cluster-meta-master',

        // === Optionales TLS / mTLS ===
        // 'tls'              => true,
        // 'ca_file'          => '/run/tls/ca.crt',
        // 'cert_file'        => '/run/tls/client.crt',
        // 'key_file'         => '/run/tls/client.key',
        // 'verify_peer'      => true,

        // === Namespace-Trennung gegen andere Caches auf demselben Redis ===
        'keyPrefix'       => 'cfb_meta_',
        'defaultLifetime' => 0,  // 0 = keine Default-TTL; TTL kommt pro set()
    ],
    // Default-Tags, die mit jedem Eintrag mitgeschrieben werden — optional.
    // 'defaultTags' => [],
];

// ---------------------------------------------------------------------------
// 2) DIE FILE-CACHES, DIE WIR ERSETZEN
//    pages, pagesection, rootline, imagesizes, assets, hash, runtime …
//    Welche Caches du auf ClusterFileBackend umstellst, ist eine
//    Performance-/Footprint-Entscheidung pro Site.
// ---------------------------------------------------------------------------

$clusterBackend = Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend::class;

$clusterCacheDefaults = [
    'metadataCacheIdentifier' => 'cluster_meta',
    'namespace' => [
        'environment' => getenv('TYPO3_ENV') ?: 'prod',
        'instance'    => getenv('TYPO3_INSTANCE') ?: 'website-a',
    ],
    // Optional (mit Defaults aus dem JSON-Schema):
    // 'compression'            => 'zstd',     // zstd | gzip | none
    // 'serializer'             => 'igbinary', // igbinary | php
    // 'defaultLifetimeSeconds' => 3600,
    // 'maxPayloadBytes'        => 10_485_760, // 10 MB
];

foreach ([
    'pages',
    'pagesection',
    'rootline',
    'imagesizes',
    'assets',
    'hash',
] as $cacheName) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$cacheName] = [
        'frontend' => TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend'  => $clusterBackend,
        'options'  => $clusterCacheDefaults + [
            'localPath' => '/app/var/cache/cluster/' . $cacheName,
        ],
    ];
}
