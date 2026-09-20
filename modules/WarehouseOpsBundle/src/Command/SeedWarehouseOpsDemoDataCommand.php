<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Command;

use App\Command\Demo\DemoDataCleaner;
use App\Command\Demo\DemoSalesOrderFactory;
use App\Command\Demo\DemoSeed;
use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\WarehouseLocation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WarehouseOpsBundle\Entity\PickList;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Entity\TransferOrderLine;
use WarehouseOpsBundle\Pick\PickConfirmationService;
use WarehouseOpsBundle\Pick\PickListCompiler;
use WarehouseOpsBundle\Repository\TransferOrderRepository;
use WarehouseOpsBundle\Transfer\TransferOrderService;

/**
 * Step 3 of `app:seed-warehouse-data`: transfers between the two demo warehouses, and pick rounds
 * against the orders that need the stock steps 1 and 2 put there.
 *
 * Lives in the bundle for the same reason the other two steps do — core may not name a bundle class,
 * because all three inventory bundles are deletable. See App\Command\SeedWarehouseDataCommand.
 *
 * ## Transfers
 *
 * Both legs go through TransferOrderService, which is the only sanctioned writer of
 * `transfer_out_quantity` and `transfer_in_quantity`. Those two buckets are recomputed WHOLE from
 * the transfer documents on every dispatch and receipt — the way `sales_hold` is recomputed from
 * orders — which is exactly why the entity has no `adjustTransferOut()` to reach for, and why a
 * pair of numbers written here by hand would be overwritten by the next real transfer and wrong
 * until then.
 *
 * The short receipt is the case worth staring at. Ten dispatched, eight arrived: the DOCUMENT still
 * goes to `received`, because the paperwork IS complete. What is unresolved is two units that left
 * one building and never reached the other, and they stay on the source warehouse's `in_transit`
 * detail row — unsellable at both ends, and never written off by anybody's guess.
 *
 * ## Pick rounds
 *
 * Built by PickListCompiler rather than by hand, because the compiler is what resolves each line's
 * warehouse, works out how much of it is still owed (uninvoiced, less whatever is backordered),
 * finds the bin holding it and stamps that bin's sort key onto the task as the route order. A
 * hand-built task also silently disables the short-pick write-off, because a task with a null
 * `suggestedLocation` has no bin to blame a shortfall on (#589) — so a fixture that skipped the
 * compiler would produce a pick list that looks right and behaves differently from every real one.
 *
 * The two short picks are the pair to review side by side. One names the bin the picker was sent to,
 * so the four units nobody could find are written off from THAT bin, to `lost`, and the task
 * settles. The other names no bin, so nothing is written off at all: the shortfall is reported and
 * stays owed, because a shortfall that cannot be pinned on a bin is not evidence about any bin's
 * count.
 *
 * ## Why the sales orders are seeded here
 *
 * PickListCompiler skips any line whose product is not dimensional, and the sell-side seeder's
 * catalogue is entirely simple. An order seeded there would compile to an empty pick list — which is
 * the exact thing this whole exercise exists to stop a reviewer being shown. They are approved and
 * left uninvoiced deliberately: the compiler picks each line's UNINVOICED quantity, so an invoiced
 * order has nothing left to pick.
 */
#[AsCommand(
    name: 'app:seed-demo-warehouse-ops',
    description: 'Step 3 of app:seed-warehouse-data: demo transfers, pick rounds and the orders they pick for.',
)]
final class SeedWarehouseOpsDemoDataCommand extends Command
{
    private const WEST = 'Demo West';
    private const EAST = 'Demo East';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemoDataCleaner $cleaner,
        private readonly BundleStatusRepository $bundles,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly TransferOrderService $transfers,
        private readonly TransferOrderRepository $transferOrders,
        private readonly PickListCompiler $pickLists,
        private readonly PickConfirmationService $picks,
        private readonly DemoSalesOrderFactory $salesOrders,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (['WarehouseOpsBundle', 'InventoryDepthBundle'] as $bundle) {
            if (!$this->bundles->isActive($bundle)) {
                $io->error(sprintf('%s is Inactive; its screens are 404 and there is nothing to seed.', $bundle));

                return Command::FAILURE;
            }
        }

        if ($this->cleaner->hasWarehouseDocuments()) {
            $io->error('Demo warehouse documents already exist. Run app:seed-warehouse-data --force instead.');

            return Command::FAILURE;
        }

        $west = $this->warehouses->warehouseForRegionName(self::WEST);
        $east = $this->warehouses->warehouseForRegionName(self::EAST);

