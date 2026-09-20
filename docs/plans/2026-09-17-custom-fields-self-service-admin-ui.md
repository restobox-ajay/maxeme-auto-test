# Self-service custom fields, for every object — Shopify-style

## Status: plan (2026-09-17), not started

## The ask

Owner: *"Yes we want this for all objects, just like how Shopify allow you to add a bunch of
custom fields through the UI and it shows upon edit page and u can use it in output and themes."*

Two halves, both currently missing:
1. **Admin self-service**: add a NEW field to ANY object through a form, no code. Today: works only
   for 5 hardcoded object types, and even then only as *definitions* — the field itself still
   requires a bundle to write and deploy code.
2. **Front-end/theme output**: a custom field's value readable from storefront templates/themes.
   Today: 100% admin-backend only, zero exceptions.

## What already exists (read directly, not assumed)

- `src/Entity/CustomFieldDefinition.php` — field metadata: objectType, slug, label, fieldType,
  options, visibility flags, source, sortOrder, category scope.
- `src/Entity/AbstractCustomFieldValue.php` (mapped superclass) + 7 concrete subclasses:
  `CustomFieldValueProduct`, `CustomFieldValueCompany`, `CustomFieldValueOrder`,
  `CustomFieldValueInvoice`, `CustomFieldValueEstimate`, `CustomFieldValueCompanyAddress`,
  `CustomFieldValueProductCategory`.
- `src/Repository/CustomFieldValueRepository.php` — a hardcoded `MAP` (lines 35-46) of
  `objectType => [value entity class, target entity class, association property]`.
- `src/Repository/CustomFieldDefinitionRepository.php::ensureBySlug()` (lines 129-154) — the lazy
  upsert a bundle calls to register a definition.
- `src/Service/CustomFieldRenderer.php` — renders/saves the HTML form fragment
  (`renderFields`, `saveFromRequest`, `renderListingFragment`), called manually per controller.
- `src/Controller/Admin/CustomFieldController.php` — a real CRUD admin UI at
  `/admin/custom-fields`, `OBJECT_TYPES` constant (lines 33-39) gates which 5 types it even shows.
- 7 bundles already add fields this way, via a plain `kernel.request` `EventSubscriber` calling
  `ensureBySlug()`: CustomFieldExampleBundle ("Warranty (months)" on Product), FeeBCTireBundle (BC
  Tire fee eligibility on ProductCategory), Number1ProductImportBundle (vendor metadata on
  Product), Number1RimImportBundle (rim spec on Product), ShippingArrangementBundle (arrangement
  eligibility), TaxBCBundle (PST number on Company), TaxCanadaSimpleBundle (tax registration on
  Company).
