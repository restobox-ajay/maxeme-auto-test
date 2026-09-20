# Number1RimImportBundle

**Type:** Import · **Name:** Number1 Rim API Import · **Edit route:** `admin_bundle_number1_rim_import_index`

## What it does

Pulls the rim/wheel catalog from Number 1 Tire Centre's rim supplier API
(`https://wheels.rimalloycanada.com/v1/get-wheels?api_client_id=...&api_key=...&output=json`) and
creates/updates `ProductCore` records — the direct replacement for `number1_inventory`'s cron-only
`RimsUpdateController`/`rims-update` command.

This bundle does not reimplement product import logic — same approach `Number1ProductImportBundle`
already established for the QuickBooks CSV: it translates the API's JSON rows into the exact
canonical CSV format `App\Service\ProductImport\ProductImportService` (core's own generic product
importer) already understands, then calls that service directly. All upsert/validation/category/
inventory/pricing/fee-default logic lives in core and is reused as-is; this bundle only owns the
column mapping, image download/sync, and the rim-specific metadata.

Column mapping:

| API column | Becomes |
|---|---|
| `Part No` | `sku` (the match/upsert key) — not brand-qualified, see "Known data quirk" below |
| `Brand`, `Model`, `Finish`, `Size` | Combined into a constructed product name, e.g. `"RAC R01 — Gloss Black (17x7.5)"` — **deliberately not** the reference app's `"RAC: " . part_no` (wrong for every non-RAC brand row). `Finish` and `Size` are *also* captured separately, see below — this is in addition to, not instead of, the name text |
| `Price-shop` | `ProductCore::originalPrice` — the real, functional price |
| `Price-default` | `suggested_price_type = 'Number'` + `suggested_price_value` — Custom Fields owned by the separate `Number1SuggestedPriceBundle`, not this one. This bundle only ever writes to those two fields by slug; if that bundle isn't installed, the write is silently skipped, never an error. |
| `Weight` | `ProductCore::weight` (core's own import normalization already strips a trailing unit like `"20 lb"` down to `"20"`) |
| `Qty` | Inventory for whichever single `FulfillmentRegion` is picked on the config screen — the API has no per-location breakdown, unlike the QuickBooks CSV's `LOCATION` column |
| `Remark` | `ProductCore::remarks` |
| `Image` (array of URLs) | Actually downloaded into `public/uploads/products/` and synced as `ProductImage` rows — the reference app just stores the raw remote URL string, which doesn't work with our `ProductImage` entity |
| `Offset`, `PCD`, `CB`, `Backspace`, `Seat`, `Made`, `Load Rating`, `Size` | Custom Fields (`rim_offset`, `rim_pcd`, ...) owned by this bundle — no equivalent `ProductCore` column |
| `Size`, split | Also written as `rim_dimension`/`rim_width` (`"18x7.5"` → `"18"` / `"7.5"`) alongside the existing combined `rim_size` — added so Diameter and Width are independently filterable rather than only present inside one opaque string. Skipped (not written as `"0"`) for any row whose `Size` doesn't match the `NxM` shape every sample row uses |
| `Finish` | Also written as its own `rim_finish` Custom Field — previously only ever folded into the generated name text above, never captured as queryable data |

## How it's configured

One screen (`/admin/bundles/number1-rim-import`): API URL/client ID/key, which `ProductCategory`
every synced rim gets (default: find-or-create one named "Rims"), which `FulfillmentRegion` the
API's flat `Qty` applies to, and whether products missing from the latest pull get set Inactive
(default on).

**Trigger**: the console command `number1-rim-import:sync`, meant to be wired into the server's
system crontab — same operational shape as the reference app's own cron-only `rimsUpdate.php`.
The config screen's **"Save & Sync Now" button does not run the sync in the HTTP request** — a
full sync with image downloads can take a couple of minutes, far too slow to hold a browser
request open for. Instead it spawns that exact same console command as a detached background
process and redirects immediately; the page then polls a small JSON status endpoint
(`Number1RimImportBundle\Service\RimSyncStatus`, backed by a file under
`var/number1_rim_import/`) and reloads itself once the run finishes. Whether a sync was triggered
by cron or by this button, `SyncRimProductsCommand` is the one place a sync actually executes —
`RimApiImportService::sync()` is only ever called from there.

## Scheduling via cron

The command reads its config (API URL/credentials, category, region, deactivate-missing toggle)
from the same place the admin config screen saves it — nothing needs to be passed on the command
line. Confirm it works first by running it manually from the project root:

```bash
php bin/console number1-rim-import:sync --env=prod
```

Then add a crontab entry (`crontab -e`, as whichever user owns the app/has PHP-CLI access —
typically the deploy user, not `root`). Nightly, off-hours, is a reasonable default:

```cron
# Number1 Rim API Import — nightly at 2:00 AM server time
0 2 * * * php /path/to/wholesale-b2b-core/bin/console number1-rim-import:sync --env=prod >> /path/to/wholesale-b2b-core/var/log/number1_rim_import_cron.log 2>&1
```

Replace `/path/to/wholesale-b2b-core` with the real deployment path, and adjust the schedule
(`0 2 * * *`) to whatever cadence makes sense — hourly (`0 * * * *`) if same-day rim-stock
visibility matters more than load on the vendor's API. The `>> ... log 2>&1` redirect is optional
but recommended — it's the only place the command's own console output (created/updated/error
counts, or a fetch failure) gets recorded when run via cron, since nothing is watching stdout the
way the admin "Sync now" button's status-file polling does.

If the bundle is ever toggled Inactive in Bundle Management, the cron entry can stay in place —
the command checks bundle status itself and exits cleanly with a skip message rather than
importing anyway (no need to comment out the crontab line to pause syncing).

## Data

No new entities, no schema changes. Reuses `ProductCore`, `ProductCategory`, `ProductInventory`,
`FulfillmentRegion`, and `ProductImage` exactly as core's own product importer already does.
Rim-specific metadata is stored via the existing `CustomFieldDefinition`/`CustomFieldValue` system
(`OBJECT_TYPE_PRODUCT`), same mechanism `Number1ProductImportBundle` uses for its own vendor
metadata. Config values are stored via `App\Entity\AppSetting`, same pattern
`PaymentStripeBundle\Service\StripeConfigProvider` uses.

One previously-unused core column gets its first real usage: `ProductCore::syncSource` is stamped
`number1-rim-api` on every product this bundle creates/updates — see "Missing from the latest
pull" below for why.

## Missing from the latest pull → Inactive

Core's own `ProductImportService::import()` already has a `missing_rows: inactive_missing` option,
but it's **global** — it scans the entire catalog, which would inactivate every tire and
CSV-imported product the moment this bundle ran. This bundle never passes that flag; instead it
runs its own pass, scoped to `syncSource = number1-rim-api` only, mirroring a bug fix already
present in `number1_inventory`'s own `RimsUpdateController` (its own comment: an earlier version
compared against *all* products and incorrectly inactivated tires too).

