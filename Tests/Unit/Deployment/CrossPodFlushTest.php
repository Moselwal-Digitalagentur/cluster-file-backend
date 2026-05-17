<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Deployment;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\Invalidate\FlushByTag;
use Moselwal\Typo3ClusterCache\Application\Invalidate\FlushNamespace;
use Moselwal\Typo3ClusterCache\Application\Read\ReadCacheEntry;
use Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\NullCompressor;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use Moselwal\Typo3ClusterCache\Tests\Support\InMemoryLocalPayloadStore;
use PHPUnit\Framework\TestCase;

/**
 * Cluster-Konsistenz-Tests: verifiziert, dass Invalidierungen (flush /
 * flushByTag), die auf einem Pod ausgelöst werden, von einem anderen Pod
 * SOFORT beim nächsten Read gesehen werden — ohne irgendeine Synchronisation
 * zwischen den Pods.
 *
 * Die Pods werden hier durch zwei `ClusterFileBackend`-Instanzen-Stellvertreter
 * simuliert, die sich denselben `FakeMetadataCache` teilen (= zentrale
 * Wahrheitsquelle), aber JEDER seine EIGENE pod-lokale Datei-Sicht hat. Genau
 * dieses Setup spiegelt die Produktions-Topologie: gemeinsamer Metadata-
 * Cache-Frontend (Redis/DB/Memcached), getrennte `emptyDir`-Volumes.
 *
 * Wenn diese Tests jemals fehlschlagen, ist das Cluster-Versprechen
 * (zentrale Cache-Gültigkeit) gebrochen.
 */
final class CrossPodFlushTest extends TestCase
{
    private CacheNamespace $namespace;
    private FakeMetadataCache $sharedMetadata;   // ← zentrale Wahrheit (geteilt)
    private InMemoryLocalPayloadStore $podALocal; // ← Pod A: eigene Datei-Sicht
    private InMemoryLocalPayloadStore $podBLocal; // ← Pod B: eigene Datei-Sicht
    private FakeClock $clock;
    private FakeMetrics $metrics;
    private WriteCacheEntry $podAWriter;
    private WriteCacheEntry $podBWriter;
    private ReadCacheEntry $podAReader;
    private ReadCacheEntry $podBReader;
    private FlushNamespace $flusher;
    private FlushByTag $tagFlusher;

    protected function setUp(): void
    {
        $this->namespace = new CacheNamespace(EnvironmentName::Testing, 'cluster', 'pages');
        $this->sharedMetadata = new FakeMetadataCache();
        $this->podALocal = new InMemoryLocalPayloadStore();
        $this->podBLocal = new InMemoryLocalPayloadStore();
        $this->clock = new FakeClock(1_700_000_000);
        $this->metrics = new FakeMetrics();

        $compressor = new NullCompressor();
        $hasher = new ComputePayloadHash();
        $serializer = SerializerName::phpNative();
        $compression = CompressionName::none();
        $backendVersion = new BackendVersion(1);

        $this->podAWriter = new WriteCacheEntry(
            metadataCache: $this->sharedMetadata,
            localStore: $this->podALocal,
            compressor: $compressor,
            clock: $this->clock,
            metrics: $this->metrics,
            hasher: $hasher,
            serializer: $serializer,
            compression: $compression,
            backendVersion: $backendVersion,
        );
        $this->podBWriter = new WriteCacheEntry(
            metadataCache: $this->sharedMetadata,
            localStore: $this->podBLocal,
            compressor: $compressor,
            clock: $this->clock,
            metrics: $this->metrics,
            hasher: $hasher,
            serializer: $serializer,
            compression: $compression,
            backendVersion: $backendVersion,
        );
        $this->podAReader = new ReadCacheEntry(
            metadataCache: $this->sharedMetadata,
            localStore: $this->podALocal,
            compressor: $compressor,
            clock: $this->clock,
            metrics: $this->metrics,
        );
        $this->podBReader = new ReadCacheEntry(
            metadataCache: $this->sharedMetadata,
            localStore: $this->podBLocal,
            compressor: $compressor,
            clock: $this->clock,
            metrics: $this->metrics,
        );
        $this->flusher = new FlushNamespace($this->sharedMetadata, $this->metrics);
        $this->tagFlusher = new FlushByTag($this->sharedMetadata, $this->metrics);
    }

