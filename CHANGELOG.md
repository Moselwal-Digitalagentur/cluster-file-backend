# Changelog

Alle nennenswerten Änderungen werden in dieser Datei dokumentiert.
Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [2.0.1] - 2026-05-21

### Removed

- **`Build/`-Verzeichnis komplett entfernt** (inkl. der drei
  Bash-Scripts und der Deprecation-Symbol-Liste). Beide Checks waren
  redundant:
  - `Build/check-reuse-headers.sh` → ersetzt durch
    `fsfe/reuse:latest` Docker-Image im CI (`reuse-lint` Job).
  - `Build/check-deprecated.sh` + `Build/deprecated-typo3-14.txt` →
    obsolet, seit `phpstan/phpstan-deprecation-rules` (v1.3.1) jeden
    deprecated-Symbol-Aufruf zur QA-Zeit flaggt.
- **Composer-Scripts `deprecated:check` und `reuse:check`** aus
  `composer.json` entfernt; `composer qa` ruft sie nicht mehr auf.
- **`.gitignore`-Negation `!/Build/`** entfernt — wird nicht mehr
  benötigt, da kein `Build/` mehr da ist.

### Changed

- **`.gitlab-ci.yml`**: `deprecated-check`- und `reuse-check`-Jobs
  durch einen einzigen `reuse-lint`-Job ersetzt, der das offizielle
  `fsfe/reuse:latest` Docker-Image nutzt. Deprecation-Enforcement
  läuft über den `phpstan`-Job.
- **README "Development"** dokumentiert jetzt die externen QA-Tools
  (`fsfe/reuse lint` lokal via Docker, deprecation-rules über
  `composer phpstan`).

### Rationale

Die lokalen Bash-Scripts duplizierten Funktionalität, die in
`moselwal/dev` (REUSE via `fsfe/reuse` im pre-commit-Hook) bzw. via
`phpstan-deprecation-rules` (deprecated-symbols) bereits sauber
zentralisiert ist. Weniger maintenance burden, single source of truth.

## [2.0.0] - 2026-05-21

### Breaking changes

- **`Generation` Value Object und das `generation`-Feld in `CacheMetadata`
  wurden entfernt.** Das Konzept war ein Phantom — `Generation::next()`
  wurde nie aufgerufen, jeder Write benutzte hardcoded
  `new Generation(0)`. `CacheMetadata::isValid()` verlangt keinen
  `Generation`-Parameter mehr. Legacy-Cache-Einträge mit `generation`-Feld
  in der KV-Payload werden weiterhin gelesen — das Feld wird ignoriert.
- **`CompressorPort::decompress` hat eine neue Pflicht-Signatur**:
  `decompress(string $bytes, int $maxOutputBytes): string`. Compression-
  Bomb-Schutz erforderlich. Alle drei Compressor-Implementierungen
  (Gzip, Zstd, Null) wurden angepasst.
- **`LocalPayloadStorePort::probe(): bool` ist neu** als Pflichtmethode.
  Ermöglicht echte Write-Probes im Deployment-Warm-Up. Custom-Adapter
  müssen die Methode implementieren.
- **`Typo3MetadataCache::__construct` nimmt jetzt `CacheNamespace` als
  zweites Argument**. Damit werden alle Metadata-Cache-Operationen pro
  ClusterFileBackend-Instanz namespaced (siehe "Architektur" unten).
- **`ReadCacheEntry::__construct` hat einen neuen
  `int $maxDecompressedBytes`-Parameter** (Default 256 MiB) für
  Compression-Bomb-Protection.
- **TYPO3 cache-frontend Konvention für `lifetime`**: `null` =
  default, `0` = forever, `> 0` = expire after N seconds, `< 0` =
  invalid → fallback auf default. War vorher in v1.3.0 fix für `0`,
  ist jetzt vollständig dokumentiert und durchgängig durchgesetzt.

### Architektur

- **B2: Tag-Namespacing zwischen ClusterFileBackend-Instanzen.**
  Vorher teilten sich alle Cache-Instanzen, die dasselbe
  `cluster_meta`-Backend nutzten, denselben Tag-Raum — `flushByTag` auf
  `pages` konnte versehentlich Einträge in `pagesection` mit löschen.
  `Typo3MetadataCache` prefixt jetzt jeden User-Tag mit
  `__cfb_ns__{cacheName}__` und hängt einen zusätzlichen
  `__cfb_ns__{cacheName}`-Tag an jeden Eintrag. `flush()` nutzt diesen
  Namespace-Tag statt der globalen `flush()`-Operation, sodass sibling-
  Caches sicher koexistieren.
