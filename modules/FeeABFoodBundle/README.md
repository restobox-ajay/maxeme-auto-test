# FeeABFoodBundle

**Type:** Fee · **Name:** AB Food Fees · **Edit route:** `admin_bundle_fee_ab_food_config`

## What it does

Applies a fixed "AB Environmental Fee" (tax class `G`) to carts shipping to Alberta (`ABFoodFeeCalculator::supports()` matches `province === 'AB'` only). Same shape as `FeeBCFoodBundle`, scoped to one fee instead of two: `calculate()` sums `qty × perUnitValue` across cart items, using a per-product override (`ProductFeeRepository`) or the fee's admin-set default.

It implements `FeeFieldProviderInterface` — the admin product form gets a numeric input to override that product's per-unit rate for this fee.

## How it's configured

Admin screen at `admin_bundle_fee_ab_food_config` (`/admin/bundles/fees/ab-food`) — edit the fee's name, default value, tax class, and placement. The fee slug (`AB-enviro-fee`) is fixed in code.

## External dependencies

None.
