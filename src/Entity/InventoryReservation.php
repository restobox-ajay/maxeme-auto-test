<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * One row of a document's inventory-reservation ledger (#539 stage 3).
 *
 * There are two implementations and deliberately not one table with a nullable order_id/invoice_id:
 * OrderInventoryReservation holds the sales order's `sales_hold`, InvoiceInventoryReservation holds
 * the invoice's `pending`/`approved`. The nullable-pair shape was explicitly declined — it is the
 * polymorphic FK this codebase refuses everywhere else (lines, addresses and logs all take a
 * concrete class per document subtype), and it needs a check constraint to express "exactly one of
 * these is set" that two concrete entities get from the type system for free.
 *
 * What the two DO share is their shape, which is what lets one reconciler
 * (InventoryReservationReconciler) write both instead of two divergent copies of the same
 * diff-against-a-durable-ledger logic. This interface is that shape, and nothing more: the document
 * each row belongs to is deliberately absent from it, because the reconciler never needs to ask —
 * the InventoryReservationSubject it was handed already knows.
 *
 * Same rationale as DocumentLine: a mapped superclass cannot parametrize targetEntity, so each
 * subtype owns its own rows and only the shape is shared through an interface.
 */
interface InventoryReservation
{
    public function getProduct(): ProductCore;

    public function getWarehouse(): Warehouse;

    /** One of the implementing class's own BUCKET_* constants — never a bucket it cannot hold. */
    public function getBucket(): string;

    public function setBucket(string $bucket): static;

    public function getQuantity(): string;

    public function setQuantity(string|int|float $quantity): static;

    /**
     * The portion of getQuantity() already absorbed by a product-import recount. Meaningful only in
     * the approved bucket, so it is always 0 on a sales-hold row — see the note on
     * OrderInventoryReservation::$syncedQuantity for why the column is carried by both anyway.
     */
    public function getSyncedQuantity(): string;

    public function setSyncedQuantity(string|int|float $syncedQuantity): static;

    /** What actually reaches ProductInventory: max(0, quantity - syncedQuantity). */
    public function getEffectiveQuantity(): string;

    /**
     * The InventoryLot this hold is scoped to, or null when the document line it came from named
     * none — every hold before this widening, and every hold today on a product nobody has opted
     * into lot tracking. A plain id, not an association: see OrderInventoryReservation::$lotId for
     * why a core entity may not hold a compile-time reference to InventoryDepthBundle's InventoryLot.
     */
    public function getLotId(): ?int;

    public function setLotId(?int $lotId): static;

    /** The serial this hold is scoped to, or null when the line named a lot or named neither. */
    public function getSerial(): ?string;

    public function setSerial(?string $serial): static;

    public function touch(): static;
}
