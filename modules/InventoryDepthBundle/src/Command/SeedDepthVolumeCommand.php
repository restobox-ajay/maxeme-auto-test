<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Command;

use App\Command\Demo\DemoSeed;
use App\Command\Demo\DemoVolume;
use App\Command\Demo\DemoVolumeCheckpoint;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryAdjustmentReason;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovement;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\ProductReorderRule;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Import\DimensionalImportProvider;
use InventoryDepthBundle\Movement\AutomaticSourcePicker;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\InsufficientStockException;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\ReversalPlanner;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryAdjustmentReasonRepository;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use InventoryDepthBundle\Repository\InventoryMovementRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * InventoryDepthBundle's step of `app:seed-demo-volume`: bins, dimensional products, their opening
 * stock, a year of stock adjustments, lots and reorder rules.
 *
 * ## The one place the volume seeder creates products
 *
 * Every screen this bundle owns is about DIMENSIONAL stock, and the demo box's catalogue is
 * entirely simple. Converting an existing product (InventoryModeSwitcher) would UPDATE a row this
 * seeder does not own, so instead it adds a small set of new dimensional products — `--count / 5`,
 * so 40 at the default — tagged `sync_source = 'demo-seed'` and `DEMO-VOL-` SKUs. Everything else in
 * the depth, procurement and warehouse-ops steps moves THOSE products' stock. Their mode is set at
 * creation, as SeedDepthDemoDataCommand does and for the same reason: a new product has no stock to
 * carry across, which is the only thing the switcher is for.
 *
 * ## Every unit arrives and leaves through StockMovementService
 *
 * Opening stock is a `stock_found` adjustment and every later change is one of the eight reasons
 * the adjustment screen offers, built exactly as AdjustmentController builds it: inbound stock
 * receives into a key (a lot is picked or created first and flushed, a serial product gets one line
 * per unit), outbound stock goes through AutomaticSourcePicker, a hold is released from named rows,
 * and a write-off is reversed through ReversalPlanner. Nothing here writes `inventory_detail` or a
 * bucket column, so the invariant the orchestrator asserts afterwards holds by construction.
 */
#[AsCommand(
    name: 'app:seed-demo-volume:depth',
    description: 'app:seed-demo-volume step: bins, dimensional products, stock adjustments, lots and reorder rules.',
)]
final class SeedDepthVolumeCommand extends Command
{
    private const BATCH = 25;

