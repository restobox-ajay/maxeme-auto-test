# FeeUserDefinedBundle

**Type:** Fee · **Name:** User-Defined Fees · **Edit route:** `admin_bundle_fee_user_defined_index`

## What it does

Lets an admin create arbitrary named fees at runtime — unlike every other shipped Fee bundle, its fee definitions aren't fixed in code. `UserDefinedFeeCalculator::calculate()` loads every `Fee` entity row with `source = 'FeeUserDefinedBundle'`, and for each one sums `quantity × per-unit value` across the cart (a per-product override from `ProductFeeRepository`, falling back to the fee's `defaultValue` when no override is set), emitting one `FeeLine` per fee with a non-zero total.

It also implements `FeeFieldProviderInterface`: for every user-defined fee, the admin product form (add/edit) gets a numeric input to override that fee's per-unit value for that specific product.

## How it's configured

Full admin CRUD at `admin_bundle_fee_user_defined_index` (`/admin/bundles/fees/user-defined`) — create, edit, and delete fees, each with a name, default value, tax class (`E`/`G`/`S`), and placement (`main_line` or `after_tax`; `after_tax` is only allowed when the tax class is `E`, since an after-tax fee can't itself be taxed). Per-product overrides are set on each product's edit form.

## External dependencies

None.

## See also

[docs/bundles/fee.md](../../docs/bundles/fee.md) — the "how to build a Fee bundle" guide, which uses this bundle as its worked example for the `FeeCalculatorInterface` + `FeeFieldProviderInterface` combination.
