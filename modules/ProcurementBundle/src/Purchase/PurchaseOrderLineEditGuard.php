<?php

declare(strict_types=1);

namespace ProcurementBundle\Purchase;

use App\Service\QuantityScale;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;

/**
 * What a save is allowed to do to the lines of a purchase order that is past Draft (#47).
 *
 * `PurchaseOrder::canEditOnStatus()` (`HasStatus`) answers the document-level half — Closed and
 * Cancelled are shut, everything else is open, the same predicate every status-bearing document
 * now answers uniformly. This is the line-level half, and it exists as its own class for one
 * reason: the two constraints it applies are driven by **different events**, and
 * the single most likely way to get this wrong is to collapse them into one "has this line been
 * touched" test.
 *
 * ## Two events, two rules
 *
 * **Receiving governs QUANTITY.** Goods on a dock are a fact. A line cannot be reduced below what
 * has arrived and a received line cannot be removed, because either would leave the goods receipt
 * that recorded those units pointing at an order that never asked for them — and
 * `goods_receipt_line.purchase_order_line_id` is `ON DELETE SET NULL`, so the attribution the
 * three-way match needs would not even fail loudly. The owner's words: *"received is the floor.
 * That's clear cut"*, and *"Can't delete a line if it's been received. But if nothing received then
 * it's free to do whatever."* So an unreceived line on an Issued order may be re-priced, re-sized to
 * anything at all, or deleted outright. That freedom is the point of the change, not a leftover.
 *
 * **Billing governs PRICE.** Receiving says nothing about what is owed; billing is where a price
 * stops being our expectation and becomes money somebody has accepted. A line received but not yet
 * billed is therefore still price-correctable — which is the exact case the queue item was raised
 * about, an issued PO with a wrong price and one receipt against it. A line a live bill charges for
 * is not, because the order would then disagree with a bill already accepted. See
 * `PurchaseOrderLine::isPriceSettled()` for why that reads live bills and not draft ones.
 *
 * Nothing here refuses a BILL. A bill whose price disagrees with its order raises
 * `MatchLine::EXCEPTION_PRICE_VARIANCE` on the three-way match, which is machinery that already
 * exists and is the thing a buyer is meant to look at. This guard only stops the ORDER being
 * rewritten out from under such a bill after the fact.
 *
 * ## Everything, at the door
 *
 * `refusals()` returns every problem it found rather than the first, and the controller shows them
 * all. A save refused one line at a time makes somebody re-key the same form three times to
 * discover three facts the first attempt already knew, which is the complaint this whole item
 * started from. Each sentence names the line, the figures involved and the way forward, because a
 * generic "this order cannot be edited" is what sent the owner looking in the first place.
 *
 * Quantities are compared through `QuantityScale::compare()`, never as floats, for the reason
 * everything else on this document is: a line a ten-thousandth short never reads as equal. Unit
 * costs are compared at FOUR decimal places, which is the precision the purchase order
 * form can express — `number_format((float) $posted, 4)` is literally what the save writes. A stored
 * residue finer than that (an RFQ conversion can carry six) is therefore read as unchanged and, in
 * the controller, left unwritten rather than truncated.
 */
