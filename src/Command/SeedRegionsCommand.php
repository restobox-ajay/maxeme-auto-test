<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\RegionSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Populates the country/province reference tables.
 *
 * The seed migration already does this for any environment built by migrations. This command covers
 * the environments that are not: the Codeception bootstrap builds its schema with
 * `doctrine:schema:create` and then calls this, and it is the sane way to fill the tables on a
 * database created from entity metadata rather than replayed from the chain.
 *
 * Safe to run repeatedly — only missing rows are inserted, and existing ones are never overwritten.
 */
#[AsCommand(
    name: 'app:seed-regions',
    description: 'Insert any missing country/province reference rows.',
)]
final class SeedRegionsCommand extends Command
{
    public function __construct(private readonly RegionSeeder $seeder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $created = $this->seeder->seed();

        $io = new SymfonyStyle($input, $output);

        if ($created['countries'] === 0 && $created['provinces'] === 0) {
            $io->success('Country/province reference data was already complete.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Seeded %d country/countries and %d province(s).',
            $created['countries'],
            $created['provinces'],
        ));

        return Command::SUCCESS;
    }
}
