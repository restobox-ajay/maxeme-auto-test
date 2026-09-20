<?php

declare(strict_types=1);

namespace App\Contract\Product;

/**
 * The one question core's shared product picker ({@see \App\Service\Product\ProductPicker}) asks
 * the barcode layer: "which products does this typed/scanned string name, besides by name or SKU?"
 *
 * Same seam, same reason, as {@see \App\Contract\Inventory\LotAvailabilityProviderInterface}:
 * `product_barcode` (UPC/EAN/GTIN/vendor part/customer part) belongs to modules/BarcodeBundle,
 * which is deletable, and the picker — shared by every product-search screen in the app, sell and
 * buy side alike — may not hold a compile-time reference to it. With no provider registered — the
 * bundle deleted, or switched Inactive — matchingProductIds() answers [] for every term, and the
 * picker's own name/SKU match is the whole answer, exactly as it was before this seam existed.
 *
 * getSource() names the owning bundle so the App Management Active/Inactive kill-switch applies to
 * the provider without the provider checking its own status — same contract as every other provider
 * on this seam.
 */
interface ProductBarcodeSearchProviderInterface
{
    /** The owning bundle's source, checked against App\Repository\BundleStatusRepository. */
    public function getSource(): string;

    /**
     * Product ids whose UPC, EAN, GTIN, vendor part number or customer part number contains $term —
     * the same substring match the picker's own name/SKU search already runs.
     *
     * @return list<int>
     */
    public function matchingProductIds(string $term): array;

    /**
     * Every barcode code registered against each of $productIds — tier 1's (under `INLINE_LIMIT`)
     * answer to the same question `matchingProductIds()` answers for tier 2. Tier 1 renders the
     * whole catalog as a plain `<select>` and searches it in the browser with no request at all, so
     * there is nothing here to search remotely; the codes ride along as `data-*` search text instead
     * — see `templates/admin/_partials/product_field.html.twig`.
     *
     * @param list<int> $productIds
     *
     * @return array<int, list<string>> only ids that carry at least one code are present
     */
    public function codesForProducts(array $productIds): array;
}