final class PurchaseOrderLineEditGuard
{
    /**
     * Every reason this set of rows may not be written over this order, in line order.
     *
     * @param list<array{line: ?PurchaseOrderLine, quantity: string, unitCost: string}> $requested
     *        the posted rows already resolved to entities and base figures, and NOT yet applied —
     *        see PurchaseOrderController::requestedLines()
     *
     * @return list<string>
     */
    public static function refusals(PurchaseOrder $order, array $requested): array
    {
        // No status special case, deliberately — not even for Draft. A draft provably has no
        // receipts (PurchaseOrderStatus::refusesReceipts() names it), so the quantity rules cost it
        // nothing and skipping them would only be a second statement of a fact the data already
        // carries. The rules are about what has HAPPENED to a line, and that is the only thing
        // asked below.
        /** @var array<int, array{line: ?PurchaseOrderLine, quantity: string, unitCost: string}> $keptById */
        $keptById = [];
        foreach ($requested as $row) {
            $line = $row['line'];
            if ($line instanceof PurchaseOrderLine && $line->getId() !== null) {
                $keptById[(int) $line->getId()] = $row;
            }
        }

        $refusals = [];
        $position = 0;

        foreach ($order->getLines() as $line) {
            ++$position;
            $id = (int) $line->getId();
            $label = self::label($position, $line);
            $received = $line->getQuantityReceived();

            if (!isset($keptById[$id])) {
                if (QuantityScale::compare($received, 0) > 0) {
                    $refusals[] = sprintf(
                        '%s cannot be removed: %s of the %s ordered have already been received. Received quantity is the'
                        . ' floor — reduce the line to %s if the rest is not coming, or close the whole order short.',
                        $label,
                        self::readable($line->getQuantityReceived()),
                        self::readable($line->getQuantityOrdered()),
                        self::readable($line->getQuantityReceived()),
                    );
                }

                continue;
            }

            $row = $keptById[$id];

            $wanted = QuantityScale::canonical($row['quantity']);
            if (QuantityScale::compare($wanted, $received) < 0) {
                $refusals[] = sprintf(
                    '%s cannot be reduced to %s: %s of the %s ordered have already been received. Received quantity is'
                    . ' the floor — %s is the lowest this line can go.',
                    $label,
                    self::readable($wanted),
                    self::readable($line->getQuantityReceived()),
                    self::readable($line->getQuantityOrdered()),
                    self::readable($line->getQuantityReceived()),
                );
            }

            if (self::costChanged($line->getUnitCost(), $row['unitCost']) && $line->isPriceSettled()) {
                $refusals[] = sprintf(
                    '%s has been billed for %s unit(s), so its unit cost stays at %s — it is settled money, not an'
                    . ' expectation. A bill at a different price is not refused: it raises a price-variance exception on'
                    . ' the three-way match, and the bill\'s own price is what is owed.',
                    $label,
                    self::readable($line->getQuantityCharged()),
                    self::cost($line->getUnitCost()),
                );
            }
        }

        return $refusals;
    }

    /**
     * A quantity as a person would say it: 3.00 reads back as 3, and 0.50 as 0.5.
     *
     * The same formatting `InvoiceOrderMatcher::trimmed()` applies to the sell side's own mismatch
     * sentences, and for the same reason — these strings are read by a buyer, not parsed. The
     * owner's example of what a refusal should sound like is *"3 of these 10 have already been
     * received"*, which is this and not "3.00 of these 10.00".
     *
     * Only the prose uses it. Every comparison above goes through QuantityScale::compare(),
     * because a sentence is not a place to do arithmetic.
     */
    private static function readable(string $quantity): string
    {
        $formatted = number_format((float) $quantity, 2, '.', '');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    /**
     * Has this row asked for a different unit cost from the one the line holds.
     *
     * Both sides through the same normalisation, so that a line whose column reads `5.000000` and a
     * box that posts back `5.0000` — which is exactly what this form round-trips — is not read as
     * an edit and does not freeze a billed line nobody touched.
     */
    public static function costChanged(string $stored, string $posted): bool
    {
        return self::cost($stored) !== self::cost($posted);
    }

    /** The unit cost as this form is able to express it: four decimal places, the save's own write. */
    public static function cost(string $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    /**
     * How a line is named back to the person who typed it: its row number AND its description.
     *
     * Both, because either alone is ambiguous on a real order — two rows can carry the same product
     * under different vendor part numbers, and a row number alone is meaningless once somebody has
     * scrolled.
     */
    private static function label(int $position, PurchaseOrderLine $line): string
    {
        $name = trim($line->getName());

        return $name === ''
            ? sprintf('Line %d', $position)
            : sprintf('Line %d (%s)', $position, $name);
    }
}