    /** base name (sprintf), variants, tracking policy, unit, category, cost range */
    private const CATALOGUE = [
        ['Clip-On Wheel Weight %s (box of 25)', ['0.25oz', '0.50oz', '0.75oz', '1.00oz', '1.50oz'], 'None', 'BOX', 'Tools & Shop Supplies', [9, 22]],
        ['Adhesive Wheel Weight Strip %s', ['5g x 12', '10g x 6', 'Black 5g x 12'], 'None', 'BOX', 'Tools & Shop Supplies', [14, 30]],
        ['Snap-In Valve Stem %s (bag of 100)', ['TR413', 'TR414', 'TR418', 'TR600HP'], 'None', 'BAG', 'TPMS & Valve Stems', [18, 45]],
        ['Lug Nut %s (bag of 20)', ['12x1.5 Chrome', '12x1.25 Chrome', '14x1.5 Black', '1/2in Acorn'], 'None', 'BAG', 'Lug Nuts & Wheel Locks', [11, 28]],
        ['Wheel Lock Set %s', ['12x1.5', '14x1.5', '1/2in'], 'None', 'EA', 'Lug Nuts & Wheel Locks', [16, 34]],
        ['Tire Sealant %s', ['500ml', '1L', '4L'], 'Lot + expiry', 'EA', 'Tools & Shop Supplies', [7, 38]],
        ['Vulcanizing Rubber Cement %s', ['8oz', '32oz'], 'Lot + expiry', 'EA', 'Tools & Shop Supplies', [6, 19]],
        ['Radial Repair Patch %s (box of 20)', ['RP-20', 'RP-30', 'RP-40'], 'Lot + expiry', 'BOX', 'Tools & Shop Supplies', [21, 48]],
        ['Tire Bead Lubricant %s', ['1 gal', '5 gal'], 'Lot', 'EA', 'Tools & Shop Supplies', [12, 55]],
        ['Balancing Beads %s', ['2oz bag', '4oz bag', '6oz bag'], 'Lot', 'BAG', 'Tools & Shop Supplies', [3, 9]],
        ['Nitrogen Valve Cap %s (bag of 100)', ['Green', 'Chrome'], 'Lot', 'BAG', 'TPMS & Valve Stems', [8, 17]],
        ['TPMS Sensor %s', ['315MHz Universal', '433MHz Universal', 'Programmable Clamp-In'], 'Serial', 'EA', 'TPMS & Valve Stems', [24, 61]],
        ['Cordless Impact Wrench %s', ['1/2in 18V', '3/8in 12V'], 'Serial', 'EA', 'Tools & Shop Supplies', [140, 310]],
        ['Digital Tire Pressure Gauge %s', ['0-100psi', '0-150psi'], 'Serial', 'EA', 'Tools & Shop Supplies', [14, 36]],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly BundleStatusRepository $bundles,
        private readonly DemoVolumeCheckpoint $checkpoint,
        private readonly StockMovementService $movements,
        private readonly AutomaticSourcePicker $picker,
        private readonly ReversalPlanner $reversals,
        private readonly InventoryAdjustmentReasonRepository $reasons,
        private readonly InventoryDetailRepository $details,
        private readonly InventoryMovementRepository $movementRows,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'How many new rows of each object to add.', '200')
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'Run token shared with the other steps.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = max(1, (int) $input->getOption('count'));
        $volume = new DemoVolume($input->getOption('run'), 'depth');

        if (!$this->bundles->isActive('InventoryDepthBundle')) {
            $io->error('InventoryDepthBundle is Inactive; its screens are 404 and there is nothing to seed.');

            return Command::FAILURE;
        }

