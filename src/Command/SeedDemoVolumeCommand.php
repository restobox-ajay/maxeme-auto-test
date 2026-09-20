<?php

declare(strict_types=1);

namespace App\Command;

use App\Command\Demo\DemoInventoryInvariant;
use App\Command\Demo\DemoVolume;
use App\Command\Demo\DemoVolumeSellSide;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills a demo box with VOLUME: roughly `--count` new rows of every business object, so every admin
 * list screen has pages of realistic rows to page, sort and filter.
 *
 * ## How this differs from app:seed-demo-data and app:seed-warehouse-data
 *
 * Those two build a small, hand-picked set — one document per interesting case — and refuse to run
 * against a database that already has demo data. This one is the opposite on both counts: it builds
 * a large generated set and it is ADDITIVE. Each run adds `--count` new top-level rows per object on
 * top of whatever exists (a second run adds another `--count`), and it never updates or deletes a
 * row it did not create, beyond what the application's own services do as a side effect of a new
 * document (a receipt raising a stock bucket, an order taking a sales hold).
 *
 * It uses the catalogue that is there. The only products it creates are a small set of DIMENSIONAL
 * ones in InventoryDepthBundle's step, because bins, lots, movements, transfers and picks can only
 * exist against a dimensional product, and converting an existing product would be an UPDATE of a
 * row this command does not own.
 *
 * ## Structure — core orchestrates, bundles seed their own screens
 *
 * Core may not name a bundle class (see SeedWarehouseDataCommand for why that rule is load-bearing),
 * so this command seeds the sell side itself (DemoVolumeSellSide) and then runs one step per bundle
 * BY NAME:
 *
 *   app:seed-demo-volume:depth           InventoryDepthBundle  bins, dimensional products, stock
 *                                                              adjustments, lots, reorder rules
 *   app:seed-demo-volume:procurement     ProcurementBundle     vendors, vendor prices, RFQs, POs,
 *                                                              receipts, bills, vendor returns,
 *                                                              debit memos
 *   app:seed-demo-volume:warehouse-ops   WarehouseOpsBundle    transfers, pick lists and the orders
 *                                                              they pick
 *   app:seed-demo-volume:barcode         BarcodeBundle         product barcodes
 *
 * A step whose bundle is not installed is not registered, and is reported and skipped. A step whose
 * bundle is installed but switched off in Bundle Management refuses on its own, exactly as the
 * fixed seeders do.
 *
 * ## What it proves on the way out
 *
 * Row counts before and after for every table that changed; the depth-layer invariant (see
 * DemoInventoryInvariant); `PRAGMA foreign_key_check`; wall time and peak memory. Run it on a box
 * with `php -d memory_limit=512M bin/console app:seed-demo-volume`.
 */
