<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Enum\CacheState;

final readonly class CacheMetadata
{
    public function __construct(
        public CacheIdentifier $identifier,
        public PayloadHash $hash,
        public PayloadChecksum $checksum,
        public Generation $generation,
        public Lifetime $lifetime,
        public SerializerName $serializer,
        public CompressionName $compression,
        public int $payloadSize,
        public TagSet $tags,
        public CacheState $state,
        public BackendVersion $backendVersion,
    ) {
        if ($payloadSize < 0) {
            throw new \InvalidArgumentException(\sprintf('CacheMetadata.payloadSize must be >= 0, got %d', $payloadSize));
        }
    }

    public function isValid(int $now, Generation $currentNamespaceGeneration): bool
    {
        return $this->state->isValid()
            && !$this->lifetime->isExpired($now)
            && $this->generation->isAtLeast($currentNamespaceGeneration);
    }

    /**
     * @return array<string, mixed>
     */
    public function toKvPayload(): array
    {
        return [
            'identifier' => $this->identifier->value,
            'hash' => 'sha256:' . $this->hash->digest,
            'checksum' => 'sha256:' . $this->checksum->digest,
            'generation' => $this->generation->value,
            'createdAt' => $this->lifetime->createdAt,
            'expiresAt' => $this->lifetime->expiresAt,
            'serializer' => $this->serializer->version,
            'compression' => $this->compression->name->value,
            'payloadSize' => $this->payloadSize,
            'tags' => $this->tags->toArray(),
            'state' => $this->state->value,
            'backendVersion' => $this->backendVersion->value,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromKvPayload(array $raw): self
    {
        foreach (['identifier', 'hash', 'checksum', 'generation', 'createdAt', 'expiresAt',
            'serializer', 'compression', 'payloadSize', 'tags', 'state', 'backendVersion'] as $required) {
            if (!\array_key_exists($required, $raw)) {
                throw new \RuntimeException(\sprintf('CacheMetadata payload missing required field "%s"', $required));
            }
        }

        $hashDigest = self::stripAlgoPrefix((string) $raw['hash']);
        $checksumDigest = self::stripAlgoPrefix((string) $raw['checksum']);

        $serializer = (string) $raw['serializer'];
        $serializerParts = explode(':', $serializer, 2);
        $serializerName = 2 === \count($serializerParts) ? $serializerParts[0] : $serializer;

        return new self(
            identifier: new CacheIdentifier((string) $raw['identifier']),
            hash: new PayloadHash($hashDigest),
            checksum: new PayloadChecksum($checksumDigest),
            generation: new Generation((int) $raw['generation']),
            lifetime: new Lifetime((int) $raw['createdAt'], (int) $raw['expiresAt']),
            serializer: new SerializerName($serializerName, $serializer),
            compression: CompressionName::fromString((string) $raw['compression']),
            payloadSize: (int) $raw['payloadSize'],
            tags: new TagSet(self::normalizeTags($raw['tags'])),
            state: CacheState::from((string) $raw['state']),
            backendVersion: new BackendVersion((int) $raw['backendVersion']),
        );
    }

    private static function stripAlgoPrefix(string $value): string
    {
        $colon = strpos($value, ':');

        return false === $colon ? $value : substr($value, $colon + 1);
    }

    /**
     * @return list<string>
     */
    private static function normalizeTags(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $result = [];
        foreach ($raw as $tag) {
            if (\is_string($tag)) {
                $result[] = $tag;
            }
        }

        return $result;
    }
}
