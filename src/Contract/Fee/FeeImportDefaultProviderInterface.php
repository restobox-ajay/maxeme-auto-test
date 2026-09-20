<?php

declare(strict_types=1);

namespace App\Contract\Fee;

use App\Entity\ProductCore;

/**
 * Optional companion to FeeFieldProviderInterface — lets a fee calculator seed its
 * own per-product eligibility the moment a product is created via CSV import, so
 * staff isn't hand-toggling FeeFieldProviderInterface's checkbox on every SKU.
 * ProductImportService only calls this for newly-created rows, so it never
 * overwrites an admin's existing per-product choice on a later re-import.
 */
interface FeeImportDefaultProviderInterface
{
    public function applyImportDefault(ProductCore $product): void;
}
