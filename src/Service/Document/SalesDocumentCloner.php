<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\AbstractSalesDocument;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValueOrder;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceStatus;
use App\Repository\CustomFieldValueRepository;
use App\Service\EstimateNumberGenerator;
use App\Service\InvoiceNumberGenerator;
use App\Service\OrderNumberGenerator;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Make another like this" — a sell-side document copied into a new DRAFT of the same type.
 *
 * A wholesale staple: the same customer orders the same twelve lines every month, and retyping them
 * is both slow and the place a quantity gets fat-fingered. Zoho carries Clone in the overflow menu
 * of all three sell-side documents; this is that action for the estimate, the sales order and the
 * invoice.
 *
 * ## The rule, in one sentence
 *
 * **A clone copies what the document WAS. It copies nothing recording what HAPPENED TO it.**
 *
 * That single line settles every field below, and it is the same sentence
 * {@see \App\EventSubscriber\DocumentLockFlushGuard} uses to decide what a lock freezes — deliberately, so
 * there is one rule to learn rather than two that can drift.
 *
 * ### What it was, and therefore copies
 *
 * The customer and the identity frozen against them, the PO number, the raising user, the source,
 * the special instructions, the fulfilment region label, the shipping method, every product line
 * (product, name, location, SKU, quantity, weight, unit, tax code, cost, price, subtotal, batch and
 * the row order), the fee/charge rows — which is where shipping lives — the coupon codes, the tax
 * line snapshot, the three money columns, the payment method and term, the frozen address
 * snapshots, and the order's custom field values.
 *
 * ### What happened to it, and therefore does not
 *
 * The document number (a fresh one is drawn), the status (always Draft), `createdAt` and every
 * other timestamp including `document_date`, `invoice_date` and `due_date`, the timeline, the
 * payments and the payment status, the inventory reservations, the estimate's `converted_order_id`,
 * the invoice's `sales_order_id` and its lines' `sales_order_line_id` attributions, the optimistic
 * `version` counter, the per-line stock-override rows, and the per-line backorder split
 * (`backordered_quantity`, `fulfillment_status`, `restock_eta`). None of those describe the
 * document; they describe its history, its money and its effect on stock.
 *
 * ## Addresses: the frozen snapshot is copied, never re-resolved
 *
 * This is the decision worth arguing, and it goes the same way
 * {@see \App\Service\EstimateConversionService} already settled it for estimate -> order:
 *
 *     "The order inherits the estimate's FROZEN addresses, and must not re-resolve them from the
 *      company address book. The team prices a quote against a specific delivery address; if the
 *      customer edits that address between quote and acceptance, re-resolving would quote one
 *      location and ship to another."
 *
 * The same hazard, worse. A clone is reached for precisely when a document is old — "we ship this
 * every month", "this one was wrong, make another" — so the gap between the original's freeze and
 * the clone is measured in months rather than minutes, and the customer is that much more likely to
 * have moved, merged a branch or closed a depot in between. Re-resolving would silently change
 * where the goods go, on a document the admin is copying *because* they want the same document
 * again, and the change would be invisible: the address panel would render a perfectly plausible
 * address that is simply not the one they meant.
 *
 * The counter-argument is real and is why this is stated rather than assumed: an address-book
 * correction (a typo, a new postcode) does not propagate into the clone, so the admin may copy a
 * stale address forward. That is the better failure. It is VISIBLE — the address is on the form the
 * clone opens on, and editing it is one field — whereas a silent re-resolve is not visible at all,
 * and the clone is a Draft precisely so that anything needing a change can be changed before it
 * means anything.
 *
 * `copyAddressFrom()` copies the values AND the `source_address_id` link, so a clone of a document
 * that was still link-only (a cart-shaped row) stays link-only and resolves live exactly as its
 * original did. Copying the snapshot does not force a freeze that was not there.
 *
 * ## Prices: copied as they were, never re-priced
 *
 * Same answer, for a plainer reason. Re-pricing is arguably more useful — the customer's price list
 * may have moved and the clone would be right about today. It is also unpredictable in the one
 * direction that costs money: the admin presses Clone expecting the same document and gets a
 * different total, with no statement of what moved or why, on a screen that shows only the new
 * figures. A quantity they can check; a per-line price change three lines down they cannot.
 *
 * Copying is also what both existing copiers in this application already do — the estimate -> order
 * conversion and the invoice raised from an order — so a clone that re-priced would make "copy"
 * mean two different things one screen apart.
 *
 * It is said out loud rather than left to be discovered: the flash message the controllers raise
 * names the prices as carried over and names re-pricing as the thing to do next if that is not
 * wanted. A silent copy and a silent re-price are equally bad; the difference is the sentence.
 *
 * ## Status: Draft, through exactly one call site per document
 *
 * Each document's reset to Draft happens in ONE place here — `draftEstimate()`, `draftOrder()` and
 * `draftInvoice()` below — rather than being spread through the copy, so whatever changes about an
 * entity's status door has one line per document to change. The status seam's consolidation onto a
 * single `setStatus()` gate went through without touching any of the three.
 *
 * ## Transactions
 *
 * Persists, never flushes — the same contract `EstimateConversionService::convert()` states. The
 * caller owns the transaction, so the clone, its lines, its addresses and its custom field values
 * all commit together or not at all.
 */
