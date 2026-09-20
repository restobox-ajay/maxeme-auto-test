<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Model\InvoiceOrderMatch;
use App\Model\InvoiceOrderMismatch;

/**
 * Decides whether an existing invoice can be attached to a sales order, and what each of its rows
 * would draw down (#539 stage 5).
 *
 * From the issue, verbatim: *"In Edit Invoice page, there should be a way to relate an invoice to an
 * existing Sales Order. However, the condition is that SKU/product lines must match so it can deduct
 * properly. If not match, show clearly what is not matching so user can correct. One thing about
 * shipping and other fees, we do not need to worry about force matching those. Prices can also be
 * edited at invoice level, rare, but should be allowed."*
 *
 * Every clause of that is a rule here:
 *
 * - **SKU/product lines only.** Charges live in the fee_lines snapshot, not in the line collection,
 *   so "shipping and other fees are exempt" is not a special case this has to carve out — it is
 *   simply what happens when the matching reads getLines() and nothing else. Nothing here looks at
 *   fee_lines at all.
 * - **Prices never matter.** The two documents are compared by SKU and quantity. An invoice may
 *   charge a different price from the order it bills, which is also why "fully invoiced" is decided
 *   by quantity and never by amount.
 * - **"so it can deduct properly"** is what makes the rule directional. Every invoice line must find
 *   an order line to attribute to, with enough quantity left; an order line the invoice does NOT
 *   bill deducts nothing, which is what a part-invoice is, so it is reported and not refused. See
 *   InvoiceOrderMismatch for that distinction and why it has to exist.
 * - **"show clearly what is not matching"** is why this returns a report rather than a bool: every
 *   mismatch names its SKU, which document it is on, and the quantities involved.
 *
 * ## Attribution, not just approval
 *
 * Linking writes InvoiceLine::$salesOrderLine on every row, because that FK — not a SKU comparison
 * repeated later — is what makes uninvoiced quantity derivable. An invoice attached to an order
 * without it would show up on the order page and draw down nothing.
 *
 * When one order carries two lines of the same SKU, an invoice row is attributed to the first of
 * them with room for it, and the next row to whatever is left after that. There is no attempt to
 * split one invoice row across two order rows: a row bills one thing, and an invoice that cannot be
 * placed row-for-row is reported rather than silently rearranged.
 */
final class InvoiceOrderMatcher
{
    /**
     * The report, computed and never written. Safe to call on a GET to render the screen, which is
     * exactly what the link screen does before anyone presses anything.
     */
    public function match(Invoice $invoice, SalesOrder $order): InvoiceOrderMatch
    {
        $mismatches = [];
        $attributions = [];

        // Structural refusals first. These are also enforced by Invoice::linkToOrder(), which is
        // what actually stops them; restating them here is what turns a throw into something the
        // screen can show beside the SKU rows, the same way InvoiceController::availableActions()
        // restates Invoice's own from-state table.
        $existing = $invoice->getSalesOrder();
        if ($existing instanceof SalesOrder) {
            $mismatches[] = InvoiceOrderMismatch::blocking(
                InvoiceOrderMismatch::KIND_ALREADY_LINKED,
                $invoice->getDocumentNumber(),
                sprintf(
                    'Invoice %s is already linked to order %s. Unlink it from that order first.',
                    $invoice->getDocumentNumber(),
                    $existing->getOrderNumber(),
                ),
            );
        }

        if ($invoice->getCompany() !== $order->getCompany()) {
            $mismatches[] = InvoiceOrderMismatch::blocking(
                InvoiceOrderMismatch::KIND_DIFFERENT_COMPANY,
                $invoice->getDocumentNumber(),
                sprintf(
                    'Invoice %s bills %s but order %s is for %s.',
                    $invoice->getDocumentNumber(),
                    $invoice->getCompany()->getName(),
                    $order->getOrderNumber(),
                    $order->getCompany()->getName(),
                ),
            );
        }

        // Seeded from the order's own derivation rather than from a second one written here, so an
        // order half billed by other invoices offers this one only what is genuinely left.
        $remaining = [];
        $orderLinesBySku = [];
        foreach ($order->getLines() as $orderLine) {
            $remaining[spl_object_id($orderLine)] = (float) $order->uninvoicedQuantityFor($orderLine);

            $key = self::skuKey($orderLine->getSku());
            if ($key !== null) {
                $orderLinesBySku[$key][] = $orderLine;
            }
        }

        // A cancelled invoice bills nothing and draws nothing down, so there is no remainder for it
        // to exceed. Its SKUs still have to be on the order: attaching it is a statement about which
        // order it was raised against, and that statement should be true.
        $checkRemainder = !$invoice->isCancelled();

        $billedSkus = [];
        foreach ($invoice->getLines() as $invoiceLine) {
            $key = self::skuKey($invoiceLine->getSku());
            if ($key === null) {
                $mismatches[] = InvoiceOrderMismatch::blocking(
                    InvoiceOrderMismatch::KIND_LINE_HAS_NO_SKU,
                    $invoiceLine->getName(),
                    sprintf(
                        'Invoice line "%s" has no SKU, so there is nothing to match it to an order line by.'
                        . ' Give it the SKU it bills, or remove it.',
                        $invoiceLine->getName(),
                    ),
                );

                continue;
            }

            $billedSkus[$key] = true;
            $quantity = (float) $invoiceLine->getQuantity();

            if (!isset($orderLinesBySku[$key])) {
                $mismatches[] = InvoiceOrderMismatch::blocking(
                    InvoiceOrderMismatch::KIND_SKU_NOT_ON_ORDER,
                    (string) $invoiceLine->getSku(),
                    sprintf(
                        'SKU %s: invoice %s bills %s of it, and order %s has no line with that SKU.',
                        $invoiceLine->getSku(),
                        $invoice->getDocumentNumber(),
                        self::trimmed($quantity),
                        $order->getOrderNumber(),
                    ),
                );

                continue;
            }

            $target = $this->placeOn($orderLinesBySku[$key], $remaining, $quantity, $checkRemainder);
            if (!$target instanceof SalesOrderLine) {
                $mismatches[] = InvoiceOrderMismatch::blocking(
                    InvoiceOrderMismatch::KIND_QUANTITY_EXCEEDS_REMAINING,
                    (string) $invoiceLine->getSku(),
                    sprintf(
                        'SKU %s: invoice %s bills %s but order %s has only %s left to invoice'
                        . ' (%s ordered, %s already invoiced).',
                        $invoiceLine->getSku(),
                        $invoice->getDocumentNumber(),
                        self::trimmed($quantity),
                        $order->getOrderNumber(),
                        self::trimmed($this->remainingFor($orderLinesBySku[$key], $remaining)),
                        self::trimmed($this->orderedFor($orderLinesBySku[$key])),
                        self::trimmed($this->invoicedFor($order, $orderLinesBySku[$key])),
                    ),
                );

                continue;
            }

            $remaining[spl_object_id($target)] -= $quantity;
            $attributions[spl_object_id($invoiceLine)] = $target;
        }

        foreach ($orderLinesBySku as $key => $orderLines) {
            if (isset($billedSkus[$key])) {
                continue;
            }

            $left = $this->remainingFor($orderLines, $remaining);
            if ($left <= 0.0) {
                continue;
            }

            $mismatches[] = InvoiceOrderMismatch::notice(
                InvoiceOrderMismatch::KIND_ORDER_SKU_NOT_BILLED,
                (string) $orderLines[0]->getSku(),
                sprintf(
                    'SKU %s: order %s still has %s left to invoice and invoice %s bills none of it.'
                    . ' That quantity stays uninvoiced.',
                    $orderLines[0]->getSku(),
                    $order->getOrderNumber(),
                    self::trimmed($left),
                    $invoice->getDocumentNumber(),
                ),
            );
        }

        return new InvoiceOrderMatch($mismatches, $attributions);
    }

