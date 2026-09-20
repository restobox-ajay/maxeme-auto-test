<?php

declare(strict_types=1);

namespace ProcurementBundle\Product;

use App\Entity\ProductCore;
use App\Service\Product\ProductPicker as CoreProductPicker;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Repository\VendorPriceRepository;

/**
 * The product field every purchase screen shares, priced against the vendor's rate card.
 *
 * ## What this replaces, and why it was worth replacing
 *
 * Every line-entry screen in this bundle asked for a product by typing its DATABASE ID into
 * `<input type="number" name="lines[0][product_id]">` — the purchase order, goods receipt, RFQ,
 * vendor return and the vendor rate card alike. One of them labelled the box `Product ID` in so
 * many words. Nobody knows product ids; the sell side has never asked anybody to.
 *
 * ## Where the three tiers live now (#8)
 *
 * In core, as `App\Service\Product\ProductPicker`, since Inventory Depth's lots and low-stock
 * screens needed the same field and **a bundle must not depend on another bundle**: modules are
 * discovered by `glob()` in `config/bundles.php` and switched off through `bundle_status`, so a
 * field owned here and included there would be a 500 on an inventory screen the day somebody
 * deleted purchasing. Core is the only layer neither bundle can delete. The full reasoning is on
 * that class.
 *
 * **This class kept its name, its constants and its exact public API** — including
 * `search(string, ?Vendor, int)` — because five screens here call it today and more are being
 * written. Nothing that includes `@Procurement/_product_field.html.twig` or injects
 * `ProcurementBundle\Product\ProductPicker` had to change.
 *
 * ## Why the buy side needs its own search and cannot call the sell side's
 *
 * `OrderController::searchOrderProducts()` resolves a CUSTOMER price: it takes a company and a
 * region and reads `ProductPricing` off that company's price list. A purchase screen has no
 * company and wants the opposite number — what this VENDOR charges US, which lives in
 * `vendor_price` (#637). So the paging, the two-character floor and the `hasMore` contract are
 * core's, shared with every other picker in the app; only the money is this bundle's, and it is
 * the one thing core must not know about. A core class type-hinting `Vendor` would invert the
 * dependency: `glob()` is free to delete the folder that class lives in.
 *
 * That also makes the picker agree with what the save already does. `PurchaseOrderController`
 * fills a blank line cost from the vendor's rate card (#637); before this the admin could not see
 * that number until after saving, which is a poor way to find out what something costs.
 */
final class ProductPicker
{
    /**
     * Restated from core rather than re-chosen, so the buy side switches to remote search at the
     * same catalog size as everything else. Kept as constants on this class because callers and
     * tests already name them here.
     */
    public const INLINE_LIMIT = CoreProductPicker::INLINE_LIMIT;

    public const SEARCH_PAGE_SIZE = CoreProductPicker::SEARCH_PAGE_SIZE;

    public const MIN_SEARCH_CHARS = CoreProductPicker::MIN_SEARCH_CHARS;

    public function __construct(
        private readonly CoreProductPicker $picker,
        private readonly VendorPriceRepository $vendorPrices,
    ) {
    }

    /**
     * True when the catalog is too big to inline, so the screen must render tier 2 + 3.
     */
    public function isRemote(): bool
    {
        return $this->picker->isRemote();
    }

    /**
     * The options a `<select>` should carry — the catalog, or just what the document names.
     *
     * @param list<int> $selectedIds product ids already on the document, kept when remote
     *
     * @return list<ProductCore>
     */
    public function options(array $selectedIds = []): array
    {
        return $this->picker->options($selectedIds);
    }

    /**
     * One page of matches for `$term`, priced against `$vendor`'s rate card when there is one.
     *
     * @return array{products: list<array<string, string>>, hasMore: bool}
     */
    public function search(string $term, ?Vendor $vendor = null, int $offset = 0): array
    {
        $page = $this->picker->searchPage($term, $offset);

        // One query for the page's costs, resolved BEFORE the loop. A findOneActiveFor() per
        // product is a textbook N+1 — 50 matches would mean 50 extra round trips per keystroke.
        $costs = $vendor instanceof Vendor ? $this->vendorPrices->unitCostsFor($vendor, $page['products']) : [];

        $rows = [];
        foreach ($page['products'] as $product) {
            $rows[] = [
                'id' => (string) $product->getId(),
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                // Empty string and not '0.00' when this vendor has no rate for the product: a
                // missing rate card entry is "we do not know what they charge", which the screen
                // must not render as a price of nothing. The save side makes the same distinction.
                'unitCost' => $costs[(int) $product->getId()] ?? '',
            ];
        }

        return ['products' => $rows, 'hasMore' => $page['hasMore']];
    }
}
