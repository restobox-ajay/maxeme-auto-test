<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Command;

use App\Command\Demo\DemoSalesOrderFactory;
use App\Command\Demo\DemoSeed;
use App\Command\Demo\DemoVolume;
use App\Command\Demo\DemoVolumeCheckpoint;
use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\InsufficientStockException;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WarehouseOpsBundle\Entity\PickList;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Entity\TransferOrderLine;
use WarehouseOpsBundle\Pick\PickConfirmationException;
use WarehouseOpsBundle\Pick\PickConfirmationService;
use WarehouseOpsBundle\Pick\PickListCompiler;
use WarehouseOpsBundle\Repository\TransferOrderRepository;
use WarehouseOpsBundle\Transfer\TransferException;
use WarehouseOpsBundle\Transfer\TransferOrderService;

/**
 * WarehouseOpsBundle's step of `app:seed-demo-volume`: transfers between warehouses and pick rounds,
 * in every status, on the dimensional stock the depth and procurement steps put on the shelves.
 *
 * ## Every unit moves through the bundle's own services
 *
 * A transfer is built as TransferOrderController builds one (entity setters for the draft, the lot
 * TransferOrderService::suggestLot() proposes) and then dispatched and received by
 * TransferOrderService. A pick round is compiled by PickListCompiler from approved orders,
 * released and closed with the same entity calls PickListController makes, and every pick is
 * confirmed through PickConfirmationService. Nothing here writes a quantity — see
 * NoSecondWritePathTest, which holds this directory to that.
 *
 * ## Why a transfer line is sized from the first pickable row
 *
 * TransferOrderService::dispatch() draws a line's whole quantity from ONE row: the first pickable
 * row for that lot. A line larger than that row is refused by the movement service, which is a
 * real rule, so each line is sized to fit it rather than forced.
 *
 * The orders a pick round picks for are raised by core's DemoSalesOrderFactory, which approves them
 * and tags them with DemoSeed::DOCUMENT_NOTE; transfers and pick lists carry the same note, which is
 * what DemoDataCleaner deletes them by.
 */
#[AsCommand(
    name: 'app:seed-demo-volume:warehouse-ops',
    description: 'app:seed-demo-volume step: transfers and pick lists, with the orders they pick.',
)]
final class SeedWarehouseOpsVolumeCommand extends Command
{
    private const BATCH = 20;

    /** @var array<string, array<string, int>> */
    private array $outcomes = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly BundleStatusRepository $bundles,
        private readonly DemoVolumeCheckpoint $checkpoint,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly TransferOrderService $transfers,
        private readonly TransferOrderRepository $transferOrders,
        private readonly PickListCompiler $pickLists,
        private readonly PickConfirmationService $picks,
        private readonly DemoSalesOrderFactory $salesOrders,
        private readonly InventoryDetailRepository $details,
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
        $volume = new DemoVolume($input->getOption('run'), 'wops');
        $this->outcomes = [];

        foreach (['WarehouseOpsBundle', 'InventoryDepthBundle'] as $bundle) {
            if (!$this->bundles->isActive($bundle)) {
                $io->error(sprintf('%s is Inactive; its screens are 404 and there is nothing to seed.', $bundle));

                return Command::FAILURE;
            }
        }

