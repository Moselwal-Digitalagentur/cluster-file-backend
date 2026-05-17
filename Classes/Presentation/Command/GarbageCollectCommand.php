<?php

// SPDX-FileCopyrightText: 2026 Moselwal GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Presentation\Command;

use Moselwal\Typo3ClusterCache\Application\GarbageCollect\RunGarbageCollection;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'clusterfilebackend:gc',
    description: 'Triggert die Garbage Collection des Metadata-Caches eines ClusterFileBackend-Namespaces.',
)]
final class GarbageCollectCommand extends Command
{
    private const int EXIT_OK = 0;
    private const int EXIT_GENERAL_ERROR = 1;
    private const int EXIT_ARG_ERROR = 64;

    public function __construct(
        private readonly RunGarbageCollection $gcRunner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'namespace',
                null,
                InputOption::VALUE_REQUIRED,
                'Vollqualifizierter Cache-Namespace: cfb:{env}:{instance}:{cacheName}',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Keine Schreibvorgänge')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $namespaceArg = $input->getOption('namespace');
        if (!\is_string($namespaceArg) || '' === $namespaceArg) {
            $output->writeln('<error>--namespace is required</error>');

            return self::EXIT_ARG_ERROR;
        }

        $namespace = $this->parseNamespace($namespaceArg, $output);
        if (null === $namespace) {
            return self::EXIT_ARG_ERROR;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        try {
            $report = $this->gcRunner->execute($namespace, $dryRun);
            $output->writeln((string) json_encode($report->toArray(), \JSON_THROW_ON_ERROR));

            return self::EXIT_OK;
        } catch (\Throwable $e) {
            $output->writeln('<error>GC failed: ' . $e->getMessage() . '</error>');

            return self::EXIT_GENERAL_ERROR;
        }
    }

    private function parseNamespace(string $value, OutputInterface $output): ?CacheNamespace
    {
        if (1 !== preg_match(
            '/^cfb:(prod|staging|testing|development):([a-z0-9-]{1,64}):([a-zA-Z0-9_]{1,64})$/',
            $value,
            $m,
        )) {
            $output->writeln(\sprintf('<error>Invalid --namespace "%s"</error>', $value));

            return null;
        }
        try {
            return new CacheNamespace(EnvironmentName::from($m[1]), $m[2], $m[3]);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return null;
        }
    }
}
