<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend;

use Moselwal\Typo3ClusterCache\Application\GarbageCollect\RunGarbageCollection;
use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\Invalidate\FlushByTag;
use Moselwal\Typo3ClusterCache\Application\Invalidate\FlushNamespace;
use Moselwal\Typo3ClusterCache\Application\Invalidate\RemoveCacheEntry;
use Moselwal\Typo3ClusterCache\Application\Read\ReadCacheEntry;
use Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry;
use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\CompressorPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionAlgo;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Typo3MetadataCache;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\GzipCompressor;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\NullCompressor;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\ZstdCompressor;
use Moselwal\Typo3ClusterCache\Infrastructure\LocalStore\EmptyDirPayloadStore;
use Moselwal\Typo3ClusterCache\Infrastructure\Observability\PrometheusMetrics;
use Moselwal\Typo3ClusterCache\Infrastructure\Time\SystemClock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Backend\AbstractBackend;
use TYPO3\CMS\Core\Cache\Backend\PhpCapableBackendInterface;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception;
use TYPO3\CMS\Core\Cache\Exception\InvalidCacheException;
use TYPO3\CMS\Core\Cache\Exception\InvalidDataException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Cluster-aware TYPO3 cache backend without a shared filesystem.
 *
 * Drop-in replacement for {@see \TYPO3\CMS\Core\Cache\Backend\FileBackend} and
 * {@see \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend}, including their
 * PhpFrontend-capable use as code caches (`typoscript`, `fluid_template`).
 *
 * Architecture:
 * - **Source of truth**: a separate TYPO3 cache frontend, referenced via the
 *   `metadataCacheIdentifier` option. The consumer is free to pick the
 *   backend — e.g. {@see \Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend}
 *   for Redis/Valkey, {@see \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend}
 *   for DB clusters.
 * - **Payload store**: pod-local via {@see EmptyDirPayloadStore}.
 *
 * This package does NOT know anything about Redis/Valkey directly — all
 * cluster semantics are routed through the TYPO3 cache API.
 */
final class ClusterFileBackend extends AbstractBackend implements TaggableBackendInterface, PhpCapableBackendInterface
{
    /**
     * Lifecycle note: the cache name is unknown at construction time —
     * TYPO3's CacheManager calls `setCache()` afterwards. Since the cache
     * name participates in metadata-key namespacing, we cannot mark the
     * namespace-bound services (`metadataCache`, `writer`, `reader`,
     * `remover`, `namespaceFlusher`, `tagFlusher`, `gcRunner`) as
     * `readonly`. They are wired twice: once with a placeholder namespace
     * in the constructor (so the backend is usable before TYPO3 binds it
     * to a cache, e.g. in tests) and once with the real cache name in
     * `setCache()`. `$localStore`, `$compressionName`, `$isPhpCache` are
     * for the same reason mutable — `setCache()` flips PhpFrontend mode on
     * if necessary.
     */
    private CacheNamespace $namespace;
    private readonly EnvironmentName $environment;
    private readonly string $instance;
    private readonly string $localPath;
    private readonly string $metadataCacheIdentifier;
    private readonly int $cfbDefaultLifetime;
    private readonly int $maxPayloadBytes;
    private readonly int $minCompressedBytes;
    private readonly FrontendInterface $metadataCacheFrontend;
    private EmptyDirPayloadStore $localStore;
    /** @var array<string, CompressorPort> Lookup CompressionAlgo->value → codec */
    private readonly array $compressorsByAlgo;
    private readonly ClockPort $clock;
    private readonly SerializerName $serializer;
    private CompressionName $compressionName;
    private readonly BackendVersion $backendVersion;
    private readonly MetricsPort $metrics;
    private bool $isPhpCache = false;
    private MetadataCachePort $metadataCache;
    private WriteCacheEntry $writer;
    private ReadCacheEntry $reader;
    private RemoveCacheEntry $remover;
    private FlushNamespace $namespaceFlusher;
    private FlushByTag $tagFlusher;
    private RunGarbageCollection $gcRunner;
    private readonly LoggerInterface $cfbLogger;
    private bool $collectGarbageDelegated = false;