        $stocked = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT DISTINCT warehouse_id FROM inventory_detail WHERE status = 'available' AND quantity > 0 ORDER BY warehouse_id",
        ));
        if ($stocked === []) {
            $io->error('No warehouse holds dimensional stock. Run app:seed-demo-volume:depth first.');

            return Command::FAILURE;
        }

        [$transfers, $refused] = $this->seedTransfers($count, $volume, $stocked);
        $io->writeln(sprintf('  %-30s %6d', 'transfer_order', $transfers));
        if ($refused > 0) {
            $io->writeln(sprintf('  %-30s %6d', '  refused by the service', $refused));
        }

        [$lists, $orders, $tasks] = $this->seedPickLists($count, $volume, $stocked);
        $io->writeln(sprintf('  %-30s %6d', 'sales_order (to pick)', $orders));
        $io->writeln(sprintf('  %-30s %6d', 'pick_list', $lists));
        $io->writeln(sprintf('  %-30s %6d', 'pick_task', $tasks));

        foreach ($this->outcomes as $table => $statuses) {
            ksort($statuses);
            $io->writeln(sprintf('  %-14s %s', $table, implode(', ', array_map(
                static fn (string $s, int $n): string => sprintf('%s %d', $s, $n),
                array_keys($statuses),
                $statuses,
            ))));
        }

        ($this->checkpoint)();

        return Command::SUCCESS;
    }

    // ---------------------------------------------------------------------------------------------
    // Transfers

    /**
     * @param list<int> $stocked warehouse ids holding dimensional stock
     *
     * @return array{0: int, 1: int} transfers created, dispatches/receipts the service refused
     */
    private function seedTransfers(int $count, DemoVolume $volume, array $stocked): array
    {
        $all = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT DISTINCT warehouse_id FROM warehouse_location WHERE status = 'Active' AND type = 'pick'",
        ));
        if (\count($all) < 2) {
            return [0, 0];
        }

        $created = 0;
        $refused = 0;

        for ($i = 0; $i < $count; ++$i) {
            $fromId = $volume->pick($stocked);
            $toId = $volume->pick(array_values(array_diff($all, [$fromId])));
            $from = $this->em->find(Warehouse::class, $fromId);
            $to = $this->em->find(Warehouse::class, $toId);
            if (!$from instanceof Warehouse || !$to instanceof Warehouse) {
                continue;
            }

            $target = $volume->weighted(['draft' => 18, 'dispatched' => 25, 'received' => 40, 'short' => 10, 'cancelled' => 7]);

            // Size the lines first, so a source with nothing to send never becomes an empty draft.
            $specs = [];
            foreach ($this->transferableProducts($fromId, $volume, $volume->int(1, 3)) as $productId) {
                $product = $this->em->find(ProductCore::class, $productId);
                if (!$product instanceof ProductCore) {
                    continue;
                }
                $lot = $this->transfers->suggestLot($product, $from);
                $fits = $this->firstRowQuantity($product, $from, $lot);
                if ($fits > 0) {
                    $specs[] = [$product, $lot, min($fits, $volume->int(4, 40))];
                }
            }
            if ($specs === []) {
                continue;
            }

            $transfer = (new TransferOrder())
                ->setNumber($this->transferOrders->nextNumber())
                ->setFromWarehouse($from)
                ->setToWarehouse($to)
                ->setStatus(TransferOrder::STATUS_DRAFT)
                ->setNotes(sprintf('%s %s', $volume->pick([
                    'Restocking the pick face.', 'Balancing stock ahead of the season.', 'On the Wednesday shuttle.',
                    'Customer pickup at the other site.', 'Weekly top-up.',
                ]), DemoSeed::DOCUMENT_NOTE));
            $this->em->persist($transfer);

            foreach ($specs as [$product, $lot, $quantity]) {
                $line = (new TransferOrderLine())
                    ->setProduct($product)
                    ->setLot($lot)
                    ->setSku($product->getSku())
                    ->setName($product->getName())
                    ->setQuantityRequested($quantity);
                $transfer->addLine($line);
                $this->em->persist($line);
            }

            // Flushed per transfer: nextNumber() reads MAX(id), so two unflushed drafts would share
            // a number and trip the UNIQUE constraint.
            $this->em->flush();
            ++$created;

            try {
                if ($target === 'cancelled') {
                    $transfer->setStatus(TransferOrder::STATUS_CANCELLED);
                    $this->em->flush();
                } elseif ($target !== 'draft') {
                    $operation = $volume->operationId('transfer');
                    $this->transfers->dispatch($transfer, $operation, DemoSeed::ACTOR_LABEL);

                    if ($target === 'received' || $target === 'short') {
                        $received = [];
                        $bins = [];
                        $destinationBins = $this->pickBinIds($toId);
                        foreach ($transfer->getLines() as $line) {
                            $inTransit = $line->outstandingInTransit();
                            $received[(int) $line->getId()] = $target === 'short' ? max(1, $inTransit - $volume->int(1, 3)) : $inTransit;
                            $bins[(int) $line->getId()] = $destinationBins === [] ? null : $this->em->find(WarehouseLocation::class, $volume->pick($destinationBins));
                        }
                        $this->transfers->receive($transfer, $received, $bins, $operation, DemoSeed::ACTOR_LABEL);
                    }
                }
            } catch (TransferException|InsufficientStockException|\InvalidArgumentException) {
                ++$refused;
                if (!$this->em->isOpen()) {
                    throw new \RuntimeException('The entity manager was closed by a refused transfer; stopping.');
                }
            }

            $this->tally('transfer_order', $transfer->getStatus());

            if (($i + 1) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return [$created, $refused];
    }

    /**
     * Dimensional products with available stock at a warehouse, serial-tracked ones excluded: a
     * serial line names one unit, and transferring units one serial at a time is a different demo.
     *
     * @return list<int>
     */
    private function transferableProducts(int $warehouseId, DemoVolume $volume, int $howMany): array
    {
        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT d.product_id FROM inventory_detail d
               JOIN product_core p ON p.id = d.product_id
               LEFT JOIN tracking_policy t ON t.id = p.tracking_policy_id
             WHERE d.warehouse_id = ? AND d.status = 'available' AND d.quantity > 0 AND d.serial IS NULL
               AND COALESCE(t.mode, 'none') <> 'serial'
             GROUP BY d.product_id",
            [$warehouseId],
        ));

        return $volume->sample($ids, $howMany);
    }

    /** What the row dispatch() will draw from can supply: the first pickable row carrying this lot. */
    private function firstRowQuantity(ProductCore $product, Warehouse $from, ?InventoryLot $lot): int
    {
        foreach ($this->details->pickableRows($product, $from) as $row) {
            if ($row->getSerial() === null && $row->getLot()?->getId() === $lot?->getId()) {
                return $row->getQuantity();
            }
        }

        return 0;
    }

    /** @return list<int> */
    private function pickBinIds(int $warehouseId): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM warehouse_location WHERE warehouse_id = ? AND status = 'Active' AND type = 'pick' ORDER BY sort_key",
            [$warehouseId],
        ));
    }

    // ---------------------------------------------------------------------------------------------
    // Pick lists

    /**
     * @param list<int> $stocked
     *
     * @return array{0: int, 1: int, 2: int} pick lists, orders raised for them, tasks
     */
    private function seedPickLists(int $count, DemoVolume $volume, array $stocked): array
    {
        $actor = DocumentActor::automation(DemoSeed::ACTOR_LABEL);
        $companies = array_map('intval', $this->connection->fetchFirstColumn(sprintf(
            "SELECT id FROM company WHERE %s AND status = 'Active' ORDER BY id",
            DemoSeed::WHERE_COMPANY,
        )));
        if ($companies === []) {
            return [0, 0, 0];
        }

        // Only warehouses that serve a region can be picked for: an order names a region, and the
        // region is what resolves the building that ships it. And only those with a staging bin,
        // without which a round cannot be released.
        $pickable = [];
        foreach ($stocked as $warehouseId) {
            $warehouse = $this->em->find(Warehouse::class, $warehouseId);
            $region = $warehouse instanceof Warehouse ? $this->warehouses->regionForWarehouse($warehouse) : null;
            $staging = (int) $this->connection->fetchOne(
                "SELECT id FROM warehouse_location WHERE warehouse_id = ? AND type = 'staging' AND status = 'Active' ORDER BY id LIMIT 1",
                [$warehouseId],
            );
            if ($region !== null && $staging > 0) {
                $pickable[$warehouseId] = [$region->getName(), $staging];
            }
        }
        if ($pickable === []) {
            return [0, 0, 0];
        }

        $lists = 0;
        $ordersRaised = 0;
        $tasks = 0;

        for ($i = 0; $i < $count; ++$i) {
            $warehouseId = $volume->pick(array_keys($pickable));
            [$regionName, $stagingId] = $pickable[$warehouseId];

            $orderIds = [];
            for ($o = 0, $n = $volume->weightedInt([1 => 75, 2 => 25]); $o < $n; ++$o) {
                $lines = $this->orderLines($warehouseId, $volume);
                $company = $this->em->find(Company::class, $volume->pick($companies));
                if ($lines === [] || !$company instanceof Company) {
                    continue;
                }
                $order = $this->salesOrders->approvedOrder(
                    $company,
                    $regionName,
                    $lines,
                    sprintf('WH-%05d', $volume->int(10000, 99999)),
                    $volume->int(0, 21),
                    $actor,
                );
                $orderIds[] = (int) $order->getId();
                ++$ordersRaised;
            }
            if ($orderIds === []) {
                continue;
            }

            $warehouse = $this->em->find(Warehouse::class, $warehouseId);
            $orders = array_values(array_filter(array_map(fn (int $id) => $this->em->find(SalesOrder::class, $id), $orderIds)));
            $compiled = $this->pickLists->compile($warehouse, $orders, $volume->pick(DemoVolume::RECEIVERS));
            if ($compiled->isEmpty()) {
                continue;
            }

            $list = $compiled->list;
            $list->setNotes(DemoSeed::DOCUMENT_NOTE);
            $this->pickLists->persist($list);
            $this->em->flush();
            ++$lists;
            $tasks += $list->getTasks()->count();

            $target = $volume->weighted(['draft' => 25, 'released' => 25, 'closed' => 40, 'cancelled' => 10]);

            if ($target === 'cancelled') {
                $list->setStatus(PickList::STATUS_CANCELLED)->setClosedAt(new \DateTimeImmutable());
                $this->em->flush();
            } elseif ($target !== 'draft') {
                // PickListController::release() — a staging bin in this warehouse, then released.
                $list
                    ->setStagingLocation($this->em->find(WarehouseLocation::class, $stagingId))
                    ->setStatus(PickList::STATUS_RELEASED)
                    ->setReleasedAt(new \DateTimeImmutable());
                $this->em->flush();

                $confirmAll = $target === 'closed';
                foreach ($list->getTasks() as $task) {
                    if (!$confirmAll && $volume->chance(0.5)) {
                        continue; // a round still in progress: some tasks not yet walked
                    }
                    $requested = $task->getQuantityRequested();
                    $picked = $volume->chance(0.1) ? max(0, $requested - 1) : $requested;

                    try {
                        $this->picks->confirm($task, $picked, $volume->operationId('pick'), DemoSeed::ACTOR_LABEL);
                    } catch (PickConfirmationException|InsufficientStockException|\InvalidArgumentException) {
                        if (!$this->em->isOpen()) {
                            throw new \RuntimeException('The entity manager was closed by a refused pick; stopping.');
                        }
                    }
                }

                if ($confirmAll) {
                    $list->setStatus(PickList::STATUS_CLOSED)->setClosedAt(new \DateTimeImmutable());
                    $this->em->flush();
                }
            }

            $this->tally('pick_list', $list->getStatus());

            if (($i + 1) % 10 === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return [$lists, $ordersRaised, $tasks];
    }

    /**
     * One to three lines of dimensional, non-serial products this warehouse can actually supply,
     * each for a quantity well inside what is on its shelves.
     *
     * @return array<string, int> sku => units
     */
    private function orderLines(int $warehouseId, DemoVolume $volume): array
    {
        $candidates = $this->connection->fetchAllAssociative(
            "SELECT p.sku, SUM(d.quantity) AS available FROM inventory_detail d
               JOIN product_core p ON p.id = d.product_id
               LEFT JOIN tracking_policy t ON t.id = p.tracking_policy_id
             WHERE d.warehouse_id = ? AND d.status = 'available' AND d.serial IS NULL AND d.location_id IS NOT NULL
               AND COALESCE(t.mode, 'none') <> 'serial'
             GROUP BY p.sku HAVING SUM(d.quantity) >= 20",
            [$warehouseId],
        );

        $lines = [];
        foreach ($volume->sample($candidates, $volume->int(1, 3)) as $row) {
            $lines[(string) $row['sku']] = $volume->int(1, min(12, intdiv((int) $row['available'], 10)));
        }

        return $lines;
    }

    private function tally(string $table, string $status): void
    {
        $this->outcomes[$table][$status] = ($this->outcomes[$table][$status] ?? 0) + 1;
    }
}
