# Number1CategoryProductPageBundle

**Type:** Template Override · **Name:** Category Product Pages · **Edit route:** `admin_bundle_number1_category_product_page_index`

## What it does

Gives Tire, Wheel, and Accessories each their own customer-facing catalog page and filter form,
instead of every category sharing the one generic catalog page — the direct replacement for
`number1_inventory`'s separate Tire/Rim/Hardware inventory pages
(`controllers/ProductController.php::actionTires()`/`actionRims()`/`actionHardware()`, each
rendering a different view). See `CATEGORY_PAGE_FILTER_PLAN.md` at the repo root for the full
findings-and-design writeup this bundle was built from.

- **Tire** and **Accessories** pages: the same generic grid, just a different search-box
  placeholder ("Search by tire NUMBERS only" / "Search Name or Description").
- **Wheel** page: replaces the search box with checkbox filter panels (Diameter, PCD, ... whatever
  fields are configured) with live counts, e.g. "5x112 (236)" — matching the reference app's
  `Product::getGroupListData()` facet counts exactly, including "Advanced View" for
  secondary filters.

This bundle does not reimplement product-listing or query logic — it configures and consumes
three core capabilities added specifically to support it (see "Core capabilities used" below).

## How it's configured

One screen (`/admin/bundles/number1-category-product-page`):

1. **Category roles** — pick which real `ProductCategory` fills each of the three fixed roles
   (Tire/Wheel/Accessories). Not set up yet? A category with that exact name (case-insensitive) is
   found-or-created automatically the first time any customer loads the catalog page — same
   find-or-create pattern `Number1RimImportBundle` already uses for its own default "Rims"
   category. If your store already has a category that should fill a role but under a different
   name (e.g. "Accessories & Refills" rather than "Accessories"), just pick it from the dropdown —
   the auto-created one is only a bootstrap default, never forced.
2. **View mode per page** — per role (Tire/Wheel/Accessories), whether the page's "SELECT VIEW"
   toggle offers Grid, List, or both, and which one is the default a customer sees first. Turning
   off both on a role is not allowed — the form silently keeps Grid available rather than saving a
   page with no usable view. Stored via `App\Entity\AppSetting`, same pattern as the role→category
   mapping. Consumed by core through the same generic, bundle-agnostic mechanism as template
   overrides and Wheel facets: `App\Contract\Bundle\CatalogViewConfigProviderInterface` +
   `App\Service\CatalogViewConfigResolver` (highest-priority winner per `customer_catalog_index:
   {categoryId}` point, same as `TemplateOverrideResolver`) — `Customer\CatalogController::index()`
   resolves it to decide which view mode(s) render in the toggle and which is the effective default
   when no `ProductSearch[view]` is present. A role never configured here keeps grid+list both
   available with Grid the default, i.e. today's pre-bundle behavior.
3. **Product fields table** — one row per registered product Custom Field (from any bundle, e.g.
   `Number1RimImportBundle`'s `rim_pcd`, `rim_dimension`, ...):
   - **Applies to** — scopes the field so it only renders on the admin product add/edit form when
     the product is in that category (leave "All categories" to keep today's behavior, showing
     everywhere).
   - **Show on Wheel filter** + **Advanced** — adds the field as a checkbox facet on the Wheel
     page; Advanced hides it behind the "Advanced View" toggle instead of always showing it.
   - **Filter label** — the human-readable heading shown above the checkboxes (defaults to the
     field's own admin label).

## Core capabilities used (added specifically to support this bundle, but generic)

- **`App\Service\TemplateOverrideResolver`** (already existed, previously only used by
  `Number1GuestCoverPageBundle` for the login page) — `Customer\CatalogController::index()` now
  resolves `customer_catalog_index:{categoryId}` (the exact category being browsed, not its tree
  root) before rendering, falling back to the shared default template if nothing matches. See
  `docs/bundles/template-overrides.md`.
- **`App\Contract\Bundle\CatalogFacetProviderInterface` + `App\Service\CatalogFacetResolver`**
  (new) — same point-naming convention as template overrides, but union semantics like Injection
  Points (every active matching provider's facets get computed, not just one winner). Lets
  `CatalogController` ask "which custom-field slugs does this category's page want facet counts
  for" without ever hardcoding a Wheel-specific slug itself.
- **`AbstractCustomerController::customFieldFacetCounts()`** (new) — the Doctrine equivalent of
  the reference app's `Product::getGroupListData()`: exact-string `GROUP BY` on one custom field's
  value, scoped to the same visible/active/category/region query the catalog itself already uses.
- **`AbstractCustomerController::catalogProductQueryBuilder()`** (extended) — now accepts
  `array<string, list<string>> $customFieldFilters` (slug => selected values), read generically
  off `ProductSearch[cf][<slug>][]=<value>` query params by `CatalogController` — no hardcoded
  knowledge of what any slug means.
- **`CustomFieldDefinition::$category`** (new nullable FK) + **`CustomFieldRenderer`** (updated) —
  a custom field definition can now be scoped to one `ProductCategory`; `null` (the default, and
  every pre-existing field's value) still means "applies everywhere," so nothing already deployed
  changes behavior until an admin explicitly scopes a field via this bundle's config screen.
- **`App\Contract\Bundle\CatalogViewConfigProviderInterface` + `App\Service\CatalogViewConfigResolver`**
  (new) — same point-naming convention as template overrides, and same "highest-priority winner"
  semantics (a page's grid/list availability + default is one coherent setting, not a union).
  `CatalogController` resolves it to decide which view mode(s) the toggle shows and which one wins
  when no `ProductSearch[view]` is on the request.

## Data

No new entities beyond the one additive core column above. Role → category mapping and Wheel
facet config are stored via `App\Entity\AppSetting`, same pattern
`Number1RimImportBundle\Service\RimImportConfig` uses.

## Verified live

Configured Tire → `TIRE` (existing), Wheel → `Rims` (existing, 513 real products with `rim_pcd`
values from `Number1RimImportBundle`), Accessories → `Accessories & Refills` (existing) against a
running dev server:

- Each category rendered its own template (confirmed by absence/presence of each page's
  distinguishing markup) instead of the shared default.
- Wheel page's PCD facet rendered real checkbox counts (`5x112 (236)`, matching the exact shape of
  the reference app's own facet display).
- Submitting `ProductSearch[cf][rim_pcd][]=5x112` correctly narrowed the result count (513 → 236)
  and left the matching checkbox checked on reload.
- A field with no data yet (`rim_dimension` — not backfilled onto existing products until
  `Number1RimImportBundle` re-syncs) correctly rendered "No values yet" instead of an empty or
  broken panel.

All throwaway config/test rows from this verification pass (`AppSetting` rows, one stray
auto-created "Accessories" category from an early bug in the verification method itself, not the
bundle) were cleaned up afterward, leaving a fresh-install state.
