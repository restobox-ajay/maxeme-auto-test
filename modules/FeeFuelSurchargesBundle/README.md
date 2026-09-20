# FeeFuelSurchargesBundle

**Type:** Fee · **Name:** Fuel Surcharges · **Edit route:** `admin_bundle_fee_fuel_surcharges_config`

## What it does

Applies a flat, per-order fuel surcharge to carts shipping anywhere outside BC (`FuelSurchargeFeeCalculator::supports()` matches any province except `'BC'` and non-empty). The rate depends on the destination: a lower rate (default $5.00) for AB/SK/MB, a higher rate (default $15.00) everywhere else. Unlike the food-fee bundles, this isn't scaled by quantity — it's a single flat `FeeLine` per order, and it only applies at all if at least one cart item is marked "fuel-surcharge eligible" via its per-product checkbox.

It implements `FeeFieldProviderInterface` — the admin product form shows a single checkbox ("eligible for fuel surcharge") along with both rates for reference; checking it on any cart item makes the whole order's flat surcharge apply.

## How it's configured

Admin screen at `admin_bundle_fee_fuel_surcharges_config` (`/admin/bundles/fees/fuel-surcharges`) — edit both fees' (`fuel-surcharge-low`, `fuel-surcharge-high`) name, default value, tax class, and placement. Per-product eligibility is a checkbox on each product's edit form.

## External dependencies

None.
