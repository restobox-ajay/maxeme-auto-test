<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Transfer;

use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Entity\TransferOrderLine;
use WarehouseOpsBundle\Repository\TransferOrderLineRepository;
use App\Service\Inventory\InventoryOperation;
use App\Service\Inventory\InventoryOperationContext;

/**
 * Dispatching and receiving a transfer (#552).
 *
 * ## Two movements, never one
 *
 * **Dispatch** moves stock out of its bin at the source and into `in_transit` owned by the SOURCE
 * warehouse. **Receipt** moves it from there into `available` at the destination. Between the two,
 * the stock is out of `SUM(available)` at the source — so it is not sellable there — and has not
 * arrived at the destination, so it is not sellable there either. It is still locatable, because the
 * row names the warehouse that shipped it.
 *
 * A single "move A to B" movement would put the stock on the destination's shelf the moment the
 * truck left, which is how a customer is sold something that is on a motorway.
 *
 * ## Transfers do not inherit FEFO
 *
 * #550's automatic picker takes earliest expiry first, and that is right for a customer shipment and
 * wrong here — sending the earliest-expiring stock to a slower site is how it expires there. So a
 * transfer line names its lot, and suggestLot() prefers the LONGEST-dated batch. The allocation rule
 * is a property of the operation, not a global setting, which is why the opposite choice lives here
 * rather than as a flag on the picker.
 *
 * ## Still not a second write path
 *
 * Both movements are ordinary MovementRequests handed to StockMovementService. What this class owns
 * is the document — which lines, which lots, how much left and how much arrived — and the two
 * buckets DERIVED from it, and nothing else.
 *
 * ## The two buckets a transfer owns (#584)
 *
 *     transfer_out(product, W) = SUM(quantity_dispatched) over lines whose transfer is FROM W
 *     transfer_in (product, W) = SUM(quantity_received)   over lines whose transfer is TO   W
 *
 *                    West (from), quantity 50      East (to), quantity 0
 *                    out   in   available          out   in   available
 *   in flight         10    0      40                0    0       0
 *   arrived           10    0      40                0   10      10
 *
 * Cumulative, so neither falls back when the goods land: what left is gone from the source for
 * good, and `quantity` cannot say so because it is the client's imported figure and not ours to
 * reduce. Recomputed from the lines rather than incremented, so they self-heal — the same
 * relationship `sales_hold` has to orders and `pending` has to invoices.
 *
 * This class writing `product_inventory` at all is worth stating plainly: the alternative was for
 * the movement layer to keep deriving `transfer_out` from the `in_transit` detail rows, which is the
 * bug #584 fixed. Those rows are consumed by the receipt. The bundle that owns the document is the
 * one that can answer what the document did, so it owns the columns that say so. It still does not
 * touch a hold bucket, a detail row's quantity or `quantity` itself.
 */
