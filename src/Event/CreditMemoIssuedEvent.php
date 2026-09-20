<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\CreditMemo;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once a credit note has gone Draft → Open and the change is on disk (#586).
 *
 * ## Why an event and not a service call
 *
 * Because putting stock back means writing `inventory_detail` rows, and `inventory_detail` belongs
 * to InventoryDepthBundle. Core has no compile-time reference to any bundle — see CartSyncedEvent,
 * which is the same decoupling point for cart holds — so core dispatches a plain event it owns and
 * InventoryDepthBundle's CreditMemoRestockSubscriber is the only thing that knows it means
 * anything. Delete the bundle and the event goes unheard: credit notes keep working, the money side
 * is unaffected, and there is simply no dimensional ledger for returned goods to land in, which is
 * the truthful outcome rather than a broken one.
 *
 * ## After the flush, on purpose
 *
 * StockMovementService opens its own transaction and flushes several times inside it. Dispatched
 * from a Doctrine listener mid-flush that would be re-entrant against the unit of work that is
 * currently being committed. The controller dispatches it after its own flush has returned, so the
 * note has an id, its status is settled, and the movement layer is working against a database that
 * agrees with it.
 *
 * Listeners must be idempotent: StockMovementService keys on `clientOperationId`, and the
 * subscriber derives one from the note's id so a re-dispatch applies nothing a second time.
 */
final class CreditMemoIssuedEvent extends Event
{
    public function __construct(public readonly CreditMemo $creditMemo)
    {
    }
}
