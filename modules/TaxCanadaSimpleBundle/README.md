# TaxCanadaSimpleBundle

**Type:** Tax · **Name:** Canada Simple Tax Bundle · **Edit route:** `admin_bundle_tax_canada_simple_config`

## What it does

Computes tax for every Canadian province except BC (`CanadaSimpleTaxCalculator::supports()` explicitly excludes `'BC'`, since `TaxBCBundle` owns that one). Each supported province maps to 1–2 tax components:

| Provinces | Components |
|---|---|
| ON, NB, NL, NS, PE | HST 13–15% (single component) |
| AB | GST 5% only |
| SK | GST 5% + PST 6% |
| MB | GST 5% + PST 7% |
| QC | GST 5% + QST 9.975% |

Tax class `E` gets no tax lines. When a province has two components, the first (GST) always applies; the second (PST/QST) applies only when the tax class is `S` **and** the customer has no PST/QST number on file — mirroring `TaxBCBundle`'s PST-number exemption rule.

The `TaxCalculatorResolver` this feeds into (`modules/TaxBundle/src/Tax/TaxCalculatorResolver.php`) throws a `RuntimeException` if no active Tax bundle supports a requested province. That exception does **not** reach the customer: its only caller, `App\Service\OrderTaxBreakdownService::safeCalculateTax()`, catches it, logs a `warning` ("Order tax calculation failed, treating as $0 tax.") and returns no tax lines. This bundle covers 9 of Canada's 13 provinces/territories (all except BC and the three territories), so turning it Inactive does not break checkout for them — their orders are placed and invoiced with **$0 tax**, with the log line as the only signal.

That safety net lives at the call site, not in the contract: a new direct caller of `TaxCalculatorResolver` would see the raw exception unless it adds the same try/catch. See [docs/bundles/tax.md](../../docs/bundles/tax.md).

## Provincial tax registration numbers

`Service/CanadaTaxRegistrations` records a company's provincial sales tax registration number, one field per province:

| Province | Field slug | Label |
|---|---|---|
| SK | `tax_reg_sk_pst` | Saskatchewan PST # |
| MB | `tax_reg_mb_rst` | Manitoba RST # |
| QC | `tax_reg_qc_qst` | Quebec QST # |

One field per province, not one shared field: these are separate numbers, and none of them is the BC PST number held in `Company::$pstNumber`. Which provinces have a field is a configuration decision recorded in `CanadaTaxRegistrations::PROVINCES`; add or remove entries there.

**These values do not affect tax calculation and must not be wired into it.** `CanadaSimpleTaxCalculator` never reads them. They are a stored record of the company's registration numbers, and nothing is inferred from a number being present or absent.

`CanadaTaxRegistrationFieldSubscriber` registers the fields lazily on `kernel.request` (the same pattern as `FeeBCTireBundle`'s TSBC # field — see [docs/bundles/custom-fields.md](../../docs/bundles/custom-fields.md)), with the field metadata defined once in `CanadaTaxRegistrations::PROVINCES`. Because they are registered `visibleOnAdd`/`visibleOnEdit`, the boxes appear on the admin company add/edit form automatically via `App\Service\CustomFieldRenderer` — this bundle ships no form code. Programmatic access is `get(int $companyId, string $province)` / `set(...)` / `all(int $companyId)`, keyed by province code so callers never handle slugs; `set()` does not flush, matching every other custom-field writer in the app.

Nothing displays these on the customer-facing side yet.

## How it's configured

Admin screen at `admin_bundle_tax_canada_simple_config` (`/admin/bundles/tax/canada-simple`) — edit each province's component rate(s) and toggle each row's own Active/Inactive status independently of the bundle-level kill-switch in Bundle Management.

The registration number fields above have no config screen; their slugs and labels are fixed in code. They are covered by the bundle-level Active/Inactive kill-switch, since they carry `CanadaSimpleTaxCalculator::SOURCE`.

## External dependencies

None.
