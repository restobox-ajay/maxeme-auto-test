# FeeONFoodBundle

**Type:** Fee · **Name:** ON Food Fees · **Edit route:** `admin_bundle_fee_on_food_config`

## What it does

Applies a fixed "ON Environmental Fee" (tax class `G`) to carts shipping to Ontario (`ONFoodFeeCalculator::supports()` matches `province === 'ON'` only). Same shape as `FeeABFoodBundle`: `calculate()` sums `qty × perUnitValue` across cart items, using a per-product override (`ProductFeeRepository`) or the fee's admin-set default.

It implements `FeeFieldProviderInterface` — the admin product form gets a numeric input to override that product's per-unit rate for this fee.

## How it's configured

Admin screen at `admin_bundle_fee_on_food_config` (`/admin/bundles/fees/on-food`) — edit the fee's name, default value, tax class, and placement. The fee slug (`ON-enviro-fee`) is fixed in code.

## External dependencies

None.
