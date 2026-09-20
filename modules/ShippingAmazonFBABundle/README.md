# ShippingAmazonFBABundle

**Type:** Shipping · **Name:** Amazon FBA · **Edit route:** `admin_bundle_shipping_amazon_fba`

## What it does

Offers a "Delivery by Amazon" shipping option, but only when **every** item in the cart is on an admin-maintained allowlist of eligible product IDs — `AmazonFBAShippingCalculator::supports()` returns `false` if any cart item's product ID isn't in the list, or if the list is empty. When it matches, the single option returned prices as $6.00 base + $1.00 per unit, with a 1-day delivery estimate.

## How it's configured

The admin screen at `admin_bundle_shipping_amazon_fba` (`/admin/bundles/shipping/amazon-fba`) lets an admin paste/edit a list of eligible product IDs (comma or whitespace separated). It's stored as a JSON-encoded array in the `shipping_amazon_fba_product_ids` `AppSetting` row (key defined by `AmazonFBAShippingCalculator::SETTING_KEY`) and read back via `App\Service\AppSettings`. The base rate ($6.00), per-unit rate ($1.00), and delivery estimate (1 day) are hardcoded, not admin-editable.

## External dependencies

None — this does not call any real Amazon FBA API; it's an allowlist-based flat-rate calculator that mimics the eligibility gate a real FBA integration would need.