        $warehouseIds = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM warehouse WHERE status = 'Active' ORDER BY id",
        ));
        if ($warehouseIds === []) {
            $io->error('There is no active warehouse to put bins in.');

            return Command::FAILURE;
        }

        // Add the eight adjustment reasons if a build is missing any — the screen does the same on
        // first load. A box that has them all (the usual case) gets no write at all.
        $this->reasons->ensureCatalogue();

        $bins = $this->seedBins($count, $volume, $warehouseIds);
        $io->writeln(sprintf('  %-34s %6d', 'warehouse_location', $bins));

        $products = $this->seedProducts(max(8, intdiv($count, 5)), $volume);
        $io->writeln(sprintf('  %-34s %6d', 'product_core (new, dimensional)', \count($products)));

        $opening = $this->seedOpeningStock($products, $warehouseIds, $volume);
        $io->writeln(sprintf('  %-34s %6d', 'movement groups (opening counts)', $opening));

        [$adjustments, $refused] = $this->seedAdjustments($count, $products, $volume);
        $io->writeln(sprintf('  %-34s %6d', 'movement groups (adjustments)', $adjustments));
        if ($refused > 0) {
            $io->writeln(sprintf('  %-34s %6d', '  refused by the service (skipped)', $refused));
        }

        $lots = $this->topUpLots($count, $products, $volume);
        $io->writeln(sprintf('  %-34s %6d', 'inventory_lot (registered ahead)', $lots));

        $rules = $this->seedReorderRules($count, $products, $warehouseIds, $volume);
        $io->writeln(sprintf('  %-34s %6d', 'inventory_reorder_rule', $rules));

        ($this->checkpoint)();

        return Command::SUCCESS;
    }

    // ---------------------------------------------------------------------------------------------
    // Bins

    /**
     * $count new bins across the warehouses, with the zone, sort key and map coordinates the bin
     * map and pick path read. Every warehouse is also given one receiving, one hold and one staging
     * bin if it has none of that type — a pick list cannot be released without a staging bin.
     *
     * Codes are `A-01-1` (aisle, bay, level) and continue past whatever the warehouse already has,
     * so a second run adds a new aisle rather than colliding with the first.
     *
     * @param list<int> $warehouseIds
     */
    private function seedBins(int $count, DemoVolume $volume, array $warehouseIds): int
    {
        $shares = $this->warehouseShares(\count($warehouseIds));
        $created = 0;

        foreach ($warehouseIds as $index => $warehouseId) {
            $warehouse = $this->em->find(Warehouse::class, $warehouseId);
            if (!$warehouse instanceof Warehouse) {
                continue;
            }

            $existing = [];
            $types = [];
            $maxSort = 0;
            foreach ($this->connection->fetchAllAssociative('SELECT code, type, sort_key FROM warehouse_location WHERE warehouse_id = ?', [$warehouseId]) as $row) {
                $existing[strtoupper((string) $row['code'])] = true;
                $types[(string) $row['type']] = true;
                $maxSort = max($maxSort, (int) $row['sort_key']);
            }

            $wanted = $index === array_key_last($warehouseIds)
                ? $count - $created
                : (int) round($count * $shares[$index]);

            // The three working bins first, where missing.
            foreach ([
                [WarehouseLocation::TYPE_RECEIVING, 'RECV', 'Inbound', 5],
                [WarehouseLocation::TYPE_HOLD, 'HOLD', 'Quarantine', 8000],
                [WarehouseLocation::TYPE_STAGING, 'STAGE', 'Outbound', 9000],
            ] as [$type, $prefix, $zone, $sortKey]) {
                if (isset($types[$type]) || $wanted <= 0) {
                    continue;
                }
                $code = $this->freeCode($prefix . '-01', $existing);
                $this->persistBin($warehouse, $code, $type, $zone, null, $sortKey, $volume->int(1, 3), $prefix === 'RECV' ? 1 : 9);
                $existing[$code] = true;
                --$wanted;
                ++$created;
            }

            $aisles = range('A', 'Z');
            $sort = max(100, $maxSort + 10);

            foreach ($aisles as $aisleIndex => $aisle) {
                for ($bay = 1; $bay <= 12 && $wanted > 0; ++$bay) {
                    for ($level = 1; $level <= 3 && $wanted > 0; ++$level) {
                        $code = sprintf('%s-%02d-%d', $aisle, $bay, $level);
                        if (isset($existing[$code])) {
                            continue;
                        }

                        // One bin in ten is left off the map on purpose: a map where every pin is
                        // placed proves nothing about how the screen handles the unplaced one.
                        $placed = !$volume->chance(0.1);
                        $this->persistBin(
                            $warehouse,
                            $code,
                            WarehouseLocation::TYPE_PICK,
                            $level === 3 ? 'Overstock' : 'Pick',
                            $aisle,
                            $sort += 10,
                            $placed ? $bay + 1 : null,
                            $placed ? 2 + $aisleIndex * 2 : null,
                        );
                        $existing[$code] = true;
                        --$wanted;
                        ++$created;
                    }
                }

                if ($wanted <= 0) {
                    break;
                }
            }

            $this->em->flush();
        }

        ($this->checkpoint)();

        return $created;
    }

    private function persistBin(Warehouse $warehouse, string $code, string $type, string $zone, ?string $aisle, int $sortKey, ?int $x, ?int $y): void
    {
        // The same setters BinController::save() chains.
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
    }

    /** @param array<string, true> $existing */
    private function freeCode(string $code, array $existing): string
    {
        [$prefix, $n] = explode('-', $code);
        for ($i = (int) $n; isset($existing[sprintf('%s-%02d', $prefix, $i)]); ++$i) {
        }

        return sprintf('%s-%02d', $prefix, $i);
    }

    /** @return list<float> the first warehouse takes half, the second a third, the rest share what is left */
    private function warehouseShares(int $warehouses): array
    {
        return match ($warehouses) {
            1 => [1.0],
            2 => [0.6, 0.4],
            default => array_merge([0.5, 0.3], array_fill(0, $warehouses - 2, 0.2 / ($warehouses - 2))),
        };
    }

    // ---------------------------------------------------------------------------------------------
    // Products

    /** @return list<int> the new dimensional product ids */
    private function seedProducts(int $count, DemoVolume $volume): array
    {
        $policies = [];
        foreach ($this->em->getRepository(TrackingPolicy::class)->findAll() as $policy) {
            $policies[$policy->getMode() . ($policy->requiresExpiry() ? '+expiry' : '')] ??= $policy;
        }

        $categories = [];
        foreach ($this->em->getRepository(ProductCategory::class)->findAll() as $category) {
            $categories[$category->getName()] = $category;
        }

        $skus = array_flip(array_map('strval', $this->connection->fetchFirstColumn('SELECT sku FROM product_core')));
        $names = array_flip(array_map('strval', $this->connection->fetchFirstColumn('SELECT name FROM product_core')));
        $next = 1 + (int) $this->connection->fetchOne(
            "SELECT COALESCE(MAX(CAST(SUBSTR(sku, 10) AS INTEGER)), 0) FROM product_core WHERE sku LIKE 'DEMO-VOL-%'",
        );

        $variants = [];
        foreach (self::CATALOGUE as [$pattern, $options, $policyName, $unit, $category, $costRange]) {
            foreach ($options as $option) {
                $variants[] = [sprintf($pattern, $option), $policyName, $unit, $category, $costRange];
            }
        }

        $ids = [];
        $series = 1;

        while (\count($ids) < $count) {
            foreach ($volume->sample($variants, \count($variants)) as [$name, $policyName, $unit, $categoryName, [$min, $max]]) {
                if (\count($ids) >= $count) {
                    break;
                }

                // Serial products are capped at one in ten: each unit is its own row, and a demo
                // where half the catalogue is serialised would be a demo of nothing else.
                if ($policyName === 'Serial' && $volume->chance(0.5)) {
                    continue;
                }

                $label = $series === 1 ? $name : sprintf('%s (Series %d)', $name, $series);
                if (isset($names[$label])) {
                    continue;
                }
                $names[$label] = true;

                do {
                    $sku = sprintf('DEMO-VOL-%05d', $next++);
                } while (isset($skus[$sku]));

                $policy = match ($policyName) {
                    'Serial' => $policies[TrackingPolicy::MODE_SERIAL] ?? null,
                    'Lot' => $policies[TrackingPolicy::MODE_LOT] ?? null,
                    'Lot + expiry' => $policies[TrackingPolicy::MODE_LOT . '+expiry'] ?? $policies[TrackingPolicy::MODE_LOT] ?? null,
                    default => $policies[TrackingPolicy::MODE_NONE] ?? null,
                };

                $cost = round($volume->float((float) $min, (float) $max), 2);
                $price = round($cost * $volume->float(1.45, 1.9), 2);

                $product = (new ProductCore())
                    ->setSku($sku)
                    ->setName($label)
                    ->setCategory($categories[$categoryName] ?? null)
                    ->setUnit($unit === 'EA' ? 'Each' : ucfirst(strtolower($unit)))
                    ->setCostPrice(number_format($cost, 2, '.', ''))
                    ->setDefaultPrice(number_format($price, 2, '.', ''))
                    ->setOriginalPrice(number_format($price, 2, '.', ''))
                    ->activate()
                    ->setVisible(true)
                    ->setSalesTaxCode('Taxable')
                    ->setShortDescription($label)
                    ->setSyncSource(DemoSeed::PRODUCT_SYNC_SOURCE)
                    ->setTrackingPolicy($policy)
                    ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);

                $this->em->persist($product);
                $this->em->flush();
                $ids[] = (int) $product->getId();
            }

            ++$series;
        }

        ($this->checkpoint)();

        return $ids;
    }

    // ---------------------------------------------------------------------------------------------
    // Opening stock

    /**
     * A `stock_found` count for every new product in every warehouse that carries it, into one to
     * three pick bins, dated about a year ago so every later movement follows it.
     *
     * Lot products get a real batch per bin (some already past expiry, so the expiring and expired
     * lot filters both have rows); one in six also gets a delivery counted with no batch code, which
     * lands on the policy's sentinel lot with `expect_resolution` set — the tracking worklist's
     * entire contents.
     *
     * @param list<int> $productIds
     * @param list<int> $warehouseIds
     */
    private function seedOpeningStock(array $productIds, array $warehouseIds, DemoVolume $volume): int
    {
        if (!$this->reasons->findActiveByCode('stock_found') instanceof InventoryAdjustmentReason) {
            return 0;
        }

        $groups = 0;

        foreach ($productIds as $n => $productId) {
            foreach ($warehouseIds as $index => $warehouseId) {
                // Everything is stocked in the first warehouse; the others carry a subset.
                if ($index > 0 && !$volume->chance($index === 1 ? 0.75 : 0.35)) {
                    continue;
                }

                $product = $this->em->find(ProductCore::class, $productId);
                $warehouse = $this->em->find(Warehouse::class, $warehouseId);
                $bins = $this->pickBins($warehouseId);
                if (!$product instanceof ProductCore || !$warehouse instanceof Warehouse || $bins === []) {
                    continue;
                }

                $policy = $product->getTrackingPolicy();
                $mode = $policy?->getMode() ?? TrackingPolicy::MODE_NONE;
                $daysAgo = $volume->int(330, 364);
                // Looked up per request rather than once: a checkpoint detaches everything.
                $reason = $this->reasons->findActiveByCode('stock_found');

                $request = MovementRequest::of(
                    $reason->movementType(),
                    $volume->operationId('open'),
                    'Opening count',
                    DemoSeed::ACTOR_LABEL,
                    null,
                    $volume->at($daysAgo),
                    $reason,
                );

                if ($mode === TrackingPolicy::MODE_SERIAL) {
                    $bin = $this->em->find(WarehouseLocation::class, $volume->pick($bins));
                    for ($unit = 1, $units = $volume->int(6, 18); $unit <= $units; ++$unit) {
                        $serial = sprintf('SN-%s-%s-%03d', substr($product->getSku(), -5), strtoupper(substr($volume->run, -4)), $index * 100 + $unit);
                        $request->receive($product, new DetailKey($warehouse, $bin, null, $serial), 1);
                    }
                } else {
                    foreach ($volume->sample($bins, $volume->int(1, 3)) as $binId) {
                        $bin = $this->em->find(WarehouseLocation::class, $binId);
                        $lot = $mode === TrackingPolicy::MODE_LOT ? $this->newLot($product, $volume, $daysAgo, (bool) $policy?->requiresExpiry()) : null;
                        $request->receive($product, new DetailKey($warehouse, $bin, $lot), $volume->int(60, 400));
                    }

                    if ($mode === TrackingPolicy::MODE_LOT && $volume->chance(0.17)) {
                        $bin = $this->em->find(WarehouseLocation::class, $volume->pick($bins));
                        $request->receive(
                            $product,
                            new DetailKey($warehouse, $bin, $this->sentinelLot($product, $policy?->getSentinelIn()), null, InventoryDetail::STATUS_AVAILABLE, true),
                            $volume->int(12, 48),
                        );
                    }
                }

                $this->movements->apply($request);
                ++$groups;
            }

            if (($n + 1) % 10 === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return $groups;
    }

    /** @return list<int> active pick-bin ids in one warehouse */
    private function pickBins(int $warehouseId): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM warehouse_location WHERE warehouse_id = ? AND type = 'pick' AND status = 'Active' ORDER BY sort_key",
            [$warehouseId],
        ));
    }

    /**
     * A new batch, created and flushed the way the adjustment screen's "new lot" field does it —
     * flushed first because the movement service looks the destination row up by query.
     */
    private function newLot(ProductCore $product, DemoVolume $volume, int $receivedDaysAgo, bool $withExpiry): InventoryLot
    {
        $lot = (new InventoryLot())
            ->setProduct($product)
            ->setCode(sprintf('L%s-%04d', (new \DateTimeImmutable(sprintf('-%d days', $receivedDaysAgo)))->format('ym'), $volume->int(1, 9999)))
            ->setExpiry($withExpiry ? new \DateTimeImmutable(sprintf('%+d days', $volume->int(-45, 700))) : null)
            ->setReceivedAt($volume->at($receivedDaysAgo))
            ->setSource($volume->pick(['Opening count', 'Found during cycle count', 'Transferred in from the old warehouse system']));

        $this->em->persist($lot);
        $this->em->flush();

        return $lot;
    }

    /** The `[PENDING]` lot, reused rather than remade — AdjustmentController::sentinelLot(). */
    private function sentinelLot(ProductCore $product, ?string $code): ?InventoryLot
    {
        if ($code === null || $code === '') {
            return null;
        }

        $expiry = new \DateTimeImmutable(DimensionalImportProvider::UNKNOWN_EXPIRY);
        foreach ($this->em->getRepository(InventoryLot::class)->findBy(['product' => $product, 'code' => $code], ['id' => 'ASC']) as $candidate) {
            if ($candidate->getExpiry()?->format('Y-m-d') === $expiry->format('Y-m-d')) {
                return $candidate;
            }
        }

        $lot = (new InventoryLot())->setProduct($product)->setCode($code)->setExpiry($expiry);
        $this->em->persist($lot);
        $this->em->flush();

        return $lot;
    }

    // ---------------------------------------------------------------------------------------------
    // Adjustments

    /**
     * $count adjustments across the year, one of each reason the adjustment screen offers plus bin
     * moves, in proportions a working warehouse would produce.
     *
     * A request the service refuses (a withdrawal the stock cannot cover, a reversal of something
     * already reversed) is skipped and counted, not forced. Those refusals happen before the
     * service opens its transaction, so a refusal costs nothing but the attempt.
     *
     * @param list<int> $productIds
     *
     * @return array{0: int, 1: int} applied, refused
     */
    private function seedAdjustments(int $count, array $productIds, DemoVolume $volume): array
    {
        $applied = 0;
        $refused = 0;

        for ($i = 0, $attempts = 0; $applied < $count && $attempts < $count * 3; ++$attempts) {
            $kind = $volume->weighted([
                'move' => 28, 'hold' => 12, 'release_hold' => 8, 'damaged' => 10, 'spoiled' => 5,
                'scrapped' => 5, 'lost' => 9, 'stock_found' => 13, 'reverse_write_off' => 10,
            ]);

            $product = $this->em->find(ProductCore::class, $volume->pick($productIds));
            if (!$product instanceof ProductCore) {
                continue;
            }

            try {
                $request = $this->adjustment($kind, $product, $volume);
                if ($request === null || $request->isEmpty()) {
                    continue;
                }
                $this->movements->apply($request);
                ++$applied;
            } catch (InsufficientStockException|\InvalidArgumentException) {
                ++$refused;
                if (!$this->em->isOpen()) {
                    throw new \RuntimeException('The entity manager was closed by a refused movement; stopping.');
                }
            }

            if ((++$i) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return [$applied, $refused];
    }

    private function adjustment(string $kind, ProductCore $product, DemoVolume $volume): ?MovementRequest
    {
        $daysAgo = $volume->int(0, 320);
        $at = $volume->at($daysAgo);
        $actor = DemoSeed::ACTOR_LABEL;

        if ($kind === 'move') {
            $rows = $this->availableRows($product);
            if ($rows === []) {
                return null;
            }
            $row = $volume->pick($rows);
            $targets = array_values(array_diff($this->pickBins((int) $row->getWarehouse()->getId()), [(int) $row->getLocation()?->getId()]));
            if ($targets === []) {
                return null;
            }
            $to = $this->em->find(WarehouseLocation::class, $volume->pick($targets));
            $from = new DetailKey($row->getWarehouse(), $row->getLocation(), $row->getLot(), $row->getSerial());
            $quantity = $row->getSerial() !== null ? 1 : min($row->getQuantity(), $volume->int(5, 60));

            return MovementRequest::of(InventoryMovementGroup::TYPE_MOVE, $volume->operationId('move'), $volume->pick([
                'Consolidating the aisle', 'Making room for a delivery', 'Moved to the forward pick face', 'Re-slotted by velocity',
            ]), $actor, null, $at)->move($product, $from, new DetailKey($row->getWarehouse(), $to, $row->getLot(), $row->getSerial()), $quantity);
        }

        $reason = $this->reasons->findActiveByCode($kind);
        if (!$reason instanceof InventoryAdjustmentReason) {
            return null;
        }

        $note = match ($kind) {
            'hold' => 'Held pending inspection — packaging damaged in transit',
            'release_hold' => 'Inspected and fit to sell',
            'damaged' => $volume->pick(['Forklift damage', 'Crushed carton', 'Dropped from the top shelf']),
            'spoiled' => 'Past its date on the shelf',
            'scrapped' => 'Disposed of per supplier instruction',
            'lost' => 'Cycle count shortfall',
            'stock_found' => 'Found during cycle count',
            default => 'Written off in error; the stock turned up',
        };

        $request = MovementRequest::of($reason->movementType(), $volume->operationId($kind), $note, $actor, null, $at, $reason);
        $rows = $this->availableRows($product);

        switch ($kind) {
            case 'stock_found':
                $warehouse = $rows !== [] ? $volume->pick($rows)->getWarehouse() : null;
                if (!$warehouse instanceof Warehouse) {
                    return null;
                }
                $bins = $this->pickBins((int) $warehouse->getId());
                if ($bins === []) {
                    return null;
                }
                $bin = $this->em->find(WarehouseLocation::class, $volume->pick($bins));
                $policy = $product->getTrackingPolicy();
                if ($policy?->getMode() === TrackingPolicy::MODE_SERIAL) {
                    $request->receive($product, new DetailKey($warehouse, $bin, null, sprintf('SN-%s-%s-F%03d', substr($product->getSku(), -5), strtoupper(substr($volume->run, -4)), $volume->int(1, 999))), 1);
                } else {
                    $lot = $policy?->getMode() === TrackingPolicy::MODE_LOT ? $this->newLot($product, $volume, $daysAgo, $policy->requiresExpiry()) : null;
                    $request->receive($product, new DetailKey($warehouse, $bin, $lot), $volume->int(2, 30));
                }

                return $request;

            case 'release_hold':
                $held = $this->details->namedSourceRows($product, InventoryDetail::STATUS_QUARANTINE);
                if ($held === []) {
                    return null;
                }
                $row = $volume->pick($held);
                $key = new DetailKey($row->getWarehouse(), $row->getLocation(), $row->getLot(), $row->getSerial(), InventoryDetail::STATUS_QUARANTINE);

                return $request->move($product, $key, $key->forStatus(InventoryDetail::STATUS_AVAILABLE), $row->getSerial() !== null ? 1 : $volume->int(1, $row->getQuantity()));

            case 'reverse_write_off':
                $candidates = array_filter(
                    $this->movementRows->reversibleWriteOffs($product, 20),
                    static fn (array $c): bool => $c['remaining'] > 0,
                );
                if ($candidates === []) {
                    return null;
                }
                $candidate = $volume->pick($candidates);
                /** @var InventoryMovement $original */
                $original = $candidate['movement'];

                return $this->reversals->addReversal($request, $original, $volume->int(1, $candidate['remaining']));

            default:
                // Outbound from available, which AutomaticSourcePicker answers the same way the
                // adjustment screen does when the policy does not track outbound identity.
                if ($rows === []) {
                    return null;
                }
                $warehouse = $volume->pick($rows)->getWarehouse();
                $available = $this->details->availableTotal($product, $warehouse);
                $quantity = min($available, $product->getTrackingPolicy()?->getMode() === TrackingPolicy::MODE_SERIAL ? 1 : $volume->int(1, 12));
                if ($quantity <= 0) {
                    return null;
                }

                return $this->picker->addWithdrawal($request, $product, $warehouse, $quantity, (string) $reason->getToStatus());
        }
    }

    /** @return list<InventoryDetail> available rows with stock, across warehouses */
    private function availableRows(ProductCore $product): array
    {
        return $this->details->namedSourceRows($product, InventoryDetail::STATUS_AVAILABLE);
    }

    // ---------------------------------------------------------------------------------------------
    // Lots and reorder rules

    /**
     * Batches announced ahead of their delivery, created the way LotController::save() creates one,
     * until this run has added $count lots in all. Receipts in the procurement step add more.
     *
     * @param list<int> $productIds
     */
    private function topUpLots(int $count, array $productIds, DemoVolume $volume): int
    {
        $lotProducts = array_map('intval', $this->connection->fetchFirstColumn(sprintf(
            "SELECT p.id FROM product_core p JOIN tracking_policy t ON t.id = p.tracking_policy_id
             WHERE t.mode = 'lot' AND p.id IN (%s)",
            implode(',', array_map('intval', $productIds)) ?: '0',
        )));
        $already = (int) $this->connection->fetchOne(sprintf(
            'SELECT COUNT(*) FROM inventory_lot WHERE product_id IN (%s)',
            implode(',', array_map('intval', $productIds)) ?: '0',
        ));

        if ($lotProducts === []) {
            return 0;
        }

        $created = 0;
        for ($i = $already; $i < $count; ++$i) {
            $product = $this->em->find(ProductCore::class, $volume->pick($lotProducts));
            if (!$product instanceof ProductCore) {
                continue;
            }

            $this->em->persist(
                (new InventoryLot())
                    ->setProduct($product)
                    ->setCode(sprintf('ASN-%s-%04d', date('ym'), $volume->int(1, 9999)))
                    ->setSource($volume->pick(['Advance ship notice', 'Supplier certificate of analysis', 'Pre-registered from the vendor portal']))
                    ->setExpiry($product->getTrackingPolicy()?->requiresExpiry() ? new \DateTimeImmutable(sprintf('+%d days', $volume->int(120, 900))) : null),
            );
            ++$created;

            if ($created % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return $created;
    }

    /**
     * Reorder points for the new products in each warehouse that stocks them, then for existing
     * products in the main warehouse — ReorderController::save() creates one per (product,
     * warehouse) pair and refuses a duplicate, so this skips pairs that already have a rule.
     *
     * @param list<int> $productIds
     * @param list<int> $warehouseIds
     */
    private function seedReorderRules(int $count, array $productIds, array $warehouseIds, DemoVolume $volume): int
    {
        $taken = [];
        foreach ($this->connection->fetchAllAssociative('SELECT product_id, warehouse_id FROM inventory_reorder_rule') as $row) {
            $taken[$row['product_id'] . ':' . $row['warehouse_id']] = true;
        }

        $pairs = array_map(
            static fn (array $r): array => [(int) $r['product_id'], (int) $r['warehouse_id']],
            $this->connection->fetchAllAssociative(sprintf(
                'SELECT DISTINCT product_id, warehouse_id FROM inventory_detail WHERE product_id IN (%s)',
                implode(',', array_map('intval', $productIds)) ?: '0',
            )),
        );

        // Existing simple products in the first warehouse make up the rest: a reorder rule does not
        // care how the stock is counted, and the low-stock screen reads both kinds.
        foreach ($volume->sample(array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT product_id FROM product_inventory WHERE warehouse_id = ? ORDER BY product_id",
            [$warehouseIds[0]],
        )), $count) as $productId) {
            $pairs[] = [$productId, $warehouseIds[0]];
        }

        $created = 0;
        foreach ($pairs as [$productId, $warehouseId]) {
            if ($created >= $count || isset($taken[$productId . ':' . $warehouseId])) {
                continue;
            }

            $product = $this->em->find(ProductCore::class, $productId);
            $warehouse = $this->em->find(Warehouse::class, $warehouseId);
            if (!$product instanceof ProductCore || !$warehouse instanceof Warehouse) {
                continue;
            }

            $point = $volume->int(4, 80);
            $this->em->persist(
                (new ProductReorderRule())
                    ->setProduct($product)
                    ->setWarehouse($warehouse)
                    ->setReorderPoint($point)
                    ->setReorderQuantity($volume->chance(0.8) ? $point * $volume->int(2, 6) : null)
                    ->setSafetyStockQuantity($volume->chance(0.6) ? (int) ceil($point / $volume->int(2, 4)) : null),
            );
            $taken[$productId . ':' . $warehouseId] = true;
            ++$created;

            if ($created % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return $created;
    }
}
