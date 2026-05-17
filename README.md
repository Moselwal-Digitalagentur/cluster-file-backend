# cluster-file-backend

**Clusterfähiges TYPO3-14-Cache-Backend ohne Shared Filesystem.**

Drop-in-Ersatz für `TYPO3\CMS\Core\Cache\Backend\FileBackend` und `SimpleFileBackend`
in Kubernetes-Deployments. Cache-Gültigkeit kommt aus einem **zweiten** TYPO3-Cache-
Frontend (das der Anwender frei konfiguriert), Payloads werden **pod-lokal** als
atomar geschriebene Dateien materialisiert.

- **Composer-Paket**: `moselwal/cluster-file-backend`
- **Extension-Key**: `cluster_file_backend`
- **Namespace**: `Moselwal\Typo3ClusterCache\`
- **TYPO3**: 14.x (Composer-Mode-only — kein `ext_emconf.php`)
- **PHP**: 8.3+
- **Lizenz**: GPL-2.0-or-later

## Architektur in einer Zeile

> Dieses Paket weiß **nichts** über Redis/Valkey/KV-Stores. Es spricht ausschließlich
> mit der TYPO3-Cache-API und delegiert die Cluster-Persistenz an ein vom Anwender
> gewähltes TYPO3-Cache-Backend.

```
TYPO3 Cache API → ClusterFileBackend
                      │
                      ├─► Metadata-Cache (anderes TYPO3-Cache-Frontend,
                      │   Backend frei wählbar: KeyValueBackend, Database,
                      │   Memcached, …)
                      │
                      └─► Local Payload Store (pod-lokal, emptyDir)
```

## Was es ist

- **Kein RWX-Volume** zwischen Pods erforderlich.
- **Zentrale Cache-Gültigkeit** über die TYPO3-Cache-API.
- **Deterministische Wiederherstellung** über sha256-Hash-Validierung.
- **Tag-basierte Invalidierung** clusterweit (via TYPO3-`TaggableBackendInterface`).
- **Garbage Collection** über CLI (`clusterfilebackend:gc`) — delegiert an das
  Metadata-Cache-Backend.

## Was es **nicht** ist

- Kein Ersatz für TYPO3 FAL.
- Kein Ersatz für fileadmin.
- Kein Ersatz für den TYPO3 Core Cache (`var/cache/code/core` bleibt im Image deployt).
- Kein Session-Store.
- Kein generischer Blob-Store.
- Kein Distributed Filesystem.
- **Bringt kein eigenes Redis/Valkey-Wissen mit.** Wer Redis als Cluster-Storage will,
  konfiguriert ein TYPO3-Cache-Backend dafür (z. B. `moselwal/keyvalue-store`s
  `KeyValueBackend`) und verweist `ClusterFileBackend` per `metadataCacheIdentifier`
  darauf.

## Installation

```bash
composer require moselwal/cluster-file-backend:^1.0
```

## Konfiguration

**Schritt 1**: Definiere einen TYPO3-Cache, der die Metadaten persistiert. Welches
Backend du wählst, ist deine Entscheidung — alles, was die TYPO3-Cache-API
implementiert, funktioniert (inkl. `TaggableBackendInterface` für `flushByTag`).

```php
// Beispiel: Redis-Backend via moselwal/keyvalue-store
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['cluster_meta'] = [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend'  => \Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend::class,
    'options'  => [
        'hostname' => 'valkey',
        'port'     => 6379,
        // TLS / Sentinel / Backoff: siehe moselwal/keyvalue-store
    ],
];

// Alternative ohne KV — DB-Cluster:
//   'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
```

**Schritt 2**: Verweise `ClusterFileBackend` auf diesen Cache.

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages'] = [
    'backend' => \Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend::class,
    'options' => [
        'localPath'               => '/app/var/cache/cluster/pages',
        'metadataCacheIdentifier' => 'cluster_meta',
        'namespace' => [
            'environment' => 'prod',
            'instance'    => 'website-a',
        ],
        // Defaults: compression=zstd, serializer=igbinary,
        // defaultLifetimeSeconds=3600, maxPayloadBytes=10MB.
    ],
];
```

Vollständige Konfigurations-Referenz: `Configuration/Backend/ClusterFileBackend.options.schema.json`.

## Kubernetes-Deployment (Auszug)

```yaml
volumes:
  - name: cluster-cache
    emptyDir: { sizeLimit: 2Gi }
volumeMounts:
  - name: cluster-cache
    mountPath: /app/var/cache/cluster
```

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: clusterfilebackend-gc-pages
spec:
  schedule: "*/15 * * * *"
  concurrencyPolicy: Forbid
  jobTemplate:
    spec:
      template:
        spec:
          containers:
            - name: typo3-cli
              args: ["clusterfilebackend:gc", "--namespace=cfb:prod:website-a:pages"]
```

## Architektur (intern)

DDD 4-Layer (Domain → Application → Infrastructure → Presentation), enforced via
`deptrac`. Die einzige Außenschnittstelle für „zentrale Wahrheit" ist der
`MetadataCachePort` — implementiert vom `Typo3MetadataCache`-Adapter, der jeden
beliebigen TYPO3-`FrontendInterface` annimmt.

Details:
- `specs/001-cluster-cache-backend/spec.md`
- `specs/001-cluster-cache-backend/plan.md`
- `specs/001-cluster-cache-backend/research.md`
- `specs/001-cluster-cache-backend/data-model.md`
- `specs/001-cluster-cache-backend/contracts/`
- `.specify/memory/constitution.md`

## Entwicklung

```bash
composer install
composer test               # Unit-Tests
composer test:contract      # Contract-Tests
composer test:functional    # Functional (benötigt TYPO3-Testing-Framework-Bootstrap)
composer phpstan            # PHPStan Level 8
composer deptrac            # DDD-Layer-Check
composer cs:check           # PER-CS3x0 + Symfony
composer deprecated:check   # TYPO3-14-Deprecation-Check
composer qa                 # Aggregat
```

## Häufige Fallstricke

- **`localPath` muss schreibbar sein**. Bei read-only `/app` muss `emptyDir`/`tmpfs`
  den Cache-Pfad bedienen.
- **Identisches Container-Image für alle Pods**. Andere PHP-/igbinary-Versionen
  führen zu unterschiedlichen Hashes → permanente Blob-Misses.
- **`metadataCacheIdentifier`** muss vor dem Booten des `pages`-Caches registriert
  sein. TYPO3 lädt `cacheConfigurations` der Reihe nach in der Array-Reihenfolge —
  also `cluster_meta` vor `pages` definieren.
- **Classic-Mode-TYPO3 wird nicht unterstützt** — Composer-Mode-only, kein `ext_emconf.php`.

## Lizenz

GPL-2.0-or-later — siehe `LICENSES/GPL-2.0-or-later.txt`.