- **`ClusterFileBackend::setCache()` rebuildet jetzt alle
  namespace-abhängigen Services** (writer, reader, etc.) — damit
  greift das Tag-Namespacing erst, wenn TYPO3 das Backend an einen
  konkreten Cache bindet.

### Fixed

- **B1: `WriteCacheEntry` propagierte `Lifetime::remainingSeconds()` an
  den Metadata-Cache** statt der TTL-Semantik (`0` für unlimited).
  Inkonsistenz zum Repair-Branch beseitigt; beide nutzen jetzt
  `ttlForBackend()`.
- **B3: `EmptyDirPayloadStore::write()` prüft jetzt vor `rename()`,
  ob der Ziel-Pfad oder das Shard-Verzeichnis ein Symlink ist** —
  Defense-in-Depth gegen Symlink-Race in fehlkonfigurierten
  Deployments. Identifier-Pfade werden auch beim Read auf Symlinks
  geprüft (treated as `PayloadIntegrityException`).
- **B4: `public/index.php` (GPL-lizenziert) entfernt** aus dem
  MIT-Repository (Versehen aus lokalem Test-Setup).
- **B5: CI-Default-Image auf PHP 8.5** angehoben (war PHP 8.3, im
  Konflikt mit `composer.json require: ^8.5`).
- **`.gitignore`-Regel `/build/` matchte auch das tracked
  `Build/`-Verzeichnis** auf macOS (case-insensitive HFS+/APFS). Fix
  mit `!/Build/`-Negation; alle Build-Scripts sind jetzt versioniert.

### Härtung (Security + Resilience)

- **Compression-Bomb-Protection**: alle Decompress-Operationen
  haben jetzt ein Output-Size-Limit, das vom `ClusterFileBackend`
  über `maxPayloadBytes` durchgereicht wird (Default 256 MiB).
- **`CacheMetadata::fromKvPayload` validiert Typen pro Feld** —
  korrupte Metadata-Cache-Daten (z.B. `expiresAt` als String)
  führen zu kontrollierter Exception statt unexpected behavior.
- **Logger-Sanitization**: Cache-Identifier werden vor dem Logging
  via sha256 gehasht (statt unredacted) — TYPO3 Cache-Identifier
  enthalten oft Session-IDs / User-Hashes.
- **Exception-Messages enthalten keine vollständigen Pfade mehr**;
  Wrapped-Cache-Exception leakt keine inneren `getMessage()`.
- **Broken-State-TTL Konstanten benannt** (`BROKEN_STATE_MIN_TTL_SECONDS`,
  `BROKEN_STATE_MAX_TTL_SECONDS`); zweiter `set()`-Call jetzt in
  try/catch eingeschlossen.
- **Tag-Validation in `flushByTag`/`flushByTags`/`findIdentifiersByTag`**:
  jeder Tag läuft durch `TagSet`-Pattern-Check (Defense-in-Depth gegen
  Custom-Backend mit fehlendem Escaping).
- **ENV-Variable-Länge in `BackendVersion::fromEnv` auf 512 chars
  gekappt** (sehr minor CPU-DoS-Vektor bei Container-Escape).

### Dead code removed

- `Classes/Domain/Model/Generation.php`
- `Classes/Infrastructure/Observability/NullMetrics.php` (Services.yaml
  aliased ohnehin direkt auf `PrometheusMetrics`)
- `Tests/Unit/Domain/Model/GenerationTest.php`
- Leere `Tests/Contract` und `Tests/Functional` PHPUnit-Suites
  (entsprechende `composer test:contract` / `test:functional`
  Scripts entfernt)
- `public/index.php` (GPL-Boilerplate aus lokalem Test-Setup)
- `.phpstan.cache/` (versehentlich committed gewesen)

### Added (Tests)

