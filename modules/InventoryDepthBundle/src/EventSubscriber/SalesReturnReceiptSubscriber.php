<?php

declare(strict_types=1);

namespace InventoryDepthBundle\EventSubscriber;

use App\Entity\ProductCore;
use App\Entity\SalesReturn;
use App\Entity\Warehouse;
use App\Event\SalesReturnReceivedEvent;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\QuantityScale;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Goods physically arriving back against an authorised RMA (#596).
 *
 * CreditMemoRestockSubscriber's sibling, and deliberately its near-twin: the same `null → returned`
 * movement, the same one-group-per-document rule, the same derived idempotency key. Two subscribers
 * rather than one because the two events mean genuinely different things — one is "a credit was
 * issued and it says the goods came back", the other is "the goods came back" — and because exactly
 * one of them may fire for any given units. CreditMemo::setRestock() enforces that on the way in,
 * refusing to tick restock on a note that carries a `sales_return_id`; if both ever ran for the same
 * units, `quarantine_quantity` would read double.
 *
 * ## Where the units land, and why not on the shelf
 *
 * In `returned`, which sums into `quarantine_quantity` and is therefore NOT sellable. This is the
 * point of the status existing separately from `available`. A receiving clerk opening a box is not
 * the business deciding those goods may be sold to somebody else — the RMA does not know whether
 * the item is faulty, and `sales_return_line.disposition` is a note about what the receiver SAW, not
 * an instruction this subscriber reads. Somebody inspects, and the units move on from there.
 *
 * `returned` keeps its own name rather than being written as `quarantine`, so a returned unit stays
 * distinguishable from one that failed inspection — same bucket, different provenance.
 *
 * ## `received_quantity` MUST NOT MOVE, and this is where that is guaranteed
 *
 * The movement is `null → returned`: the units ENTER the ledger, from outside, into a non-available
 * status. MovementRequest::receivedDelta() only counts crossings where one side is `available` in
 * this warehouse, so neither side of this movement matches and the delta is zero. Nothing in
 * MovementRequest needed changing for #596 and nothing was changed — the crossing #586 established
 * already answers zero, and SalesReturnReceiptTest asserts the column directly rather than trusting
 * that it still does.
 *
 *     receive 10      received 10  approved 0  quarantine 0   available 10
 *     sell + ship 2   received 10  approved 2  quarantine 0   available  8
 *     return 2        received 10  approved 2  quarantine 2   available  6
 *     credit applied  received 10  approved 0  quarantine 2   available  8   correct
 *
 * Ten physical units, two held pending inspection, eight sellable. `received` never moves, because
 * the shipment never decremented it either — the invoice's `approved` hold absorbed the departure,
 * and this app has no mechanism that decrements Starting Inventory on shipment. Crediting `received`
 * on the way back would count those units as having arrived twice.
 *
 * The `from` side is deliberately `null` rather than `sold`. Nothing in the invoice lifecycle writes
 * `sold` detail rows — StockMovementService's own docblock says so, and #552 is where that would be
 * wired — so drawing from a `sold` row would throw InsufficientStockException on every instance that
 * has not manually shipped through the movement layer. `null` says what actually happened: goods the
 * ledger was not tracking have arrived in the building.
 *
 * ## The warehouse is read off the document, not resolved from a region name
 *
 * SalesReturn::receive() takes the warehouse as a parameter and the transition refuses without one,
 * so by the time this runs there is nothing to resolve and nothing to guess. That is the one place
 * this subscriber deliberately differs from CreditMemoRestockSubscriber, which resolves a region
 * NAME through OrderInventoryBucketResolver and — correctly, for a financial document that must
 * issue regardless — skips a line whose region matches no warehouse. A receipt has no such fallback
 * available to it: "the goods arrived and we did not record where" is not a receipt, so the
 * refusal happens on the form, in front of the person standing next to the box.
 *
 * ## Simple products record nothing
 *
 * StockMovementService refuses a product on simple inventory outright, because `quantity` there is a
 * number an admin types and this layer must not touch it. So receiving a return of a simple product
 * records the receipt on the document and moves no stock, and says so in the log rather than
 * throwing — the RMA is a customer-facing document first, and refusing to receive a parcel that is
 * physically in the building because a SKU is not opted in would be the wrong trade.
 */
final class SalesReturnReceiptSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly StockMovementService $movements,
        private readonly InventoryModeResolver $modes,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SalesReturnReceivedEvent::class => 'onSalesReturnReceived',
        ];
    }

    public function onSalesReturnReceived(SalesReturnReceivedEvent $event): void
    {
        $return = $event->salesReturn;

        $warehouse = $return->getWarehouse();
        if (!$warehouse instanceof Warehouse) {
            // Unreachable through receive(), which requires one. Logged rather than thrown because
            // a listener that raised here would leave a document correctly marked Received and the
            // request in an error page, with no way to retry the stock half — and the movement is
            // idempotent, so retrying is exactly what an operator should be able to do.
            $this->logger->warning('Sales return receipt: no warehouse on the document, no stock recorded.', [
                'salesReturn' => $return->getDocumentNumber(),
            ]);

            return;
        }

        // ONE request for the whole document, not one per line. A movement group is the unit of
        // "this operation happened", and a parcel containing three SKUs is one operation — half of
        // which having been written is not a state the ledger should be able to reach.
        $request = MovementRequest::of(
            // A return IS goods arriving, whatever the paperwork upstream of it says, and `receipt`
            // is the type that means that here. There is no `return` type and adding one would be a
            // change to InventoryMovementGroup::types() that every existing consumer of that list
            // would have to be re-checked against, for a label. The RMA number is carried as the
            // group's reference, which is what an operator actually searches the ledger by.
            InventoryMovementGroup::TYPE_RECEIPT,
            // Idempotency: the return's identity, so a re-dispatch — a retried request, a listener
            // run twice — finds the existing group and applies nothing. Derived rather than random,
            // which is exactly the distinction MovementRequest::of() draws. Prefixed distinctly from
            // the credit note's key so a return and a note with the same id cannot collide.
            'sales-return-receipt-' . (string) $return->getId(),
            'Customer return received',
            null,
            $return->getDocumentNumber(),
        );

        foreach ($return->getLines() as $line) {
            $product = $line->getProduct();

            if (!$this->modes->isDimensional($product)) {
                $this->logger->info('Sales return receipt: product is not on dimensional inventory, no detail row written.', [
                    'salesReturn' => $return->getDocumentNumber(),
                    'product' => $product->getSku(),
                ]);

                continue;
            }

            $units = $line->getUnits();
            if (QuantityScale::compare($units, 0) <= 0) {
                continue;
            }

            $request->receive(
                $product,
                // No bin, no lot, no serial. The goods are in the building and nobody has decided
                // where they belong until they have been looked at, and a null location is exactly
                // how this ledger says "in this building, bin unspecified". Putting them away is a
                // separate act with a separate movement, which is what /warehouse-ops/scan is for.
                new DetailKey($warehouse, null, null, null, InventoryDetail::STATUS_RETURNED),
                $units,
            );
        }

        if ($request->isEmpty()) {
            return;
        }

        $this->movements->apply($request);
    }
}