    /**
     * Request-scoped L1 memoization for `MetadataCachePort::get()` results.
     * In FrankenPHP worker mode TYPO3 re-bootstraps per request (see
     * `Moselwal\FrankenPHP\Runtime\FrontendWorker::handleRequest()`), so a
     * fresh CacheManager and a fresh backend instance is created on every
     * request — this map is automatically request-scoped, no shutdown hook
     * needed. Map: cache-identifier value → CacheMetadata|null|false where
     * `false` is the explicit "not-yet-looked-up" sentinel and `null` means
     * "looked up, was a miss".
     *
     * @var array<string, CacheMetadata|false|null>
     */
    private array $metadataL1 = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        // Intentionally empty parent options array: AbstractBackend::__construct
        // maps every option key to `set<UcfirstKey>(...)` and otherwise throws
        // \InvalidArgumentException. Our options are nested (`namespace` is an
        // array) and do not fit the TYPO3 setter pattern. We validate ourselves
        // via JSON schema instead but keep the TYPO3 logger init and all other
        // parent initialisations.
        parent::__construct([]);

        $validator = new OptionsValidator();
        $normalized = $validator->validateAndApplyDefaults($options);

        $this->environment = EnvironmentName::from((string) $normalized['namespace']['environment']);
        $this->instance = (string) $normalized['namespace']['instance'];
        $this->localPath = (string) $normalized['localPath'];
        $this->metadataCacheIdentifier = (string) $normalized['metadataCacheIdentifier'];
        $this->cfbDefaultLifetime = (int) $normalized['defaultLifetimeSeconds'];
        $this->maxPayloadBytes = (int) $normalized['maxPayloadBytes'];
        $this->minCompressedBytes = (int) $normalized['minCompressedBytes'];
        $this->cfbLogger = $this->logger ?? new NullLogger();

        // Bootstrap-safe port resolution: the `assets` cache (and any cache
        // configured for early-bootstrap use) is instantiated by
        // Bootstrap::createCache() while only the FailsafeContainer is up
        // — that container does not know about extension-provided DI
        // mappings, so GeneralUtility::makeInstance() on a port interface
        // would fall through to `new $interface()` and throw
        // "Cannot instantiate interface". Resolve through the container if
        // available, otherwise fall back to the default implementation that
        // Services.yaml maps the port to anyway.
        $this->metrics = $this->resolvePortOrDefault(MetricsPort::class, static fn(): MetricsPort => new PrometheusMetrics());
        $this->clock = $this->resolvePortOrDefault(ClockPort::class, static fn(): ClockPort => new SystemClock());

        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $this->metadataCacheFrontend = $this->resolveMetadataFrontend($cacheManager, $this->metadataCacheIdentifier);

        // Initialize $namespace BEFORE resolveCompressor() — the zstd
        // fallback path emits a structured-log line with `cacheName` from
        // $this->namespace, which would otherwise blow up with "Typed
        // property must not be accessed before initialization" on systems
        // without ext-zstd.
        $this->namespace = new CacheNamespace($this->environment, $this->instance, 'unbound');

        $this->serializer = $this->resolveSerializer((string) $normalized['serializer']);
        $primary = $this->resolveCompressor((string) $normalized['compression']);
        $this->compressionName = CompressionName::fromString($primary->name());
        // Register every codec so the reader can decompress whatever marker
        // it finds on disk, even after a compression-option change on a
        // running namespace (e.g. operator switches `zstd` → `gzip`; both
        // codecs must still be able to read pre-switch payloads until they
        // are GC'd). The skip-compress path always needs NullCompressor.
        $byAlgo = [
            CompressionAlgo::None->value => $primary instanceof NullCompressor ? $primary : new NullCompressor(),
        ];
        $byAlgo[CompressionAlgo::Gzip->value] = $primary instanceof GzipCompressor ? $primary : new GzipCompressor();
        $byAlgo[CompressionAlgo::Zstd->value] = $primary instanceof ZstdCompressor ? $primary : new ZstdCompressor();
        $this->compressorsByAlgo = $byAlgo;
        $this->backendVersion = BackendVersion::fromEnv((string) $normalized['backendVersionEnvVar']);

