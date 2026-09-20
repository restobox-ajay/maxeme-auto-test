<?php

declare(strict_types=1);

namespace App\Contract\Fee;

use App\Entity\ProductCore;

/**
 * Optional companion to FeeFieldProviderInterface — lets a fee calculator claim a
 * per-row CSV column (e.g. 'fee__BC-tsbc') so a bulk import can set/clear its
 * eligibility explicitly per SKU, the same choice FeeFieldProviderInterface's own
 * checkbox makes one product at a time. Unlike FeeImportDefaultProviderInterface
 * (category-seeded default, new rows only), this runs for every row — new or
 * existing — because an explicit CSV value is an admin's stated choice, not a
 * fallback, so it always wins over both the category default and an older stored
 * value.
 */
interface FeeImportColumnProviderInterface
{
    /** The CSV column header this provider claims, e.g. 'fee__BC-tsbc'. */
    public function getImportColumnName(): string;

    public function applyImportValue(ProductCore $product, bool $enabled): void;
}
