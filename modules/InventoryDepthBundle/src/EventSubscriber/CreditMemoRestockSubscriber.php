<?php

declare(strict_types=1);

namespace InventoryDepthBundle\EventSubscriber;

use App\Entity\CreditMemo;
use App\Entity\CreditMemoLine;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Event\CreditMemoIssuedEvent;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\Inventory\OrderInventoryBucketResolver;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Goods coming back from a customer, when the credit note that credited them says they did (#586).
 *
 * ## Where the units land, and why not on the shelf
 *
 * In `returned`, which sums into `quarantine_quantity` and is therefore NOT sellable. That is the
 * whole point of the status existing separately from `available`: a customer returning faulty goods
 * and a customer returning perfectly good ones raise the same credit note, and the note cannot know
 * which happened. Somebody inspects, and releases the units onward from the adjustment screen's
 * "Release from hold" path. Putting them straight back on the shelf would offer stock for sale that
 * nobody has looked at.
 *
 * `returned` also keeps its own name rather than being written as `quarantine`, so a returned unit
 * stays distinguishable from one that failed inspection — same bucket, different provenance.
 *
 * ## `received_quantity` MUST NOT MOVE, and this is where that is guaranteed
 *
 * The movement is `null → returned`: the units ENTER the ledger, from outside, into a non-available
 * status. MovementRequest::receivedDelta() only counts crossings where one side is `available` in
 * this warehouse, so neither side of this movement matches and the delta is zero.
 *
 * That is not an accident of the implementation, it is the correct answer, and the arithmetic is
 * worth having written down:
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
 * ## Simple products record nothing
 *
 * StockMovementService refuses a product on simple inventory outright, because `quantity` there is a
 * number an admin types and this layer must not touch it. So a restock of a simple product credits
 * the money and moves no stock, and says so in the log rather than throwing — the credit note is a
 * financial document first and refusing to issue it because a SKU is not opted in would be the wrong
 * trade.
 */
final class CreditMemoRestockSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly StockMovementService $movements,
        private readonly InventoryModeResolver $modes,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CreditMemoIssuedEvent::class => 'onCreditMemoIssued',
        ];
    }

    public function onCreditMemoIssued(CreditMemoIssuedEvent $event): void
    {
        $memo = $event->creditMemo;
        if (!$memo->isRestock()) {
            return;
        }

        $warehousesByLowerRegionName = $this->warehouses->warehousesByLowerRegionName();

        // ONE request for the whole note, not one per line. A movement group is the unit of "this
        // operation happened", and a return of three SKUs is one operation — half of which having
        // been written is not a state the ledger should be able to reach.
        $request = MovementRequest::of(
            // A return IS goods arriving, whatever the paperwork upstream of it says, and `receipt`
            // is the type that means that here. There is no `return` type and adding one would be a
            // change to InventoryMovementGroup::types() that every existing consumer of that list
            // would have to be re-checked against, for a label.
            InventoryMovementGroup::TYPE_RECEIPT,
            // Idempotency: the note's identity, so a re-dispatch — a retried request, a listener run
            // twice — finds the existing group and applies nothing. Derived rather than random,
            // which is exactly the distinction MovementRequest::of() draws.
            'credit-memo-restock-' . (string) $memo->getId(),
            'Customer return',
            null,
            $memo->getDocumentNumber(),
        );

        foreach ($memo->getLines() as $line) {
            $warehouse = $this->warehouseFor($memo, $line, $warehousesByLowerRegionName);
            if (!$warehouse instanceof Warehouse) {
                continue;
            }

            $product = $line->getProduct();
            if (!$product instanceof ProductCore || !$this->modes->isDimensional($product)) {
                if ($product instanceof ProductCore) {
                    $this->logger->info('Credit note restock: product is not on dimensional inventory, no detail row written.', [
                        'creditMemo' => $memo->getDocumentNumber(),
                        'product' => $product->getSku(),
                    ]);
                }

                continue;
            }

            $units = $line->getUnits();
            if (QuantityScale::compare($units, 0) <= 0) {
                continue;
            }

            $request->receive(
                $product,
                // No bin. The goods are in the building and nobody has decided where they belong
                // until they have been looked at, and a null location is exactly how this ledger
                // says "in this building, bin unspecified".
                new DetailKey($warehouse, null, null, null, InventoryDetail::STATUS_RETURNED),
                $units,
            );
        }

        if ($request->isEmpty()) {
            return;
        }

        $this->movements->apply($request);
    }

    /**
     * The building the goods came back to.
     *
     * Resolved through the same OrderInventoryBucketResolver::resolveLineWarehouse() the reservation
     * reconciler uses, with the same precedence — the line's own region name, falling back to the
     * document header's. One rule for "which warehouse does this row mean", so a credit note and the
     * invoice it credits can never disagree about where the stock is.
     *
     * A row naming no resolvable region is skipped with a warning rather than guessed at, exactly as
     * InventoryReservationReconciler skips one: putting stock into an arbitrary warehouse is worse
     * than putting it nowhere, because nothing afterwards can tell it was a guess.
     *
     * @param array<string, Warehouse> $warehousesByLowerRegionName
     */
    private function warehouseFor(CreditMemo $memo, CreditMemoLine $line, array $warehousesByLowerRegionName): ?Warehouse
    {
        $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse(
            $line->getLocation(),
            $memo->getFulfillmentRegion(),
            $warehousesByLowerRegionName,
        );

        if (!$warehouse instanceof Warehouse) {
            $this->logger->warning('Credit note restock: could not resolve a fulfillment region for a line, skipping.', [
                'creditMemo' => $memo->getDocumentNumber(),
                'product' => $line->getProduct()?->getSku(),
                'regionName' => trim($line->getLocation() ?? '') ?: trim((string) $memo->getFulfillmentRegion()),
            ]);
        }

        return $warehouse;
    }
}