        $this->localStore = new EmptyDirPayloadStore($this->localPath);
        $this->wireNamespaceBoundServices();
    }

    /**
     * (Re-)builds every service whose behaviour depends on the current
     * `$this->namespace` or `$this->localStore`. Called from the constructor
     * (with placeholder namespace) and from `setCache()` (with the real
     * cache name and, for PhpFrontend caches, a `.php`-suffixed local
     * store).
     */
    private function wireNamespaceBoundServices(): void
    {
        $this->metadataCache = new Typo3MetadataCache(
            $this->metadataCacheFrontend,
            $this->namespace,
        );
        $this->writer = new WriteCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $this->localStore,
            compressorsByAlgo: $this->compressorsByAlgo,
            clock: $this->clock,
            metrics: $this->metrics,
            hasher: new ComputePayloadHash(),
            serializer: $this->serializer,
            compression: $this->compressionName,
            backendVersion: $this->backendVersion,
            minCompressedBytes: $this->minCompressedBytes,
        );
        $this->reader = new ReadCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $this->localStore,
            compressorsByAlgo: $this->compressorsByAlgo,
            clock: $this->clock,
            metrics: $this->metrics,
            maxDecompressedBytes: $this->maxPayloadBytes,
        );
        $this->remover = new RemoveCacheEntry($this->metadataCache);
        $this->namespaceFlusher = new FlushNamespace($this->metadataCache, $this->metrics);
        $this->tagFlusher = new FlushByTag($this->metadataCache, $this->metrics);
        $this->gcRunner = new RunGarbageCollection($this->metadataCache, $this->clock);
    }

    /**
     * The TYPO3 cache frontend identifier configured via `metadataCacheIdentifier`.
     * Consumed by deployment-time helpers (warm-up runner, health checks).
     */
    public function getMetadataCacheIdentifier(): string
    {
        return $this->metadataCacheIdentifier;
    }

    /**
     * Absolute path on the local (ephemeral) filesystem where this backend
     * materialises payloads. Consumed by deployment-time helpers.
     */
    public function getLocalPath(): string
    {
        return $this->localPath;
    }

    public function getNamespaceEnvironment(): EnvironmentName
    {
        return $this->environment;
    }

    public function getNamespaceInstance(): string
    {
        return $this->instance;
    }

    public function setCache(FrontendInterface $cache): void
    {
        parent::setCache($cache);
        $this->namespace = new CacheNamespace(
            $this->environment,
            $this->instance,
            $cache->getIdentifier(),
        );

        // PhpFrontend caches eval their payload as PHP code via require_once.
        // That demands two configuration changes for the lifetime of this
        // backend instance: compression off (otherwise the file is not a
        // valid PHP source), and a `.php` filename suffix (otherwise PHP's
        // OPcache does not ingest the file). We force both here — the
        // user-configured `compression` option is ignored for PhpFrontend
        // caches, by design.
        $isPhp = $cache instanceof PhpFrontend;
        if ($isPhp !== $this->isPhpCache) {
            $this->isPhpCache = $isPhp;
            if ($isPhp) {
                $this->localStore = new EmptyDirPayloadStore($this->localPath, '.php');
                $this->compressionName = CompressionName::none();
            } else {
                $this->localStore = new EmptyDirPayloadStore($this->localPath);
                // Compression returns to whatever was configured by options.
                // We do not stash that name on the instance because the
                // current arrangement is "configured-once-per-cache" — a
                // backend would never flip back from PhpFrontend to
                // VariableFrontend in practice.
                $this->compressionName = CompressionName::fromString($this->primaryCodec()->name());
            }
        }

        // Rebuild the namespace-bound services so that the metadata cache
        // (and everything that depends on it) writes tags namespaced to
        // this specific cache name. Without this re-wire, all
        // ClusterFileBackend instances would share the same tag space in
        // the underlying metadata-cache frontend.
        $this->wireNamespaceBoundServices();
        $this->metadataL1 = [];
    }

    /**
     * @param array<mixed> $tags
     */
    public function set(string $entryIdentifier, string $data, array $tags = [], ?int $lifetime = null): void
    {
        if (\strlen($data) > $this->maxPayloadBytes) {
            throw new InvalidDataException(\sprintf('Payload size %d exceeds configured maxPayloadBytes %d', \strlen($data), $this->maxPayloadBytes), 1747500021);
        }
        $identifier = new CacheIdentifier($entryIdentifier);
        $tagSet = new TagSet(array_values(array_map('strval', $tags)));
        // TYPO3 BackendInterface convention:
        //   $lifetime === null → use the backend's default lifetime
        //   $lifetime === 0    → cache forever (unlimited)
        //   $lifetime  >  0    → expire after N seconds
        //   $lifetime  <  0    → invalid; fall back to default
        $lifetimeSeconds = match (true) {
            null === $lifetime, $lifetime < 0 => $this->cfbDefaultLifetime,
            default => $lifetime,
        };
        try {
            $this->writer->execute($this->namespace, $identifier, $data, $tagSet, $lifetimeSeconds);
            // Invalidate L1 — the next has()/get() should fetch the fresh
            // metadata. We do not eagerly populate L1 with the new entry
            // because the metadata write goes through the configured cache
            // frontend's serialisation layer, and reconstructing the exact
            // post-roundtrip metadata payload here would duplicate logic.
            unset($this->metadataL1[$identifier->value]);
        } catch (\Throwable $e) {
            $this->cfbLogger->error('ClusterFileBackend::set failed', [
                'cacheName' => $this->namespace->cacheName,
                'identifierDigest' => $this->hashIdentifierForLog($identifier),
                'exceptionClass' => $e::class,
                'exceptionMessage' => $e->getMessage(),
            ]);

            // Do not leak the wrapped message — full details are in the
            // structured log above. TYPO3 may surface this exception in
            // debug error pages.
            throw new Exception('ClusterFileBackend write failed; see log for details', 1747500022, $e);
        }
    }

    public function get(string $entryIdentifier): mixed
    {
        $identifier = new CacheIdentifier($entryIdentifier);
        try {
            $result = $this->reader->execute($this->namespace, $identifier);
        } catch (\Throwable $e) {
            $this->cfbLogger->error('ClusterFileBackend::get failed', [
                'cacheName' => $this->namespace->cacheName,
                'identifierDigest' => $this->hashIdentifierForLog($identifier),
                'exceptionClass' => $e::class,
                'exceptionMessage' => $e->getMessage(),
            ]);

            return false;
        }

        return $result ?? false;
    }

    public function has(string $entryIdentifier): bool
    {
        $identifier = new CacheIdentifier($entryIdentifier);
        $metadata = $this->loadMetadata($identifier);
        if (null === $metadata) {
            return false;
        }

        return $metadata->state->isValid()
            && !$metadata->lifetime->isExpired($this->clock->now());
    }

    public function remove(string $entryIdentifier): bool
    {
        $identifier = new CacheIdentifier($entryIdentifier);
        try {
            $result = $this->remover->execute($identifier);
            unset($this->metadataL1[$identifier->value]);

            return $result;
        } catch (\Throwable $e) {
            $this->cfbLogger->error('ClusterFileBackend::remove failed', [
                'cacheName' => $this->namespace->cacheName,
                'identifierDigest' => $this->hashIdentifierForLog($identifier),
                'exceptionClass' => $e::class,
                'exceptionMessage' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function flush(): void
    {
        $this->namespaceFlusher->execute($this->namespace);
        $this->metadataL1 = [];
    }

    public function flushByTag(string $tag): void
    {
        $this->tagFlusher->execute($this->namespace, $tag);
        // The tag-to-identifier mapping lives in the metadata cache, not in
        // L1. Cheapest correct option is to drop the whole L1 — the next
        // request will refill it lazily. Selective invalidation would mean
        // walking every L1 entry's tags, which negates the speedup.
        $this->metadataL1 = [];
    }

    public function flushByTags(array $tags): void
    {
        $this->tagFlusher->executeMany($this->namespace, array_values(array_map('strval', $tags)));
        $this->metadataL1 = [];
    }

    /**
     * @return list<string>
     */
    public function findIdentifiersByTag(string $tag): array
    {
        return $this->metadataCache->findIdentifiersByTag($tag);
    }

    public function collectGarbage(): void
    {
        if ($this->collectGarbageDelegated) {
            return;
        }
        $this->collectGarbageDelegated = true;
        $this->gcRunner->execute($this->namespace);
    }

    /**
     * Eval-or-load a PhpFrontend cache entry. Sources the payload from the
     * pod-local store after a metadata lookup, so the cluster-coherent
     * invalidation contract is preserved — the metadata read sees flushes
     * from other pods. If the local file is missing, return false so the
     * TYPO3 frontend triggers a caller rebuild via `set()`.
     *
     * `require_once` is used by deliberate design over `eval`: PHP's
     * OPcache caches require-included files automatically (compilation
     * once per pod for the lifetime of the file), whereas `eval`'d code is
     * recompiled on every call.
     */
    public function requireOnce(string $entryIdentifier): mixed
    {
        return $this->doRequire($entryIdentifier, true);
    }

    public function require(string $entryIdentifier): mixed
    {
        return $this->doRequire($entryIdentifier, false);
    }

    private function doRequire(string $entryIdentifier, bool $once): mixed
    {
        $identifier = new CacheIdentifier($entryIdentifier);
        $metadata = $this->loadMetadata($identifier);
        if (null === $metadata) {
            return false;
        }
        if (!$metadata->state->isValid() || $metadata->lifetime->isExpired($this->clock->now())) {
            return false;
        }
        $path = $this->localStore->pathFor($metadata->hash);
        if (!is_file($path)) {
            // Blob-miss: file is not pod-locally materialised yet. The
            // TYPO3 PhpFrontend contract returns false to signal "rebuild
            // me"; the caller will then `set()` the payload, which writes
            // the local file on this pod. Subsequent requireOnce() finds
            // the file and OPcache caches it.
            return false;
        }

        return $once ? require_once $path : require $path;
    }

    /**
     * Memoised wrapper around `MetadataCachePort::get()`. Hits are returned
     * from RAM (~0.5 µs); misses round-trip to the metadata cache once per
     * identifier per request. Negative results (no-entry) are cached too,
     * to avoid repeating the round-trip on `has('foo'); has('foo'); …` in
     * the same request — which `Has` patterns in TypoScript template
     * resolution and Fluid rendering commonly do.
     */
    private function loadMetadata(CacheIdentifier $identifier): ?CacheMetadata
    {
        $key = $identifier->value;
        if (\array_key_exists($key, $this->metadataL1)) {
            $cached = $this->metadataL1[$key];

            return false === $cached ? null : $cached;
        }
        try {
            $metadata = $this->metadataCache->get($identifier);
        } catch (\Throwable $e) {
            $this->cfbLogger->error('ClusterFileBackend::loadMetadata failed', [
                'cacheName' => $this->namespace->cacheName,
                'identifierDigest' => $this->hashIdentifierForLog($identifier),
                'exceptionClass' => $e::class,
                'exceptionMessage' => $e->getMessage(),
            ]);

            // Do not poison L1 with the error — on transient metadata
            // backend failure (Redis blip) the next call should re-try.
            return null;
        }
        $this->metadataL1[$key] = $metadata ?? false;

        return $metadata;
    }

    /**
     * Hashes the cache identifier before logging. Cache identifiers in
     * TYPO3 routinely contain session-id / user-hash fragments
     * (e.g. `userhash_%session_id%`); emitting them as plaintext into
     * PSR-3 logs that ship to Loki/Splunk is an unnecessary disclosure.
     * The 16-hex-char prefix gives operators enough entropy for cache-key
     * correlation across pods without leaking the underlying value.
     */
    private function hashIdentifierForLog(CacheIdentifier $identifier): string
    {
        return substr(hash('sha256', $identifier->value), 0, 16);
    }

    /**
     * Resolve a port via the TYPO3/Symfony container if possible, otherwise
     * fall back to the supplied default. Called from the constructor at a
     * time when the Symfony container may not be available yet (Bootstrap
     * runs ServiceProvider::getAssetsCache() under the FailsafeContainer
     * before extension-provided DI mappings exist) — `GeneralUtility::
     * makeInstance($interface)` would then throw a fatal "Cannot
     * instantiate interface". This helper degrades gracefully to the
     * concrete default that Services.yaml aliases the port to anyway.
     *
     * @template T of object
     *
     * @param class-string<T> $interface
     * @param callable():T    $default
     *
     * @return T
     */
    private function resolvePortOrDefault(string $interface, callable $default): object
    {
        try {
            /** @var T $instance */
            $instance = GeneralUtility::makeInstance($interface);

            return $instance;
        } catch (\Throwable) {
            return $default();
        }
    }

    private function resolveMetadataFrontend(CacheManager $cacheManager, string $identifier): FrontendInterface
    {
        try {
            return $cacheManager->getCache($identifier);
        } catch (\Throwable $e) {
            throw new InvalidCacheException(\sprintf('ClusterFileBackend: configured metadataCacheIdentifier "%s" is not a registered TYPO3 cache. Register it under $GLOBALS["TYPO3_CONF_VARS"]["SYS"]["caching"]["cacheConfigurations"]["%s"].', $identifier, $identifier), 1747500050, $e);
        }
    }

    private function resolveSerializer(string $name): SerializerName
    {
        if (SerializerName::IGBINARY === $name && \extension_loaded('igbinary')) {
            return SerializerName::igbinary();
        }
        if (SerializerName::IGBINARY === $name) {
            $this->cfbLogger->warning(
                'ext-igbinary not loaded — falling back to native PHP serializer',
                ['cacheName' => $this->namespace->cacheName],
            );

            return SerializerName::phpNative();
        }

        return SerializerName::phpNative();
    }

    private function resolveCompressor(string $name): CompressorPort
    {
        return match ($name) {
            'zstd' => $this->buildZstdOrFallback(),
            'gzip' => new GzipCompressor(),
            'none' => new NullCompressor(),
            default => throw new InvalidCacheException(\sprintf('Unknown compression "%s"', $name), 1747500040),
        };
    }

    private function buildZstdOrFallback(): CompressorPort
    {
        $zstd = new ZstdCompressor();
        if ($zstd->isAvailable()) {
            return $zstd;
        }
        $this->cfbLogger->warning(
            'ext-zstd not loaded — falling back to gzip compression',
            ['cacheName' => $this->namespace->cacheName],
        );

        return new GzipCompressor();
    }

    /**
     * Returns the configured "primary" codec used for VariableFrontend
     * caches. Used by {@see setCache()} when the backend leaves PhpFrontend
     * mode again (a theoretical edge case — see the comment there).
     */
    private function primaryCodec(): CompressorPort
    {
        return $this->compressorsByAlgo[CompressionAlgo::Zstd->value]->isAvailable()
            ? $this->compressorsByAlgo[CompressionAlgo::Zstd->value]
            : $this->compressorsByAlgo[CompressionAlgo::Gzip->value];
    }
}
