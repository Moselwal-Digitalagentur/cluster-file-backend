# Architektur — cluster-file-backend

Dieses Dokument beschreibt die interne Architektur der Extension auf einen
Blick. Detail-Spezifikationen liegen in `specs/001-cluster-cache-backend/`.

## DDD-4-Layer

```
┌────────────────────────────────────────────────────────────────────────┐
│                         TYPO3 Cache API                                │
│            (CacheManager → FrontendInterface → BackendInterface)       │
└────────────────────────────────┬───────────────────────────────────────┘
                                 │
┌────────────────────────────────▼───────────────────────────────────────┐
│ Presentation                                                           │
│   GarbageCollectCommand (Symfony Console)                              │
└────────────────────────────────┬───────────────────────────────────────┘
                                 │
┌────────────────────────────────▼───────────────────────────────────────┐
│ Infrastructure                                                         │
│   ClusterFileBackend       — implementiert BackendInterface +          │
│                              TaggableBackendInterface                  │
│   Typo3MetadataCache       — Adapter MetadataCachePort ←→ TYPO3-       │
│                              FrontendInterface (beliebiges Backend)    │
│   EmptyDirPayloadStore     — atomares pod-lokales File-IO              │
│   {Igbinary,PhpNative}Ser. — Serializer-Adapter                        │
│   {Zstd,Gzip,Null}Compr.   — Compressor-Adapter                        │
│   SystemClock              — ClockPort                                 │
│   NullMetrics              — MetricsPort (Default für Tests)           │
│   PrometheusMetrics        — MetricsPort über PSR-3-Logger             │
│   StructuredLoggerEnricher — reichert Pod-Name + Co. an                │
│   OptionsValidator         — JSON-Schema-Validation der options[]      │
└────────────────────────────────┬───────────────────────────────────────┘
                                 │
┌────────────────────────────────▼───────────────────────────────────────┐
│ Application                                                            │
│   WriteCacheEntry          — set()-Orchestrierung                      │
│   ReadCacheEntry           — get()-Orchestrierung                      │
│   RemoveCacheEntry         — remove()                                  │
│   FlushNamespace           — flush()                                   │
│   FlushByTag               — flushByTag()                              │
│   RunGarbageCollection     — collectGarbage()-Orchestrierung           │
│   ComputePayloadHash       — sha256 mit definierten Hash-Inputs        │
└────────────────────────────────┬───────────────────────────────────────┘
                                 │
┌────────────────────────────────▼───────────────────────────────────────┐
│ Domain                                                                 │
│   Contracts (Ports):                                                   │
│     MetadataCachePort      — die einzige Sicht auf den Metadata-Store  │
│     LocalPayloadStorePort  — pod-lokales File-IO                       │
│     SerializerPort         — Serializer-Vertrag                        │
│     CompressorPort         — Compressor-Vertrag                        │
│     ClockPort              — zentrale Zeit                             │
│     MetricsPort            — Counter + Histogram                       │
│     PayloadRebuilderPort   — Reserve für künftige Erweiterung          │
│   Models (Value Objects, alle `final readonly`):                       │
│     CacheNamespace, CacheIdentifier, CacheMetadata,                    │
│     PayloadHash, PayloadChecksum, Generation, Lifetime,                │
│     TagSet, BackendVersion, SerializerName, CompressionName,           │
│     PayloadReference                                                   │
│   Enums:                                                               │
│     CacheState (Valid | Broken)                                        │
│     EnvironmentName (prod | staging | testing | development)           │
│   Exceptions:                                                          │
│     PayloadIntegrityException, PayloadNotFoundException,               │
│     LocalStoreWriteException, SerializerUnavailableException, …       │
└────────────────────────────────────────────────────────────────────────┘
```

## Abhängigkeitsregeln (`deptrac`)

| Layer | darf abhängen von |
|---|---|
| **Domain** | nur PHP-Core (`Throwable`, `InvalidArgumentException`, …) |
| **Application** | Domain, PSR-Contracts, PHP-Core |
| **Infrastructure** | Application, Domain, TYPO3-Framework, Symfony, PSR, PHP-Core, Vendor |
| **Presentation** | Application, Domain, TYPO3-Framework, Symfony, PSR, PHP-Core |

Enforced über `deptrac.yaml` — 0 Violations zur Laufzeit.

## Datenflüsse

### Schreib-Pfad (`set`)

```
TYPO3 CacheManager
  └─► ClusterFileBackend::set()
       ├─► WriteCacheEntry::execute()
       │    ├─► CompressorPort::compress(rawBytes)
       │    ├─► ComputePayloadHash::fromRawBytes(...)
       │    ├─► PayloadChecksum::ofBytes(compressed)
       │    ├─► MetadataCachePort::get(id)  ← existing? Hash gleich? → Repair-Pfad
       │    ├─► MetadataCachePort::set(id, CacheMetadata, tags, ttl)
       │    │       └─► FrontendInterface::set() im konfigurierten Cache
       │    └─► LocalPayloadStorePort::write(hash, compressed)
       │            └─► tempnam + write + rename (atomar)
       └─► MetricsPort::counter('cache_write_total', ...)
```

### Lese-Pfad (`get`)

```
TYPO3 CacheManager
  └─► ClusterFileBackend::get()
       └─► ReadCacheEntry::execute()
            ├─► MetadataCachePort::get(id)  ← null? broken? expired? → Cache-Miss
            └─► LocalPayloadStorePort::readVerified(hash, checksum)
                 ├─► File exists?            ← Datei fehlt → Blob-Miss (Caller re-computes)
                 └─► sha256(bytes) == checksum?  ← Mismatch → state=Broken, Cache-Miss
            ↳ Hit-Fall: CompressorPort::decompress(bytes) → return
```

### Garbage Collection (CLI)

```
clusterfilebackend:gc --namespace=cfb:prod:website-a:pages
  └─► GarbageCollectCommand::execute()
       └─► RunGarbageCollection::execute(namespace)
            └─► MetadataCachePort::collectGarbage()
                 └─► FrontendInterface::collectGarbage()
                      └─► Backend-eigene Räumlogik
                          (RedisBackend: TTL-Auto-Expire,
                           Typo3DatabaseBackend: DELETE WHERE expires < NOW())
```

## Persistenz-Topologie

**Zentral** (per `metadataCacheIdentifier` konfiguriert):

- TYPO3-Cache-Frontend (z. B. `VariableFrontend`)
- Beliebiges TYPO3-Cache-Backend (`KeyValueBackend` für Redis,
  `Typo3DatabaseBackend`, `MemcachedBackend`, …)
- Hält: serialisierte `CacheMetadata`-Arrays + Tag-Indices
- Cluster-Charakteristik (Latenz, Replication, etc.) ist Sache des Backends

**Pod-lokal**:

- Ephemeres `emptyDir` unter `{localPath}/{shard2}/{hash}`
- Hält: komprimierte Payload-Bytes
- Niemals Source of Truth
- Verlust jederzeit akzeptiert (Blob-Miss-Pfad)

## Verweise

- [Spec](../specs/001-cluster-cache-backend/spec.md)
- [Plan](../specs/001-cluster-cache-backend/plan.md)
- [Research](../specs/001-cluster-cache-backend/research.md) (Technologie-Entscheidungen)
- [Data Model](../specs/001-cluster-cache-backend/data-model.md)
- [Contracts](../specs/001-cluster-cache-backend/contracts/)
- [Constitution](../.specify/memory/constitution.md)
