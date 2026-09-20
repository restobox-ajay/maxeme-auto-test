# ShippingBulkBundle

**Type:** Shipping · **Name:** Bulk Delivery · **Edit route:** `admin_bundle_shipping_bulk`

## What it does

Offers an LTL "Bulk Delivery" shipping option for carts containing at least one product whose `shippingClass` is `'bulk'` (`ProductCore::getShippingClass()`). `BulkShippingCalculator::supports()` checks every cart item and matches if any one of them is flagged bulk. The single option returned has a 14-day delivery estimate and a flat rate that varies by destination province:

| Province | Rate |
|---|---|
| BC | $250.00 |
| AB | $500.00 |
| Everywhere else | $1000.00 |

## How it's configured

The rates and delivery estimate are hardcoded constants (`BulkShippingCalculator::RATES`, `RATE_DEFAULT`, `DELIVERY_DAYS`) — the admin screen at `admin_bundle_shipping_bulk` (`/admin/bundles/shipping/bulk`) is **read-only**, it displays the current rate table but has no form to change it. Changing the rates requires editing `modules/ShippingBulkBundle/src/Shipping/BulkShippingCalculator.php`.

## External dependencies

None. Product bulk eligibility is set directly on `ProductCore::$shippingClass`, not managed by this bundle.
