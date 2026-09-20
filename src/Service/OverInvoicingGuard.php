<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;

/**
 * Refuses an invoice that would bill a sales order line for more than was ordered, at the moment it
 * is ISSUED (#31).
 *
 * Before this, a sales order line for 10 could be billed twice for 10 and the customer was invoiced
 * twice for one delivery. `OrderInvoicingService::invoiceFromOrder()` does refuse a line quantity
 * above what is left — but it refuses it at the moment the invoice is RAISED, and it measures the
 * remainder with `SalesOrder::uninvoicedQuantityFor()`, which counts only invoices that
 * {@see Invoice::countsTowardInvoicedQuantity()}. A draft counts for nothing. So two drafts each for
 * the whole line both pass that check — each sees the full remainder, because the other is a draft —
 * and nothing then looked at the pair again. Both could be issued, and the sell side has no
 * exceptions screen that would surface the duplicate afterwards.
 *
 * ## Where the refusal happens: at ISSUE, not at save
 *
 * The owner's ruling, and it is the opposite of the buy side's on purpose.
 *
 *  - **A draft holds nothing.** Two drafts against one order line may both exist and that is fine:
 *    a draft is a person part way through deciding what to bill, and two people drafting the same
 *    instalment is a thing that happens and resolves itself when one of them is issued. Nothing here
 *    is called at save, and a draft claims, reserves and consumes nothing — which is exactly what
 *    {@see Invoice::countsTowardInvoicedQuantity()} has always said, and this guard does not change
 *    it.
 *  - **Issuing is the claim.** The first invoice to be issued takes the quantity off the order line.
 *    A second issue that would carry the line past what was ordered is refused, and the person is
 *    told the two things they can actually do about it.
 *
 * The buy side reached the opposite answer for a reason that does not hold here: a draft vendor bill
 * feeds the three-way match and the exception screens, so it is already misinforming somebody before
 * anybody approves it. That is why `VendorBillStatus` needs TWO readings — `counts()` for "is this a
 * charge" and `claimsOrderedQuantity()` for "is this a claim on the line" — which disagree about
 * exactly one case, the draft. **The sell side needs only one reading**, because a draft invoice is
 * neither a charge nor a claim; `countsTowardInvoicedQuantity()` answers both questions and there is
 * no second predicate to write. Adding one here would be a second answer to a question that already
 * has one, and it would drift.
 *
 * So "remaining to invoice" is untouched. It counts issued invoices, which is what it already did,
 * and no screen gains a second number.
 *
 * ## Why the call site is inside the transition
 *
 * {@see Invoice::issue()} and {@see Invoice::issueAwaitingPayment()} call this before they write the
 * status, and there is no other way to that write — `transitionTo()` is private and there is no
 * setStatus(). That is the buy side's lesson taken literally: the check and the write live in one
 * method, so no caller reaches the write without passing the check. The invoice screen's Issue
 * button, the create-invoice screen's "save and issue", a scripted POST to
 * `/admin/invoice/{id}/action/issue` and the seeders all go through the same door.
 *
 * On Hold is issued too, and so is guarded: it is a different claim from Pending — it holds no stock
 * and the stale-unpaid sweep looks at it — but it is an invoice the customer has been given, and it
 * already counts toward the order's invoiced quantity. `paymentReceived()` (On Hold -> Pending) is
 * deliberately NOT guarded: that invoice made its claim when it was issued, and re-checking it there
 * would refuse a legitimate release because of quantity the invoice itself is holding.
 *
 * ## What it deliberately does NOT refuse
 *
 *  - **An invoice with no sales order.** A standalone invoice draws down nothing because there is
 *    nothing to draw down. This is also the first of the two remedies: unlinking an invoice from its
 *    order makes it exactly that.
 *  - **An invoice line attributed to no order line** — a pallet charge, an extra added at ship time.
 *    It bills something the order does not have a row for, so it has no remainder to exceed. Core
 *    has allowed that since #539 and `SalesOrder::invoicedQuantityFor()` says why.
 *  - **Billing less than was ordered.** A part invoice is the normal case, and the remainder stays
 *    on the order for the next one.
 */
