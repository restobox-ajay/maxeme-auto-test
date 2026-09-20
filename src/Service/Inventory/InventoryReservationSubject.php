<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\AbstractSalesDocument;
use App\Entity\InventoryReservation;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One document, described in the only three terms InventoryReservationReconciler needs (#539 stage
 * 3): which bucket it currently holds, which quantities it holds there, and which ledger rows record
 * that.
 *
 * This interface exists so the reconciliation is SHARED rather than copied. It is a hundred and
 * eighty lines of diffing a computed target against a durable ledger, and stage 3 gave the app a
 * second reservation entity that needs exactly the same treatment; two copies of it is how a bucket
 * ends up disagreeing with its own ledger — silently, because both copies keep looking right on
 * their own. Everything that actually differs between a sales order and an invoice is here, and
 * everything that does not is in the reconciler.
 *
 * ## One document may be two subjects (#548)
 *
 * Backorder did not need a third reservation entity or a second reconciler: an order's promise of
 * goods it does not have is the same "computed target versus durable ledger" problem, so it is a
 * second implementation of THIS interface over the same table in a different bucket. The two
 * subjects for one order are reconciled independently and each sees only its own bucket's rows.
 *
 * @see SalesOrderReservationSubject          the order's `sales_hold` on the stocked part of its uninvoiced quantity
 * @see SalesOrderBackorderReservationSubject the order's `backordered` on the part with no stock behind it
 * @see InvoiceReservationSubject             the invoice's `pending`/`approved` on what it bills
 */
interface InventoryReservationSubject
{
    /**
     * The bucket this document holds right now, or null when it holds none at all.
     *
     * Null is "no bucket", not "no quantity": a document may legitimately return a bucket and then
     * no lines, which is how a fully invoiced order's sales hold falls to zero without needing a
     * status rule of its own.
     */
    public function targetBucket(): ?string;

    /**
     * What this document holds, one entry per line, before region resolution.
     *
     * ## Quantities are DECIMAL STRINGS
     *
     * They used to be whole PHYSICAL units — "the same `(int) round((float) $qty)` every other
     * consumer of a line quantity performs" — which is what made an invoice line for 1.5 hold two
     * units of real stock and a line for 0.4 hold none. The ledger rows and the cache columns are
     * `decimal(14, 4)` and now read as decimals in PHP too, so there is nothing left to round to.
     *
     * Reconciliation is comparison and subtraction, and those have to be exact — a float subtraction
     * repeated per edit drifts. `App\Service\QuantityScale`'s bcmath helpers (`compare()`, `add()`,
     * `sub()`) are how the reconciler stays exact without going through a scaled-integer detour.
     *
     * `lot`/`serial` are the line's own raw identity fields — every implementation yields whatever
     * SalesOrderLine::getLotId()/getSerial() or InvoiceLine's equivalents already hold, with no
     * policy check of its own. The reconciler is what decides whether a product is actually opted
     * into lot/serial tracking (TrackingPolicy composite with InventoryModeResolver::isDimensional())
     * and blanks these two fields out when it is not — one gate, shared by every subject, rather than
     * three copies of the same composite check (2026-09-14 lot/serial/expiry plan).
     *
     * @return iterable<array{product: ProductCore, location: ?string, quantity: string, lot: ?int, serial: ?string}>
     */
    public function heldLines(): iterable;

    /**
     * The document header's region name, used when a line does not name its own — admin-entered
     * lines carry a location each, checkout and conversion documents only set the header.
     */
    public function fallbackRegionName(): ?string;

    /** The ledger rows this document currently owns. @return list<InventoryReservation> */
    public function existingReservations(EntityManagerInterface $entityManager): array;

    /** A new, unpersisted ledger row already pointed at this document. */
    public function newReservation(ProductCore $product, Warehouse $warehouse): InventoryReservation;

    /**
     * The InventoryBucketChangeLog action recorded for changes this document causes, e.g.
     * 'order_reconciled'. Distinct per document type so a change history says which side moved.
     */
    public function changeAction(): string;

    /** The document itself, for the reconciled event and for log context. */
    public function document(): AbstractSalesDocument;

    /** Human-readable identifier for warning logs — the order or invoice number. */
    public function reference(): string;
}