final class SalesDocumentCloner
{
    public function __construct(
        private readonly EstimateNumberGenerator $estimateNumbers,
        private readonly OrderNumberGenerator $orderNumbers,
        private readonly InvoiceNumberGenerator $invoiceNumbers,
        private readonly CustomFieldValueRepository $customFieldValues,
    ) {
    }

    public function cloneEstimate(Estimate $original, EntityManagerInterface $entityManager): Estimate
    {
        $copy = new Estimate();
        $copy->setDocumentNumber($this->estimateNumbers->next($entityManager));
        $this->draftEstimate($copy);
        $this->copyHeader($original, $copy);

        foreach ($original->getLines() as $line) {
            $copy->addLine(
                $this->copyLineFields($line, new EstimateLine())
                    // Nullable on a quote and only here: "TBD" is a real state for a quote's money,
                    // so a null price must stay null rather than becoming '0.00'.
                    ->setPrice($line->getPrice())
                    ->setSubtotal($line->getSubtotal()),
            );
        }

        $entityManager->persist($copy);

        return $copy;
    }

    public function cloneOrder(SalesOrder $original, EntityManagerInterface $entityManager): SalesOrder
    {
        $copy = new SalesOrder();
        $copy->setOrderNumber($this->orderNumbers->next($entityManager));
        $this->draftOrder($copy);
        $this->copyHeader($original, $copy);
        $copy->setPaymentMethod($original->getPaymentMethod());
        $copy->setPaymentTerm($original->getPaymentTerm());

        foreach ($original->getLines() as $line) {
            $copy->addLine(
                $this->copyLineFields($line, new SalesOrderLine())
                    ->setPrice($line->getPrice())
                    ->setSubtotal($line->getSubtotal())
                    ->setBatch($line->getBatch())
                    ->setLotId($line->getLotId())
                    ->setSerial($line->getSerial()),
                // backorderedQuantity, fulfillmentStatus and restockEta are deliberately left at
                // their constructor values: a split says which of THIS order's units were promised
                // rather than held, which is a fact about a reservation the clone has not made.
            );
        }

        $entityManager->persist($copy);
        $this->copyOrderCustomFields($original, $copy, $entityManager);

        return $copy;
    }

    public function cloneInvoice(Invoice $original, EntityManagerInterface $entityManager): Invoice
    {
        $copy = new Invoice();
        $copy->setDocumentNumber($this->invoiceNumbers->next($entityManager));
        $this->draftInvoice($copy);
        $this->copyHeader($original, $copy);
        $copy->setPaymentMethod($original->getPaymentMethod());
        $copy->setPaymentTerm($original->getPaymentTerm());

        foreach ($original->getLines() as $line) {
            $copy->addLine(
                $this->copyLineFields($line, new InvoiceLine())
                    ->setPrice($line->getPrice())
                    ->setSubtotal($line->getSubtotal())
                    ->setBatch($line->getBatch())
                    ->setLotId($line->getLotId())
                    ->setSerial($line->getSerial()),
                // setSalesOrderLine() is NOT called. That column attributes billed quantity back to
                // an order line, and a clone has billed nothing — carrying it would make the
                // original order read as over-invoiced by the whole of this copy.
            );
        }

        $entityManager->persist($copy);

        return $copy;
    }