- 14 neue Tests; Baseline jetzt **181 Tests / 315 Assertions / 7
  skipped** (vorher 168/296/5):
  - `CompressorRoundtripTest` — dedizierte Tests für Gzip/Zstd/Null
    inkl. Compression-Bomb-Rejection.
  - `RemoveCacheEntryTest` — bisher nur indirekt via Read/Write-Flow
    abgedeckt.
  - Boundary-Tests: `CacheIdentifier`-Länge=250, `TagSet`=64.
  - `CacheNamespace::fromString` (neue Factory) inkl. Negativ-Cases.
  - `CacheMetadata`-Type-Mismatch und Legacy-Generation-Toleranz.

### Docs

- README "Operational requirements" erweitert um:
  - Required metadata-cache backend capabilities (Taggable-Tabelle).
  - Deploy-time `IMAGE_TAG`-Konsistenz zwischen Worker- und Web-Containern.
  - Y2K38-Limit für `Lifetime::unlimited()`.
  - crc32-Kollisions-Schwelle in `BackendVersion::fromString`.
- `docs/architecture.md`: Phantom-Klassen entfernt
  (`SerializerPort`, `PayloadRebuilderPort`, `NullMetrics`,
  `IgbinarySerializer`, `PhpNativeSerializer`).

### Toolchain

- Symfony-Patches via `composer update` (8 Security-Advisories → 0).
- `BackendWarmUpRunner` ist nicht mehr `final` (Testbarkeit).
- 3 Observability-PHPDocs auf Englisch übersetzt (durchgerutscht in
  v1.1.0-Sprachumstellung).

### Migration guide (1.x → 2.0)

Für Konsumenten der TYPO3-Cache-API (Standard-Nutzung) sind
**keine Code-Änderungen nötig**. Der `ClusterFileBackend`-Konstruktor,
die Cache-Konfiguration und die CLI-Commands sind alle
backwards-kompatibel.

Direkte Konsumenten der internen Application-Layer-Klassen müssen:

1. `Generation`-Importe und -Referenzen entfernen
2. `CacheMetadata::fromKvPayload` darf jetzt mit corrupter Payload
   `\RuntimeException` werfen (bisher konnte das durchschlüpfen).
3. `LocalPayloadStorePort`-Custom-Implementierungen müssen die neue
   `probe()`-Methode implementieren.
4. `CompressorPort`-Custom-Implementierungen müssen die neue
   `decompress($bytes, $maxOutputBytes)`-Signatur unterstützen.
5. Direkte `Typo3MetadataCache`-Instanzierung braucht jetzt einen
   `CacheNamespace` als zweites Argument.

## [1.3.2] - 2026-05-21

### Security

- **Composer-Dependencies upgedated** auf neueste Patch-Versionen.
  `composer audit` davor: 8 Security-Advisories für Symfony-Pakete
  (`symfony/cache`, `mailer`, `mime`, `routing`, `yaml`). Danach:
  **0 advisories**. Betroffene Pakete sind transitive Abhängigkeiten
  von TYPO3 14.3; das Update ist API-kompatibel (alle Pakete blieben
  in derselben Major/Minor-Version).

### Verified clean against TYPO3 14.3

Vollständiger Scan gegen alle 174 Deprecation/Breaking-RST-Files der
14.x-Reihe (14.0 + 14.1 + 14.2 + 14.3 + 14.3.x). Geprüfte Symbole, die
wir verwenden:

| Symbol | Status |
|---|---|
| `AbstractBackend`, `BackendInterface`, `TaggableBackendInterface` | strict-typed in 14.0 (Breaking-107315); unsere Signaturen exakt konform |
| `FrontendInterface`, `VariableFrontend` | strict-typed; konform |
| `CacheManager`, `CacheWarmupEvent` | unverändert |
| `Cache\Exception`, `InvalidCacheException`, `InvalidDataException` | unverändert |
| `FileBackend`, `SimpleFileBackend`, `Typo3DatabaseBackend` | nur in PHPDoc-Verweisen referenziert |
| `GeneralUtility::makeInstance` | weiterhin offiziell, **nicht** deprecated |

In 14.3 wurden mehrere `GeneralUtility`-Methoden deprecated
(`isOnCurrentHost`, `sanitizeLocalUrl`, `locationHeaderUrl`,
`getIndpEnv`, `resolveBackPath`) — wir nutzen davon **keine**.

### Changed

- **`Build/deprecated-typo3-14.txt`** erweitert um in 14.0 entfernte
  Klassen und 14.3-`GeneralUtility`-Deprecations, sodass jeder
  zukünftige Code-Pfad-Bug vom statischen CI-Check abgefangen wird.
