# Plan: province/country single source of truth, then address snapshots

**Date:** 2026-07-30
**Base:** `origin/main` @ `2d2483a`
**Sequence:** PR 1 → PR 2 (must stay in order). PR 1b is independent and can land any time.

---

## Why

Two problems found while auditing whether an order is an immutable record.

**1. Province/country data is duplicated five ways** and stored as display names, so every consumer has to normalise. `TaxContext`, `ShippingContext` and `FeeContext` each carry a byte-identical `PROVINCE_MAP` (name → code); `CustomerImportService` has its own `PROVINCE_CODES`; the dropdown is driven by an inline `REGION_MAP` JS literal listing 13 Canadian provinces and 50 US states as names; country is `['Canada','United States']` hardcoded in controllers and inline `<option>` pairs.

Because storage is display text, an unrecognised spelling (`B.C.`, trailing space) reaches `BCTaxCalculator::supports()` unmatched, no calculator claims the province, and `OrderTaxBreakdownService::safeCalculateTax()` swallows the resulting exception and invoices at **$0 tax**. Storing codes removes the whole class of failure.

**2. Order/estimate addresses are not snapshotted.** Line items and money are (`AdminOrderLine` has its own `name`/`sku`/`price`/`subtotal`; the parent has `subtotal`/`tax`/`total`/`taxLines`/`feeLines`/`fulfillmentRegion`). But `billingAddress`/`shippingAddress` are live `ManyToOne` to `CompanyAddress`, and `order.company.*` is live too. So editing an address rewrites historical invoices.

Worse, `AbstractSalesDocument::getEffectiveBillingAddress()` is:

```php
return $this->billingAddress ?: $this->company->getDefaultBillingAddress();
```

If the FK is null the document silently prints **today's default address** — not blank. An invoice can show an address the goods never went to.

And a live bug: the admin order form posts a full editable address (`billing_address_1`, `_city`, `_province`, `_postal_code`, `_phone`, …) but the controller reads only `*_first_name`, `*_last_name`, `*_company_name` and the `*_address_id`. **Street/city/province/postal/phone are discarded on every save**, then re-rendered from the live FK. An admin corrects an address, gets a success flash, and the change vanishes.

---

## Decisions locked

| Decision | Rationale |
|---|---|
| Province/country = **table**, read once per request by a plain injectable service | Reporting queries the snapshot columns, not the reference data, so no join is needed — but `status` gives a place for future per-province settings |
| Plain service, **not** static/facade | A Symfony service is already a singleton held in RAM; statics would need container bridging and leak between tests |
| Keyword is **`province`** everywhere (holds US states too) | Already the house term: `company_address.province`, `sales_tax.province_name`, `TaxContext->province`, `billing_province` form fields, and `populateProvince()` fills it with US states. `state` appears only in the Stripe address mapping |
| Store **codes** (`BC`, `CA`), render labels | Kills normalisation as a per-consumer concern |
| Addresses store the code as a **plain validated string, not an FK** | An FK would let a rename/delete rewrite order history — the bug being fixed |
| Document addresses = **child tables** | Matches `admin_order_line` / `estimate_line` convention; keeps parents narrow; queryable for reporting |
| Order **and** Estimate both snapshot | The team prices quotes against the address; if it moves across town after quoting, the quote is wrong |
| All **14** `company_address` payload fields, incl. `delivery_instructions` | Dedicated table, so cost is row width. Freezing instructions at order time is the point |
| **No geo admin UI** in v1 | A table nobody can edit is the worst of both; add a screen when there's something to toggle |
| `status` filters **dropdowns only** | Deactivating a province stops new selections; it must not retroactively invalidate saved addresses |
| Company-name snapshot **deferred** | Same bug class, separate branch |

---

## PR 1 — `Region`: single source of truth

Branch: `feature/region-single-source-of-truth`

### New

