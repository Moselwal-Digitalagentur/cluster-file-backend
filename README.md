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
- **PHP**: 8.5+
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

## Cluster-Konsistenz: was passiert beim Cache-Clear?

> Häufig gestellte Frage: „Wenn ein Editor im TYPO3-Backend auf *Clear all caches*
> klickt, wie wird sichergestellt, dass **alle** Pods das mitbekommen?"

Antwort in einer Zeile: Der Pod, der den Klick verarbeitet, leert die zentrale
Metadata. Alle anderen Pods fragen beim nächsten `get()` die zentrale Metadata —
und sehen sofort, dass sie leer ist. **Keine Pod-zu-Pod-Synchronisation nötig**,
weil die Metadata-Wahrheit nie auf einem Pod lebt.

### Ablauf im Detail

```
Pod A: TYPO3 Backend „Clear all caches" / Editor speichert Seite /
       `vendor/bin/typo3 cache:flush`
   │
   ▼
ClusterFileBackend::flush()              auf Pod A
   │
   ▼  delegiert an Metadata-Cache-Frontend (z. B. `cluster_meta`)
$metadataCache->flush()
   │
   ▼  TYPO3-Cache-API ruft das konfigurierte Backend
KeyValueBackend / DatabaseBackend / MemcachedBackend → flush()
   │
   ▼  passiert SERVER-SEITIG (Redis `FLUSHDB`, SQL `TRUNCATE`, Memcached `flush_all`)
Alle Pods sehen sofort leere Metadata
```

Beim nächsten `get(id)` auf **irgendeinem** Pod:

```php
$metadata = $this->metadataCache->get($identifier);   // → null (cache geflushed)
if ($metadata === null) {
    // cache_miss_total{reason=no-metadata}++
    return null;   // ← Pod fragt NICHT seinen lokalen FS
}
```

Das ist der Kernunterschied zum TYPO3-Core-FileBackend: wir prüfen **niemals**
`file_exists()` als Cache-Gültigkeits-Entscheider. Der lokale FS ist Materialisierung,
nicht Wahrheitsquelle.

### Was passiert mit der lokalen Datei nach `flush()`?

**Nichts.** Sie bleibt liegen. Aber sie wird:

- nicht ausgeliefert, weil `ReadCacheEntry` zuerst die Metadata prüft und ohne
  Match sofort `null` zurückgibt;
- nicht über `file_exists()` „entdeckt", weil niemand außerhalb von
  `ReadCacheEntry::execute` den Pfad jemals direkt liest;
- bei nächstem `set()` mit identischem Inhalt **idempotent überschrieben**
  (derselbe Hash → derselbe Filename);
- bei Pod-Restart durch das `emptyDir`-Reset entfernt;
- oder bei einem GC-Lauf bereinigt.

Diese „orphan files" sind harmlos und kosten höchstens Disk-Space, nie Korrektheit.

### Was ist mit `flushByTag()`?

```
Editor speichert Seite 42 → TYPO3 ruft cache->flushByTag('pageId_42') auf Pod A
   │
   ▼
ClusterFileBackend::flushByTag('pageId_42')
   │
   ▼  delegiert an Metadata-Cache via TaggableBackendInterface
$metadataCache->flushByTag('pageId_42')
   │
   ▼  Backend-natives Tag-Lookup (z. B. Redis Tag-Index)
DELETE alle Metadata-Records mit Tag pageId_42
```

Alle Pods sehen das beim nächsten `get('page_42_lang_0')` (Cache-Miss).
Untagged Einträge oder mit anderem Tag bleiben gültig.

### Wenn der Inhalt sich ändert (= anderer Hash)

Wenn der Caller (TYPO3-Cache-Frontend) nach dem Flush **anderen** Inhalt produziert
(z. B. weil die Seite editiert wurde), wird ein neuer Hash berechnet → neue Metadata
→ neuer lokaler Filename. Die alte lokale Datei wird nicht mehr referenziert
(siehe oben).

Wenn der Caller **denselben** Inhalt deterministisch erneut produziert (z. B. weil
sich nur ein Cache-Lookup-Pfad refresht), ist der Hash identisch — die existierende
lokale Datei wird wiederverwendet (write ist idempotent). **Das ist gewollt: spart
Re-Materialisierung in Edge-Cases wie HPA-Scale-Up oder Pod-Restart.**

### Verifiziert durch Test-Suite

`Tests/Unit/Deployment/CrossPodFlushTest.php` enthält fünf Tests:

| Test | Was beweist er? |
|---|---|
| `testFlushOnPodAIsImmediatelyVisibleOnPodB` | Globaler `flush()` propagiert in Pod B sofort, ohne Sync-Schritt. |
| `testFlushByTagOnPodAInvalidatesOnlyMatchingEntriesOnPodB` | Tag-Flush invalidiert nur Matches; untagged Einträge bleiben. |
| `testLocalFileSurvivesFlushButIsUnreachableWithoutMetadata` | Lokale Datei überlebt Flush, ist aber nicht mehr auslieferbar. |
| `testWriteAfterFlushReestablishesConsistency` | Nach Flush + neuer Schreibvorgang: Cluster konsistent, Blob-Miss-Pfad funktioniert. |
| `testFlushPropagatesToArbitraryNumberOfPods` | Funktioniert für 1, 2, 5, N Pods — keine Skalierungs-Annahme. |

## Komplexität: warum es im Cluster schneller ist

| Operation | TYPO3 Core FileBackend | ClusterFileBackend | Speedup-Quelle |
|---|---|---|---|
| `flushByTag` | **O(N_all)** — DirectoryIterator über alle Cache-Files, 2× `file_get_contents` pro Datei | **O(M_matching)** — Backend liest Tag-Index direkt | Andere Komplexitätsklasse + Tag-Indizes |
| `findIdentifiersByTag` | **O(N_all)** wie oben | **O(M_matching)** | dito |
| `collectGarbage` | **O(N_all) per Pod** | **O(0)** active work (Redis TTL-Auto-Expire) bzw. **O(N_expired) server-side** (DB) | Backend-native + cluster-once |
| `flush` | O(N_all) per Pod | O(N_all) **einmal server-side** | Konstanten 100–1000× kleiner; kein Pod-Faktor |

**Konkretes Beispiel**: 10.000 Cache-Einträge, davon 100 mit Tag `site_1`,
5 Pods im Cluster.

| | File-Reads | unlink-Calls | Round-Trips |
|---|---|---|---|
| Core FileBackend (`flushByTag('site_1')`) | **20.000** (2 × 10.000) | 100 | ~20.100 lokale FS-IO **pro Pod** |
| Unser Backend (Redis) | **0** | 0 | ~2 (SMEMBERS + Pipeline-DEL) **einmal cluster-weit** |

Mehr als „kleineres n": **andere Komplexitätsklasse**, **Backend-native Algorithmen**
und **kein Pod-Multiplikator**.

## Lizenz

GPL-2.0-or-later — siehe `LICENSES/GPL-2.0-or-later.txt`.
