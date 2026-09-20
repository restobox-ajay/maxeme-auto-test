<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * A {@see DocumentLine} that also has a cost — what we pay, as opposed to `getPrice()`, what the
 * customer is charged. Separate from `DocumentLine` itself rather than widening it: `CartItem`
 * implements `DocumentLine` too, and a cart's money is live and price-only (see its own docblock)
 * — it has no cost to snapshot, and forcing one onto it would be inventing a concept that does not
 * apply there just to satisfy an unrelated reader. Implemented by `SalesOrderLine`/`InvoiceLine`/
 * `EstimateLine`, the three {@see \App\Service\Document\SellSideLineReconciler} actually reconciles.
 */
interface CostedDocumentLine extends DocumentLine
{
    public function getName(): string;

    public function getCost(): string;

    public function getSku(): ?string;

    public function getWeight(): ?string;

    public function getUnit(): ?string;
}
