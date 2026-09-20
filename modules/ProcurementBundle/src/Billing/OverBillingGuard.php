<?php

declare(strict_types=1);

namespace ProcurementBundle\Billing;

use App\Service\QuantityScale;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\VendorBill;

/**
 * Refuses a bill that charges for more than its purchase order has left (#658).
 *
 * Before this, a purchase order line for 10 could be billed for 1000, or billed in full five times,
 * and nothing objected anywhere: `VendorBillController::save()` attached a bill line to a PO line
 * with no check of any kind, `ThreeWayMatchService` compared *one* bill line against the line's
 * total received with no term for what other bills already held, and approval never blocked. Two
 * bills of 100 against a single 100-unit receipt both reported a clean match and both approved.
 *
 * ## Where the refusal happens: per line, at SAVE
 *
 * Deliberate, and the alternative was a document-level check at approval. Three reasons:
 *
 *  1. **It mirrors the sell side**, which is what was asked for. `App\Service\OrderInvoicingService`
 *     refuses per line at the moment the invoice is raised, with a `\DomainException` naming the
 *     line, what was asked for and what is left. Same structure, same exception type, same shape of
 *     sentence here.
 *  2. **Save is where a person can still fix it.** The figures are in front of them and the line is
 *     named in the message. A refusal at approval accepts the document, lets it sit in the payables
 *     queue, and fails at the moment money is authorised — later, after three screens have shown a
 *     figure that was never payable.
 *  3. **A draft is not inert on this side.** Drafts feed the match and exception screens, so a draft
 *     holding an impossible quantity is already misinforming somebody before anybody approves it.
 *
 * ## The case a naive per-line check misses, and why this one does not
 *
 * "This bill line exceeds what remains" is only a guard if *what remains* already accounts for the
 * OTHER bills. A check that compared each line against the PO line's ordered quantity alone would
 * catch a single oversized bill and wave through a second bill for the same full quantity — which
 * is the case that actually happens, because the second bill is a duplicate arriving by email.
 *
 * So the remainder is `PurchaseOrder::unbilledQuantityFor()`, which sums every bill line pointing at
 * that PO line whose bill is not Void — drafts included; see
 * `VendorBillStatus::claimsOrderedQuantity()` for why a draft counts here and does not count as a
 * charge — and subtracts it from what was ordered. The bill being saved is excluded from its own
 * remainder, or an unchanged draft would refuse to save a second time.
 *
 * Quantities are BASE figures throughout (`quantity`, never `quantity_entered`): a bill for 40
 * Cases of a 12-pack resolves to 480 before it gets here, so a document entered in one denomination
 * cannot out-bill a purchase order written in another.
 *
 * ## What it deliberately does NOT refuse
 *
 *  - **A charge with no PO line behind it** — freight, a substitution, a correction. It matches
 *    against nothing, which is an exception worth surfacing rather than an error, and the bundle has
 *    said so since #555.
 *  - **Billing for goods that have not arrived.** The comparison is against what was ORDERED, as on
 *    the sell side. `billed_not_received` is a real exception with a screen of its own, and refusing
 *    it here would make that screen unreachable for the case it exists to show.
 *  - **A bill against no purchase order at all.** A standalone bill draws down nothing because there
 *    is nothing to draw down.
 */
final class OverBillingGuard
{
    /**
     * @param list<array{line: ?PurchaseOrderLine, quantity: string, name: string}> $requested
     *
     * @throws \DomainException on the first row that cannot be billed
     */
    public function assertWithinRemaining(?PurchaseOrder $order, ?VendorBill $bill, array $requested): void
    {
        if (!$order instanceof PurchaseOrder) {
            return;
        }

        /** @var array<int, string> $takenByLine already claimed by earlier rows of THIS bill */
        $takenByLine = [];

        foreach ($requested as $row) {
            $line = $row['line'] ?? null;
            if (!$line instanceof PurchaseOrderLine) {
                continue;
            }

            // A line belonging to another purchase order has no remainder ON THIS ONE, but
            // unbilledQuantityFor() would happily quote its own ordered quantity back — it reads the
            // line, and the line does not know it is a stranger here. So the check is explicit:
            // billing another order's goods is refused, not merely unlikely to be requested. The
            // sell side makes the same check for the same reason.
            if ($line->getPurchaseOrder()->getId() !== $order->getId()) {
                throw new \DomainException(sprintf(
                    '%s is not a line on purchase order %s.',
                    $row['name'] !== '' ? $row['name'] : $line->getName(),
                    $order->getPoNumber(),
                ));
            }

            $quantity = QuantityScale::canonical($row['quantity']);
            if (QuantityScale::compare($quantity, 0) <= 0) {
                continue;
            }

            $key = (int) $line->getId();
            $alreadyOnThisBill = $takenByLine[$key] ?? QuantityScale::canonical(0);
            $remaining = QuantityScale::sub($order->unbilledQuantityFor($line, $bill), $alreadyOnThisBill);

            if (QuantityScale::compare($quantity, $remaining) > 0) {
                throw new \DomainException(sprintf(
                    '%s: %s requested but only %s left to bill on purchase order %s (%s ordered, %s already billed).',
                    $row['name'] !== '' ? $row['name'] : $line->getName(),
                    self::trimmed($quantity),
                    self::trimmed(QuantityScale::compare($remaining, 0) > 0 ? $remaining : QuantityScale::canonical(0)),
                    $order->getPoNumber(),
                    self::trimmed($line->getQuantityOrdered()),
                    self::trimmed(QuantityScale::add($order->billedQuantityFor($line, $bill), $alreadyOnThisBill)),
                ));
            }

            // Two rows of one bill against one PO line are two claims on it, so the second is
            // measured against what the first left. Without this, a bill could be split into ten
            // rows of the full quantity and every one of them would pass on its own.
            $takenByLine[$key] = QuantityScale::add($alreadyOnThisBill, $quantity);
        }
    }

    /** '12' rather than '12.00', the way the sell side's refusal states its figures. */
    private static function trimmed(string|int|float $quantity): string
    {
        $formatted = QuantityScale::canonical($quantity);

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
