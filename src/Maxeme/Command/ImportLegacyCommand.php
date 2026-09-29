<?php

declare(strict_types=1);

namespace App\Maxeme\Command;

use App\Maxeme\Legacy\LegacyConnectionFactory;
use App\Maxeme\Legacy\LegacyImporterInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Copies the legacy Maxeme app's data into this app. Safe to re-run: every step updates the rows
 * it imported before.
 *
 *   php bin/console app:maxeme:import-legacy                 every step, in order
 *   php bin/console app:maxeme:import-legacy --only=users    one or more steps (comma-separated)
 */
#[AsCommand(name: 'app:maxeme:import-legacy', description: 'Import data from the legacy Maxeme Auto database')]
final class ImportLegacyCommand
{
    /** @param iterable<LegacyImporterInterface> $importers */
    public function __construct(
        #[AutowireIterator(LegacyImporterInterface::TAG)]
        private readonly iterable $importers,
        private readonly LegacyConnectionFactory $connectionFactory,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Comma-separated steps to run')] string $only = ''): int
    {
        $importers = [];
        foreach ($this->importers as $importer) {
            $importers[$importer::name()] = $importer;
        }
        uasort($importers, static fn (LegacyImporterInterface $a, LegacyImporterInterface $b): int => $a::order() <=> $b::order());

        $selected = array_filter(array_map('trim', explode(',', $only)));
        $unknown = array_diff($selected, array_keys($importers));
        if ($unknown !== []) {
            $io->error(sprintf('Unknown step(s): %s. Available: %s.', implode(', ', $unknown), implode(', ', array_keys($importers))));

            return Command::INVALID;
        }

        $legacy = $this->connectionFactory->create();

        foreach ($importers as $name => $importer) {
            if ($selected !== [] && !in_array($name, $selected, true)) {
                continue;
            }

            $io->section($name);
            $io->success(sprintf('%s: %d record(s) imported or updated.', $name, $importer->import($legacy, $io)));
        }

        return Command::SUCCESS;
    }
}
