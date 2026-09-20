<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\Inventory\ShippedQuantityProviderInterface;
use App\Entity\Invoice;
use App\Enum\InvoiceShippingStatus;
use App\Repository\BundleStatusRepository;
use App\Service\QuantityScale;

/**
 * Recomputes an invoice's shipping status from what has actually shipped against its lines.
 *
 * The same shape as InvoicePaymentStatusDeriver next door, and for the same reason. A shipping
 * status somebody can type in is a second answer to a question the shipment rows already answer,
 * and it disagrees with them the first time a shipment is recorded, amended or voided by anything
 * that forgot to update it — which is how an invoice could sit at "Shipped" with nothing ever
 * picked against it, and how a part shipment could leave one reading "Not Shipped" forever.
 *
 * So there is no setShippingStatus(). This is the only thing that writes the column, through the
 * narrow Invoice::applyDerivedShippingStatus(), and InvoiceShippingStatusSubscriber calls it on
 * every write to an invoice. A bundle that records shipments calls this same deriver when its own
 * rows change — it never writes the column itself, so there is one writer whether or not a bundle
 * is installed.
 *
 * ## The rules
 *
 * | Status            | When                                                                   |
 * |-------------------|------------------------------------------------------------------------|
 * | Shipped           | every billed line is fully covered by shipments that still stand       |
 * | Partially Shipped | something has shipped, but at least one line is not fully covered      |
 * | Not Shipped       | nothing has shipped                                                    |
 *
 * ## Degradation is the point, not a caveat
 *
 * Core owns no shipment table. `App\Contract\Inventory\ShippedQuantityProviderInterface` is how it
 * asks whoever is listening, and the providers are gated on their bundle being Active — the same
 * `BundleStatusRepository::isActiveForInstance()` gate `CartInfoFieldResolver` and
 * `DocumentPrefixCatalogue` apply to their own seams, so switching a bundle off on App Management
 * has the same effect as never installing it.
 *
 * ## Completed means Shipped, bundle on or off
 *
 * This is a RULE, not a fallback for when nothing is listening, and it is read before any provider
 * is asked. `InvoiceStatus::Completed` is core's own statement that the goods went — `Invoice`'s own
 * docblock says so, and `InvoiceInventoryBucketResolver` already moves the whole balance to the
 * `shipped` bucket on it. A bundle being installed does not repeal that.
 *
 * Deriving it the other way round — asking the shipment rows first and letting them answer for a
 * Completed invoice — reads Not Shipped in two situations that are both ordinary rather than exotic:
 *
 *  - **Every invoice completed before this feature existed.** They carry no `ShipmentLine` rows and
 *    never will, so switching the bundle on would restate the entire back catalogue as unshipped.
 *  - **A completion whose auto-shipment refused.** `InvoiceShippingRemainderSubscriber` deliberately
 *    logs and swallows a `ShipmentException` rather than failing the status write, so an invoice can
 *    legitimately be Completed with no lines recorded against it.
 *
 * So shipment rows REFINE the answer below Completed — they are what tells Not Shipped from
 * Partially Shipped, which core cannot do alone — and they are never able to drag a Completed
 * invoice back down. Turning the bundle off changes what a Processing invoice reads; it does not
 * change what a Completed one reads.
 *
 * With nothing listening the invoice's own status is the whole of core's evidence, so the answer is
 * the two ends only. Never Partially Shipped: core has no partial information, and a middle value
 * invented from a lifecycle status would be a guess dressed as a fact. That is what the column meant
 * before any shipment bundle existed and what it goes back to meaning the moment one is switched
 * off; nothing about core's behaviour references, or needs, a bundle.
 *
 * ## Why Shipped is decided per line rather than on the totals
 *
 * Summing every line's shipped quantity and comparing it with every line's billed quantity would
 * call an invoice Shipped when line A went out twice over and line B never left the shelf. Each
 * line is therefore capped at its own billed quantity before the totals are added, so Shipped means
 * "no line is still owed", which is what the word is read as on a grid. `ShipmentService::ship()`
 * refuses to oversell a line today, so the cap changes no current answer — it is here so that the
 * rule does not silently depend on a guard living in a bundle that may be switched off.
 *
 * ## Quantities are compared in ten-thousandths, never as floats
 *
 * `invoice_line.quantity` and `shipment_line.quantity` are both `NUMERIC(14, 4)`, and fractional
 * quantities are real in this application. Scaling to whole ten-thousandths — the exact precision
 * of both columns — is the quantity-side counterpart of `Invoice::cents()`: it keeps the fractions
 * and drops the binary floating-point comparison that would otherwise leave a line 0.1 + 0.2 short
 * of 0.3 forever. Deliberately NOT `(int) round((float) $quantity)`, which reports 0.4 of a case as
 * nothing shipped and 1.6 as two.
 *
 * This was the one place that got it right while a shipment bundle rounded, and for a while the
 * bundle kept a copy of this one private line in a class of its own — `ShipmentQuantity` — because
 * core could not expose it. Core exposes it now: `App\Service\QuantityScale` is the service, the
 * bundle-local copy is deleted, and this method delegates to it. Core still names no bundle.
 */