- Datei auf Englisch umgestellt (Konsistenz).

### Fixed (versioning hygiene)

- **`Build/` directory war nie versioniert.** Die `.gitignore`-Regel
  `/build/` matchte auf case-insensitiven Filesystems (macOS HFS+/APFS)
  auch das tracked `Build/`-Verzeichnis. Ergebnis: `composer qa` brach
  auf jedem frischen Clone, weil `Build/check-deprecated.sh` und
  `Build/check-reuse-headers.sh` fehlten. `.gitignore` hat jetzt eine
  explizite `!/Build/` Negation und die drei Build-Dateien sind im Git
  aufgenommen.

## [1.3.1] - 2026-05-21

### Fixed

- **Deprecated Symfony API entfernt**: `Application::add()` (seit
  Symfony 7.4 deprecated) durch `Application::addCommand()` in
  `GarbageCollectCommandTest` ersetzt.

### Changed

- **`phpstan/phpstan-deprecation-rules ^2.0`** als `require-dev`
  eingebunden. `moselwal/dev` deklariert die deprecation-rules selbst
  nicht — die direkte Einbindung garantiert, dass jeder Konsumer einer
  deprecated 3rd-party API (TYPO3, Symfony, etc.) zur QA-Zeit
  geflaggt wird.
- **composer.json `suggest`-Sektion** auf Englisch umgestellt
  (Konsistenz mit dem restlichen Paket).
- **phpstan.neon-Kommentar** korrigiert (war veraltet — listete
  deprecation-rules als von moselwal/dev kommend, was nicht stimmte).

### Verified clean

Folgende Checks lieferten keine Treffer:
- TYPO3 14 deprecated Symbole (`composer deprecated:check`).
- `@deprecated`-Annotations im eigenen Code.
- Legacy-Dateien (`ext_emconf.php`, `ext_localconf.php`, `.bak`,
  `.DS_Store`).
- Manuelle Deprecation-Mechanismen (`DeprecationLogger`,
  `trigger_error(...E_USER_DEPRECATED)`).
- `FreezableBackendInterface` (in TYPO3 14 entfernt) wird nicht
  referenziert.

## [1.3.0] - 2026-05-17

### Fixed

- **`lifetime === 0` bedeutet jetzt "cache forever"** (TYPO3-Konvention,
  siehe `Typo3DatabaseBackend::FAKED_UNLIMITED_EXPIRE`). Vorher wurde
  `0` als "ungültig" interpretiert und auf `defaultLifetimeSeconds`
  zurückgesetzt — system-Caches (`cache_core`, `cache_runtime` etc.)
  bekamen dadurch ungewollt eine TTL und wurden nach 3600s evicted.
  **Production-Impact**: schleichende Cache-Misses für system-Caches.

### Added

- **`Lifetime::unlimited(ClockPort)`** Factory + `Lifetime::isUnlimited()`
  + `Lifetime::UNLIMITED_EXPIRES_AT = 2147483647` (TYPO3-Core-Konvention).
- **Graceful Degradation bei Metadata-Cache-Ausfall**:
  `ReadCacheEntry::execute()` fängt Exceptions vom MetadataCache,
  returnt `null` (= Cache-Miss) und inkrementiert die neue
  Metrik-Reason `cache_miss_total{reason=metadata-error}`. Damit
  überlebt die App einen Redis-Outage ohne Crash — auf Kosten von
  erhöhter Upstream-Last bis das Backend wieder erreichbar ist.
- **Drei neue Edge-Case-Tests**:
  - `Tests/Unit/EdgeCases/UnlimitedLifetimeTest.php` — verifiziert das
    `lifetime=0`-Verhalten Ende-zu-Ende (write → metadata-flag →
    read nach 10 Jahren).
  - `Tests/Unit/EdgeCases/MetadataCacheOfflineTest.php` — verifiziert
    graceful Degradation bei Backend-Ausfall (read returnt null,
    write surfaced Exception, keine Orphan-Inflation).
  - `Tests/Unit/EdgeCases/ConcurrentWriteTest.php` — verifiziert
    Last-Writer-Wins-Semantik + Orphan-Verhalten bei gleichzeitigen
    Cross-Pod-Writes.

### Changed

- **README**: neue Section **"Operational requirements"** mit Subsections
  zu Pod-Clock-Synchronisation (chrony / systemd-timesyncd in
  Kubernetes) und Metadata-Cache-Verfügbarkeit (Alert-Empfehlung auf
  `cache_miss_total{reason=metadata-error}`).

