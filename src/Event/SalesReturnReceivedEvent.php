<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\SalesReturn;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once a sales return has gone Authorised → Received and the change is on disk (#596).
 *
 * ## Why an event and not a service call
 *
 * Exactly CreditMemoIssuedEvent's answer, and deliberately the same seam rather than a second,
 * differently-shaped one. Putting goods back means writing `inventory_detail` rows, and
 * `inventory_detail` belongs to InventoryDepthBundle. Core has no compile-time reference to any
 * bundle — CartSyncedEvent and CreditMemoIssuedEvent are the other two points where this rule bites
 * — so core dispatches a plain event it owns and InventoryDepthBundle's SalesReturnReceiptSubscriber
 * is the only thing that knows it means anything.
 *
 * The dependency that DOES exist runs the other way and always has: the subscriber imports
 * `App\Entity\SalesReturn`, just as CreditMemoRestockSubscriber imports `App\Entity\CreditMemo`. A
 * bundle knowing about core is what a bundle is for. What must never appear is the mirror of it —
 * core naming a bundle class — and this file is how that stays true while a core document still
 * moves dimensional stock.
 *
 * Delete InventoryDepthBundle and the event goes unheard: returns are still authorised, still
 * received, still declined and closed, and there is simply no dimensional ledger for the goods to
 * land in. On an instance running simple inventory that is the truthful outcome rather than a
 * broken one, because on such an instance `quantity` is a number an admin types and this layer must
 * not touch it.
 *
 * ## After the flush, on purpose
 *
 * StockMovementService opens its own transaction and flushes several times inside it. Dispatched
 * from a Doctrine listener mid-flush this would be re-entrant against the unit of work currently
 * being committed. SalesReturnController dispatches it after its own flush has returned, so the
 * return has an id, its status and `received_at` are settled, and the movement layer is working
 * against a database that agrees with it.
 *
 * Listeners must be idempotent: StockMovementService keys on `clientOperationId`, and the
 * subscriber derives one from the return's id so a re-dispatch applies nothing a second time.
 */
final class SalesReturnReceivedEvent extends Event
{
    public function __construct(public readonly SalesReturn $salesReturn)
    {
    }
}