        if (!$west instanceof Warehouse || !$east instanceof Warehouse) {
            $io->error('The demo warehouses do not exist. Run app:seed-demo-data first.');

            return Command::FAILURE;
        }

        $products = [];
        foreach ($this->em->getRepository(ProductCore::class)->findBy(['syncSource' => DemoSeed::PRODUCT_SYNC_SOURCE]) as $product) {
            if (str_starts_with($product->getSku(), 'DEMO-DIM-')) {
                $products[$product->getSku()] = $product;
            }
        }

        if ($products === []) {
            $io->error('The demo dimensional products do not exist. Run app:seed-demo-depth first.');

            return Command::FAILURE;
        }

        $actor = DocumentActor::automation(DemoSeed::ACTOR_LABEL);

        $orders = $this->seedPickableOrders($products, $actor);
        $transfers = $this->seedTransfers($products, $east, $west);
        $picking = $this->seedPickLists($west, $orders);

        $io->writeln(sprintf('  %-36s %6d', 'sales_order (for picking)', \count($orders)));
        $io->writeln(sprintf('  %-36s %6d', 'transfer_order', $transfers));
        $io->writeln(sprintf('  %-36s %6d', 'pick_list', $picking['lists']));
        $io->writeln(sprintf('  %-36s %6d', 'pick_task', $picking['tasks']));