## [1.2.1] - 2026-05-17

### Changed

- **README**: Big-O-Notation der Komplexitäts-Sektion auf CS-Konventionen
  korrigiert (Cormen/Knuth):
  - Kleinbuchstabige, kursive Variablen (`O(`*n*`)`, `O(`*m*`)`,
    `O(`*k*`)`) statt Großbuchstaben.
  - `O(1)` für konstante Zeit statt mathematisch problematischem `O(0)`.
  - Pod-Faktor explizit in der Notation (`O(`*n* · *p*`)`) statt nur im
    Fließtext.
  - Notation-Legende vor der Tabelle definiert *n*, *m*, *k*, *p*.

## [1.2.0] - 2026-05-17

### Added

- **Deploy-scoped BackendVersion**:
  - `BackendVersion::fromEnv($envVar = 'IMAGE_TAG')` und
    `BackendVersion::fromString($deployIdentifier)` falten beliebige
    Deploy-Identifier (Image-Tag, Git-SHA, Release-Semver) via crc32 in
    eine stabile, cluster-konsistente Versionsnummer.
  - Neue Backend-Option **`backendVersionEnvVar`** (default: `IMAGE_TAG`)
    macht den env-Variable-Namen konfigurierbar — z. B. `CI_COMMIT_SHA`
    oder `RELEASE_VERSION`.
  - ClusterFileBackend liest die Variable beim Boot; jeder Deploy mit
    neuem Tag invalidiert automatisch alle Cache-Einträge. Schützt vor
    "App-Code mit Cache-Layout-Drift während Rolling Deploy".
- 8 neue Tests für `BackendVersion::fromEnv` / `::fromString`
  (Determinismus, Divergenz, Fallback, Min-1-Invariant).

### Changed

- **README "Rolling deploys with version skew"** empfiehlt jetzt
  `IMAGE_TAG`-Bumping als primären Schutz vor Cache-Korruption durch
  App-Code-Änderungen.

## [1.1.0] - 2026-05-17

### Added

- **Deployment-Time Cache Warm-Up**:
  - `Application/WarmUp/WarmUpCacheBackend.php` — Service mit
    Metadata-Health-Check, Local-Store-Path-Probe und optionalem Identifier-
    Pre-Touch.
  - `Application/WarmUp/WarmUpReport.php` — strukturiertes Result-Object,
    JSON-serialisierbar für CI/CD-Automation.
  - `Infrastructure/WarmUp/BackendWarmUpRunner.php` — orchestriert Warm-Ups
    über alle konfigurierten ClusterFileBackend-Caches.
  - `Presentation/Command/WarmUpCommand.php` — `clusterfilebackend:warmup`
    CLI-Befehl, JSON-Lines-Output, Exit-Code-Differenzierung.
  - `Presentation/EventListener/CacheWarmupListener.php` — automatische
    Aktivierung über `\TYPO3\CMS\Core\Cache\Event\CacheWarmupEvent`
    (Service.yaml `event.listener`-Tag).
- **Zero-Dependency Default-Konfiguration**:
  - `Configuration/Example/cache-configurations.example.php` nutzt jetzt
    `Typo3DatabaseBackend` als Metadata-Cache — funktioniert ohne zusätzliche
    Composer-Pakete.
  - `Configuration/Example/cache-configurations-redis.example.php` —
    optionale Redis/Valkey-Variante via `moselwal/keyvalue-store`.

### Changed

- **Lizenz**: auf **MIT** umgestellt (vorher proprietär). Reuse-Headers,
  `LICENSE`, `LICENSES/MIT.txt` und `composer.json` konsistent angepasst.
- **Sprache**: README, Inline-Code-Kommentare, PHPDoc-Blöcke, Test-
  Beschreibungen, JSON-Schema-Descriptions und Build-Scripts vollständig auf
  Englisch umgestellt.

### Tests

- 145 Unit-Tests / 263 Assertions / 5 architektonisch übersprungene
  (vorher 120/223).
- `Tests/Unit/Application/WarmUp/WarmUpCacheBackendTest.php` — verifiziert
  Health-Check, Probe-Counting, Report-Struktur.

## [1.0.1] - 2026-05-17

### Changed (Tech-Stack)

