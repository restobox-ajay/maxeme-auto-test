<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Movement;

use App\Service\QuantityScale;

use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovement;
use InventoryDepthBundle\Repository\InventoryMovementRepository;

/**
 * Turns "put that write-off back" into movement lines, and is the ONE place the reversal bound is
 * enforced (#585).
 *
 * ## A reversal is the original movement with its sides swapped
 *
 * Not "a movement that happens to go the other way" — the same rows, exchanged. The write-off wrote
 *
 *     available (bin A-12, lot L-1, serial ABC-0042)  →  damaged
 *
 * so its reversal writes
 *
 *     damaged  →  available (bin A-12, lot L-1, serial ABC-0042)
 *
 * and every dimension on both sides comes off the original's own detail rows. **Nothing is retyped**,
 * which is what makes serials survivable here: the old screen had one serial text box and
 * StockMovementService::assertSerialRowsStayAtOne() correctly refuses more than one unit per serial
 * row, so putting 300 found serialised units back meant 300 submissions. Reversing the entries that
 * wrote them off needs none, because each entry already names its unit.
 *
 * It is also why the reversal cannot land the goods somewhere they were never taken from. An
 * operator naming the destination by hand can put a reversed unit in the wrong bin, on the wrong
 * lot, or — with a serial — under a number that belongs to a different physical unit.
 *
 * ## The bound, and why it is the whole guard
 *
 *     remaining = original.quantity − SUM(quantity WHERE reverses_movement_id = original.id)
 *
 * That single expression is what refuses a reversal of 500 against a write-off of 12, AND what
 * refuses the same 12 twice. Both are the same mistake — claiming units the entry never lost — and
 * neither is catchable by validating a quantity box, because nothing else in the schema knows the
 * two entries are about the same goods. Dynamics ties a reversal to the original Item Ledger Entry
 * for exactly this reason.
 *
 * **Deliberately not delegated to the stock check.** StockMovementService::assertSourcesCanCover()
 * would catch a reversal larger than the `damaged` row still holds, but that is a different
 * question with a different answer: one write-off of 12 and another of 40 share a single
 * `damaged` row of 52, so reversing "the 12 entry" for 40 units passes the stock check and is still
 * wrong — it claims against an entry that only ever lost 12. The bound is about the ENTRY; the
 * stock check is about the ROW. Both run, and neither is redundant.
 *
 * ## Why a service and not a method on the controller
 *
 * So that the bound has an address. A guard inlined into a controller action is a guard that gets
 * copied the day a second caller appears — an API endpoint, a bulk screen, a command — and copies
 * drift. Also so it can be deleted in one edit to check that the test protecting it actually fails,
 * which is how the bound was verified rather than assumed.
 */
final class ReversalPlanner
{
    public function __construct(private readonly InventoryMovementRepository $movements)
    {
    }

    /** How much of $original can still be put back. See the class docblock for the formula. */
    public function remaining(InventoryMovement $original): string
    {
        return $this->movements->remainingReversible($original);
    }

    /**
     * Folds a reversal of $quantity units of $original into $request as one line, sides swapped.
     *
     * Refuses, with a sentence rather than an exception type nobody can act on, when:
     *
     *  - the quantity is not positive. Direction is the shape of the movement, never a minus sign;
     *  - the entry is not a write-off of previously available stock. Releasing a hold and unbilling
     *    a sale are different operations with their own reasons and their own paperwork, and the
     *    picker never offers those entries — this is the refusal for a hand-built POST that names
     *    one anyway, which is the only way to get here;
     *  - the entry has no source row. A write-off that came from nowhere invented its loss, and
     *    swapping its sides would push the units OUT of the ledger instead of back onto a shelf;
     *  - the quantity exceeds `remaining`.
     *
     * @throws \InvalidArgumentException on any of the above, before anything is written
     */
    public function addReversal(MovementRequest $request, InventoryMovement $original, int $quantity): MovementRequest
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('A reversal needs a positive quantity — reversing is the direction, not the sign.');
        }

        $writtenOffInto = $original->getToDetail();
        $cameFrom = $original->getFromDetail();

        if (!$writtenOffInto instanceof InventoryDetail || !in_array($writtenOffInto->getStatus(), InventoryDetail::writeOffStatuses(), true)) {
            throw new \InvalidArgumentException(sprintf(
                'That entry is not a write-off, so there is nothing here to reverse. This reason undoes %s. '
                . 'Stock coming off a hold is released with its own reason, and stock billed to a customer '
                . 'is put back by a credit memo against the invoice that billed it.',
                implode(', ', InventoryDetail::writeOffStatuses()),
            ));
        }

        if (!$cameFrom instanceof InventoryDetail || $cameFrom->getStatus() !== InventoryDetail::STATUS_AVAILABLE) {
            throw new \InvalidArgumentException(
                'That write-off has no sellable source to give the stock back to, so reversing it would take '
                . 'the units out of the ledger rather than put them on a shelf. Record what is physically there '
                . 'as stock found instead.'
            );
        }

        $remaining = $this->movements->remainingReversible($original);

        if (QuantityScale::compare($quantity, $remaining) > 0) {
            throw new \InvalidArgumentException(sprintf(
                'That entry wrote off %s unit(s) and %s have already been reversed, so at most %s can be put back — not %s. '
                . 'If more stock than that has turned up, it was never written off: record the rest as stock found.',
                QuantityScale::trim(QuantityScale::canonical($original->getQuantity())),
                QuantityScale::trim(QuantityScale::sub($original->getQuantity(), $remaining)),
                QuantityScale::trim($remaining),
                QuantityScale::trim(QuantityScale::canonical($quantity)),
            ));
        }

        // Both sides off the original's own rows, in the order that says what happened: out of the
        // write-off row, back into the row it was taken from. `expectResolution` is deliberately
        // left at its default false on both keys — a reversal makes no claim about whether somebody
        // still owes this row an identity, and StockMovementService only ever raises that flag, so
        // passing false cannot clear a to-do somebody else set.
        return $request->move(
            $original->getProduct(),
            self::keyFor($writtenOffInto),
            self::keyFor($cameFrom),
            $quantity,
            $original,
        );
    }

    /**
     * A detail row read back as the key that resolves to it.
     *
     * Round-trips exactly, because DetailKey's five identity fields are the same five
     * InventoryDetailRepository::findOrCreate() looks a row up by — so this cannot silently create a
     * second row for stock that is already somewhere.
     */
    private static function keyFor(InventoryDetail $row): DetailKey
    {
        return new DetailKey(
            $row->getWarehouse(),
            $row->getLocation(),
            $row->getLot(),
            $row->getSerial(),
            $row->getStatus(),
        );
    }
}
