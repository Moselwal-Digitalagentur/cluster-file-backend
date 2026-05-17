# Changelog

Alle nennenswerten Änderungen werden in dieser Datei dokumentiert.
Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

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

[1.1.0]: https://gitlab.moselwal.io/development/moselwal/cluster-file-backend/-/compare/v1.0.1...v1.1.0
[1.0.1]: https://gitlab.moselwal.io/development/moselwal/cluster-file-backend/-/tags/v1.0.1
