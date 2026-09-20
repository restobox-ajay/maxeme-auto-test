<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Command;

use App\Command\Demo\DemoDataCleaner;
use App\Command\Demo\DemoSeed;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 1 of `app:seed-warehouse-data`: the depth layer — bins, dimensional products, and the
 * opening stock the other two steps move around.
 *
 * ## Why this lives in the bundle and not in src/Command
 *
 * Because core may not name a bundle class. That is not a style preference: all three inventory
 * bundles are deletable, and a core class with `use InventoryDepthBundle\...` at the top would stop
 * `bin/console` compiling the moment one was removed. ProcurementBundle even pins the rule as a test
 * (ProcurementPackagingTest::testCoreDoesNotReadAnyProcurementTable), which is what caught the first
 * draft of this fixture living in core. So the seeder is one command made of three steps, each in
 * the bundle whose screens it fills, orchestrated by name — a string, not a namespace — from
 * App\Command\SeedWarehouseDataCommand.
 *
 * ## Every unit of stock here arrives through StockMovementService
 *
 * Nothing in this file writes `inventory_detail`, and nothing writes a bucket column on
 * `product_inventory`. The reason is concrete rather than doctrinal: the two sides of the depth
 * layer are read by different screens — the bin list and the tracking worklist read the detail rows,
 * the inventory grid reads the buckets — so a fixture that INSERTed detail rows would render a
 * completely plausible bin list, a completely plausible grid, and two numbers that disagree. Every
 * screen would review fine, and the first person to find out would be whoever tried to sell the
 * stock. The invariant the orchestrator asserts afterwards is what turns "we used the service" from
 * an intention into a fact.
 *
 * The corollary: a dimensional product's `product_inventory.quantity` is never written here.
 * `quantity` is the number an admin types for a SIMPLE product; for a dimensional one the movement
 * layer maintains `received_quantity`, `transfer_in/out`, `write_off` and `quarantine`, and a
 * starting figure typed in beside them is exactly the drift the invariant catches.
 */