- `src/Entity/GeoCountry.php` — `code` VARCHAR(2) UNIQUE, `name`, `status`, `sort_order`
- `src/Entity/GeoProvince.php` — `country_id` FK NOT NULL, `code` VARCHAR(6), `name`, `status`, `sort_order`, UNIQUE(`country_id`,`code`), index on `status`
- `src/Service/RegionSeedData.php` — the 2 + 63 rows as a `const`
- `src/Service/Region.php`:

```php
final class Region
{
    private ?array $data = null;
    public function __construct(private EntityManagerInterface $em) {}
    private function data(): array { return $this->data ??= /* one query */; }

    public function countries(): array;
    public function provinces(string $country): array;
    public function isValidCountry(string $c): bool;
    public function isValidProvince(string $country, string $p): bool;
    public function provinceName(string $country, string $p): ?string;
    public function normalizeProvince(string $country, string $raw): ?string;  // name|lowercase|code => code
}
```

- Twig extension for label lookup in templates

### ⚠️ Trap: tests don't run migrations

`tests/DoctrineIntegrationTestCase` builds schema with `SchemaTool::createSchema`, **not** migrations. The geo tables would be empty in every test and all validation would fail. Both the migration **and** the test base class must seed from `RegionSeedData`. This is why the seed data is a PHP const and not inline SQL.

### Migrations

1. Create `geo_country` + `geo_province`, seed idempotently from `RegionSeedData`.
2. Normalise `company_address.province` (names → codes) and `.country` (`Canada` → `CA`). Report unmapped values.

### Delete

- `PROVINCE_MAP` from `src/Contract/Tax/TaxContext.php`, `src/Contract/Shipping/ShippingContext.php`, `src/Contract/Fee/FeeContext.php`, and the normalisation in their constructors — they now receive codes and compare directly, staying pure value objects with no DB access
- `PROVINCE_CODES` from `modules/Number1CustomerImportBundle/src/Service/CustomerImportService.php` → use `Region::normalizeProvince()`
- inline `REGION_MAP` JS in `templates/admin/company/address_form.html.twig`
- `['Canada','United States']` literals in `Customer/CompanyAddressController` and inline `<option>Canada</option>` pairs

### Leave alone — legitimate domain data keyed by code

- `FuelSurchargeFeeCalculator::LOW_PROVINCES = ['AB','SK','MB']` — a business grouping
- `CanadaSimpleTaxCalculator`'s per-province rate table
- individual `$context->province === 'BC'` comparisons in tax/fee/shipping calculators

### Touch

Controllers supplying dropdowns: `Customer/CompanyAddressController`, `Admin/CompanyController`, `Admin/OrderController`, `Customer/AuthController`, `Customer/CheckoutController`.

Templates: `admin/company/address_form`, `admin/order/form`, `customer/auth/register`, `admin/order/detail`, `admin/estimate/detail`.

**Bulk of the diff:** every site printing a province now prints `BC` unless routed through `provinceName()` — invoice, packing slip, quote, quote_pdf, both order lists, address book.

### Tests

`Region` unit tests (validation, normalisation, unknown → null); seeded table matches `RegionSeedData`; functional test that dropdowns render from the table and an invalid province is rejected on save.

---

## PR 1b — delivery instructions (small, independent)

`company_address.delivery_instructions` (CLOB) exists and customers can set it at `templates/customer/company_address/form.html.twig:87`, but:

1. **It is absent from the admin address form** (`templates/admin/company/address_form.html.twig` — zero references), so admins never see it.
2. **Registration misfiles it.** `Customer/AuthController:96` does `($data['company_notes'] ?? ($data['delivery_instructions'] ?? ''))` → `$company->setNotes(...)`. There is **no `company_notes` input**, so the `??` is dead and delivery instructions always land in `Company::notes`. Registration does create addresses (`new CompanyAddress()` at `:245` shipping, `:261` billing), so it should go to `$shipping->setDeliveryInstructions()`.
3. It is **printed on no document**, and its only other use is as a sort key (`Customer/OrderController:501`). So today a customer can type "back entrance, closed after 3pm" and nobody ever sees it.

