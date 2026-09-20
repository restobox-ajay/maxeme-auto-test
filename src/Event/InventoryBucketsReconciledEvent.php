<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\AbstractSalesDocument;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by InventoryReservationReconciler::reconcile() once per reconciliation pass that
 * actually changed a ProductInventory hold bucket — never on a no-op pass. This is the sanctioned
 * extension point for future code that wants to react to a document's inventory reservation
 * changing (e.g. a low-stock-alert or notification bundle): listen to this with a plain
 * EventSubscriberInterface, no knowledge of Doctrine's UnitOfWork or the reservation ledger
 * required. Compare with InventoryReconciliationSubscriber, which is the reliability mechanism that
 * guarantees reconcile() itself always runs — not meant to be listened to directly by other code.
 *
 * $document is an AbstractSalesDocument rather than a SalesOrder from #539 stage 3 on: a sales order
 * holds `sales_hold` and an invoice holds `pending`/`approved`, so both now cause a reservation
 * change and a listener that could only be told about one of them would miss half of them.
 */
final class InventoryBucketsReconciledEvent extends Event
{
    /**
     * @param list<array{product: \App\Entity\ProductCore, warehouse: \App\Entity\Warehouse, bucket: ?string, previousQuantity: int, newQuantity: int}> $changes
     */
    public function __construct(
        public readonly AbstractSalesDocument $document,
        public readonly array $changes,
    ) {
    }
}