final class InvoiceShippingStatusDeriver
{
    /**
     * @param iterable<ShippedQuantityProviderInterface> $shippedQuantityProviders
     */
    public function __construct(
        private readonly iterable $shippedQuantityProviders,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    /**
     * Puts $invoice into the shipping status its shipments imply, and writes the timeline entry when
     * that is a real change.
     *
     * Returns true when the invoice was moved, so the caller knows whether a flush is owed.
     */
    public function recalculate(Invoice $invoice): bool
    {
        $previous = $invoice->getShippingStatus();
        $target = $this->statusFor($invoice);

        if (!$invoice->applyDerivedShippingStatus($target)) {
            return false;
        }

        // Written here rather than by whoever triggered the flush, for the reason
        // InvoicePaymentStatusDeriver writes its own: this is the only place that knows a transition
        // happened. The actor is System because nobody performed this — recording the shipment is
        // the act, and whatever recorded it signed its own entry; this is its consequence.
        $invoice->queueActivityLogEntry()
            ->setUserName(DocumentActor::system()->displayName)
            ->setComment(sprintf('Shipping status changed from %s to %s.', $previous->value, $target->value))
            ->setType('System');

        return true;
    }

    /** The shipping status $invoice's shipments imply, ignoring what it currently says. */
    public function statusFor(Invoice $invoice): InvoiceShippingStatus
    {
        // Completed is core's own statement that the goods went, and it holds whatever is listening.
        // Read off the status string rather than getStatusEnum(), so a row carrying a status this
        // application does not recognise answers below rather than throwing on a grid render.
        if ($invoice->getStatus() === 'Completed') {
            return InvoiceShippingStatus::Shipped;
        }

        $providers = $this->activeProviders();
        if ($providers === []) {
            // Nothing is listening, so the invoice's own status was the whole of the evidence and it
            // has already been read. Never Partially Shipped: core has no partial information, and a
            // middle value invented from a lifecycle status would be a guess dressed as a fact.
            return InvoiceShippingStatus::NotShipped;
        }

        $billed = QuantityScale::canonical(0);
        $shipped = QuantityScale::canonical(0);

        foreach ($invoice->getLines() as $line) {
            $lineBilled = QuantityScale::canonical($line->getQuantity());
            if (QuantityScale::compare($lineBilled, 0) <= 0) {
                // A zero- or negative-quantity line (a discount row, a correction) is not goods, so
                // it neither waits on a shipment nor counts as one that arrived.
                continue;
            }

            $lineShipped = QuantityScale::canonical(0);
            foreach ($providers as $provider) {
                $lineShipped = QuantityScale::add($lineShipped, $provider->shippedQuantityForInvoiceLine($line));
            }

            $billed = QuantityScale::add($billed, $lineBilled);
            $shipped = QuantityScale::add($shipped, QuantityScale::compare($lineShipped, $lineBilled) <= 0 ? $lineShipped : $lineBilled);
        }

        if (QuantityScale::compare($billed, 0) <= 0) {
            // Nothing billed to measure against — no lines, or every line a zero-quantity discount
            // or correction row. "Shipped" and "not shipped" are both vacuously true of nothing, and
            // the invoice is not Completed or it would have answered above.
            return InvoiceShippingStatus::NotShipped;
        }

        if (QuantityScale::compare($shipped, 0) <= 0) {
            return InvoiceShippingStatus::NotShipped;
        }

        return QuantityScale::compare($shipped, $billed) >= 0
            ? InvoiceShippingStatus::Shipped
            : InvoiceShippingStatus::PartiallyShipped;
    }

    /**
     * The providers whose bundle is switched on right now.
     *
     * Resolved per call rather than cached: a bundle can be activated or deactivated on App
     * Management mid-process, and this runs inside a flush where a stale answer would write the
     * wrong status to a row.
     *
     * @return list<ShippedQuantityProviderInterface>
     */
    private function activeProviders(): array
    {
        $active = [];
        foreach ($this->shippedQuantityProviders as $provider) {
            if ($this->bundleStatusRepo->isActiveForInstance($provider)) {
                $active[] = $provider;
            }
        }

        return $active;
    }

}
