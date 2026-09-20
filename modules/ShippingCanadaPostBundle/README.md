# ShippingCanadaPostBundle

**Type:** Shipping · **Name:** Canada Post · **Edit route:** `admin_bundle_shipping_canada_post`

## What it does

Offers two Canada Post shipping options — Ground and Express — for any cart that has at least one item and contains **no** bulk-shipping-class products (`CanadaPostShippingCalculator::supports()` returns `false` outright if any cart item is `shippingClass === 'bulk'`, since those are handled by `ShippingBulkBundle` instead). Both options price as a flat base plus a per-unit amount scaled by total cart quantity:

| Option | Base | Per unit | Delivery |
|---|---|---|---|
| Ground | $10.00 | $2.50 | 5 days |
| Express | $21.84 | $4.50 | 2 days |

## How it's configured

The rates are hardcoded constants (`CanadaPostShippingCalculator::GROUND_BASE`/`GROUND_PER_UNIT`/`GROUND_DAYS`/`EXPRESS_BASE`/`EXPRESS_PER_UNIT`/`EXPRESS_DAYS`) — the admin screen at `admin_bundle_shipping_canada_post` (`/admin/bundles/shipping/canada-post`) is **read-only**, displaying the current rates with no form to change them. Changing the rates requires editing `modules/ShippingCanadaPostBundle/src/Shipping/CanadaPostShippingCalculator.php`.

## External dependencies

None — despite the name, this is a flat-rate calculator, not a live integration with the real Canada Post rating API.
