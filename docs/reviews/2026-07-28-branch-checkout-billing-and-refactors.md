# Code review — `fix/checkout-billing-duplicate-and-text`

**Date:** 2026-07-28
**Scope:** `git diff main...HEAD` — 37 changed files (branch only, not a full-repo audit)
**Method:** Workflow-backed review at high effort — 4 finders produced 35 candidates; 24 independent verifiers confirmed 29 and refuted 6; the confirmed set collapses to the 10 distinct defects below.

## Summary

Three clusters of change, three clusters of defect:

- **Module discovery refactor** (`config/services.yaml`, `config/bundles.php`, `src/Routing/BundleControllerLoader.php`, `config/routes*`) — 5 findings. Trading 35 explicit ordered imports and 24 explicit route resources for globs cost ordering guarantees, cache invalidation, and loud failure.
- **Column-resize rewrite** (`public/assets/js/app.js`) — 4 findings, all downstream of deleting the `hasComplexHeader || colgroup` early-return without giving grouped/colgroup tables a real per-column storage identity.
- **Checkout/profile fix** (`src/Controller/Customer/ProfileController.php`) — 1 finding. The guard move fixed the 500 but introduced data loss on the error path.

The single highest-impact item is #1: it changes the shipping method a customer is actually charged.

---

## Findings

### 1. Glob import reorders tagged iterators, changing the checkout shipping default

**`config/services.yaml:10`** · correctness · CONFIRMED

Collapsing 35 ordered module service imports into one glob reorders every `!tagged_iterator` collection alphabetically.

Verified empirically: `php bin/console debug:container --tag=app.shipping_option` on this branch lists **AmazonFBA, Arrangement, Bulk, CanadaPost, Free, Pickup**; with `main`'s `config/services.yaml` swapped in (same command, container rebuilt) it lists **Pickup, Free, Bulk, CanadaPost, AmazonFBA, Arrangement**.

`ShippingResolver::collect()` / `getMenuItems()` iterate the tagged iterator with no sort, and `CheckoutController.php:141` does `$matchedShippingOption = $shippingOptions[0]`, writing that label into the session when the customer has not chosen one. A customer opening checkout for the first time now has "Amazon FBA" preselected instead of "Pickup"; submitting without touching the delivery selector creates and charges the order with the Amazon FBA shipping method/amount (`CheckoutController.php:459-462` re-reads the session label at submit time).

Admin sidebar Shipping/Fee/Tax menu ordering and the Bundle Management list order (`app.bundle_descriptor`, 31 services) shift the same way.

### 2. Blank-company-name guard reverts every other submitted field

**`src/Controller/Customer/ProfileController.php:122`** · correctness · CONFIRMED

Moving the guard above all entity mutation fixed the 500 but now discards everything else the user submitted, because the re-rendered form reads values back off the unmodified entity.

POST `/company-profile` with a blank `company_name` (JS-disabled browser, or any client bypassing the `required` attribute) plus edited `trade_name`, `company_phone`, `bill_address1`, `bill_city`, `bill_postal_code` and custom fields: the new early branch adds the flash and falls through to `render('customer/profile/company.html.twig', ['company' => $company, …])`, and that template prints `value="{{ company.tradeName ?: '' }}"`, `value="{{ billing ? (billing.city ?: '') : '' }}"` — the *stored* values. On `main` the entity had already been populated from the request, so the user saw their own input back next to the error. Now every edit is reverted and must be retyped.

### 3. Text-keyed widths collide across repeated group leaf headers

**`public/assets/js/app.js:5443`** · correctness · CONFIRMED

Column widths are keyed by header text, and the newly-supported grouped tables repeat identical sub-header text once per fulfillment region, so all regions share one storage key for five different physical columns.

On `/admin/inventory` with two or more regions, the second header row is `<th>Starting</th><th>Hold</th>…` emitted inside `{% for region in regions %}` with no `data-sort-field`, so `columnKeyForHeader()` returns `text:hold` for every region's Hold column. Previously this table was skipped entirely (`th[rowspan]` early-return), so no keys were ever written. Now: drag Region B's Hold to 300px (only that column widens — correct), reload, and `ensureFrozen()` writes `savedWidths['text:hold']` into Region A's col 4 **and** Region B's col 9 **and** every further region — all jump to 300px. Sizing regions differently is impossible; last drag wins for all.