    /**
     * Szenario: Editor klickt im TYPO3-Backend „Clear all caches" → Pod A
     * führt `flush()` aus. Pod B (frisches Request) MUSS sofort Cache-Miss
     * sehen — ohne irgendeinen Sync-Mechanismus zwischen den Pods.
     */
    public function testFlushOnPodAIsImmediatelyVisibleOnPodB(): void
    {
        $id = new CacheIdentifier('page_42');

        // Pod A schreibt, danach lesen beide Pods erfolgreich (Pod B repariert
        // via Caller-Rebuild, wir simulieren das hier durch direkten Write
        // auf Pod B mit identischem Payload).
        $this->podAWriter->execute($this->namespace, $id, 'content_v1', new TagSet(), 3600);
        $this->podBWriter->execute($this->namespace, $id, 'content_v1', new TagSet(), 3600);
        self::assertSame('content_v1', $this->podAReader->execute($this->namespace, $id));
        self::assertSame('content_v1', $this->podBReader->execute($this->namespace, $id));

        // Pod A führt clear-cache durch.
        $this->flusher->execute($this->namespace);

        // Pod B liest sofort danach → MUSS Cache-Miss sehen.
        // Kein Sync-Schritt zwischen den Pods notwendig.
        self::assertNull(
            $this->podBReader->execute($this->namespace, $id),
            'Pod B must see the flush from Pod A immediately on next get()',
        );
        self::assertNull(
            $this->podAReader->execute($this->namespace, $id),
            'Pod A must see its own flush, of course',
        );
    }

    /**
     * Szenario: Editor speichert Seite 42 → TYPO3 ruft `flushByTag('pageId_42')`
     * auf Pod A. Andere Tags bleiben gültig. Pod B sieht sofort:
     * page_42 → miss, page_7 → hit.
     */
    public function testFlushByTagOnPodAInvalidatesOnlyMatchingEntriesOnPodB(): void
    {
        $page42 = new CacheIdentifier('page_42');
        $page7 = new CacheIdentifier('page_7');

        // Pod A schreibt zwei Einträge mit unterschiedlichen Tags
        $this->podAWriter->execute($this->namespace, $page42, 'content_42', new TagSet(['pageId_42']), 3600);
        $this->podAWriter->execute($this->namespace, $page7, 'content_7', new TagSet(['pageId_7']), 3600);
        // Pod B repariert (deterministisch identische Bytes)
        $this->podBWriter->execute($this->namespace, $page42, 'content_42', new TagSet(['pageId_42']), 3600);
        $this->podBWriter->execute($this->namespace, $page7, 'content_7', new TagSet(['pageId_7']), 3600);

        // Pod A invalidiert nur den Tag pageId_42
        $this->tagFlusher->execute($this->namespace, 'pageId_42');

        // Pod B sieht: page_42 → miss, page_7 → hit
        self::assertNull(
            $this->podBReader->execute($this->namespace, $page42),
            'Pod B must see the tag flush from Pod A',
        );
        self::assertSame(
            'content_7',
            $this->podBReader->execute($this->namespace, $page7),
            'Untagged entry must remain valid on Pod B',
        );
    }

    /**
     * Hard-Invariant-Check: Pod B's lokale Cache-Datei überlebt einen Flush
     * — wird aber nicht ausgeliefert, weil die Metadata weg ist. Wenn Pod B
     * danach den gleichen Content neu schreibt, materialisiert er denselben
     * Filename (Hash-Determinismus) — die alte Datei kann harmlos überschrieben
     * werden oder bleibt als no-op-Identität.
     */
    public function testLocalFileSurvivesFlushButIsUnreachableWithoutMetadata(): void
    {
        $id = new CacheIdentifier('page_42');
        $this->podAWriter->execute($this->namespace, $id, 'content', new TagSet(), 3600);
        $this->podBWriter->execute($this->namespace, $id, 'content', new TagSet(), 3600);

        // Pod B's lokale Datei existiert nach dem Write
        $hashesBefore = [];
        foreach ($this->podBLocal->iterateAll() as $payloadHash) {
            $hashesBefore[] = $payloadHash->digest;
        }
        self::assertCount(1, $hashesBefore, 'Pod B materialized exactly one file');

        // Pod A flushed
        $this->flusher->execute($this->namespace);

        // Pod B's Datei ist NOCH DA — wird aber nicht ausgeliefert
        $hashesAfter = [];
        foreach ($this->podBLocal->iterateAll() as $hashAfter) {
            $hashesAfter[] = $hashAfter->digest;
        }
        self::assertSame($hashesBefore, $hashesAfter, 'Local file survives flush (orphan)');
        self::assertNull(
            $this->podBReader->execute($this->namespace, $id),
            'But it is unreachable: Metadata is gone, so the file is not auslieferbar',
        );
    }

