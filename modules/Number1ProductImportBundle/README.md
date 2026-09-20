# Number1ProductImportBundle

**Type:** Import · **Name:** Number1 Product CSV Import · **Edit route:** `admin_bundle_number1_product_import_index`

## What it does

Imports/updates `ProductCore` records (plus inventory and pricing) in bulk from an
uploaded CSV. Column format (`NAME`, `REFNUM`, `INVITEMTYPE`, `DESC`, `QNTY`, `PRICE`,
`COST`, `VALUE`, `TAXABLE`, `SALESTAXCODE`, `ACCNT`, `ASSETACCNT`, `COGSACCNT`,
`VENDOR`, `LOCATION`, `NOTES`) matches the QuickBooks Item List export CSV used by
Number 1 Tire Centre's legacy `number1_inventory` system, to ease migrating that
vendor data into this catalog.

This bundle does not reimplement product import logic — it translates the vendor CSV
into the exact canonical format `App\Service\ProductImport\ProductImportService`
(core's own generic product importer, at `/admin/product/import`) already understands,
then calls that service directly. All upsert/validation/category/inventory/pricing
matching logic lives in core and is reused as-is; this bundle only owns the column
mapping and the vendor-specific metadata it stores as custom fields.

Column mapping:

| Vendor column | Becomes |
|---|---|
| `REFNUM` | `sku` (the match/upsert key) |
| `DESC` (falls back to `NAME`) | `name` |
| `NAME`'s first colon segment | `category` — matched against existing category names (case-insensitive); if no match, either left uncategorized, set to one fixed fallback category, or auto-created (title-cased, e.g. `TIRE` → `Tire`) — whichever the admin chose on the upload form |
| `INVITEMTYPE` | `type` |
| `QNTY` + `LOCATION` | inventory for whichever `FulfillmentRegion` the admin mapped that `LOCATION` value to (see below); a blank `LOCATION` cell instead uses the fallback region configured on the upload screen, if any |
| `PRICE` | `original_price` |
| `COST` | `cost_price` |
| `TAXABLE` + `SALESTAXCODE` | `sales_tax_code` (`TAXABLE = N` forces Exempt regardless of `SALESTAXCODE`) |
| `VALUE` | dropped — QuickBooks' own computed `QNTY × COST` column, no independent meaning |
| `NAME` (full), `VENDOR`, `ACCNT`/`ASSETACCNT`/`COGSACCNT`, `NOTES` | custom fields on the product (`number1_qb_item_name`, `number1_supplier`, `number1_gl_accounts`, `number1_notes`) — bookkeeping/reference data with no equivalent `ProductCore` column, optional via a checkbox on the upload form. Registered `visibleOnAdd`/`visibleOnEdit`/`visibleOnListing` all `true`, so they show on the product add/edit form *and* the "Custom Fields" column on `/admin/product/detail/index`. |

`LOCATION` (e.g. `"Warehouse A"`) is deliberately **not** stored as a custom field —
it's the same idea as our own `FulfillmentRegion`, so it drives real inventory
placement instead of sitting next to it as duplicate inert text (see "How it's
configured" below for the mapping step this requires).

Newly-created products get whatever defaults `ProductCore` itself defaults to
(Active, visible, not private, not deleted) — this bundle never sets those columns
explicitly, so re-importing an existing SKU never clobbers a status an admin changed
by hand, consistent with core's own "blank cell never overwrites" convention.

## How it's configured

Two-step flow, since which `FulfillmentRegion` each row's inventory belongs to depends
on that row's `LOCATION` cell, and the set of distinct `LOCATION` values is only known
after the file is parsed:

1. **Upload** (`admin_bundle_number1_product_import_index`,
   `/admin/bundles/number1-product-import`): choose what happens to a `NAME` segment
   that doesn't match an existing category — leave uncategorized, use one fixed fallback
   category, or auto-create a category per unmatched segment (case-insensitive
   find-or-create, title-cased display name, reused on later uploads) — whether SKUs
   missing from the file should be marked Inactive, whether to store vendor metadata as
   custom fields, whether to **enable TSBC for Tire products** (see below), the
   **default sales tax code** (`E`/`G`/`S`, used when `SALESTAXCODE` is blank or the
   column is missing), and the **fallback fulfillment region** (a `FulfillmentRegion`
   dropdown, or "None") — then upload the CSV. **Fallback category** opens on the `Tire`
   category when one exists (this importer exists for a tire vendor's catalog, and it's
   the category the TSBC checkbox keys off); with no `Tire` category it stays on "None",
   rather than creating one as a side effect of viewing the page. The fallback region and
   default sales tax code are saved via `App\Entity\AppSetting` (keys
   `number1_product_import_fallback_region_id` and `default_sales_tax_code` — the latter
   with no bundle-specific prefix, since `App\Service\ProductImport\ProductImportService`
   reads that exact key for every import source, not just this bundle's — through
   `Number1ProductImportBundle\Service\ProductImportConfig`, same pattern
   `Number1RimImportBundle\Service\RimImportConfig` uses) so both persist across uploads
   instead of being re-picked every time. The file is stashed to a token-named temp path
   (not the database) and every distinct non-blank `LOCATION` value found in it is
   scanned.
   - If no non-blank `LOCATION` values are found (blank column, or none in the file),
     the import runs immediately — step 2 is skipped, there's nothing to map — with
     every row's `QNTY` applied to the fallback region, if one is configured.
2. **Match locations** (`admin_bundle_number1_product_import_confirm`): one
   `FulfillmentRegion` dropdown per distinct non-blank `LOCATION` value found. Options
   are "Don't update inventory for this location", "Create a Fulfillment Region
   '&lt;location&gt;' and update inventory" (creates a new region named exactly like the
   `LOCATION` value, case-insensitive find-or-create so re-uploading later reuses it
   instead of creating a duplicate — **pre-selected by default** for any `LOCATION` that
   doesn't already match an existing region), or any existing region. If a `LOCATION`
   value case-insensitively matches an existing region's name already, that region is
   pre-selected instead. Submitting this is what actually runs
   `ProductCsvTransformer::transform()` and `ProductImportService::import()`.

   Rows whose `LOCATION` cell is blank never appear on this screen — they always use the
   fallback region from step 1 (or get no inventory update, if no fallback is
   configured), regardless of what's picked here for other `LOCATION` values.

### Enable TSBC for Tire products

A checkbox on the upload screen, **on by default**, backed by
`Number1ProductImportBundle\Service\TireTsbcEligibility`. When it's on, every product the
import **creates** whose resolved category is `Tire` is marked eligible for
`FeeBCTireBundle`'s BC Tire Stewardship fee, so a BC order containing that product charges
TSBC per unit. Eligibility is a `ProductFee` row (value `1.0`) against the `BC-tsbc` fee —
the same storage the per-product TSBC checkbox on the product edit form writes, so an
imported product is indistinguishable from one an admin ticked by hand.

- **Scope is the resolved category**, so it covers both the rows whose `NAME` segment
  matched `Tire` directly and any the fallback-category dropdown sent there. A row that
  landed in some other category (or none) is left alone.
- **Newly-created products only.** Re-importing an existing SKU never re-stamps
  eligibility, matching the contract `FeeBCTireBundle`'s own category-driven import default
  already follows (`BCTireFeeCalculator::applyImportDefault`) — an admin who unticks TSBC on
  one product keeps that choice through every later upload, so the box is safe to leave on
  permanently. The post-import flash reports how many products were marked.
- **Independent of the category-level flag.** `FeeBCTireBundle` has its own import default
  driven by a `bc_tire_fee_eligible` custom field on the category; this checkbox doesn't
  read or write that field, and the two can be used together (both write the same
  `ProductFee` row, so the result is the same either way).
- The checkbox **only appears when `FeeBCTireBundle` is installed and Active.** Both halves
  of that check matter: `BundleStatusRepository::isActive()` reports Active for a bundle
  that was never installed at all, so the `BC-tsbc` fee row's existence is what confirms the
  bundle is actually present. That row is seeded lazily by `FeeBCTireBundle` (its fee config
  screen, or any product add/edit form render), so on a brand-new install the checkbox stays
  hidden until one of those has been opened once.

Results (created/updated/skipped counts, row-by-row report) render after step 2 via
core's own product-import results partial, since this bundle produces the exact same
`ProductImportResult` type core's own importer does. An example CSV matching the exact
expected columns is downloadable from the upload screen
(`admin_bundle_number1_product_import_template`).

The pending file token is just a filename fragment resolved under the system temp
directory (validated against a strict pattern before use) — there's no server-side
registry of in-flight uploads, so an abandoned step-1 upload (never followed by step 2)
simply leaves one temp file behind for the OS to clean up eventually, same trade-off
core's own `progress_token` file already accepts.

There is no separate on/off feature toggle beyond Bundle Management itself
(`/admin/bundle-management`) — deactivating the bundle there removes its nav entry.

## Data

No new entities or migration. Reuses the existing core `ProductCore`, `ProductCategory`,
`ProductInventory`, `ProductPricing`, and `FulfillmentRegion` entities exactly as core's
own product importer already does. Vendor-specific metadata is stored via the existing
`CustomFieldDefinition`/`CustomFieldValue` system (`OBJECT_TYPE_PRODUCT`), the same
mechanism `FeeBCTireBundle` uses — no schema changes.

## External dependencies

None. Consumes `App\Service\ProductImport\ProductImportService` directly (unchanged) —
this bundle transforms the vendor CSV into core's canonical import format in memory and
calls that service exactly as `Admin\ProductImportController` does.

`FeeBCTireBundle` is an **optional** peer, not a dependency: the TSBC checkbox above reaches
that bundle's fee through core's `FeeRepository` by slug (`BC-tsbc`) rather than by importing
its calculator class, matching this app's convention that no module `use`s another module's
classes. With that bundle absent the checkbox isn't rendered and nothing else changes; the
one coupling is the slug/source string pair in `TireTsbcEligibility`, which has to stay in
sync with `BCTireFeeCalculator::FEES` and `::SOURCE`.
