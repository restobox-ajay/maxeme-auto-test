<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;

/**
 * One posted row, resolved — what {@see SellSideLineReconciler} hands back for a caller to write
 * onto its OWN concrete line entity (SalesOrderLine/InvoiceLine/EstimateLine). The reconciler
 * never constructs or mutates a line itself: the three entities share no common setter surface
 * ({@see \App\Entity\DocumentLine} is read-only, by design — see its own docblock), so "decide what
 * a row means" and "write it onto this document's own entity type" stay two different jobs, done by
 * two different classes.
 *
 * $existingId is null for a brand-new line, non-null for a posted row that matched an existing one
 * by id — the caller's own cue for `$existing[$id] ?? new WhateverLine()`.
 */
final class ResolvedSellSideLine
{
    public function __construct(
        public readonly ?int $existingId,
        public readonly ?ProductCore $product,
        public readonly string $name,
        public readonly ?string $location,
        public readonly ?string $sku,
        public readonly ?string $weight,
        public readonly ?string $unit,
        public readonly ?string $taxCode,
        public readonly string $cost,
        /** Full precision — what the subtotal is computed from. Null means "TBD" (Estimate only). */
        public readonly ?string $price,
        /** The same rate, at the scale its denomination needs — what the price COLUMN stores. */
        public readonly ?string $priceForColumn,
        public readonly ?string $batch,
        public readonly ?int $lotId,
        public readonly ?string $serial,
        public readonly ?\DateTimeImmutable $restockEta,
        public readonly int $sortOrder,
        public readonly float $enteredQuantity,
        public readonly float $baseQuantity,
        public readonly ?UnitOfMeasure $lineUnit,
        public readonly ?UnitOfMeasure $baseUnit,
        /** Null alongside a null $price — an unpriced (TBD) line contributes nothing. */
        public readonly ?string $lineSubtotal,
    ) {
    }
}