#[AsCommand(
    name: 'app:seed-demo-depth',
    description: 'Step 1 of app:seed-warehouse-data: demo bins, dimensional products and opening stock.',
)]
final class SeedDepthDemoDataCommand extends Command
{
    private const WEST = 'Demo West';
    private const EAST = 'Demo East';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemoDataCleaner $cleaner,
        private readonly BundleStatusRepository $bundles,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly StockMovementService $movements,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->bundles->isActive('InventoryDepthBundle')) {
            $io->error('InventoryDepthBundle is Inactive; its screens are 404 and there is nothing to seed.');

            return Command::FAILURE;
        }

        if ($this->cleaner->hasDepthLayer()) {
            $io->error('Demo depth data already exists. Run app:seed-warehouse-data --force instead.');

            return Command::FAILURE;
        }

        $west = $this->warehouses->warehouseForRegionName(self::WEST);
        $east = $this->warehouses->warehouseForRegionName(self::EAST);

        if (!$west instanceof Warehouse || !$east instanceof Warehouse) {
            $io->error('The demo warehouses do not exist. Run app:seed-demo-data first.');

            return Command::FAILURE;
        }

        $bins = $this->seedBins($west, $east);
        $policies = $this->seedTrackingPolicies($io);
        $products = $this->seedDimensionalProducts($policies);
        $groups = $this->seedOpeningStock($bins[$west->getName()], $west);

        $io->writeln(sprintf('  %-36s %6d', 'warehouse_location', array_sum(array_map('count', $bins))));
        $io->writeln(sprintf('  %-36s %6d', 'product_core (dimensional)', $products));
        $io->writeln(sprintf('  %-36s %6d', 'inventory_movement_group (opening)', $groups));

        return Command::SUCCESS;
    }

    /**
     * Bins, with the zone, aisle, sort key and map coordinates the bin map and the pick path need.
     *
     * `sortKey` is not decoration: it is the walking order of the building, and PickListCompiler
     * routes a pick round by it. Bins with equal or absent sort keys produce a pick list that looks
     * fine on screen and sends the picker back and forth across the warehouse, which is precisely
     * what the pick screens are being reviewed to prevent — so the demo keys ascend along each aisle.
     *
     * `mapX`/`mapY` are a separate question: a bin is drawn on the bin map only once it has BOTH, so
     * one bin per warehouse is deliberately left unplaced. A map where every pin is present proves
     * nothing about how the screen handles the bin nobody has positioned yet.
     *
     * @return array<string, array<string, WarehouseLocation>> warehouse name => bin code => bin
     */
    private function seedBins(Warehouse $west, Warehouse $east): array
    {
        // code, type, zone, aisle, sortKey, mapX, mapY
        $layout = [
            ['RECV-01', WarehouseLocation::TYPE_RECEIVING, 'Inbound', null, 5, 1, 1],
            ['A-01', WarehouseLocation::TYPE_PICK, 'Pick', 'A', 100, 2, 3],
            ['A-02', WarehouseLocation::TYPE_PICK, 'Pick', 'A', 110, 3, 3],
            ['A-03', WarehouseLocation::TYPE_PICK, 'Pick', 'A', 120, 4, 3],
            ['B-01', WarehouseLocation::TYPE_PICK, 'Pick', 'B', 200, 2, 5],
            ['B-02', WarehouseLocation::TYPE_PICK, 'Pick', 'B', 210, 3, 5],
            ['C-01', WarehouseLocation::TYPE_PICK, 'Pick', 'C', 300, null, null],
            ['HOLD-01', WarehouseLocation::TYPE_HOLD, 'Quarantine', null, 800, 6, 1],
            ['STAGE-01', WarehouseLocation::TYPE_STAGING, 'Outbound', null, 900, 7, 4],
        ];

        $bins = [];

        foreach ([$west, $east] as $warehouse) {
            $bins[$warehouse->getName()] = [];

            foreach ($layout as [$code, $type, $zone, $aisle, $sortKey, $x, $y]) {
                $bin = (new WarehouseLocation())
                    ->setWarehouse($warehouse)
                    ->setCode($code)
                    ->setType($type)
                    ->setZone($zone)
                    ->setAisle($aisle)
                    ->setSortKey($sortKey)
                    ->setStatus('Active')
                    ->setMapX($x)
                    ->setMapY($y);

                $this->em->persist($bin);
                $bins[$warehouse->getName()][$code] = $bin;
            }
        }

        $this->em->flush();

        return $bins;
    }

    /**
     * The four tracking policies — adopted, not invented.
     *
     * #573's own migration ships exactly these four rows (`None`, `Lot`, `Lot + expiry`, `Serial`),
     * because a policy list with nothing in it is not a configurable feature. So this looks them up
     * by name and attaches products to them, and creates one — under a `Demo ` name, so the cleaner
     * can take it back out — only if a build genuinely has none for a mode.
     *
     * Seeding four parallel `Demo *` policies would have been easier and would have been wrong: the
     * settings screen would then list eight, four of them duplicates, and the first question a
     * reviewer asked would be which pair is real. A fixture that makes a screen harder to review has
     * defeated its own purpose.
     *
     * What the policies are FOR, and why one receipt in step 2 deliberately omits a lot code: a
     * policy never blocks a receipt. When it asks for a lot or a serial and the paperwork carries
     * none, ReceivingService substitutes `sentinel_in` and flags the row `expect_resolution` — and
     * that flag is the tracking worklist's entire contents.
     *
     * @return array<string, TrackingPolicy>
     */
    private function seedTrackingPolicies(SymfonyStyle $io): array
    {
        // name => [mode, requiresExpiry]. `trackIn` without `trackOut` on purpose: #573 is explicit
        // that picking and shipping are out of scope for identity capture, so a policy claiming to
        // track outbound would describe behaviour that does not exist yet.
        $wanted = [
            'None' => [TrackingPolicy::MODE_NONE, false],
            'Lot' => [TrackingPolicy::MODE_LOT, false],
            'Lot + expiry' => [TrackingPolicy::MODE_LOT, true],
            'Serial' => [TrackingPolicy::MODE_SERIAL, false],
        ];

        $repository = $this->em->getRepository(TrackingPolicy::class);
        $policies = [];
        $created = 0;

        foreach ($wanted as $name => [$mode, $expiry]) {
            $policy = $repository->findOneBy(['name' => $name])
                ?? $repository->findOneBy(['mode' => $mode, 'requiresExpiry' => $expiry]);

            if (!$policy instanceof TrackingPolicy) {
                $policy = (new TrackingPolicy())
                    ->setName(DemoSeed::SHARED_NAME_PREFIX . $name)
                    ->setMode($mode)
                    ->setRequiresExpiry($expiry)
                    ->setTrackIn($mode !== TrackingPolicy::MODE_NONE)
                    ->setTrackOut(false)
                    ->setSentinelIn($mode === TrackingPolicy::MODE_NONE ? null : TrackingPolicy::DEFAULT_SENTINEL);

                $this->em->persist($policy);
                ++$created;
            }

            $policies[$name] = $policy;
        }

        $this->em->flush();

        $io->writeln(sprintf('  %-36s %6d', 'tracking_policy (created/adopted)', $created));

        return $policies;
    }

    /**
     * The products whose stock the depth layer maintains — one on each tracking mode the model has.
     *
     * One per mode is what makes the tracking screens comparable: `none` carries no identity at all,
     * `lot` carries a batch, `lot + expiry` carries a batch that goes off, and `serial` carries one
     * row per unit and therefore a detail row that may never hold more than 1.
     *
     * `setInventoryMode()` directly rather than through InventoryModeSwitcher, because these are new
     * products with no stock: the switcher's job is to convert an EXISTING simple product by writing
     * an opening-balance movement for whatever it already had, and there is nothing to carry across.
     * An admin converting a stocked product must go through the switcher, and does.
     *
     * They join the Demo categories the sell-side seeder created, so the catalogue screens show both
     * kinds of product side by side — which is the comparison a reviewer needs, since the quantity
     * field is editable for one and not for the other.
     *
     * @param array<string, TrackingPolicy> $policies
     */
    private function seedDimensionalProducts(array $policies): int
    {
        $categories = [];
        foreach ($this->em->getRepository(ProductCategory::class)->findAll() as $category) {
            $categories[$category->getName()] = $category;
        }

        // sku => [name, category, policy, cost, price, unit]
        $catalogue = [
            'DEMO-DIM-5001' => ['Marine Grade Sealant 400ml', 'Demo Adhesives', 'Lot + expiry', '9.20', '19.75', 'EA'],
            'DEMO-DIM-5002' => ['Nitrile Glove Box (100)', 'Demo Safety', 'Lot', '7.60', '15.40', 'BOX'],
            'DEMO-DIM-5003' => ['Torque Wrench 1/2in 40-200Nm', 'Demo Power Tools', 'Serial', '112.00', '229.00', 'EA'],
            'DEMO-DIM-5004' => ['Anchor Bolt Sleeve M12 (bag of 50)', 'Demo Fasteners', 'None', '21.40', '39.90', 'BAG'],
        ];

        foreach ($catalogue as $sku => [$name, $categoryName, $policyName, $cost, $price, $unit]) {
            $this->em->persist(
                (new ProductCore())
                    ->setSku($sku)
                    ->setName($name)
                    ->setCategory($categories[$categoryName] ?? null)
                    ->setUnit($unit)
                    ->setCostPrice($cost)
                    ->setDefaultPrice($price)
                    ->setOriginalPrice($price)
                    ->activate()
                    ->setVisible(true)
                    ->setSalesTaxCode('Taxable')
                    ->setShortDescription($name)
                    ->setSyncSource(DemoSeed::PRODUCT_SYNC_SOURCE)
                    ->setTrackingPolicy($policies[$policyName])
                    ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL),
            );
        }

        $this->em->flush();

        return \count($catalogue);
    }

    /**
     * Opening stock for the untracked product, plus the two movements that give the depth screens
     * something other than arrivals to show.
     *
     * Three `MovementRequest`s handed to StockMovementService — the same call the adjustment screen
     * makes:
     *
     *  - a receipt counted into TWO bins, so one product's total is a sum across bins rather than a
     *    single row, which is the case a bin-level screen has to be able to add up;
     *  - a move between bins, which is one fact (the same units, somewhere else) rather than the two
     *    unrelated adjustments a stock-out and a stock-in would record;
     *  - a status change into `quarantine`, a bucket that is otherwise zero everywhere and which is
     *    SUBTRACTED from availability — so it also proves the invariant is doing arithmetic rather
     *    than comparing two copies of the same number.
     *
     * The lot-carrying stock is not here: it arrives on a purchase order in step 2, because a lot
     * code and an expiry date are things a supplier's paperwork tells you, and inventing them
     * without a delivery behind them would produce lots no receipt can explain.
     *
     * The client operation ids are stable strings rather than random ones, deliberately: they are
     * uniquely indexed, so a re-run after a crash mid-way applies each movement exactly once instead
     * of doubling the stock.
     *
     * @param array<string, WarehouseLocation> $bins the West warehouse's bins, keyed by code
     */
    private function seedOpeningStock(array $bins, Warehouse $west): int
    {
        $untracked = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'DEMO-DIM-5004']);
        if (!$untracked instanceof ProductCore) {
            return 0;
        }

        $this->movements->apply(
            MovementRequest::of(
                InventoryMovementGroup::TYPE_RECEIPT,
                'demo-seed-opening-5004',
                'Opening count, counted into two bins',
                DemoSeed::ACTOR_LABEL,
            )
                ->receive($untracked, new DetailKey($west, $bins['B-01']), 90)
                ->receive($untracked, new DetailKey($west, $bins['B-02']), 45),
        );

        $this->movements->apply(
            MovementRequest::of(
                InventoryMovementGroup::TYPE_MOVE,
                'demo-seed-move-5004',
                'Making room in B-02; the overflow walked to C-01',
                DemoSeed::ACTOR_LABEL,
            )->move(
                $untracked,
                new DetailKey($west, $bins['B-02']),
                // C-01 rather than an A bin, deliberately. `sort_key` is the pick path, and both
                // TransferOrderService::sourceBin() and PickListCompiler::suggestBin() take the
                // FIRST pickable row along it — so parking stock in a low-sorted bin quietly makes
                // that small bin the source of every subsequent dispatch and the bin every short
                // pick is blamed on. C-01 sorts last, which leaves the demo's transfers and picks
                // drawing from the aisle they are supposed to.
                new DetailKey($west, $bins['C-01']),
                15,
            ),
        );

        // Quarantine is a status change, not a write-off. The units are still there and still
        // counted by the bin screens; they are simply not available to sell, which is why the bucket
        // is subtracted from availability rather than deducted from stock.
        $this->movements->apply(
            MovementRequest::of(
                InventoryMovementGroup::TYPE_STATUS_CHANGE,
                'demo-seed-quarantine-5004',
                'Suspected damage in transit — held pending inspection',
                DemoSeed::ACTOR_LABEL,
            )->move(
                $untracked,
                new DetailKey($west, $bins['B-01']),
                new DetailKey($west, $bins['HOLD-01'], null, null, InventoryDetail::STATUS_QUARANTINE),
                12,
            ),
        );

        return 3;
    }
}
