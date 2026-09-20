<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Pick;

use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use App\Service\Inventory\OrderInventoryBucketResolver;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use WarehouseOpsBundle\Entity\PickList;
use WarehouseOpsBundle\Entity\PickTask;
use WarehouseOpsBundle\Repository\PickListRepository;
use WarehouseOpsBundle\Repository\PickTaskRepository;
use App\Service\Inventory\InventoryModeResolver;

/**
 * Turns the lines of one or more orders into a walking route (#552).
 *
 * ## It writes no stock and holds none
 *
 * Compiling touches `inventory_detail` only to READ it, and writes nothing but the tasks. There is
 * no allocation, no reservation and no soft claim, because #550's picker chooses its rows at the
 * moment a withdrawal is applied — earliest expiry, then lowest bin. A list compiled at 08:00 and
 * walked at 11:00 therefore draws on the stock that is there at 11:00, and the bin printed on the
 * task is a hint, not a promise. Freezing the choice at compile time would be a second allocation
 * layer disagreeing with the one that actually moves the stock.
 *
 * ## The route
 *
 * `warehouse_location.sort_key` ascending, **across every order on the list**. That is the whole
 * reason batch picking is faster than picking each order in turn: one walk of the aisles serving
 * four orders instead of four walks. The key is copied onto the task rather than joined at read
 * time, so renumbering a shelf mid-round cannot reshuffle a printout somebody is already holding.
 *
 * ## What it refuses to put on a list, and why each one
 *
 *  - **A line whose product is on `simple` inventory.** There is no breakdown to walk and
 *    StockMovementService would refuse the confirmation anyway. Picking one is a paper exercise.
 *  - **A line already on an open list.** Two rounds sent for the same units is how two pickers meet
 *    at one bin, and the plan is explicit that this job owes the second one a sensible message.
 *  - **A line whose warehouse is not this list's.** Region-to-warehouse resolution is core's
 *    (OrderInventoryBucketResolver::resolveLineWarehouse), used here rather than reimplemented so a
 *    round and the reservation ledger can never disagree about which building a line comes out of.
 *  - **A line with nothing left to owe** — fully invoiced, fully backordered, or quantity zero.
 *
 * Each refusal is reported back rather than silently skipped: a picker who is handed nine of the ten
 * lines they expected needs to know which one is missing and why.
 *
 * ## Two lines for the same SKU do not get sent to the same bin (#592)
 *
 * The refusal above stops the same LINE reaching two open rounds. It does nothing about two
 * DIFFERENT lines — two orders for the same SKU are two lines, and before #592 both were handed the
 * same bin, because suggestBin() was called once per line with no memory of what the line before it
 * had already been promised. Two pickers walk to bin A, the first takes everything, the second finds
 * it empty and types 0.
 *
 * So one compile now carries a running claim map — product, then bin, then units already promised to
 * earlier tasks in the same compile — and a bin whose available quantity is used up by those claims
 * is stepped over in favour of the next row #550's picker would take. It is still a hint and still
 * not a reservation: nothing is written to `inventory_detail`, and a list compiled at 08:00 and
 * walked at 11:00 still draws on the stock that is there at 11:00. What it buys is that the hints
 * printed by ONE compile do not contradict each other.
 *
 * When the claims use up every bin, the task keeps `pick_task.suggested_location_id` NULL and sorts
 * last. That is a legitimate answer — "we cannot tell you where to stand" — and #589 already made it
 * a safe one: a task naming no bin writes nothing off. Inventing a bin to fill the column would
 * trade an honest blank for a wrong address.
 *
 * The map lives for one compile only. Two SEPARATE compiles can still hand the same bin to two
 * rounds; closing that needs the open rounds' outstanding tasks read back out of `pick_task`, which
 * is a different change from this one.
 */
