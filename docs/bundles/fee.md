# Building a Fee bundle

A Fee bundle adds one or more line-item charges to a cart — environmental levies, stewardship fees, surcharges — on top of the product subtotal. Fees are distinct from shipping and tax: they're per-product or per-order amounts a bundle decides to apply, each carrying its own tax class.

## The contract

Implement `App\Contract\Fee\FeeCalculatorInterface` (`src/Contract/Fee/FeeCalculatorInterface.php`):

```php
interface FeeCalculatorInterface
{
    public function supports(FeeContext $context): bool;

    /** @return FeeLine[] */
    public function calculate(FeeContext $context): array;
}
```

`App\Contract\Fee\FeeContext` (`src/Contract/Fee/FeeContext.php`) gives you:

- `province` — normalized two-letter code.
- `cartItems` — `array<int, array{product: ProductCore, qty: int}>`.
- `paymentMethod` — the checkout-selected payment method slug, or `null` (added in Section 10.6 — see `AbstractFeeCalculator` below).
- `companyId` — the placing company's ID, or `null`. Generic and deliberately not tied to any one bundle's concern (shipping calculators reach the same thing as `$document->getCompany()?->getId()` — see [shipping.md](shipping.md)) — a fee that needs its own per-company data (a self-exemption number, a negotiated rate, anything) looks it up itself via `CustomFieldValueRepository::getValuesForObject('company', $context->companyId)` rather than the shared contract growing a bundle-specific field. `FeeBCTireBundle\Fee\BCTireFeeCalculator` is the real example — see its own README for the TSBC-number self-exemption this enables.

`App\Contract\Fee\FeeLine` (`src/Contract/Fee/FeeLine.php`) is the return type: `feeId` (nullable), `slug`, `label`, `taxClass`, `amount`, and `placement` (`Fee::PLACEMENT_MAIN_LINE` or `Fee::PLACEMENT_AFTER_TAX`, default `main_line`).

## The tag

Tag your calculator with `app.fee_calculator`:

```yaml
# modules/MyFeeBundle/config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    MyFeeBundle\:
        resource: '../src/'

    MyFeeBundle\Fee\MyFeeCalculator:
        tags: ['app.fee_calculator']
```

## The resolver

`FeeBundle\Fee\FeeCalculatorResolver` (`modules/FeeBundle/src/Fee/FeeCalculatorResolver.php`) collects every `app.fee_calculator`-tagged service via `!tagged_iterator app.fee_calculator`, skips any whose owning bundle is Inactive, and — for the rest — calls `supports()` then `calculate()`, flattening all returned `FeeLine`s into one list. Unlike `TaxCalculatorResolver`, it never throws — a cart matching zero fee calculators just gets no fee lines.

## Optional: FeeFieldProviderInterface — a per-product admin field

If your fee needs a per-product override (a checkbox, a dollar amount, anything editable on the product form), also implement `App\Contract\Fee\FeeFieldProviderInterface` (`src/Contract/Fee/FeeFieldProviderInterface.php`):

```php
interface FeeFieldProviderInterface
{
    public function renderProductFields(ProductCore $product): string;

    public function saveProductFields(ProductCore $product, Request $request): void;
}
```

Tag the same service with the additional `app.fee_field_provider` tag (you can implement both interfaces on one class — see `FeeUserDefinedBundle` below). It's consumed by `src/Controller/Admin/ProductController.php`, which iterates `#[AutowireIterator('app.fee_field_provider')]` at several points in the product add/edit flow (rendering the fields, saving them) and — since the recent fee-field enforcement fix — checks `BundleStatusRepository::isActiveForInstance($provider)` before calling either method. An Inactive fee bundle's field no longer appears on the product form and its stored value is no longer touched on save, matching how the resolver already skipped Inactive calculators in cart/order math.

## Optional: FeeImportDefaultProviderInterface — seeding the per-product field from CSV import

If your fee uses `FeeFieldProviderInterface`'s per-product field (above) and you want it defaulted automatically for products created via the CSV importer — rather than staff hand-toggling it on every imported SKU — also implement `App\Contract\Fee\FeeImportDefaultProviderInterface` (`src/Contract/Fee/FeeImportDefaultProviderInterface.php`):

```php
interface FeeImportDefaultProviderInterface
{
    public function applyImportDefault(ProductCore $product): void;
}
```

No new tag needed — it's consumed off the same `app.fee_field_provider`-tagged iterator, this time from `src/Service/ProductImport/ProductImportService.php`, which calls `applyImportDefault($product)` on every provider that implements the interface, **only for newly-created rows** (never on a re-import of an existing SKU, so a manual per-product override an admin already set is never silently overwritten), and only when `BundleStatusRepository::isActiveForInstance($provider)` — an Inactive fee bundle's import default is skipped exactly like its product-form field and cart-math already are, and import itself is never affected by the bundle's state either way.