- **PHP-Baseline**: `^8.3` → **`^8.5`**.
- **TYPO3-Baseline**: `^14.0` → **`^14.3`**.
- **PHP-CS-Fixer-Regeln**: jetzt vollständig über `moselwal/dev` —
  `@Symfony` + `@PER-CS3x0` + **`@PHP85Migration`** + `@DoctrineAnnotation`.
  `.php-cs-fixer.dist.php` delegiert nun an `vendor/moselwal/dev/.php-cs-fixer.dist.php`.
- **PHPStan**: profitiert über `phpstan/extension-installer` automatisch von den
  `moselwal/dev`-Extensions (`phpstan-strict-rules`, `phpstan-phpunit`,
  `phpstan-deprecation-rules`); memory-limit auf 512M angehoben.
- **deptrac**: Migration von abandoned `qossmic/deptrac` auf
  **`deptrac/deptrac ^4.6`** — keine PHP-8.5-Deprecation-Hinweise mehr.
- **Constitution**: v1.0.0 → **v1.1.0** (MINOR — Tech-Stack-Baseline-Anhebung,
  Sync-Impact-Report im File-Header).

### Added

- **Test-Suite ausgebaut**: 120 Unit-Tests, 223 Assertions (vorher 100/176):
  - `Tests/Unit/Invariants/HardInvariantsTest.php` — 10 Hard Invariants aus
    der Spec, 6 testbar mit Fakes (I1, I2, I3, I4, I5, I10), 4 als
    architektonisch nicht-im-Paket-testbar markiert.
  - `Tests/Unit/EdgeCases/DiskFullSimulationTest.php` — `LocalStoreWriteException`
    bei ENOSPC.
  - `Tests/Unit/EdgeCases/BackendVersionBumpTest.php` — Hash-Diff bei
    `BackendVersion`-Inkrement.
  - `Tests/Unit/EdgeCases/MaxPayloadRejectionTest.php` — JSON-Schema-Validation
    von `maxPayloadBytes`-Grenzen.
  - `Tests/Unit/EdgeCases/FlushDuringWriteRaceTest.php` — vier Race-Konstellationen
    (set→flush, flush→set, set→flush→set, mehrere Tags).
  - `Tests/Unit/Deployment/RollingDeployTest.php` — zwei Backend-Instanzen
    cross-konsistent gegen geteilten Metadata-Cache (SC-005).
  - `Tests/Unit/Presentation/Command/GarbageCollectCommandTest.php` —
    `CommandTester`-Tests für Argument-Parsing, Exit-Codes, JSON-Lines-Output.
  - `Tests/Unit/Infrastructure/Observability/PrometheusMetricsTest.php`
    und `StructuredLoggerEnricherTest.php`.
  - `Tests/Unit/Infrastructure/Cache/Backend/OptionsValidatorTest.php`
    und `Tests/Unit/Infrastructure/Cache/Typo3MetadataCacheTest.php`
    (gegen TYPO3-`TransientMemoryBackend`).
  - Weitere Domain-VO-Tests: `BackendVersionTest`, `SerializerNameTest`,
    `CompressionNameTest`.
- **`docs/architecture.md`** mit DDD-4-Layer-Diagramm, Datenfluss-Sequenzen
  und Persistenz-Topologie.

### Added

- **MVP-Implementation des ClusterFileBackend** für TYPO3 14 (Composer-Mode-only).
- DDD-4-Layer-Architektur (Domain, Application, Infrastructure, Presentation),
  enforced via `deptrac`.
- **Domain-Layer**: Value Objects (`CacheNamespace`, `CacheIdentifier`, `PayloadHash`,
  `PayloadChecksum`, `Generation`, `Lifetime`, `TagSet`, `BackendVersion`,
  `SerializerName`, `CompressionName`, `PayloadReference`, `CacheMetadata`),
  Enums (`CacheState`, `EnvironmentName`), Port-Interfaces (`MetadataCachePort`,
  `LocalPayloadStorePort`, `SerializerPort`, `CompressorPort`, `ClockPort`,
  `MetricsPort`, `PayloadRebuilderPort`), Domain-Exceptions.
- **Application-Layer**: `WriteCacheEntry`, `ReadCacheEntry`, `RemoveCacheEntry`,
  `FlushNamespace`, `FlushByTag`, `RunGarbageCollection`, `ComputePayloadHash`.
