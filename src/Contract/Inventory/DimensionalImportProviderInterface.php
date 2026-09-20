<?php

declare(strict_types=1);

namespace App\Contract\Inventory;

use App\Entity\ProductCore;
use App\Entity\Warehouse;

/**
 * How an import declares stock for a product the depth layer is tracking (#565).
 *
 * The import declares a total per product per warehouse. A dimensional product's stock is spread
 * across bins, lots and serials, so a file that says "47" cannot say **which** 47. Before this
 * there was no answer at all for what an import does to such a product.
 *
 * The answer, in one sentence: **the count is authoritative for the total; the identities catch up
 * afterwards.** Every case falls out of that.
 *
 * Core owns the file, the columns and the per-row plumbing; it does not own bins. So it hands the
 * declaration over here and the depth layer decides what it means for the rows. With no provider
 * registered — the bundle deleted or Inactive — core never calls this and the plain import runs
 * exactly as it always has.
 */
interface DimensionalImportProviderInterface
{
    /** The owning bundle's source, checked against App\Repository\BundleStatusRepository. */
    public function getSource(): string;

    /**
     * The advanced column names this provider understands, lower-cased.
     *
     * Core uses these for two things only: deciding whether a row carries any advanced data at all,
     * and ignoring them for simple-mode products. It never interprets the values.
     *
     * @return list<string>
     */
    public function advancedColumns(): array;

    /**
     * Apply one product's declaration for one warehouse.
     *
     * @param int                        $declaredTotal the product-level total, or the sum of $rows when rows were given
     * @param list<array<string, string>> $rows          zero or more bin/lot/serial/expiry declarations
     * @param bool                       $deleteUnspecified whether rows the file did not mention go to 0 or stay as they are
     *
     * @return list<string> human-readable warnings for the import summary — a short count is a
     *                      warning, never a failure
     *
     * @throws DimensionalImportRefusal when this product's declaration is self-contradictory. Core
     *                                  catches it, leaves the product entirely alone, names it in
     *                                  the summary and carries on with the rest of the file. A
     *                                  contradictory product must never abort the run: an
     *                                  implementation that does passes a naive "it was rejected"
     *                                  assertion while being unusable against a 5,000-row file.
     */
    /**
     * What the detail rows now hold as available for this product in this warehouse.
     *
     * Core needs it to re-baseline `product_inventory` after a count, and must not learn how the
     * depth layer stores rows to get it.
     */
    public function availableTotal(ProductCore $product, Warehouse $warehouse): int;

    public function applyDeclaration(
        ProductCore $product,
        Warehouse $warehouse,
        int $declaredTotal,
        array $rows,
        bool $deleteUnspecified,
    ): array;
}
