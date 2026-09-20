# FeeBCTireBundle

**Type:** Fee · **Name:** BC Tire Stewardship · **Edit route:** `admin_bundle_fee_bc_tire_config`

## What it does

Applies the BC Tire Stewardship (TSBC) fee to carts shipping to British Columbia (`BCTireFeeCalculator::supports()` matches `province === 'BC'` only). Unlike the food-fee bundles, this is opt-in per product rather than an editable amount per product: each product has a checkbox ("eligible for TSBC") rather than a numeric override — `calculate()` charges the fee's flat default rate (default $5.00) once per unit for every eligible product/quantity in the cart, and $0 for products where the checkbox isn't set.

**Customer self-exemption**, matching number1_inventory's own TSBC behavior exactly: a company that has its own TSBC registration number on file (the "TSBC #" field, on both the admin and customer-facing company forms) is presumed to self-remit, so `calculate()` returns no fee lines at all for that company — same empty/non-empty guard shape `TaxBCBundle\BCTaxCalculator` already uses for PST, just sourced from this bundle's own `bc_tsbc_number` company custom field via `FeeContext::$companyId` rather than `Company::$pstNumber`.

It implements `FeeFieldProviderInterface` — the admin product form shows a single checkbox for TSBC eligibility.

It also implements `FeeImportDefaultProviderInterface` — when a **new** product is created via the CSV importer (`admin_product_import_index`), its category is checked against a `bc_tire_fee_eligible` custom field (registered on `product_category` by `BCTireCategoryEligibilityFieldSubscriber`); if that category is flagged, the checkbox above is auto-checked for the new product, so staff isn't hand-toggling it on every imported SKU. This only ever fires for newly-created rows — re-importing an existing SKU never overwrites a per-product checkbox an admin already set. Mark categories via the "Eligible for BC Tire Stewardship (TSBC) fee" custom field, which appears automatically on the category add/edit form (`admin_category_create`/`admin_category_update`).

`Number1ProductImportBundle`'s own upload screen has a second, independent route to the same result — an "Enable TSBC for Tire products" checkbox (on by default) that marks every product *that* importer creates in the `Tire` category eligible, without going through a category custom field. It writes the same `ProductFee` row this bundle's own checkbox does and follows the same newly-created-only rule, so the two can be used together or separately; it reaches the `BC-tsbc` fee by slug rather than by importing anything from this bundle. See that bundle's README for the availability rules.

**TSBC # on the order record.** `BCTireFeeCalculator` also implements `FeeOrderSnapshotProviderInterface::applyOrderSnapshot()`, called by `FeeCalculatorResolver::applyOrderSnapshots()` right after an order's `feeLines` are finalized in `Admin\OrderController::create()`/`edit()` and `Customer\CheckoutController::buildAndPersistOrder()`. It copies the company's *current* TSBC # onto the order as a permanent snapshot (a second custom field, `bc_tsbc_number_on_order` on the `order` object type, hidden from the generic order form the same way the category field is) — mirrors how `feeLines`/`taxLines` are already snapshotted once and never recomputed on later views, and matches number1_inventory's own `Order.user_tsbc` field. Only written for BC orders. Re-editing an order re-snapshots it, same as `feeLines` does. Displayed via three `InjectionPointProviderInterface` providers (`BCTireAdminOrderDetailProvider`, `BCTireCustomerOrderDetailProvider`, `BCTireInvoiceNoteProvider`, all sharing one `BCTireOrderSnapshotRenderer`) targeting `admin_order_detail_info`, `customer_order_detail_info`, and `invoice_note` (which already covers both admin and customer invoice pages, PDF included) — each renders its own markup matching that surface's native field-row convention (admin's `.detail-row`, customer's grid-based `.customer-view-order-info`, invoice's free-text note slot), just "TSBC #: value", no status line. Not wired into estimates/quotes or the estimate→order conversion path (`EstimateConversionService` copies `feeLines` as a raw string without ever running fee-calculator logic, so an order accepted from a quote has the same gap `feeLines` itself already has there) — out of scope for this feature.

Each of those three display surfaces can be turned off independently from the config screen below — see "How it's configured".

## How it's configured

