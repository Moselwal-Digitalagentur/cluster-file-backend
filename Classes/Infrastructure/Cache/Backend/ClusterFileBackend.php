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
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Typo3MetadataCache;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\GzipCompressor;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\NullCompressor;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\ZstdCompressor;
use Moselwal\Typo3ClusterCache\Infrastructure\LocalStore\EmptyDirPayloadStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Backend\AbstractBackend;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception;
use TYPO3\CMS\Core\Cache\Exception\InvalidCacheException;
use TYPO3\CMS\Core\Cache\Exception\InvalidDataException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Cluster-aware TYPO3 cache backend without a shared filesystem.
 *
 * Drop-in replacement for {@see \TYPO3\CMS\Core\Cache\Backend\FileBackend} and
 * {@see \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend}.
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
final class ClusterFileBackend extends AbstractBackend implements TaggableBackendInterface
{
    private CacheNamespace $namespace;
    private readonly EnvironmentName $environment;
    private readonly string $instance;
    private readonly string $localPath;
    private readonly string $metadataCacheIdentifier;
    private readonly int $cfbDefaultLifetime;
    private readonly int $maxPayloadBytes;
    private readonly MetadataCachePort $metadataCache;
    private readonly EmptyDirPayloadStore $localStore;
    private readonly CompressorPort $compressor;
    private readonly ClockPort $clock;
    private readonly SerializerName $serializer;
    private readonly CompressionName $compressionName;
    private readonly WriteCacheEntry $writer;
    private readonly ReadCacheEntry $reader;
    private readonly RemoveCacheEntry $remover;
    private readonly FlushNamespace $namespaceFlusher;
    private readonly FlushByTag $tagFlusher;
    private readonly RunGarbageCollection $gcRunner;
    private readonly LoggerInterface $cfbLogger;
    private bool $collectGarbageDelegated = false;

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
        $this->cfbLogger = $this->logger ?? new NullLogger();

        $metrics = GeneralUtility::makeInstance(MetricsPort::class);
        $this->clock = GeneralUtility::makeInstance(ClockPort::class);
        $clock = $this->clock;

        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $this->metadataCache = new Typo3MetadataCache(
            $this->resolveMetadataFrontend($cacheManager, $this->metadataCacheIdentifier),
        );

        $this->serializer = $this->resolveSerializer((string) $normalized['serializer']);
        $this->compressor = $this->resolveCompressor((string) $normalized['compression']);
        $this->compressionName = CompressionName::fromString($this->compressor->name());

        $this->localStore = new EmptyDirPayloadStore($this->localPath);
        $this->namespace = new CacheNamespace($this->environment, $this->instance, 'unbound');

        $this->writer = new WriteCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $this->localStore,
            compressor: $this->compressor,
            clock: $clock,
            metrics: $metrics,
            hasher: new ComputePayloadHash(),
            serializer: $this->serializer,
            compression: $this->compressionName,
            backendVersion: BackendVersion::fromEnv((string) $normalized['backendVersionEnvVar']),
        );
        $this->reader = new ReadCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $this->localStore,
            compressor: $this->compressor,
            clock: $clock,
            metrics: $metrics,
        );
        $this->remover = new RemoveCacheEntry($this->metadataCache);
        $this->namespaceFlusher = new FlushNamespace($this->metadataCache, $metrics);
        $this->tagFlusher = new FlushByTag($this->metadataCache, $metrics);
        $this->gcRunner = new RunGarbageCollection($this->metadataCache, $clock);
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
        } catch (\Throwable $e) {
            $this->cfbLogger->error('ClusterFileBackend::set failed', [
                'cacheName' => $this->namespace->cacheName,
                'identifier' => $identifier->value,
                'exception' => $e,
            ]);

            throw new Exception('ClusterFileBackend write failed: ' . $e->getMessage(), 1747500022, $e);
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
                'identifier' => $identifier->value,
                'exception' => $e,
            ]);

            return false;
        }

        return $result ?? false;
    }

    public function has(string $entryIdentifier): bool
    {
        $identifier = new CacheIdentifier($entryIdentifier);
        try {
            $metadata = $this->metadataCache->get($identifier);
        } catch (\Throwable $e) {
            $this->cfbLogger->error('ClusterFileBackend::has failed', [
                'cacheName' => $this->namespace->cacheName,
                'identifier' => $identifier->value,
                'exception' => $e,
            ]);

            return false;
        }
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
            return $this->remover->execute($identifier);
        } catch (\Throwable $e) {
            $this->cfbLogger->error('ClusterFileBackend::remove failed', [
                'cacheName' => $this->namespace->cacheName,
                'identifier' => $identifier->value,
                'exception' => $e,
            ]);

            return false;
        }
    }

    public function flush(): void
    {
        $this->namespaceFlusher->execute($this->namespace);
    }

    public function flushByTag(string $tag): void
    {
        $this->tagFlusher->execute($this->namespace, $tag);
    }

    public function flushByTags(array $tags): void
    {
        $this->tagFlusher->executeMany($this->namespace, array_values(array_map('strval', $tags)));
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
}
