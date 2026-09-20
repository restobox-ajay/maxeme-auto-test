# FeeBCFoodBundle

**Type:** Fee · **Name:** BC Food Fees · **Edit route:** `admin_bundle_fee_bc_food_config`

## What it does

Applies two fixed food-related fees — "BC Environmental Fee" (tax class `G`) and "BC Recycling Fee" (tax class `E`) — to carts shipping to British Columbia (`BCFoodFeeCalculator::supports()` matches `province === 'BC'` only). Each fee is per-product-quantity: `calculate()` sums `qty × perUnitValue` across cart items for each fee definition, using a per-product override (`ProductFeeRepository`) or the fee's admin-set default.

It implements `FeeFieldProviderInterface` — the admin product form gets a numeric input per fee (Environmental Fee, Recycling Fee) to override that product's per-unit rate.

## How it's configured

Admin screen at `admin_bundle_fee_bc_food_config` (`/admin/bundles/fees/bc-food`) — edit each fee's name, default value, tax class, and placement. The two fee slugs (`BC-enviro-fee`, `BC-recycling-fee`) are fixed in code; only their name/value/tax-class/placement are admin-editable.

## External dependencies

None.