This is deliberately a separate interface rather than a third method bolted onto `FeeFieldProviderInterface` — additive only, so the five existing implementers (`FeeBCFoodBundle`, `FeeABFoodBundle`, `FeeONFoodBundle`, `FeeFuelSurchargesBundle`, `FeeUserDefinedBundle`) don't need a no-op method added just to keep compiling.

See `FeeBCTireBundle\Fee\BCTireFeeCalculator::applyImportDefault()` (`modules/FeeBCTireBundle/src/Fee/BCTireFeeCalculator.php`) for the real example: it checks the imported product's category against a `bc_tire_fee_eligible` custom field (registered on the `product_category` object type — see [custom-fields.md](custom-fields.md)) and, if flagged, sets the same `ProductFee` value `saveProductFields()` would set from a manual checkbox.

## Optional: AbstractFeeCalculator — payment-surcharge fees

`App\Contract\Fee\AbstractFeeCalculator` (`src/Contract/Fee/AbstractFeeCalculator.php`, added Section 10.6) is a convenience base for a fee that should only apply when a specific checkout payment method was selected:

```php
abstract class AbstractFeeCalculator implements FeeCalculatorInterface
{
    abstract protected function supportedPaymentMethodSlug(): string;

    public function supports(FeeContext $context): bool
    {
        return $context->paymentMethod === $this->supportedPaymentMethodSlug();
    }
}
```

Extend it, implement `supportedPaymentMethodSlug()` and `calculate()`, and `supports()` is handled for you by matching against `FeeContext::$paymentMethod`. This is additive — existing Fee bundles keep implementing `FeeCalculatorInterface` directly and aren't retrofitted onto this base. See `PaymentPayUponDeliveryBundle\Fee\PayUponDeliverySurchargeFeeCalculator` (`modules/PaymentPayUponDeliveryBundle/src/Fee/PayUponDeliverySurchargeFeeCalculator.php`) for the real (if intentionally trivial) example: a flat $2.00 surcharge that only fires when `pay-upon-delivery` was the selected method.

## The kill-switch: BundleDescriptorInterface

As with every bundle type, implement `App\Contract\Bundle\BundleDescriptorInterface` (tag `app.bundle_descriptor`) or your bundle won't appear in Bundle Management (`/admin/bundle-management`) and can't be toggled Inactive. `getSource()` must match the bundle's root PHP namespace segment — `BundleStatusRepository::isActiveForInstance()` and the `FeeFieldProviderInterface` enforcement in `ProductController` both derive the source that way.

## Example: FeeUserDefinedBundle

The simplest real Fee bundle, and the one example in the repo that implements both `FeeCalculatorInterface` and `FeeFieldProviderInterface`. `UserDefinedFeeCalculator` (`modules/FeeUserDefinedBundle/src/Fee/UserDefinedFeeCalculator.php`):

- `supports()` always returns `true` — every cart is in scope.
- `calculate()` loads every `Fee` row whose `source` is `FeeUserDefinedBundle::SOURCE`, sums `qty * perUnitValue` across cart items (per-product override from `ProductFeeRepository`, falling back to the fee's `defaultValue`), and emits one `FeeLine` per fee with a non-zero total.
- `renderProductFields()` / `saveProductFields()` render and persist a numeric input per fee on the admin product form.

Its `config/services.yaml` tags the one calculator class with both `app.fee_calculator` and `app.fee_field_provider`:

```yaml
FeeUserDefinedBundle\Fee\UserDefinedFeeCalculator:
    tags: ['app.fee_calculator', 'app.fee_field_provider']
```

Unlike the fixed, hardcoded fee definitions in bundles like `FeeBCFoodBundle`, `FeeUserDefinedBundle` also ships a full admin CRUD (`UserDefinedFeeController`, route `admin_bundle_fee_user_defined_index`) for creating arbitrary named fees at runtime — see [modules/FeeUserDefinedBundle/README.md](../../modules/FeeUserDefinedBundle/README.md).

Note: every other shipped Fee bundle (`FeeBCFoodBundle`, `FeeBCTireBundle`, `FeeABFoodBundle`, `FeeONFoodBundle`, `FeeFuelSurchargesBundle`) also implements both interfaces — a per-product opt-in field turns out to be the common case, not the exception, once a fee needs to apply per-product rather than order-wide. They differ from `FeeUserDefinedBundle` in having a fixed, hardcoded set of fee definitions (province-scoped via `supports()`) with admin-editable rates, rather than an admin-creatable list of fees. The one real example of the plain `FeeCalculatorInterface`-only case (no product-level field at all) is `PaymentPayUponDeliveryBundle\Fee\PayUponDeliverySurchargeFeeCalculator` above, via `AbstractFeeCalculator` — a flat, product-independent surcharge that only depends on the selected payment method. `FeeBCTireBundle` additionally implements `FeeImportDefaultProviderInterface` (above) — the one shipped bundle so far that seeds its per-product field from a category-level default at import time rather than requiring every field to be set by hand.
