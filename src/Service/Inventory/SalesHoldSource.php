<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A kind of document, other than a sales order, that holds `sales_hold` through
 * InventoryReservationReconciler (e.g. a shop's repair order once the customer approves it).
 *
 * app:inventory-recalc rebuilds `sales_hold` from the sales orders' lines every hour; it adds what
 * every SalesHoldSource reports, so those holds survive the recount instead of being wiped.
 */
#[AutoconfigureTag(SalesHoldSource::class)]
interface SalesHoldSource
{
    /** @return array<string, string> "productId|warehouseId" => the quantity held there (a decimal string) */
    public function salesHoldSums(): array;
}