final class PickListCompiler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PickListRepository $lists,
        private readonly PickTaskRepository $tasks,
        private readonly InventoryDetailRepository $details,
        private readonly WarehouseFulfillmentRegionService $regions,
        private readonly InventoryModeResolver $inventoryModes,
    ) {
    }

    /**
     * Builds (but does not flush) a list for these orders.
     *
     * @param list<SalesOrder> $orders
     */
    public function compile(Warehouse $warehouse, array $orders, ?string $assignedTo = null): CompiledPickList
    {
        $list = (new PickList())
            ->setNumber($this->lists->nextNumber())
            ->setWarehouse($warehouse)
            ->setStatus(PickList::STATUS_DRAFT)
            ->setAssignedTo($assignedTo);

        $warehousesByRegion = $this->regions->warehousesByLowerRegionName();
        $skipped = [];

        // product => bin => units already promised to earlier tasks in THIS compile (#592). Declared
        // out here, not inside the order loop, because batch picking is the point: four orders for
        // the same SKU are walked together and must not all be sent to the same shelf.
        /** @var array<int, array<int, int>> $claimed */
        $claimed = [];

        foreach ($orders as $order) {
            $alreadyRouted = $this->tasks->orderLineIdsOnOpenLists($order);

            foreach ($order->getLines() as $line) {
                $product = $line->getProduct();

                if (!$product instanceof ProductCore) {
                    $skipped[] = sprintf('%s / %s — the product it named has been deleted.', $order->getOrderNumber(), $line->getName());
                    continue;
                }

                // Through the resolver, never the raw column: with InventoryDepthBundle Inactive a
                // stored `dimensional` must read as simple everywhere (#566).
                if (!$this->inventoryModes->isDimensional($product)) {
                    $skipped[] = sprintf(
                        '%s / %s — on simple inventory, so it has no bins to walk.',
                        $order->getOrderNumber(),
                        $line->getSku() ?: $line->getName(),
                    );
                    continue;
                }

                $lineWarehouse = OrderInventoryBucketResolver::resolveLineWarehouse(
                    $line->getLocation(),
                    $order->getFulfillmentRegion(),
                    $warehousesByRegion,
                );

                if (!$lineWarehouse instanceof Warehouse || $lineWarehouse->getId() !== $warehouse->getId()) {
                    $skipped[] = sprintf(
                        '%s / %s — fulfilled from %s, not %s.',
                        $order->getOrderNumber(),
                        $line->getSku() ?: $line->getName(),
                        $lineWarehouse?->getName() ?? 'nowhere resolvable',
                        $warehouse->getName(),
                    );
                    continue;
                }

                if ($line->getId() !== null && isset($alreadyRouted[$line->getId()])) {
                    $skipped[] = sprintf(
                        '%s / %s — already on an open pick list.',
                        $order->getOrderNumber(),
                        $line->getSku() ?: $line->getName(),
                    );
                    continue;
                }

                // The same arithmetic SalesOrderReservationSubject uses for what the order still
                // holds: the uninvoiced remainder, less whatever is backordered and therefore owed
                // by a promise rather than by stock. Read from the order rather than recomputed,
                // because a second implementation of "what does this line still owe" is a second
                // answer to a question the invoice lines already answer.
                //
                // An exact decimal string throughout, not `(int) round((float) …)`: that rounding
                // made a 0.4 remainder pick nothing and a 1.6 one pick two. suggestBin() still works
                // in whole physical units — a bin is claimed or it is not — so the exact remainder
                // is floored to whole units at this one boundary, never earlier.
                $remaining = QuantityScale::sub($order->uninvoicedQuantityFor($line), $line->getBackorderedUnits());
                $quantity = (int) floor(max(0.0, (float) $remaining));

                if ($quantity <= 0) {
                    continue;
                }

                [$suggested, $sortKey] = $this->suggestBin($product, $warehouse, $quantity, $claimed);

                $list->addTask(
                    (new PickTask())
                        ->setOrder($order)
                        ->setOrderLine($line)
                        ->setProduct($product)
                        ->setOrderNumber($order->getOrderNumber())
                        ->setSku($line->getSku() ?: $product->getSku())
                        ->setName($line->getName() !== '' ? $line->getName() : $product->getName())
                        ->setSuggestedLocation($suggested)
                        ->setSortKey($sortKey)
                        ->setQuantityRequested($quantity)
                );
            }
        }

        return new CompiledPickList($list, $skipped);
    }

    /** Persists a compiled list, tasks included. */
    public function persist(PickList $list): void
    {
        $this->em->persist($list);
        foreach ($list->getTasks() as $task) {
            $this->em->persist($task);
        }
    }

    /**
     * The first bin #550's picker would draw this product from that earlier tasks in this compile
     * have not already used up, and its route position.
     *
     * A task with no stock behind it sorts last rather than first: it has no bin, so there is
     * nowhere on the walk to put it, and burying it at the end of the round is better than sending
     * a picker to the front of the warehouse for something that is not there. It still appears on
     * the list, because "we owe this and there is none" is exactly what the picker has to report.
     *
     * ## What "used up" means, and why it is measured per BIN rather than per row (#592)
     *
     * pickableRows() returns one row per (bin, lot, serial), and a bin routinely holds several of
     * them. A picker sent to a bin walks to the bin, not to a row — so what decides whether it is
     * worth sending a second picker there is what the WHOLE bin still holds after the promises
     * already made. Two lots of five in bin A can answer a task for eight and a task for two; they
     * cannot answer two tasks for eight, and it is the bin total that says so.
     *
     * A bin with anything left is still offered, even if it cannot cover the whole line. That is
     * deliberate: a hint that says "start at A, there is some there" is worth more to a picker than
     * a blank, and the confirm screen is where the rest of the story gets told. What is ruled out is
     * only the case the issue is about — sending somebody to a bin that earlier tasks have already
     * spoken for down to zero.
     *
     * @param int                            $wanted  units this task will ask for, so the claim is
     *                                                the size of the promise rather than of the bin
     * @param array<int, array<int, int>>    $claimed product => bin => units already promised;
     *                                                READ AND WRITTEN, which is what makes the
     *                                                second call to this method know about the first
     *
     * @return array{0: ?WarehouseLocation, 1: int}
     */
    private function suggestBin(ProductCore $product, Warehouse $warehouse, int $wanted, array &$claimed): array
    {
        // Products reaching here are order lines' products and always have an id; the object handle
        // is the fallback so a fixture that never flushed cannot make two products share a key.
        $productKey = $product->getId() ?? -spl_object_id($product);
        $rows = $this->details->pickableRows($product, $warehouse);

        /** @var array<int, int> $inBin */
        $inBin = [];
        foreach ($rows as $row) {
            if (!$row instanceof InventoryDetail) {
                continue;
            }

            $location = $row->getLocation();
            if (!$location instanceof WarehouseLocation) {
                continue;
            }

            $binKey = $location->getId() ?? -spl_object_id($location);
            $inBin[$binKey] = ($inBin[$binKey] ?? 0) + $row->getQuantity();
        }

        foreach ($rows as $row) {
            if (!$row instanceof InventoryDetail) {
                continue;
            }

            $location = $row->getLocation();
            if (!$location instanceof WarehouseLocation) {
                continue;
            }

            $binKey = $location->getId() ?? -spl_object_id($location);
            $free = ($inBin[$binKey] ?? 0) - ($claimed[$productKey][$binKey] ?? 0);

            if ($free <= 0) {
                continue;
            }

            $claimed[$productKey][$binKey] = ($claimed[$productKey][$binKey] ?? 0) + min($free, max(0, $wanted));

            return [$location, $location->getSortKey()];
        }

        return [null, self::UNROUTED_SORT_KEY];
    }

    /** Far enough past any real `sort_key` that unbinned tasks land at the end of the walk. */
    public const UNROUTED_SORT_KEY = 1_000_000_000;
}
