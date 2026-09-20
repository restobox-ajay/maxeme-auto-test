<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\ProductCore;
use App\Entity\SalesOrderLine;
use App\Entity\TrackingPolicy;
use App\Service\QuantityScale;

/**
 * Refuses an invoice that would leave Draft while one of its lines is missing the lot/serial
 * identity its product's TrackingPolicy requires on the way out (2026-09-14 lot/serial/expiry plan,
 * section 5 — the piece that actually enforces the owner's original capture rule).
 *
 * Copying a sales order line's `batch` string onto a draft invoice has always been fine — nothing
 * final has happened yet. Finalizing the invoice is what requires a human to actually say what is
 * being invoiced, which is why this runs at ISSUE and not at save, the same call site and the same
 * reason as {@see OverInvoicingGuard}.
 *
 * ## The backorder carve-out needs no new coupling
 *
 * A line still held against the order's `backordered` bucket has no real stock to pick a lot from,
 * so it is exempt until BackorderReleaseService does what it already does today: shrink the order
 * line's backordered units, which is the same fact this guard reads straight off the order line
 * rather than through a second notion of "still backordered". `BackorderReleaseService` gains no new
 * code and no new knowledge of capture rules; this guard is the only thing that reads the fact and
 * the only thing that acts on it — the concrete shape of the plan's "division of labour".
 *
 * ## What it deliberately does NOT check
 *
 *  - **A product nobody has opted into lot/serial tracking.** `TrackingPolicy::tracksLotsOutbound()`/
 *    `tracksSerialsOutbound()` is false for the whole catalogue by default, so this refuses nothing
 *    for any invoice until a product opts in — the same invariant every other TrackingPolicy-gated
 *    behavior in this app rests on.
 *  - **Whether the picked lot itself satisfies `requiresExpiry()`.** That is InventoryLot's own
 *    concern at the point a lot is created (LotController), not a second validation of the same rule
 *    here. This guard asks only "was an identity actually recorded", not "is that identity itself
 *    well-formed".
 *  - **A line with no product**, or a standalone invoice line the order never carried — neither has
 *    a TrackingPolicy to ask.
 */
final class MandatoryCaptureGuard
{
    /**
     * @param string $invoiceLabel how to name this invoice in a refusal — see
     *                             {@see OverInvoicingGuard::assertIssuable()}'s identical parameter
     *
     * @throws \DomainException on the first line missing the identity its product requires
     */
    public static function assertCaptured(Invoice $invoice, string $invoiceLabel): void
    {
        foreach ($invoice->getLines() as $line) {
            $product = $line->getProduct();
            if (!$product instanceof ProductCore) {
                continue;
            }

            $policy = $product->getTrackingPolicy();
            if (!$policy instanceof TrackingPolicy) {
                continue;
            }

            $tracksLots = $policy->tracksLotsOutbound();
            $tracksSerials = $policy->tracksSerialsOutbound();
            if (!$tracksLots && !$tracksSerials) {
                continue;
            }

            // Exempt while this line's promise is still backordered — see the class docblock. The
            // fact is read straight off the order line's own backordered units, which is exactly
            // what a `backordered` reservation row would otherwise say, with no ledger lookup needed
            // to say it.
            $orderLine = $line->getSalesOrderLine();
            if ($orderLine instanceof SalesOrderLine && QuantityScale::compare($orderLine->getBackorderedUnits(), 0) > 0) {
                continue;
            }

            $captured = $tracksLots ? $line->getLotId() !== null : ($line->getSerial() !== null && $line->getSerial() !== '');
            if ($captured) {
                continue;
            }

            throw new \DomainException(sprintf(
                'Invoice %s cannot be issued. %s (%s) requires a %s to be recorded before this invoice can leave Draft.',
                $invoiceLabel,
                $line->getName() !== '' ? $line->getName() : ($product->getSku() ?: $product->getName()),
                $product->getSku() ?: ('#' . ($product->getId() ?? '?')),
                $tracksLots ? 'lot' : 'serial',
            ));
        }
    }
}