    /**
     * Estimate -> Draft. The same shape as `draftOrder()` below.
     *
     * Deliberately NOT `setStatus('Draft', $actor)`: that is the seam's one door and it writes a
     * timeline row on a real move. Here there is no move to record — a new Estimate is born
     * 'Draft' — and a clone's timeline must be empty, so the assertion is what is written instead.
     *
     * It also cannot be a write, and this is the half the old enum setter hid. A clone is copied
     * from a quote that may be Accepted, and `Estimate::setStatus()` refuses every move out of
     * Accepted, so a copy that had inherited its source's status could not be reset to Draft at all.
     * It does not inherit it — `$copy` is a fresh Estimate whose status has never been touched —
     * and this assertion is what proves that rather than assuming it.
     */
    private function draftEstimate(Estimate $copy): void
    {
        if (!$copy->isStatus('Draft')) {
            throw new \LogicException(sprintf(
                'A cloned estimate must start as a Draft; this one started as %s.',
                $copy->getStatus(),
            ));
        }
    }

    /**
     * SalesOrder -> Draft. The second of the three.
     *
     * Deliberately NOT `setStatus('Draft', $actor)`: that is the seam's one door and it writes a
     * timeline row on a real move. Here there is no move to record — a new SalesOrder is born
     * 'Draft' — and a clone's timeline must be empty, so the assertion is what is written instead.
     */
    private function draftOrder(SalesOrder $copy): void
    {
        if (!$copy->isStatus('Draft')) {
            throw new \LogicException(sprintf(
                'A cloned order must start as a Draft; this one started as %s.',
                $copy->getStatus(),
            ));
        }
    }

    /**
     * Invoice -> Draft. The third.
     *
     * Every move on an invoice writes an `InvoiceLog` row — through `setStatus()` now, whether the
     * caller named the target itself or came via `issue()` — and a clone must write none. A new
     * Invoice is already `InvoiceStatus::Draft`, so the assertion is the whole of the reset, for the
     * same reason as `draftOrder()` above: there is nothing to set, only something to be sure of.
     */
    private function draftInvoice(Invoice $copy): void
    {
        if (!$copy->isStatus('Draft')) {
            throw new \LogicException(sprintf(
                'A cloned invoice must start as a Draft; this one started as %s.',
                $copy->getStatus(),
            ));
        }
    }

    /**
     * Everything the three documents share, which is everything on `AbstractSalesDocument` bar the
     * dates and the identity columns each subclass owns.
     *
     * `document_date` is NOT copied. It is a calendar date recording when the document was raised,
     * and a copy raised today is not the original's date — a clone of a March order dated March
     * would age into every overdue report the moment it was saved. Leaving it null hands it to
     * `SalesDocumentDateStamp`, which stamps the display timezone's today at persist time. That is
     * the same path admin create already takes, so a clone is dated exactly as a hand-raised
     * document is.
     */
    private function copyHeader(AbstractSalesDocument $original, AbstractSalesDocument $copy): void
    {
        $copy->setCompany($original->getCompany());
        // The FROZEN identity, not the live company's — setCompany() above seeded it from the live
        // row, and this overwrites that. Same rule the addresses follow, and the same reason: a
        // document raised for one legal entity must not be copied into one naming another.
        $copy->copyCompanySnapshotFrom($original);

        $copy->setPoNumber($original->getPoNumber());
        $copy->setUserName($original->getUserName());
        $copy->setSource($original->getSource());
        $copy->setSpecialInstructions($original->getSpecialInstructions());
        $copy->setShippingMethod($original->getShippingMethod());
        $copy->setFulfillmentRegion($original->getFulfillmentRegion());
        // The charge rows come across whole, and they are where shipping lives — copying a shipping
        // figure without them would leave a total the document could not account for.
        $copy->setFeeLines($original->getFeeLines());
        $copy->setCouponCodes($original->getCouponCodes());
        $copy->setTaxLines($original->getTaxLines());
        $copy->setSubtotal($original->getSubtotal());
        $copy->setTax($original->getTax());
        $copy->setTotal($original->getTotal());

        foreach ($original->getAddresses() as $address) {
            $copy->copyAddressFrom($address);
        }
    }

