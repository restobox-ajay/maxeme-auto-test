<?php

declare(strict_types=1);

namespace App\Command;

use App\Command\Demo\DemoDataCleaner;
use App\Command\Demo\DemoInventoryInvariant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the buy and warehouse sides — vendors, purchase orders, goods receipts, vendor bills, bins,
 * lots, tracking policies, transfers and pick lists — on top of what `app:seed-demo-data` created.
 *
 * ## Why this file is an orchestrator and not the seeder
 *
 * Because core may not name a bundle class, and every one of those things belongs to a bundle.
 *
 * That rule is load-bearing rather than decorative. `InventoryDepthBundle`, `ProcurementBundle` and
 * `WarehouseOpsBundle` are all deletable: remove one and the application still runs, stock still
 * enters through the adjustment screen, and nothing needs migrating. A core class that imported one
 * of their entities at the top of the file would end that — the
 * container would fail to compile the moment the directory went, so `bin/console` itself would stop
 * working. The procurement bundle pins the rule as a test (its ProcurementPackagingTest greps core
 * for its own table and namespace names), and that test is what caught the first draft of this
 * fixture, written as one file in `src/Command/`. Note the grep matches PROSE as well as code, which
 * is why this docblock names the bundles without ever writing one of their class paths.
 *
 * So the seeding lives in three steps, each inside the bundle whose screens it fills:
 *
 *   1. `app:seed-demo-depth`          InventoryDepthBundle  bins, dimensional products, opening stock
 *   2. `app:seed-demo-procurement`    ProcurementBundle     vendors, POs, receipts, vendor bills
 *   3. `app:seed-demo-warehouse-ops`  WarehouseOpsBundle    transfers, pick rounds, orders to pick
 *
 * and this command runs them in order. It reaches them by NAME — a string, which is exactly how the
 * rest of the application already deals with optional bundles (`BundleStatusRepository::isActive()`
 * takes a source name, tagged services are found by tag). A step whose bundle has been deleted is
 * simply not registered, and this says so and carries on instead of failing to boot.
 *
 * The order matters: step 2's receipts need step 1's bins and products, and step 3's pick rounds
 * need the stock steps 1 and 2 put on the shelves.
 *
 * ## What this file DOES own
 *
 * Two things, both of which are core's business rather than any bundle's:
 *
 *  - the purge, so that `--force` removes the whole layer in one foreign-key-safe pass rather than
 *    three partial ones (DemoDataCleaner, which works by table name and therefore keeps working with
 *    a bundle removed — the tables are created by core's own migrations either way);
 *  - the invariant assertion, which is the point of the whole exercise. After seeding, for every
 *    product/warehouse pair that has detail rows:
 *
 *        SUM(inventory_detail.quantity WHERE status = 'available')
 *          == quantity + received + transfer_in - transfer_out - write_off - quarantine
 *             - SUM(inventory_detail.quantity WHERE status = 'sold')
 *
 *    A fixture that INSERTed detail rows would render a plausible bin list, a plausible inventory
 *    grid, and two numbers that disagree — and every screen would review fine. Asserting it here is
 *    what turns "the steps went through StockMovementService" from an intention into a fact.
 */
#[AsCommand(
    name: 'app:seed-warehouse-data',
    description: 'Seed vendors, purchase orders, receipts, bills, bins, lots, transfers and pick lists on top of app:seed-demo-data.',
)]
final class SeedWarehouseDataCommand extends Command
{
    /**
     * The three steps, in the order they have to run. Names, not classes — see the class docblock.
     *
     * The label is what this command prints when a step is missing, and it names the bundle rather
     * than the command so the message says what to install rather than what to type.
     */
    private const STEPS = [
        'app:seed-demo-depth' => 'InventoryDepthBundle (bins, dimensional products, opening stock)',
        'app:seed-demo-procurement' => 'ProcurementBundle (vendors, purchase orders, receipts, bills)',
        'app:seed-demo-warehouse-ops' => 'WarehouseOpsBundle (transfers, pick rounds)',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemoDataCleaner $cleaner,
        private readonly DemoInventoryInvariant $invariant,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Delete the existing demo warehouse/buy layer and seed it again. Leaves the sell side alone.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        if ($this->cleaner->isWarehouseSeeded() && !$force) {
            $io->error([
                'This database already carries demo warehouse data.',
                'Re-run with --force to delete it and seed again.',
            ]);

            return Command::FAILURE;
        }

        if ($force) {
            $io->section('Purging existing demo warehouse data');
            $purged = $this->cleaner->purgeWarehouseLayer();
            $io->writeln($purged === [] ? '  nothing to purge' : $this->formatCounts($purged));
        }

        $application = $this->getApplication();
        if ($application === null) {
            $io->error('No console application is available to run the seeding steps.');

            return Command::FAILURE;
        }

        $ran = 0;

        foreach (self::STEPS as $name => $label) {
            $io->section($label);

            if (!$application->has($name)) {
                $io->warning(sprintf(
                    '%s is not installed, so `%s` does not exist. Skipping that part of the fixture.',
                    explode(' ', $label)[0],
                    $name,
                ));

                continue;
            }

            $status = $application->find($name)->run(new ArrayInput([]), $output);

            if ($status !== Command::SUCCESS) {
                $io->error(sprintf('`%s` failed; nothing further was seeded.', $name));

                return $status;
            }

            ++$ran;
        }

        if ($ran === 0) {
            $io->error('None of the three inventory bundles is installed; there was nothing to seed.');

            return Command::FAILURE;
        }

        return $this->assertInvariant($io, $ran);
    }

    /**
     * The depth-layer invariant, asserted on this command's own output.
     *
     * The identity map is cleared first, deliberately: the steps ran inside this process and left
     * entities loaded, and the check reads the database directly. Anything still hydrated would be
     * a copy of what was written, which is precisely the sort of self-agreeing comparison this
     * assertion exists to avoid.
     */
    private function assertInvariant(SymfonyStyle $io, int $stepsRun): int
    {
        $this->em->clear();

        $failures = $this->invariant->failures();
        $pairs = $this->invariant->pairsChecked();

        if ($failures !== []) {
            $io->error([
                sprintf('The inventory invariant does not hold after seeding (%d pair(s) checked).', $pairs),
                DemoInventoryInvariant::describe($failures),
            ]);

            return Command::FAILURE;
        }

        if ($pairs === 0 && $stepsRun === \count(self::STEPS)) {
            $io->error('No product/warehouse pair has any detail rows, so nothing was actually stocked.');

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Warehouse and buy sides seeded (%d of %d steps). Inventory invariant holds across all %d'
            . ' product/warehouse pair(s) with detail rows.',
            $stepsRun,
            \count(self::STEPS),
            $pairs,
        ));

        $io->note(
            'For a second opinion from the application\'s own drift detector, run:'
            . ' php bin/console app:inventory-depth:detail-check',
        );

        return Command::SUCCESS;
    }

    /** @param array<string, int> $counts */
    private function formatCounts(array $counts): string
    {
        $lines = [];
        foreach ($counts as $table => $rows) {
            $lines[] = sprintf('  %-36s %6d', $table, $rows);
        }

        return implode("\n", $lines);
    }
}