Fix 1 and 2 here; rendering comes with PR 2.

---

## PR 2 — snapshot billing/shipping addresses

### New

- `src/Entity/AbstractDocumentAddress.php` — MappedSuperclass, all 14 fields + `type` (`billing`|`shipping`) + nullable `source_address_id` (provenance only, never rendered)
- `src/Entity/AdminOrderAddress.php` / `src/Entity/EstimateAddress.php` — each adds its own `NOT NULL` parent FK with `ON DELETE CASCADE`, plus `UNIQUE(parent_id, type)`

Two tables rather than one polymorphic one so the FK can be NOT NULL and the DB enforces "belongs to exactly one parent"; matches the `admin_order_line` / `estimate_line` convention.

### Migration

1. Create both tables.
2. Backfill from the current effective address; split `billing_name` on first space into first/last.
3. Drop `billing_name`, `shipping_name`, `shipping_company_name`, `billing_address_id`, `shipping_address_id` from both parents.

Backfill is lossy for orders whose address already changed — acceptable, no live instance.

### Entity changes

`AbstractSalesDocument`: remove those five columns; **delete the `?: getDefaultBillingAddress()` fallback**; add `getBillingAddress()` / `getShippingAddress()` over the collection.

### Write sites — must persist the full posted address, validated against `Region`

- `Customer/CheckoutController`
- `Admin/OrderController` create + update + `cloneOrder` — **this is where the discard bug is fixed**
- `EstimateConversionService` — must **copy the estimate's snapshot to the order**, never re-resolve from the address book, or conversion silently reintroduces the drift

### Read sites

invoice, packing slip, quote, quote_pdf, `customer/order/detail`, `customer/order/_list_rows` (**fix the `|join('<br>')|raw` stored XSS while rewriting the line**), `admin/order/detail`, `admin/order/_list_rows`, `admin/estimate/detail`.

Delivery instructions render on: customer order view, admin order view, **admin order edit (editable + persisted)**, invoice PDF, packing slip PDF, estimate detail, both quote PDFs.

### Query changes

The two admin filters on `o.shippingCompanyName` / `o.shippingName` become joins. Add `leftJoin(...)->addSelect(...)` to list queries so this doesn't introduce an N+1 — same pattern already used for `o.payments`.

### Tests

Rename + reprice a product and edit an address, assert the order is unchanged; assert an admin address edit **persists** (regression for the discard bug); assert cascade delete; assert invalid province rejected.

---

## Deferred

- **Company-name snapshot** — `order.company.name` / `primaryEmail` / `phoneNumber` live in 35+ template spots
- **Surface `delivery_instructions` on the packing slip** — becomes a one-line template change once PR 2 lands
- **Silent $0 tax visibility** — team decided fail-open is correct; the remaining question is whether to flag the order rather than only log
- **Geo admin screen** — when there's something to toggle

---

## Environment notes

- `var/data/wheelmart.sqlite` **is still tracked on main** (`security/untrack-committed-sqlite-database` is unmerged). `git checkout`/`reset` will overwrite the dev DB and reset its mode to 664, breaking `www-data` writes. Guarded locally with `git update-index --skip-worktree var/data/wheelmart.sqlite`; the real fix is merging that branch.
- Dev URLs come from **`.env.local`** (untracked, overrides `.env`): admin `http://admin.wholesale-b2b-core.localhost`, storefront `http://wholesale-b2b-core.localhost`. `.env.local` is not loaded when `APP_ENV=test`, which is why Cests use `Host: admin.localhost`.
- Both suites must pass: `php vendor/bin/phpunit` and `php vendor/bin/codecept run Functional`. Clear `tests/_output/failed` if Codeception complains about a stale group file.
- Exercise the **full migration chain**, not just a fresh `SchemaTool` build — these are the first changes in this batch with real migrations.
