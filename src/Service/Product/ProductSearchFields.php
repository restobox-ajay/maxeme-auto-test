<?php

declare(strict_types=1);

namespace App\Service\Product;

/**
 * The one list every product search reads instead of each hand-writing its own — the single
 * question this class answers is "what fields does a product search match, and which wins when a
 * term matches more than one?"
 *
 * Before this, `ProductPicker::searchPage()`, `OrderController::searchOrderProducts()` and
 * `StockController::productLookup()`/`productLookupSuggest()` each carried their own copy of
 * `p.name LIKE :term OR p.sku LIKE :term`, and none of them knew about barcodes until each was
 * fixed one at a time (#707). Every server-side product search now goes through
 * {@see ProductPicker}, which reads this list; `app.js`'s tier-1 client-side filter keeps its own
 * copy of the same field set in step (there is no runtime channel from PHP to a plain `<select>`
 * rendered once at page load, so it is a second hardcoded list by necessity, not an oversight).
 *
 * Order is priority, earliest first: when a term matches more than one field, a match on an
 * earlier-listed field ranks above one on a later-listed field. Hardcoded rather than a setting —
 * every install gets the same order for now. Nothing about `ProductPicker`'s use of this constant
 * assumes it can't become a real per-install setting later; that is a change to how this list is
 * produced, not to anything that reads it.
 */
final class ProductSearchFields
{
    public const SKU = 'sku';
    public const NAME = 'name';
    public const BARCODE = 'barcode';

    /** @var list<string> */
    public const ORDER = [self::SKU, self::NAME, self::BARCODE];
}