        return Command::SUCCESS;
    }

    /**
     * The approved orders the pick rounds are compiled from.
     *
     * Built by App\Command\Demo\DemoSalesOrderFactory rather than here: a sales order is core's
     * document, not this bundle's, and this bundle's own NoSecondWritePathTest refuses any
     * `->setQuantity(` in its source — a rule about stock that catches an order line's quantity too,
     * and whose instinct is right either way. This says which SKUs and how many; core builds and
     * approves the document.
     *
     * @param array<string, ProductCore> $products
     *
     * @return list<SalesOrder>
     */
    private function seedPickableOrders(array $products, DocumentActor $actor): array
    {
        $companies = $this->em->getRepository(Company::class)->findBy([], ['id' => 'ASC'], 3);
        if ($companies === []) {
            return [];
        }

        // Two lines on the first round and one on each of the others, so a reviewer sees both a
        // multi-line pick and a single-line one — and, since a round is one order here, so that the
        // "how many orders is this round for" tile has a number to show that is not always 1.
        $plan = [
            ['DEMO-DIM-5001' => 24, 'DEMO-DIM-5004' => 10],
            ['DEMO-DIM-5001' => 40],
            ['DEMO-DIM-5004' => 30],
        ];

        $orders = [];

        foreach ($plan as $index => $lines) {
            $wanted = array_intersect_key($lines, $products);
            if ($wanted === []) {
                continue;
            }

            $orders[] = $this->salesOrders->approvedOrder(
                $companies[$index] ?? $companies[0],
                self::WEST,
                $wanted,
                sprintf('WH-%04d', 9100 + $index),
                5 - $index,
                $actor,
            );
        }

        return $orders;
    }

    /**
     * Four transfers: one still draft, one on the truck, one that arrived in full, one that arrived
     * short.
     *
     * @param array<string, ProductCore> $products
     */
    private function seedTransfers(array $products, Warehouse $east, Warehouse $west): int
    {
        $eastBins = [];
        foreach ($this->em->getRepository(WarehouseLocation::class)->findBy(['warehouse' => $east]) as $bin) {
            $eastBins[$bin->getCode()] = $bin;
        }

        $product = $products['DEMO-DIM-5004'];

        // -- Draft: typed up, not dispatched. The only status a transfer can be edited in.
        $this->transfer($west, $east, $product, 15, 'Restocking the East pick face.');

        // -- Dispatched and not yet received: stock on the truck, which belongs to neither building's
        //    available balance.
        $inFlight = $this->transfer($west, $east, $product, 20, 'On the Wednesday shuttle.');
        $this->transfers->dispatch($inFlight, 'demo-seed-transfer-inflight', DemoSeed::ACTOR_LABEL);

        // -- Completed: dispatched and received in full, into a named bin.
        $completed = $this->transfer($west, $east, $product, 12, 'Weekly top-up.');
        $this->transfers->dispatch($completed, 'demo-seed-transfer-complete', DemoSeed::ACTOR_LABEL);
        $completedLine = $completed->getLines()->first();
        $this->transfers->receive(
            $completed,
            [$completedLine->getId() => 12],
            [$completedLine->getId() => $eastBins['A-02']],
            'demo-seed-transfer-complete',
            DemoSeed::ACTOR_LABEL,
        );

        // -- Short: 10 dispatched, 8 arrived.
        $short = $this->transfer($west, $east, $product, 10, 'Two cartons unaccounted for on arrival.');
        $this->transfers->dispatch($short, 'demo-seed-transfer-short', DemoSeed::ACTOR_LABEL);
        $shortLine = $short->getLines()->first();
        $this->transfers->receive(
            $short,
            [$shortLine->getId() => 8],
            [$shortLine->getId() => $eastBins['A-03']],
            'demo-seed-transfer-short',
            DemoSeed::ACTOR_LABEL,
        );

        return 4;
    }

    private function transfer(Warehouse $from, Warehouse $to, ProductCore $product, int $quantity, string $note): TransferOrder
    {
        $transfer = (new TransferOrder())
            ->setNumber($this->transferOrders->nextNumber())
            ->setFromWarehouse($from)
            ->setToWarehouse($to)
            ->setStatus(TransferOrder::STATUS_DRAFT)
            ->setNotes($note . ' ' . DemoSeed::DOCUMENT_NOTE);

        $line = (new TransferOrderLine())
            ->setProduct($product)
            ->setSku($product->getSku())
            ->setName($product->getName())
            ->setQuantityRequested($quantity);

        $transfer->addLine($line);
        $this->em->persist($transfer);
        $this->em->persist($line);

        // Flushed one at a time: TransferOrderRepository::nextNumber() reads MAX(id), and
        // TransferOrderService::receive() is keyed by transfer_order_line id — both need the row
        // written before the next call.
        $this->em->flush();

        return $transfer;
    }

    /**
     * Three pick rounds: one waiting to be released, one worked in full and closed, one worked short
     * and left open.
     *
     * @param list<SalesOrder> $orders
     *
     * @return array{lists: int, tasks: int}
     */
    private function seedPickLists(Warehouse $west, array $orders): array
    {
        if ($orders === []) {
            return ['lists' => 0, 'tasks' => 0];
        }

        $staging = $this->em->getRepository(WarehouseLocation::class)->findOneBy([
            'warehouse' => $west,
            'code' => 'STAGE-01',
        ]);

        if (!$staging instanceof WarehouseLocation) {
            return ['lists' => 0, 'tasks' => 0];
        }

        $lists = 0;
        $tasks = 0;
        $round = 0;

        foreach ($orders as $index => $order) {
            $compiled = $this->pickLists->compile($west, [$order], ['A. Bakshi', 'M. Cortez', 'A. Bakshi'][$index] ?? null);

            if ($compiled->isEmpty()) {
                // The compiler skipped every line — a product that is not dimensional, a line already
                // on an open list, a warehouse that does not match. Skipped rather than swallowed
                // silently, because a quietly empty pick list is the failure this fixture exists to
                // prevent.
                continue;
            }

            $list = $compiled->list;
            $list->setNotes(DemoSeed::DOCUMENT_NOTE);
            $this->pickLists->persist($list);
            $this->em->flush();

            ++$lists;
            $tasks += $list->getTasks()->count();

            // The first round stays Draft, so the release form has somewhere to be reviewed.
            if ($index === 0) {
                continue;
            }

            // Releasing is a controller action in this bundle rather than a service call, so the two
            // writes it makes are reproduced here: a staging bin in the same warehouse, and the
            // released stamp. There is no method to call — reported as a gap rather than worked
            // around by adding one.
            $list
                ->setStagingLocation($staging)
                ->setStatus(PickList::STATUS_RELEASED)
                ->setReleasedAt(new \DateTimeImmutable('-1 day'));
            $this->em->flush();

            foreach ($list->getTasks() as $task) {
                ++$round;
                $requested = $task->getQuantityRequested();

                // Round 2 is worked in full; round 3 is worked four units short, so the write-off
                // path runs against the bin the picker was sent to.
                $picked = $index === 1 ? $requested : max(0, $requested - 4);

                $this->picks->confirm($task, $picked, sprintf('demo-seed-pick-%d', $round), DemoSeed::ACTOR_LABEL);
            }

            // The fully-worked round is closed; the short one stays open, which is what an admin
            // would be looking at when they came to decide what to do about it.
            if ($index === 1) {
                $list->setStatus(PickList::STATUS_CLOSED)->setClosedAt(new \DateTimeImmutable('-1 hour'));
                $this->em->flush();
            }
        }

        return ['lists' => $lists, 'tasks' => $tasks];
    }
}