### 4. Stale container + new routes file 500s every URL

**`config/routes/bundle_controllers.yaml:6`** · correctness · CONFIRMED

Route loading now depends on a container-tagged service (`App\Routing\BundleControllerLoader`). If the compiled container in `var/cache` predates the tag while this routes file is present, route loading throws instead of degrading.

Reproduced: every route build dies with `Symfony\Component\Config\Exception\LoaderLoadException: Cannot load resource ".". Make sure there is a loader supporting the "bundle_controllers" type.` (`YamlFileLoader::parseImport` → `Loader::resolve`, `vendor/symfony/config/Loader/Loader.php:67`). `php bin/console debug:router` failed on every invocation until an unrelated `touch config/services.yaml` forced a container rebuild.

Any deploy shipping new code over a retained `var/cache` (rsync/atomic-symlink deploys that skip `cache:clear`, or a partial wipe that drops the routing cache but keeps the container) serves HTTP 500 on every page, admin and storefront alike. The old `routes.yaml` needed no container at all.

### 5. Stray module directory auto-registers, clobbering or breaking routes

**`src/Routing/BundleControllerLoader.php:30`** · correctness · CONFIRMED

The loader globs every `modules/*/src/Controller` unconditionally, so a copy/backup module directory is auto-registered.

A developer copies `modules/NewsBundle` to `modules/NewsBundle-wip`. `glob()` matches both, `sort()` puts `-wip` after, and `RouteCollection::addCollection()` silently replaces same-named routes — every `admin_bundle_news_*` route (and every `path()` call for them) now resolves to the half-finished copy. If the copy's namespace has no `autoload.psr-4` entry (the normal case for a hand-copied folder — and also for any genuinely new module added the way this refactor advertises), `AttributeFileLoader` reflects on the un-autoloadable class and throws while building the route collection: **every** URL returns 500 until the folder is deleted. Under the old explicit list a stray directory was inert.

### 6. Invoice percent colgroups frozen to pixels by one drag

**`public/assets/js/app.js:5417`** · correctness · CONFIRMED

Removing the `$table.find('> colgroup').length` early-return means print/PDF tables whose `<colgroup>` uses percentage widths now get resize handles.

`templates/admin/order/invoice.html.twig` and `templates/admin/estimate/quote.html.twig` render `<table class="invoice-table" style="width:100%;table-layout:fixed;">` with `<col style="width:9%">`…`<col style="width:12%">` — deliberately relative so the invoice reflows to paper width. On `main` these were skipped because they already had a `<colgroup>`. Now handles are appended and the first mousedown runs `ensureFrozen()`, which adopts that colgroup and overwrites all seven cols with `measureNaturalWidths()` pixel values from the current on-screen width, persisted in localStorage for that path. Printing or saving the PDF at a different paper size/zoom then clips the right-hand Total/Batch columns instead of scaling.

### 7. Group-header resize silently discarded on reload

**`public/assets/js/app.js:5443`** · correctness · CONFIRMED

A grouped header and its rightmost leaf header resolve to the same physical column but different storage keys, and the restore loop is last-write-wins in `cellPosition` order (group rows first, leaf rows last).

On Admin › Inventory, drag the "Region A" group header (`colspan=5`) to widen its last leaf column, then drag any region's "Available" leaf column. Both keys (`text:region a` and `text:available`) exist and both map to Region A's last column. `computeHeaderGrid` pushes row-0 (group) cells before row-1 (leaf) cells, so at `app.js:5443-5444` the leaf key applies last and always wins: after reload Region A's column is the width set on a *different* region's Available column, and the group-header resize is gone. The code comment claiming the fill order makes this order-independent is wrong.

### 8. Glob discovery misses new modules until a manual cache clear

