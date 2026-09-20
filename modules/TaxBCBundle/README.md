# TaxBCBundle

**Type:** Tax · **Name:** BC Tax Bundle · **Edit route:** `admin_bundle_tax_bc_config`

## What it does

Computes GST + PST for orders shipping to British Columbia (`BCTaxCalculator::supports()` matches `province === 'BC'` only). Rules:

- Tax class `E` (exempt): no tax lines at all.
- GST (default 5%) applies whenever the GST rate row is Active and the order isn't exempt.
- PST (default 7%) applies only when the tax class is `S`, the row is Active, **and** the customer has no PST number on file — a PST number on file exempts that customer from PST.

The `TaxCalculatorResolver` this feeds into (`modules/TaxBundle/src/Tax/TaxCalculatorResolver.php`) throws a `RuntimeException` if no active Tax bundle supports a requested province. That exception does **not** reach the customer: its only caller, `App\Service\OrderTaxBreakdownService::safeCalculateTax()`, catches it, logs a `warning` ("Order tax calculation failed, treating as $0 tax.") and returns no tax lines. So turning this bundle Inactive does not break checkout — BC orders are placed and invoiced with **$0 tax**, with the log line as the only signal.

That safety net lives at the call site, not in the contract: a new direct caller of `TaxCalculatorResolver` would see the raw exception unless it adds the same try/catch. See [docs/bundles/tax.md](../../docs/bundles/tax.md).

## How it's configured

Admin screen at `admin_bundle_tax_bc_config` (`/admin/bundles/tax/bc`) — edit the GST and PST rate percentages, and toggle each tax row's own Active/Inactive status independently of the bundle-level kill-switch in Bundle Management (that's a per-row `SalesTax.status` column, a separate concept from the bundle's Bundle Management status).

## External dependencies

None.

## See also

[docs/bundles/tax.md](../../docs/bundles/tax.md) — the "how to build a Tax bundle" guide, which uses this bundle as its worked example.