    /**
     * The first of $orderLines with room for $quantity, or null when none has.
     *
     * @param list<SalesOrderLine> $orderLines
     * @param array<int, float>    $remaining
     */
    private function placeOn(array $orderLines, array $remaining, float $quantity, bool $checkRemainder): ?SalesOrderLine
    {
        foreach ($orderLines as $orderLine) {
            // Slack of a hundredth of a cent: quantities are two-decimal strings read back as
            // floats, and 10.00 - 7.00 is not exactly 3.00 in binary floating point.
            if (!$checkRemainder || $remaining[spl_object_id($orderLine)] + 0.0001 >= $quantity) {
                return $orderLine;
            }
        }

        return null;
    }

    /**
     * @param list<SalesOrderLine> $orderLines
     * @param array<int, float>    $remaining
     */
    private function remainingFor(array $orderLines, array $remaining): float
    {
        $total = 0.0;
        foreach ($orderLines as $orderLine) {
            $total += max(0.0, $remaining[spl_object_id($orderLine)]);
        }

        return round($total, 2);
    }

    /** @param list<SalesOrderLine> $orderLines */
    private function orderedFor(array $orderLines): float
    {
        $total = 0.0;
        foreach ($orderLines as $orderLine) {
            $total += (float) $orderLine->getQuantity();
        }

        return round($total, 2);
    }

    /** @param list<SalesOrderLine> $orderLines */
    private function invoicedFor(SalesOrder $order, array $orderLines): float
    {
        $total = 0.0;
        foreach ($orderLines as $orderLine) {
            $total += (float) $order->invoicedQuantityFor($orderLine);
        }

        return round($total, 2);
    }

    /**
     * The comparable form of a SKU: trimmed, and upper-cased so "abc-1" and "ABC-1" are one product.
     *
     * Null when there is nothing to compare, which is a blocking mismatch rather than a wildcard —
     * an unmatchable line is exactly the thing the issue asks to be shown, not something to guess a
     * home for.
     */
    private static function skuKey(?string $sku): ?string
    {
        $sku = strtoupper(trim((string) $sku));

        return $sku === '' ? null : $sku;
    }

    /** A quantity as a person would type it: 3.00 reads back as 3, and 0.50 as 0.5. */
    private static function trimmed(float $quantity): string
    {
        $formatted = number_format($quantity, 2, '.', '');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
