<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Invariants;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
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
 * Verifiziert die 10 „Hard Invariants" aus spec.md gegen das tatsächliche
 * Verhalten der Application-Services. Jede Invariante hat einen eigenen Test;
 * die Identifier (`I1` … `I10`) entsprechen der Nummerierung in der Spec.
 *
 * Nicht jede Invariante ist sinnvoll im Paket testbar — I6 (keine shared
 * cache volumes), I7 (keine RWX-Filesystems), I8 (Core Cache deployed) und
 * I9 (FAL außerhalb) sind Architektur-/Konfigurations-Aussagen, deren
 * Einhaltung außerhalb dieses Pakets erzwungen wird. Diese werden hier mit
 * `markTestSkipped()` und einer dokumentierenden Begründung übergangen.
 */
final class HardInvariantsTest extends TestCase
{
    private CacheNamespace $namespace;
    private FakeMetadataCache $metadataCache;
    private InMemoryLocalPayloadStore $local;
    private FakeClock $clock;
    private FakeMetrics $metrics;
    private WriteCacheEntry $writer;
    private ReadCacheEntry $reader;

    protected function setUp(): void
    {
        $this->namespace = new CacheNamespace(EnvironmentName::Testing, 'invariants', 'pages');
        $this->metadataCache = new FakeMetadataCache();
        $this->local = new InMemoryLocalPayloadStore();
        $this->clock = new FakeClock(1_700_000_000);
        $this->metrics = new FakeMetrics();
        $compressor = new NullCompressor();
        $this->writer = new WriteCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $this->local,
            compressor: $compressor,
            clock: $this->clock,
            metrics: $this->metrics,
            hasher: new ComputePayloadHash(),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
        );
        $this->reader = new ReadCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $this->local,
            compressor: $compressor,
            clock: $this->clock,
            metrics: $this->metrics,
        );
    }

    /** I1: Metadata ist Source of Truth. */
    public function testI1MetadataIsSourceOfTruth(): void
    {
        $id = new CacheIdentifier('i1');
        $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);

        // Lokale Datei manuell löschen — Metadata bleibt unverändert
        foreach ($this->local->iterateAll() as $hash) {
            $this->local->delete($hash);
        }
        // Trotzdem ist die Metadata gültig (Source of Truth)
        self::assertNotNull($this->metadataCache->get($id));
    }

    /** I2: Lokale Dateien sind nur Materialisierung. */
    public function testI2LocalFilesAreMerelyMaterialization(): void
    {
        $id = new CacheIdentifier('i2');
        // Datei "von Hand" anlegen — ohne Metadata
        $hash = new \Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash(
            hash('sha256', 'rogue'),
        );
        $this->local->write($hash, 'rogue');

        // Read MUSS Cache-Miss liefern — Metadata fehlt
        self::assertNull($this->reader->execute($this->namespace, $id));
    }

    /** I3: Fehlende Datei ist KEIN Cache-Miss, sondern ein Blob-Miss. */
    public function testI3MissingFileIsBlobMissNotCacheMiss(): void
    {
        $id = new CacheIdentifier('i3');
        $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);

        foreach ($this->local->iterateAll() as $hash) {
            $this->local->delete($hash);
        }
        self::assertNull($this->reader->execute($this->namespace, $id));
        self::assertSame(
            1,
            $this->metrics->counterTotal('blob_miss_total'),
            'Blob-Miss-Metrik muss greifen, NICHT Cache-Miss'
        );
        self::assertSame(
            0,
            $this->metrics->counterTotal('cache_miss_total'),
            'Cache-Miss-Metrik darf bei fehlender Datei NICHT greifen'
        );
    }

    /** I4: Repair darf keine neue Identität erzeugen. */
    public function testI4RepairKeepsIdentity(): void
    {
        $id = new CacheIdentifier('i4');
        $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);
        $originalHash = $this->metadataCache->get($id)?->hash->digest;
        self::assertNotNull($originalHash);

        // Datei löschen, Caller schreibt dieselben Bytes erneut → Repair-Pfad
        foreach ($this->local->iterateAll() as $hash) {
            $this->local->delete($hash);
        }
        $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);

        $newHash = $this->metadataCache->get($id)?->hash->digest;
        self::assertSame($originalHash, $newHash, 'Repair muss identischen Hash beibehalten');
        self::assertSame(1, $this->metrics->counterTotal('repair_success_total'));
    }

    /** I5: Rebuild (TYPO3-Sinn) nur bei echtem Cache-Miss — getestet implizit über I3. */
    public function testI5RebuildOnlyOnRealCacheMiss(): void
    {
        $id = new CacheIdentifier('i5');
        $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);

        // Erster Read → Hit, kein Rebuild
        self::assertSame('bytes', $this->reader->execute($this->namespace, $id));
        self::assertSame(
            0,
            $this->metrics->counterTotal('payload_rebuild_total'),
            'Bei Cache-Hit darf kein Rebuild stattfinden'
        );
    }

    /** I6: Keine shared cache volumes. */
    public function testI6NoSharedCacheVolumes(): void
    {
        self::markTestSkipped(
            'I6 ist eine Architekturvorschrift (Kubernetes-Deployment-Topologie); '
            . 'nicht im Paket testbar. Wird durch das Fehlen jeglicher RWX-/'
            . 'Shared-FS-Operationen im Code-Pfad enforced.',
        );
    }

    /** I7: Keine RWX Filesystems. */
    public function testI7NoRwxFilesystems(): void
    {
        self::markTestSkipped(
            'I7 ist eine Deployment-Vorschrift; nicht im Paket testbar.',
        );
    }

    /** I8: Core Cache bleibt deployed. */
    public function testI8CoreCacheRemainsDeployed(): void
    {
        self::markTestSkipped(
            'I8 ist eine Container-Image-Build-Aussage; nicht im Paket testbar.',
        );
    }

    /** I9: FAL bleibt außerhalb des Cache-Systems. */
    public function testI9FalRemainsOutside(): void
    {
        self::markTestSkipped(
            'I9 ist eine Architektur-Abgrenzung; nicht im Paket testbar.',
        );
    }

    /** I10: Payload-Dateien dürfen jederzeit lokal verloren gehen. */
    public function testI10PayloadFilesMayBeLostAnytime(): void
    {
        $id = new CacheIdentifier('i10');
        $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);

        // Wiederholtes "Datei verschwunden" + erneute Caller-Berechnung
        for ($i = 0; $i < 5; ++$i) {
            foreach ($this->local->iterateAll() as $hash) {
                $this->local->delete($hash);
            }
            // Caller bemerkt Blob-Miss (false) und schreibt erneut
            self::assertNull($this->reader->execute($this->namespace, $id));
            $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);
            // Anschließender Read → Hit
            self::assertSame('bytes', $this->reader->execute($this->namespace, $id));
        }
        // Identifier ist nach 5 Verlust-Zyklen immer noch konsistent
        $finalMeta = $this->metadataCache->get($id);
        self::assertNotNull($finalMeta);
        self::assertSame('valid', $finalMeta->state->value);
    }
}