final class OverInvoicingGuard
{
    /**
     * @param string $invoiceLabel how to name this invoice in a refusal — its document number, or
     *                             `#id` while it has none. Passed in because that fallback is the
     *                             entity's own private way of naming itself and is not worth a
     *                             second implementation here
     *
     * @throws \DomainException on the first order line this invoice would take past what was ordered
     */
    public static function assertIssuable(Invoice $invoice, string $invoiceLabel): void
    {
        $order = $invoice->getSalesOrder();
        if (!$order instanceof SalesOrder) {
            return;
        }

        /**
         * Two rows of ONE invoice against one order line are two claims on it, so they are summed
         * before the comparison. Without this an invoice could be split into ten rows of the full
         * quantity and every one of them would pass on its own.
         *
         * @var array<int, array{line: SalesOrderLine, quantity: float}>
         */
        $claims = [];

        foreach ($invoice->getLines() as $invoiceLine) {
            $orderLine = $invoiceLine->getSalesOrderLine();
            if (!$orderLine instanceof SalesOrderLine) {
                continue;
            }

            // A line belonging to another order has no remainder ON THIS ONE, but
            // uninvoicedQuantityFor() would happily quote its own ordered quantity back — it reads
            // the line, and the line does not know it is a stranger here. So the check is explicit,
            // exactly as OrderInvoicingService::invoiceFromOrder() and OverBillingGuard both make
            // it: billing another order's goods is refused, not merely unlikely to be requested.
            if ($orderLine->getOrder() !== $order) {
                throw new \DomainException(sprintf(
                    'Invoice %s cannot be issued. %s is not a line on order %s.',
                    $invoiceLabel,
                    $invoiceLine->getName() !== '' ? $invoiceLine->getName() : $orderLine->getName(),
                    $order->getOrderNumber(),
                ));
            }

            $key = spl_object_id($orderLine);
            $claims[$key] ??= ['line' => $orderLine, 'quantity' => 0.0];
            // getQuantity(), not the entered figure: this is the BASE quantity, which is what
            // invoicedQuantityFor() sums and what the order line is denominated in. An invoice for
            // 40 BOX-12 of a 480-EA line resolves to 480 long before it reaches here, so a document
            // entered in one denomination cannot out-invoice an order written in another.
            $claims[$key]['quantity'] += (float) $invoiceLine->getQuantity();
        }

        foreach ($claims as $claim) {
            if ($claim['quantity'] <= 0.0) {
                continue;
            }

            $line = $claim['line'];
            // This invoice is still a draft at the moment it is read, so it is not one of the
            // counting invoices and is excluded from its own remainder — the same reason
            // OverBillingGuard passes the bill being saved into unbilledQuantityFor(). Everything
            // ALREADY issued against the line is in there, which is the whole point: a check against
            // the ordered quantity alone would catch the single oversized invoice and wave through
            // the second invoice for the same full quantity, which is the case that happens.
            $remaining = (float) $order->uninvoicedQuantityFor($line);
            if ($claim['quantity'] <= $remaining) {
                continue;
            }

            throw new \DomainException(sprintf(
                'Invoice %s cannot be issued. %s: %s requested but only %s left to invoice on order %s'
                . ' (%s ordered, %s already invoiced).'
                . ' Unlink this invoice from order %s, or increase the order line, then issue it again.',
                $invoiceLabel,
                $line->getName(),
                self::trimmed($claim['quantity']),
                self::trimmed($remaining),
                $order->getOrderNumber(),
                self::trimmed((float) $line->getQuantity()),
                self::trimmed((float) $order->invoicedQuantityFor($line)),
                $order->getOrderNumber(),
            ));
        }
    }

    /** '12' rather than '12.00' — how OrderInvoicingService's own refusal states its figures. */
    private static function trimmed(float $quantity): string
    {
        $formatted = number_format($quantity, 2, '.', '');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