- Storage is real per-type tables (not EAV) — one migration-defined table per object type with FK
  constraints, deliberately split from an earlier single shared table for exactly this reason
  (`migrations/Version20260805040000.php`, #422). The `value` column is already generic text/CLOB.
- Vendor is explicitly, documentedly blocked from getting custom fields today —
  `modules/ProcurementBundle/README.md:187-189` and `migrations/Version20260904090000.php` both
  name the same three hardcoded registries as the reason: `CustomFieldDefinition::OBJECT_TYPE_*`,
  `CustomFieldValueRepository::MAP`, `CustomFieldController::OBJECT_TYPES`. A bundle cannot add a
  new object type — only core changes can, and the README states this was "reported rather than
  forced."

## Why "the framework exists" undersells the actual work

The word "framework" in the original gap note is accurate for *definitions* (a real entity + a
real admin CRUD screen exist) and misleading for *everything else*:

- **Adding a field still means writing code.** `ensureBySlug()` is only ever called from a bundle's
  own `EventSubscriber`. There is no "create a field" button in `CustomFieldController` that
  produces a working, renderable field on its own — it can create a `CustomFieldDefinition` row,
  but nothing renders or saves it unless a controller was also hand-modified to call
  `CustomFieldRenderer::renderFields()`/`saveFromRequest()` for that object type.
- **Adding a new OBJECT TYPE is architecturally blocked**, not just inconvenient — three separate
  hardcoded lists have to agree, all three live in `src/`, and nothing outside `src/` can add an
  entry to any of them.
- **Storage is migration-defined per type.** A brand-new object type has no value table until a
  migration creates one. This is the single biggest reason "self-service" doesn't work today: a
  real self-service UI cannot run a migration on someone's behalf mid-request.

## Decisions this plan needs before implementation starts

1. **Storage strategy for new object types — pick one:**
   - (a) A generic EAV fallback table (`custom_field_value_generic`: object_type, object_id, slug,
     value) used for any object type that doesn't already have its own typed table. Simple, no
     migrations at runtime, weaker referential integrity (no FK to the target entity).
   - (b) Dynamic table creation per object type at definition-create time (Doctrine schema-tool
     call inside the request). Keeps the FK-constrained shape the app already committed to (see
     #422's split), but is a meaningfully bigger and riskier mechanism — DDL from an admin action
     is not something this app does anywhere today.
   - Recommendation: (a) for genuinely new/rare object types added through the self-service UI;
     keep the existing typed-table mechanism for the object types that already have one (Product,
     Company, Order, Invoice, Estimate, CompanyAddress, ProductCategory, and — once unblocked —
     Vendor). This avoids DDL-on-demand while still making "any object" possible.
2. **Replace the three hardcoded registries with one data-driven source of truth.** Likely: a real
   `custom_field_object_type` table (or a tagged-service registry bundles can still contribute to,
   for the object types that DO want a typed value table) that
   `CustomFieldValueRepository`/`CustomFieldController`/`CustomFieldDefinition` all read from,
   instead of three separately-maintained lists.
3. **Generic render/save wiring.** Today a controller must manually call
   `CustomFieldRenderer::renderFields()`/`saveFromRequest()`. A real self-service story needs this
   to work for a controller that was never told about custom fields at all — likely a form-type
   extension or a `kernel.view`/`kernel.controller` hook keyed off object type, not a per-controller
   opt-in.
4. **Front-end/theme read path.** Nothing today renders a custom field outside `/admin`. Needs: a
   Twig function/filter (`{{ product|customField('warranty_months') }}`) usable from
   `templates/customer/`, plus a decision on whether EVERY field is theme-visible by default or
   whether `CustomFieldDefinition` needs a new `visibleOnStorefront` flag (recommended — an
   internal-only field like a vendor SKU code shouldn't leak to the storefront by just existing).
5. **Existing bundle-authored fields — migrate or coexist?** The 7 bundles above keep working
   as-is if the old mechanism stays in place alongside the new one; recommend NOT forcing a
   migration of those on day one, since they're working and none of them need self-service (they're
   code-authored on purpose, by a specific integration).

## Build order

1. The data-driven object-type registry (decision #2), backward-compatible with the 7 existing
   bundle-authored types — this has to land first since everything else reads from it.
2. Generic render/save wiring (decision #3) so a NEW object type doesn't also need a controller
   change — prove it end-to-end on one already-supported type (e.g. Company) before touching
   anything new.
3. The EAV fallback value table (decision #1a) + `CustomFieldController`'s "add a field to a new
   object type" flow, gated to only object types the registry knows about (from step 1).
4. Unblock Vendor specifically as the first real self-service proof — it's the case already named
   as blocked and wanted (`modules/ProcurementBundle/README.md`), and it's a good test of "add a
   field to an object that has never had this before" without touching the front-end half.
5. Front-end/theme read path (decision #4) — the `visibleOnStorefront` flag, the Twig
   function/filter, and picking one real storefront template to prove it against.
6. `CustomFieldController` UI polish: a field-type picker driven by the object-type registry
   instead of the hardcoded `OBJECT_TYPES` list; delete/edit for self-service-created fields
   (bundle-sourced ones stay protected, as today).

## Test plan

- One test proving a field added through the admin UI (no code) actually renders and saves on an
  object type that previously had NO custom-field support at all (Vendor is the natural case).
- One test per existing bundle-authored field type (the 7 above) proving they still work unchanged
  after the registry refactor — this is the "don't break what already ships" regression gate.
- A storefront-rendering test: a `visibleOnStorefront` field appears in a customer-facing template;
  a non-visible one does not.
- A concurrency/DDL-avoidance test if decision #1 goes with the EAV fallback: two fields added to
  two different new object types in quick succession don't collide.

## Open question to put back to the owner before starting

Does "all objects" include objects that don't exist as Doctrine entities at all today (e.g. a
future theme-only construct), or is the ask scoped to "every entity we already have, plus new ones
as they're added"? This plan assumes the latter — worth confirming before committing to the
registry's exact shape.