- **Infrastructure-Layer**:
  - `ClusterFileBackend` — TYPO3-14-`BackendInterface` + `TaggableBackendInterface`
    Drop-in-Ersatz für `FileBackend`/`SimpleFileBackend`.
  - `Typo3MetadataCache` — Adapter, der den `MetadataCachePort` auf ein beliebiges
    TYPO3-Cache-Frontend abbildet. **Keine direkte Redis/Valkey-Anbindung.**
  - `EmptyDirPayloadStore` — atomares Schreiben (tempnam + rename) mit
    2-Zeichen-Sharding und sha256-Checksum-Validation.
  - Serializer (`IgbinarySerializer`, `PhpNativeSerializer`).
  - Compressor (`ZstdCompressor`, `GzipCompressor`, `NullCompressor`).
  - `SystemClock`, `NullMetrics`, `PrometheusMetrics`, `StructuredLoggerEnricher`.
  - `OptionsValidator` — JSON-Schema gegen
    `Configuration/Backend/ClusterFileBackend.options.schema.json`.
- **Presentation-Layer**: `GarbageCollectCommand` (`clusterfilebackend:gc` CLI).
- **Toolchain**: composer.json (Composer-only, kein `ext_emconf.php`, kein
  `ext-redis`), phpunit.xml.dist (PHPUnit 11, `failOnDeprecation`), phpstan.neon
  (Level 8 + bleedingEdge), deptrac.yaml (4-Layer-Regeln), `.php-cs-fixer.dist.php`
  (PER-CS3x0 + Symfony), REUSE-Setup, `Build/check-deprecated.sh`,
  `Configuration/Services.yaml`, `Configuration/Commands.php`.
- **Testing**: 62 Unit-Tests, 110 Assertions; Fakes (`FakeMetadataCache`,
  `FakeClock`, `FakeMetrics`, `InMemoryLocalPayloadStore`) für reproduzierbare
  Tests ohne TYPO3-/Container-Bootstrap.

### Architectural Notes

- **Wahrheitsquelle**: anderes TYPO3-Cache-Frontend (per `metadataCacheIdentifier`
  konfiguriert). Backend frei wählbar — Redis/Valkey (z. B. via
  `moselwal/keyvalue-store`), Database, Memcached, was der Anwender möchte.
- **Payload-Store**: pod-lokal unter `localPath/{shard2}/{hash}`, atomar
  geschrieben, ephemer (`emptyDir`), niemals Source of Truth.
- **Determinismus**: Identity-Hash deckt Payload, Serializer-Version,
  Compression-Mode, BackendVersion und PHP-Major/Minor ab.
- **`FreezableBackendInterface`**: in TYPO3 14 entfernt — nicht implementiert.
- **Keine Redis-/phpredis-/ext-redis-Abhängigkeit** in diesem Paket. Cluster-
  Persistenz ist Sache des konsumierenden TYPO3-Setups.
- **Constitution-Konformität**: PHPStan Level 8 grün, deptrac 0 Violations,
  keine deprecated TYPO3-14-Symbole, REUSE-Header in allen PHP-Quellen.

[2.0.1]: https://gitlab.moselwal.io/development/moselwal/cluster-file-backend/-/compare/v2.0.0...v2.0.1
[2.0.0]: https://gitlab.moselwal.io/development/moselwal/cluster-file-backend/-/compare/v1.3.2...v2.0.0
[1.3.2]: https://gitlab.moselwal.io/development/moselwal/cluster-file-backend/-/compare/v1.3.1...v1.3.2
[1.3.1]: https://gitlab.moselwal.io/development/moselwal/cluster-file-backend/-/compare/v1.3.0...v1.3.1
[1.3.0]: https://gitlab.moselwal.io/development/moselwal/cluster-file-backend/-/compare/v1.2.1...v1.3.0
[1.2.1]: https://gitlab.moselwal.io/development/moselwal/cluster-file-backend/-/compare/v1.2.0...v1.2.1
[1.2.0]: https://gitlab.moselwal.io/development/moselwal/cluster-file-backend/-/compare/v1.1.0...v1.2.0
[1.1.0]: https://gitlab.moselwal.io/development/moselwal/cluster-file-backend/-/compare/v1.0.1...v1.1.0
[1.0.1]: https://gitlab.moselwal.io/development/moselwal/cluster-file-backend/-/tags/v1.0.1