final class TransferOrderService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementService $movements,
        private readonly InventoryDetailRepository $details,
        private readonly TransferOrderLineRepository $transferLines,
        private readonly InventoryOperationContext $operations,
    ) {
    }

    /**
     * Dispatches every line that has a requested quantity left to send.
     *
     * @throws TransferException when the transfer is not in a state to dispatch, or the stock is not there
     */
    public function dispatch(TransferOrder $transfer, ?string $operationId, ?string $actor): InventoryMovementGroup
    {
        if ($transfer->getStatus() !== TransferOrder::STATUS_DRAFT) {
            throw new TransferException(sprintf('%s is %s; only a draft transfer can be dispatched.', $transfer->getNumber(), $transfer->getStatus()));
        }
        if ($transfer->getLines()->isEmpty()) {
            throw new TransferException(sprintf('%s has no lines, so there is nothing to dispatch.', $transfer->getNumber()));
        }

        $from = $transfer->getFromWarehouse();

        $request = MovementRequest::of(
            InventoryMovementGroup::TYPE_TRANSFER,
            $operationId === null ? null : $operationId . '-dispatch',
            sprintf('Transfer %s dispatched to %s', $transfer->getNumber(), $transfer->getToWarehouse()->getName()),
            $actor,
            $transfer->getNumber(),
        );

        /** @var list<array{line: TransferOrderLine, quantity: int}> $sending */
        $sending = [];

        foreach ($transfer->getLines() as $line) {
            $quantity = $line->getQuantityRequested() - $line->getQuantityDispatched();
            if ($quantity <= 0) {
                continue;
            }

            // A simple product's line has no lot/serial and $this->sourceBin() correctly returns
            // null for it (there are no InventoryDetail rows to pick a bin from) — the same
            // detail-free path StockMovementService::apply() already takes for a simple line, per
            // the simple-inventory bucket parity plan (2026-09-15). transfer_out/transfer_in are
            // summed from TransferOrderLine below, which was always detail-free, so nothing about a
            // simple product's line needs special-casing here any more.
            $sourceBin = $this->sourceBin($line, $from);

            $source = new DetailKey($from, $sourceBin, $line->getLot(), $line->getSerial(), InventoryDetail::STATUS_AVAILABLE);
            // `in_transit` is NOT terminal, so it keeps a bin if one is named. Naming none is right
            // here: stock on a truck is not in a bin, and giving it the bin it left would make the
            // source warehouse's bin map show pallets that are not there.
            $transit = new DetailKey($from, null, $line->getLot(), $line->getSerial(), InventoryDetail::STATUS_IN_TRANSIT);

            $request->move($line->getProduct(), $source, $transit, $quantity);
            $sending[] = ['line' => $line, 'quantity' => $quantity];
        }

        if ($sending === []) {
            throw new TransferException(sprintf('Every line on %s has already been dispatched.', $transfer->getNumber()));
        }

        // Same reasoning as receive() below. recomputeTransferBuckets() runs AFTER apply() has
        // returned, so it is outside the operation StockMovementService opened for the movements —
        // and it is the only thing that writes either transfer bucket (#584). Without an operation
        // out here, every `transfer_out`/`transfer_in` row in inventory_bucket_change_log would read
        // 'unattributed' with a null group, which is the gap #582 exists to close.
        return $this->operations->run(
            'transfer_dispatched',
            fn (InventoryOperation $operation): InventoryMovementGroup => $this->dispatchWithinOperation($request, $transfer, $sending, $operation),
        );
    }

    /**
     * The settling half of dispatch(), inside the ambient operation that names it.
     *
     * @param list<array{line: TransferOrderLine, quantity: int}> $sending
     */
    private function dispatchWithinOperation(MovementRequest $request, TransferOrder $transfer, array $sending, InventoryOperation $operation): InventoryMovementGroup
    {
        $group = $this->movements->apply($request);
        $operation->attachGroup($group);

        foreach ($sending as $entry) {
            $entry['line']->setQuantityDispatched($entry['line']->getQuantityDispatched() + $entry['quantity']);
        }

        $transfer->setStatus(TransferOrder::STATUS_DISPATCHED)->setDispatchedAt(new \DateTimeImmutable());
        $this->em->flush();

        // After the flush, never before: the buckets are recomputed by querying the lines, so the
        // quantities set just above have to be on disk for the sum to include them.
        $this->recomputeTransferBuckets($transfer);
        $this->em->flush();

        return $group;
    }

    /**
     * Receipts what actually arrived, line by line.
     *
     * @param array<int, int>          $received      quantity arriving, keyed by transfer_order_line id
     * @param array<int, ?WarehouseLocation> $intoBins destination bin per line id; null puts it away later
     *
     * @throws TransferException
     */
    public function receive(TransferOrder $transfer, array $received, array $intoBins, ?string $operationId, ?string $actor): InventoryMovementGroup
    {
        if ($transfer->getStatus() !== TransferOrder::STATUS_DISPATCHED) {
            throw new TransferException(sprintf('%s is %s; only a dispatched transfer can be received.', $transfer->getNumber(), $transfer->getStatus()));
        }

        $from = $transfer->getFromWarehouse();
        $to = $transfer->getToWarehouse();

        $request = MovementRequest::of(
            InventoryMovementGroup::TYPE_TRANSFER,
            $operationId === null ? null : $operationId . '-receive',
            sprintf('Transfer %s received at %s', $transfer->getNumber(), $to->getName()),
            $actor,
            $transfer->getNumber(),
        );

        /** @var list<array{line: TransferOrderLine, quantity: int}> $arriving */
        $arriving = [];

        foreach ($transfer->getLines() as $line) {
            $id = $line->getId();
            if ($id === null) {
                continue;
            }

            $quantity = max(0, min((int) ($received[$id] ?? 0), $line->outstandingInTransit()));
            if ($quantity <= 0) {
                continue;
            }

            // Out of the SOURCE warehouse's in-transit row — that is where dispatch put it, and it
            // is the only reason nothing falls into a gap between the two movements.
            $transit = new DetailKey($from, null, $line->getLot(), $line->getSerial(), InventoryDetail::STATUS_IN_TRANSIT);
            $shelf = new DetailKey($to, $intoBins[$id] ?? null, $line->getLot(), $line->getSerial(), InventoryDetail::STATUS_AVAILABLE);

            $request->move($line->getProduct(), $transit, $shelf, $quantity);
            $arriving[] = ['line' => $line, 'quantity' => $quantity];
        }

        if ($arriving === []) {
            throw new TransferException(sprintf('Nothing was entered as arriving on %s.', $transfer->getNumber()));
        }

        // recomputeTransferBuckets() below happens AFTER apply() has returned, so it is outside the
        // operation StockMovementService opened for the movements themselves. Without an operation
        // of its own, both transfer buckets — which nothing else in the app writes (#584) — would be
        // recorded as 'unattributed' with no group behind them, which is exactly the gap #582 exists
        // to close, reintroduced one layer up.
        //
        // So the receipt opens its own operation and attaches the group it gets back. The inner
        // movements keep their own 'movement_transfer' rows; these get 'transfer_received'; both
        // carry the same group_id, because InventoryOperationContext searches outwards for one.
        return $this->operations->run(
            'transfer_received',
            fn (InventoryOperation $operation): InventoryMovementGroup => $this->receiveWithinOperation($request, $transfer, $arriving, $operation),
        );
    }

    /**
     * The settling half of receive(), inside the ambient operation that names it.
     *
     * @param list<array{line: TransferOrderLine, quantity: int}> $arriving
     */
    private function receiveWithinOperation(MovementRequest $request, TransferOrder $transfer, array $arriving, InventoryOperation $operation): InventoryMovementGroup
    {
        $group = $this->movements->apply($request);
        $operation->attachGroup($group);

        foreach ($arriving as $entry) {
            $entry['line']->setQuantityReceived($entry['line']->getQuantityReceived() + $entry['quantity']);
        }

        // The DOCUMENT goes to `received` even when less arrived than left — the status, not the
        // bucket, which is a distinction worth making now that a transfer has buckets of its own.
        // The shortfall stays visible as the gap between
        // quantity_dispatched and quantity_received — that gap is stock lost in transit, and closing
        // the document is not the same as pretending it arrived. The units are still sitting on the
        // source warehouse's in_transit row, unsellable, which is exactly where lost stock belongs
        // until somebody writes it off deliberately.
        $transfer->setStatus(TransferOrder::STATUS_RECEIVED)->setReceivedAt(new \DateTimeImmutable());
        $this->em->flush();

        // NOTHING here touches `received` any more (#584). What used to happen is worth stating,
        // because the code that did it read as inevitable:
        //
        // `transfer_out` was a cached sum of the `in_transit` rows, so this receipt consuming them
        // dropped the source's bucket to zero and handed its availability straight back — for stock
        // that is now standing in another building. To hold the invariant together, the service
        // wrote the loss into the source's `received` as a negative, and the movement layer credited
        // the destination's `received` with the arrival.
        //
        // Both were WarehouseOpsBundle facts stored in a bucket BundleBucketAvailabilityGate gates
        // on ProcurementBundle. With procurement Inactive — a perfectly ordinary configuration —
        // the negative left the sum and the source read 50 of 50 sellable while its detail rows
        // said 40. `inventory_detail` was right the whole time; only the bucket arithmetic lied.
        //
        // Now both halves are recomputed from this document, below, and `received` means only what
        // #581 settled it means.
        $this->recomputeTransferBuckets($transfer);
        $this->em->flush();

        return $group;
    }

    /**
     * Rewrites both transfer buckets on both ends of this document, for every product on it (#584).
     *
     * DERIVED, never incremented. Each column is set to the whole sum over `transfer_order_line`:
     *
     *     transfer_out(product, W) = SUM(quantity_dispatched) over lines whose transfer is FROM W
     *     transfer_in (product, W) = SUM(quantity_received)   over lines whose transfer is TO   W
     *
     * The same relationship `sales_hold` has to orders and `pending` has to invoices, and it is the
     * point of the issue rather than a stylistic preference. An incremented counter is only ever as
     * correct as the last write that touched it: a crash between the movement and the increment, a
     * document edited by hand, a bucket zeroed by an import — each leaves a warehouse permanently
     * short or long with nothing to notice it. A recomputation is self-healing, so the next dispatch
     * or receipt of that product repairs whatever went wrong before it, and running it twice is the
     * same as running it once.
     *
     * BOTH ends every time, and both buckets on each end. A dispatch cannot change `transfer_in`
     * anywhere and a receipt cannot change the source's `transfer_out`, so half of these four writes
     * are always no-ops. Writing them anyway costs two queries and removes an entire class of
     * question — "which bucket does THIS half of the operation own" — that the old incremental code
     * had to answer correctly at every call site, and got wrong in exactly the way #584 describes.
     *
     * Per product rather than per line: two lines of the same product differing only by lot share
     * one ProductInventory row, and the sums above already cover every line of that product.
     */
    private function recomputeTransferBuckets(TransferOrder $transfer): void
    {
        /** @var array<int, ProductCore> $byProduct */
        $byProduct = [];

        foreach ($transfer->getLines() as $line) {
            $id = $line->getProduct()->getId();
            if ($id !== null) {
                $byProduct[$id] = $line->getProduct();
            }
        }

        foreach ([$transfer->getFromWarehouse(), $transfer->getToWarehouse()] as $warehouse) {
            foreach ($byProduct as $product) {
                $this->inventoryRow($product, $warehouse)
                    ->setTransferOutQuantity($this->transferLines->dispatchedFromTotal($product, $warehouse))
                    ->setTransferInQuantity($this->transferLines->receivedIntoTotal($product, $warehouse))
                    ->touch();
            }
        }
    }

    /**
     * The (product, warehouse) stock row, created if this warehouse has never held the product.
     *
     * The destination of the very first transfer into a new site has no row: nothing has ever been
     * received there and nothing has ever been imported for it. StockMovementService::syncCoreTotal()
     * creates one when a movement lands, and by the time this runs it has — but relying on that
     * ordering would make a missing row a silent wrong answer (`transfer_in` unrecorded, the shelf
     * holding stock nothing accounts for) rather than a loud one, so the row is ensured here too.
     *
     * Creating a stock row is not a second write path. Nothing about the physical total is decided
     * here: `quantity` stays 0, which is the truth — the client's system has never counted this
     * product at this site — and the arriving units are stated by `transfer_in` alone.
     */
    private function inventoryRow(ProductCore $product, Warehouse $warehouse): ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $warehouse,
        ]);

        if (!$row instanceof ProductInventory) {
            $row = (new ProductInventory())->setProduct($product)->setWarehouse($warehouse);
            $this->em->persist($row);
        }

        return $row;
    }

    /**
     * The lot a transfer should send: the **longest-dated** batch with stock at the source.
     *
     * The opposite of the customer-shipment rule, deliberately. Returns null when the product has no
     * lot-tracked stock there, which is a perfectly ordinary state — a product with no lots has
     * nothing to name.
     */
    public function suggestLot(ProductCore $product, Warehouse $from): ?InventoryLot
    {
        $best = null;

        foreach ($this->details->pickableRows($product, $from) as $row) {
            $lot = $row->getLot();
            if (!$lot instanceof InventoryLot) {
                continue;
            }

            if ($best === null) {
                $best = $lot;
                continue;
            }

            // An undated batch beats every dated one here: "does not expire" is the longest date
            // there is. That is the mirror image of pickableRows(), where an undated batch sorts
            // last because it is never the most urgent.
            if ($lot->getExpiry() === null) {
                $best = $lot;
                continue;
            }
            if ($best->getExpiry() !== null && $lot->getExpiry() > $best->getExpiry()) {
                $best = $lot;
            }
        }

        return $best;
    }

    /**
     * Which bin the units leave from.
     *
     * The line names a lot, not a bin, so the bin is whichever one currently holds that lot at the
     * source — read at dispatch time rather than frozen onto the line, because between drafting a
     * transfer and loading the truck somebody will have moved a pallet.
     */
    private function sourceBin(TransferOrderLine $line, Warehouse $from): ?WarehouseLocation
    {
        foreach ($this->details->pickableRows($line->getProduct(), $from) as $row) {
            if ($line->getLot() !== null && $row->getLot()?->getId() !== $line->getLot()->getId()) {
                continue;
            }
            if ($line->getSerial() !== null && $row->getSerial() !== $line->getSerial()) {
                continue;
            }

            return $row->getLocation();
        }

        return null;
    }
}