#[AsCommand(
    name: 'app:seed-demo-volume',
    description: 'Add ~N rows of every business object (sell, buy, warehouse) on top of existing data, for demo boxes.',
)]
final class SeedDemoVolumeCommand extends Command
{
    /** name => what it seeds. Names, not classes: see the class docblock. */
    private const STEPS = [
        'app:seed-demo-volume:depth' => 'InventoryDepthBundle (bins, dimensional products, adjustments, lots, reorder rules)',
        'app:seed-demo-volume:procurement' => 'ProcurementBundle (vendors, prices, RFQs, POs, receipts, bills, returns, debit memos)',
        'app:seed-demo-volume:warehouse-ops' => 'WarehouseOpsBundle (transfers, pick lists)',
        'app:seed-demo-volume:barcode' => 'BarcodeBundle (product barcodes)',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly DemoVolumeSellSide $sellSide,
        private readonly DemoInventoryInvariant $invariant,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'How many new rows of each object to add.', '200')
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'Run token (seeds the random source and every operation id). Defaults to a fresh one.')
            ->addOption('skip-sell', null, InputOption::VALUE_NONE, 'Skip the core sell side and only run the bundle steps.')
            ->addOption('backorders-only', null, InputOption::VALUE_NONE, 'Add only backorder orders, against companies the seeder already made.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only these bundle steps (depth, procurement, warehouse-ops, barcode).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);

        $count = (int) $input->getOption('count');
        if ($count < 1) {
            $io->error('--count must be a positive number.');

            return Command::FAILURE;
        }

        $volume = new DemoVolume($input->getOption('run'));
        $only = array_map(static fn (string $s): string => 'app:seed-demo-volume:' . $s, (array) $input->getOption('only'));

        $io->title(sprintf('Adding ~%d rows per object (run %s)', $count, $volume->run));

        $before = $this->tableCounts();

        if ($input->getOption('backorders-only')) {
            $io->section('Backorder orders (core)');
            $result = $this->sellSide->seedBackordersOnly($count, $volume, static fn (string $m) => $io->writeln('  ' . $m));
            $this->printOutcomes($io, $result['outcomes']);
            foreach ($result['notes'] as $note) {
                $io->note($note);
            }
            $this->em->clear();
            $this->printCounts($io, $before, $this->tableCounts());
            $io->success(sprintf('Done in %.1f s.', microtime(true) - $started));

            return Command::SUCCESS;
        }

        if (!$input->getOption('skip-sell') && $only === []) {
            $io->section('Sell side (core)');
            $result = $this->sellSide->seed($count, $volume, static fn (string $m) => $io->writeln('  ' . $m));
            $this->printOutcomes($io, $result['outcomes']);
            foreach ($result['notes'] as $note) {
                $io->note($note);
            }
            $this->em->clear();
        }

        $application = $this->getApplication();
        foreach (self::STEPS as $name => $label) {
            if ($only !== [] && !\in_array($name, $only, true)) {
                continue;
            }

            $io->section($label);

            if ($application === null || !$application->has($name)) {
                $io->warning(sprintf('%s is not installed, so `%s` does not exist. Skipped.', explode(' ', $label)[0], $name));

                continue;
            }

            $status = $application->find($name)->run(new ArrayInput(['--count' => (string) $count, '--run' => $volume->run]), $output);
            $this->em->clear();

            if ($status !== Command::SUCCESS) {
                $io->error(sprintf('`%s` failed; the steps after it were not run. Everything already written stays.', $name));

                return $status;
            }
        }

        $after = $this->tableCounts();
        $this->printCounts($io, $before, $after);

        return $this->verify($io, $started);
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        $tables = $this->connection->fetchFirstColumn(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        );

        foreach ($tables as $table) {
            $counts[(string) $table] = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM "%s"', $table));
        }

        return $counts;
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     */
    private function printCounts(SymfonyStyle $io, array $before, array $after): void
    {
        $rows = [];
        foreach ($after as $table => $rowsAfter) {
            $rowsBefore = $before[$table] ?? 0;
            if ($rowsAfter !== $rowsBefore) {
                $rows[] = [$table, $rowsBefore, $rowsAfter, sprintf('%+d', $rowsAfter - $rowsBefore)];
            }
        }

        $io->section('Row counts that changed');
        $io->table(['table', 'before', 'after', 'delta'], $rows);
    }

    /** @param array<string, array<string, int>> $outcomes */
    private function printOutcomes(SymfonyStyle $io, array $outcomes): void
    {
        foreach ($outcomes as $table => $statuses) {
            ksort($statuses);
            $io->writeln(sprintf('  %-14s %s', $table, implode(', ', array_map(
                static fn (string $status, int $n): string => sprintf('%s %d', $status, $n),
                array_keys($statuses),
                $statuses,
            ))));
        }
    }

    private function verify(SymfonyStyle $io, float $started): int
    {
        $failures = $this->invariant->failures();
        $pairs = $this->invariant->pairsChecked();
        $orphans = $this->connection->fetchAllAssociative('PRAGMA foreign_key_check');

        $io->writeln(sprintf(
            '  %.1f s, peak memory %.0f MB',
            microtime(true) - $started,
            memory_get_peak_usage(true) / 1048576,
        ));

        if ($failures !== []) {
            $io->error(['The inventory invariant does not hold after seeding.', DemoInventoryInvariant::describe($failures)]);

            return Command::FAILURE;
        }

        if ($orphans !== []) {
            $io->error(sprintf('PRAGMA foreign_key_check reports %d orphaned row(s).', \count($orphans)));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Done. Inventory invariant holds across %d product/warehouse pair(s); foreign_key_check is clean.',
            $pairs,
        ));

        return Command::SUCCESS;
    }
}