Safety guard: this pass never runs if the API call failed, or returned zero rows — a transient
vendor API problem must never be able to silently empty the entire rim catalog.

**One-way, not automatic reactivation**: a rim product inactivated because it dropped out of one
pull does **not** automatically go back to Active if it reappears in a later pull — every other
field gets refreshed on that later sync, but `status` is left alone (the canonical CSV this bundle
builds never emits a `status` cell, same blank-never-overwrites convention every other field
follows). This matches `number1_inventory`'s own real behavior exactly (its Bug-4 fix only ever
sets `STATUS_INACTIVE`, never the reverse) — reactivating a rim product that came back requires an
admin to do it by hand on the product edit form. Confirmed directly: dropped 8 SKUs from a pull,
re-ran, 7 went Inactive; put all 8 back in a later pull and re-ran again — the 7 stayed Inactive.

## Known data quirk: `Part No` is not always unique

513 of 518 rows in a real sample pull have a unique `Part No`; the other 5 are the same physical
wheel cross-listed under two `Brand` values (once under a generic `RAC` brand, once under a
vehicle-specific fitment brand). This bundle keeps `Part No` as the bare SKU (not brand-qualified,
matching the reference app), so the second occurrence of a repeated `Part No` in one pull is
reported as a `Duplicate` row (first occurrence kept) — visible in the sync results, unlike the
reference app's silent second-row-wins behavior.

## External dependencies

`symfony/http-client` (added as a real composer dependency — it wasn't previously installed in
this project despite being referenced as a transitive constraint). No dependency on
`Number1SuggestedPriceBundle`'s classes — only its Custom Field slugs, resolved at runtime and
skipped gracefully if not found.

## See also

[/RIM_API_IMPORT_PLAN.md](../../RIM_API_IMPORT_PLAN.md) — the full design review this bundle is
built from: a line-by-line comparison against `number1_inventory`'s legacy `RimsUpdateController`,
every design decision and why, and the exact entity/service/config shape.

[/SUGGESTED_PRICE_BUNDLE_PLAN.md](../../SUGGESTED_PRICE_BUNDLE_PLAN.md) — the companion bundle that
owns `suggested_price_type`/`suggested_price_value`.