    /**
     * Stellt sicher, dass nach einem Flush ein **neuer** Write auf Pod B
     * funktioniert (identischer Content → identischer Hash → idempotente
     * Materialisierung; oder neuer Content → neuer Hash → neue Datei).
     */
    public function testWriteAfterFlushReestablishesConsistency(): void
    {
        $id = new CacheIdentifier('page_42');

        $this->podAWriter->execute($this->namespace, $id, 'v1', new TagSet(), 3600);
        $this->flusher->execute($this->namespace);

        // Nach dem Flush: Pod B schreibt v2 (Content geändert) — Hash anders.
        $this->podBWriter->execute($this->namespace, $id, 'v2', new TagSet(), 3600);

        // Pod A sieht beim nächsten Read → Metadata aus dem Cache (von Pod B),
        // aber lokale Datei mit dem neuen Hash fehlt auf Pod A → Blob-Miss.
        self::assertNull(
            $this->podAReader->execute($this->namespace, $id),
            'Pod A blob-misses because Pod B wrote a different content (different hash)',
        );
        self::assertSame(1, $this->metrics->counterTotal('blob_miss_total'));

        // Pod A repariert via Caller-Rebuild (= eigener Write desselben v2)
        $this->podAWriter->execute($this->namespace, $id, 'v2', new TagSet(), 3600);
        self::assertSame('v2', $this->podAReader->execute($this->namespace, $id));
    }

    /**
     * Globale `flush()`-Operation: Pod A räumt zentralen Cache → Pod B,
     * Pod C, Pod N sehen alle Cache-Miss, unabhängig wie viele Pods im
     * Cluster sind. Wir simulieren das mit 5 unabhängigen lokalen Stores
     * gegen einen geteilten Metadata-Cache.
     */
    public function testFlushPropagatesToArbitraryNumberOfPods(): void
    {
        $id = new CacheIdentifier('page_42');

        // 5 Pods schreiben unabhängig denselben deterministischen Inhalt
        $localStores = [
            $this->podALocal,
            $this->podBLocal,
            new InMemoryLocalPayloadStore(),
            new InMemoryLocalPayloadStore(),
            new InMemoryLocalPayloadStore(),
        ];
        $writers = \array_map(
            fn(InMemoryLocalPayloadStore $store): WriteCacheEntry => new WriteCacheEntry(
                metadataCache: $this->sharedMetadata,
                localStore: $store,
                compressor: new NullCompressor(),
                clock: $this->clock,
                metrics: $this->metrics,
                hasher: new ComputePayloadHash(),
                serializer: SerializerName::phpNative(),
                compression: CompressionName::none(),
                backendVersion: new BackendVersion(1),
            ),
            $localStores,
        );
        $readers = \array_map(
            fn(InMemoryLocalPayloadStore $store): ReadCacheEntry => new ReadCacheEntry(
                metadataCache: $this->sharedMetadata,
                localStore: $store,
                compressor: new NullCompressor(),
                clock: $this->clock,
                metrics: $this->metrics,
            ),
            $localStores,
        );

        foreach ($writers as $writer) {
            $writer->execute($this->namespace, $id, 'shared', new TagSet(), 3600);
        }
        foreach ($readers as $i => $reader) {
            self::assertSame('shared', $reader->execute($this->namespace, $id), "Pod {$i} initial hit");
        }

        // Ein beliebiger Pod (hier: Pod 0) flushed
        $this->flusher->execute($this->namespace);

        // Alle 5 Pods sehen sofort Miss
        foreach ($readers as $i => $reader) {
            self::assertNull(
                $reader->execute($this->namespace, $id),
                "Pod {$i} must see the flush propagated through shared metadata cache",
            );
        }
    }
}