Admin screen at `admin_bundle_fee_bc_tire_config` (`/admin/bundles/fees/bc-tire`) — edit the fee's name, default rate, tax class, and placement. The fee slug (`BC-tsbc`) is fixed in code. Per-product eligibility is a checkbox on each product's edit form, not a per-product dollar amount. Category-level defaulting (import only) is a checkbox on each category's edit form. The company's own "TSBC #" is a plain text field on both `admin_company_create`/`admin_company_update` and the customer-facing `customer_company_profile` form — either side can enter it, and both flow through the same `bc_tsbc_number` custom field.

The same config screen also has three checkboxes controlling where the order-record snapshot (above) is displayed — admin order detail, customer order detail, invoices — each independently toggleable, all on by default. Backed by three `App\Entity\AppSetting` rows (`fee_bc_tire_show_order_detail_admin`, `fee_bc_tire_show_order_detail_customer`, `fee_bc_tire_show_invoice`, grouped under `category: 'FeeBCTireBundle'`) — the app's existing global settings table, written directly via `EntityManagerInterface` since `App\Service\AppSettings` only exposes reads; `BCTireFeeConfigController::config()` calls `AppSettings::clearCache()` after saving so the change takes effect immediately (that service caches `all()` for up to an hour otherwise). Turning a surface off doesn't delete any snapshot data — it just stops that one `InjectionPointProviderInterface` provider from rendering.

## External dependencies

None. Storage for both custom fields goes through the generic `CustomFieldDefinition`/`CustomFieldValue` system (`docs/bundles/custom-fields.md`): `bc_tire_fee_eligible` via a new `product_category` object type (see `App\Entity\CustomFieldDefinition::OBJECT_TYPE_PRODUCT_CATEGORY`), and `bc_tsbc_number` via the existing `company` object type.

## Deactivating or removing this bundle

Setting it Inactive from Bundle Management (`/admin/bundle-management`) turns everything off consistently, with no code changes needed elsewhere:

- No BC-tsbc fee line is ever added to a cart (`FeeCalculatorResolver` skips inactive calculators before calling `supports()`/`calculate()`).
- The per-product TSBC checkbox disappears from the product edit form (`Admin\ProductController`'s `FeeFieldProviderInterface` loop is already gated the same way).
- The "Eligible for BC Tire Stewardship (TSBC) fee" checkbox disappears from the category edit form, and "TSBC #" disappears from both the admin and customer-facing company forms — `CustomFieldRenderer` checks each field definition's registered `source` against the same Active/Inactive status.
- `ProductImportService`'s import-default hook is explicitly gated on `BundleStatusRepository::isActiveForInstance()` before calling `applyImportDefault()` — a newly-imported product under a flagged category is imported normally, just without the checkbox auto-set. Import itself is never affected by this bundle's state.
- `FeeCalculatorResolver::applyOrderSnapshots()` is gated the same way, so no new TSBC snapshot gets written onto an order while Inactive — order creation/editing itself is never affected. The three injection-point providers are filtered out via `getSource()`/`BundleStatusRepository::isActive()`, same as any other Inactive bundle's providers, so the "TSBC #:" block just stops appearing on order detail/invoice pages. Existing snapshots already on past orders stay in the database, dormant, and reappear once reactivated.

None of this deletes data — any `product_fee` rows or category `custom_field_value` rows already set stay in the database, dormant, and resume working the moment the bundle is reactivated.

If the bundle's code is removed outright (not just deactivated), the `bc_tire_fee_eligible` custom field definition would keep rendering/saving on the category form — `BundleStatusRepository::isActive()` defaults to Active when no status row exists for a source, and a fully-removed bundle can never register one saying otherwise. It becomes functionally inert (nothing reads it) rather than broken. This is a general characteristic of the custom-fields system, not specific to this bundle.

## See also

[docs/bundles/fee.md](../../docs/bundles/fee.md) — the "how to build a Fee bundle" guide, including the `FeeImportDefaultProviderInterface` pattern this bundle uses to seed a per-product value from a category-level field at import time.

[docs/bundles/custom-fields.md](../../docs/bundles/custom-fields.md) — custom-field object types, including the `product_category` type this bundle registers against.