**`src/Routing/BundleControllerLoader.php:30`** · correctness · CONFIRMED

Deleting the 24 explicit `resource:` entries from `config/routes.yaml` and the 35 explicit imports from `config/services.yaml` removed the tracked-config-file edit that used to invalidate the compiled container and router when a module was added. A `glob()` over directories is not registered as a container resource.

A developer drops a complete new `modules/FooBundle/` into the repo — the exact workflow this refactor advertises — on a machine with a warm cache. No tracked file changed, so neither container nor router recompiles: the bundle is unregistered, its `services.yaml` is not imported, its routes 404. The developer sees a plain 404 with no hint of a caching problem. On `main` the required edit to `routes.yaml`/`bundles.php` itself triggered the recompile.

### 9. `class_exists` gate silently skips a bundle while its routes still load

**`config/bundles.php:24`** · correctness · CONFIRMED

The explicit bundle list guaranteed a listed module either loads or the app dies loudly at boot. The new `class_exists($class)` condition silently skips it instead, while the services glob and `BundleControllerLoader` still load that module's services and routes.

A developer adds `modules/FooBundle/` (with `src/FooBundle.php`) but the PSR-4 entry is missing/stale or the namespace has a typo. `class_exists('FooBundle\FooBundle')` returns false, so the bundle is silently unregistered — yet `modules/FooBundle/config/services.yaml` is still imported and `modules/FooBundle/src/Controller` routes still register. The app boots clean, `/admin/bundles/foo` is routable, and hitting it 500s with an opaque `There are no registered paths for namespace "FooBundle"` / service-not-found, instead of an immediate boot failure naming the missing class.

### 10. Hidden first row collapses every column to 100px

**`public/assets/js/app.js:5394`** · cleanup · CONFIRMED

`measureNaturalWidths()` measures the first tbody row even when it is `display:none` (client-side pagination/search hides rows), and the early-return path has no zero guard.

On any client-paginated admin grid (the `.table-card` path where `renderPagination()` calls `allRows.hide()` and shows only the current page slice, `app.js:253`), an admin clicks page 2 — or types a search term that filters out row 1 — then drags any column edge. `$firstBodyRow` is hidden, so every `getBoundingClientRect().width` is 0; `$cells.length === grid.totalCols` returns the all-zero array immediately (unlike the fallback branch below, which has an `if (!widths[i]) widths[i] = 100` guard), and `ensureFrozen` applies `(width || 100)`. The whole table snaps to uniform 100px columns and content truncates.

**Fix:** filter to visible rows (`.filter(':visible')`) or apply the same zero guard on the early-return path.

---

## Refuted (investigated, not defects)

| Location | Claim | Why refuted |
| --- | --- | --- |
| `public/assets/css/app.css:6782` | Unrelated drive-by shrink of the sidebar collapse control | It is its own dedicated, self-documenting commit — not an accidental inclusion |
| `templates/customer/checkout/index.html.twig:177` | Deleting the Billing Details section removed the customer's only way to set the billing contact name | A billing panel rendering the selected address's name survives at lines 125-136 of the same template |
| `config/services.yaml:10` | Glob module discovery has no marker/allowlist, so stray dirs register as modules | Duplicate of the stronger, better-evidenced routing form (finding #5) |
| `tests/Support/Helper/Functional.php:15` | New helper wraps only multipart POST, leaving login/admin-Host boilerplate duplicated | The duplication is a pre-existing repo convention on `main`, not introduced here |
| `tests/Functional/AdminBundleManagementCest.php:49` | Toggle test mutates global `BundleStatus` without an `_after()` teardown | Suite uses Doctrine transactional rollback (`cleanup: true`), which rolls back on failure too |
| `public/assets/js/app.js:5390` | `measureNaturalWidths()` re-implements the empty-state row filter | Factual duplication but no observable effect — pure style |

---

## Review stats

| | |
| --- | --- |
| Effort level | high |
| Finder agents | 4 |
| Candidates raised | 35 |
| Verifier agents | 24 |
| Confirmed / refuted | 29 / 6 |
| Distinct defects reported | 10 |