    /**
     * The line fields every sell-side line entity shares. Price and subtotal are set by the caller
     * because only the quote's are nullable.
     *
     * @template T of EstimateLine|SalesOrderLine|InvoiceLine
     *
     * @param EstimateLine|SalesOrderLine|InvoiceLine $line
     * @param T                                       $copy
     *
     * @return T
     */
    private function copyLineFields(object $line, object $copy): object
    {
        $this->copyQuantity($line, $copy);

        return $copy
            ->setProduct($line->getProduct())
            ->setName($line->getName())
            ->setLocation($line->getLocation())
            ->setSku($line->getSku())
            ->setWeight($line->getWeight())
            ->setUnit($line->getUnit())
            ->setTaxCode($line->getTaxCode())
            ->setCost($line->getCost())
            // The row order is part of what the document was — a packing list read top to bottom
            // is a different document with the rows shuffled.
            ->setSortOrder($line->getSortOrder());
    }

    /**
     * The quantity AND the denomination it was said in (#659).
     *
     * `setQuantity()` alone would be wrong on any line entered in a packaging unit. Every base
     * setter calls `DenominatedQuantity::forgetEnteredExpression()`, so a line typed as "3 BOX-12"
     * would clone as "36" with no unit — the same goods, restated into a denomination nobody chose,
     * on a copy whose whole purpose is to be the same document again. What the person typed is part
     * of what the document WAS.
     *
     * The pair is re-resolved through `setEnteredQuantity()` rather than assigned, because that is
     * the trait's one writer of the triple and the resolution is the identical multiplication the
     * original went through — same entered figure, same unit row, same product base. It lands on the
     * same base figure by construction. `OrderInvoicingService::lineFrom()` carries a denomination
     * across documents exactly this way.
     *
     * @param EstimateLine|SalesOrderLine|InvoiceLine $line
     * @param EstimateLine|SalesOrderLine|InvoiceLine $copy
     */
    private function copyQuantity(object $line, object $copy): void
    {
        $unit = $line->getUnitOfMeasure();

        if ($unit === null) {
            // Entered in base units, which is every row written before #659 — the base figure IS
            // the entered figure, and there is no pair to carry.
            $copy->setQuantity($line->getQuantity());

            return;
        }

        $copy->setEnteredQuantity(
            $line->getQuantityEntered(),
            $unit,
            LineDenomination::baseUnitOf($line->getProduct()),
        );
    }

    /**
     * The order's custom field values, copied slug for slug.
     *
     * Only the SALES ORDER has them: `CustomFieldValueRepository::MAP` carries `order` and not
     * `estimate` or `invoice`, so there is no table for the other two to copy from. That is read off
     * the repository rather than assumed — if a quote or invoice object type is added later, the
     * call here is what has to grow, and a missing branch is a visible gap rather than a silent one.
     *
     * Written as entities rather than through `setValue()` because `setValue()` takes an object ID
     * and the clone has none until flush. Pointing the value rows at the unflushed clone object lets
     * Doctrine order the inserts itself, which keeps this on the caller's transaction like the rest.
     */
    private function copyOrderCustomFields(SalesOrder $original, SalesOrder $copy, EntityManagerInterface $entityManager): void
    {
        $originalId = $original->getId();
        if ($originalId === null) {
            return;
        }

        $values = $this->customFieldValues->getValuesForObject(CustomFieldDefinition::OBJECT_TYPE_ORDER, $originalId);
        if ($values === []) {
            return;
        }

        $definitions = $entityManager->getRepository(CustomFieldDefinition::class)
            ->findBy(['objectType' => CustomFieldDefinition::OBJECT_TYPE_ORDER]);

        foreach ($definitions as $definition) {
            $value = $values[$definition->getSlug()] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $entityManager->persist(
                (new CustomFieldValueOrder())
                    ->setDefinition($definition)
                    ->setOrder($copy)
                    ->setValue($value),
            );
        }
    }
}
