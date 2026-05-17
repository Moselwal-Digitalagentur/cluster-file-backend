<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\BackendVersionInfo;

final readonly class BackendVersion
{
    public function __construct(
        public int $value,
    ) {
        if ($value < 1) {
            throw new \InvalidArgumentException(\sprintf('BackendVersion must be >= 1, got %d', $value));
        }
    }

    public static function current(): self
    {
        return new self(BackendVersionInfo::CURRENT);
    }

    /**
     * Default environment variable that carries the deploy-scoped
     * identifier. Conventional in container-based deployments — Helm/
     * Kustomize/GitLab CI typically expose the image tag this way.
     * Overridable via the `backendVersionEnvVar` cache-backend option
     * for setups that use a different convention (e.g. `CI_COMMIT_SHA`,
     * `RELEASE_VERSION`).
     */
    public const string DEFAULT_ENV_VAR = 'IMAGE_TAG';

    /**
     * Builds a stable, deploy-scoped BackendVersion from the environment
     * variable `IMAGE_TAG` (or a custom variable name). Intended to be
     * set by CI/CD to a deploy-unique identifier such as `$CI_COMMIT_SHA`,
     * `$IMAGE_TAG`, or a release semver. Falls back to the package-
     * internal {@see self::current()} when the variable is unset or empty.
     *
     * The string value is folded to a positive int via crc32 — sufficient
     * for cache-identity purposes (we only need divergence between
     * deploys, not cryptographic uniqueness; the actual payload hash is
     * sha256).
     */
    public static function fromEnv(string $envVar = self::DEFAULT_ENV_VAR): self
    {
        $value = getenv($envVar);
        if (!\is_string($value) || '' === $value) {
            return self::current();
        }

        return self::fromString($value);
    }

    /**
     * Deterministically folds any non-empty string identifier into a
     * BackendVersion. Same input → same version across all pods.
     */
    public static function fromString(string $deployIdentifier): self
    {
        if ('' === $deployIdentifier) {
            throw new \InvalidArgumentException('BackendVersion::fromString requires a non-empty identifier');
        }

        return new self(max(1, crc32($deployIdentifier)));
    }
}
