# Building a Tax bundle

A Tax bundle computes the sales tax lines for a given province. Unlike Shipping and Fee, exactly one Tax bundle is expected to "own" any given province — the resolver picks the first active calculator that supports the province and returns its result directly, it does not merge results across bundles.

## The contract

Implement `App\Contract\Tax\TaxCalculatorInterface` (`src/Contract/Tax/TaxCalculatorInterface.php`):

```php
interface TaxCalculatorInterface
{
    public function supports(TaxContext $context): bool;

    /** @return TaxLine[] */
    public function calculate(TaxContext $context): array;
}
```

`App\Contract\Tax\TaxContext` (`src/Contract/Tax/TaxContext.php`) gives you:

- `province` — normalized two-letter code.
- `subtotal` — the taxable amount (float).
- `taxClass` — `'E'` / `'G'` / `'S'`, default `''`.
- `companyId` — the placing company's id, or `null`. A calculator that keys off per-company data — a self-exemption registration number, say — looks it up itself through `CustomFieldValueRepository`; the contract deliberately carries no field for any one jurisdiction's concern.

`App\Contract\Tax\TaxLine` (`src/Contract/Tax/TaxLine.php`) is the return type: `label`, `rate`, `amount`.

## The tag

Tag your calculator with `app.tax_calculator`:

```yaml
# modules/MyTaxBundle/config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    MyTaxBundle\:
        resource: '../src/'

    MyTaxBundle\Tax\MyTaxCalculator:
        tags: ['app.tax_calculator']
```

## The resolver — and its important sharp edge

`TaxBundle\Tax\TaxCalculatorResolver` (`modules/TaxBundle/src/Tax/TaxCalculatorResolver.php`) collects every `app.tax_calculator`-tagged service via `!tagged_iterator app.tax_calculator`, skips any whose owning bundle is Inactive, and returns the **first** active calculator whose `supports()` returns `true` — it does not continue past the first match:

```php
public function calculate(TaxContext $context): array
{
    foreach ($this->calculators as $calculator) {
        if (!$this->bundleStatusRepo->isActiveForInstance($calculator)) {
            continue;
        }

        if ($calculator->supports($context)) {
            return $calculator->calculate($context);
        }
    }

    throw new \RuntimeException(sprintf('No active tax calculator supports province "%s".', $context->province));
}
```

**Unlike Shipping and Fee, this resolver throws** (`modules/TaxBundle/src/Tax/TaxCalculatorResolver.php:25`) if no active calculator supports the requested province. A bundle author needs to know this: turning off (or removing) the one Tax bundle that covers a given province is not a silent no-op — calling `calculate()` for that province throws a `RuntimeException` rather than quietly returning $0 tax.

In practice, the resolver's one caller today — `App\Service\OrderTaxBreakdownService::safeCalculateTax()` — already wraps every call in a try/catch and degrades to $0 tax with a `warning`-level log line ("Order tax calculation failed, treating as $0 tax.") rather than letting the exception surface as a 500. That's a safety net at the *call site*, not a guarantee of the contract itself — if you add a new direct caller of `TaxCalculatorResolver` (or call a `TaxCalculatorInterface` some other way), it will see the raw `RuntimeException` unless you add the same try/catch. Don't rely on "it always degrades gracefully" being true everywhere; check `grep -rl TaxCalculatorResolver src/ modules/` before assuming a new call site is covered. If you're building a Tax bundle meant to cover a new province, make sure some active calculator's `supports()` returns `true` for every province your storefront actually ships to before deploying.

## The kill-switch: BundleDescriptorInterface

As with every bundle type, implement `App\Contract\Bundle\BundleDescriptorInterface` (tag `app.bundle_descriptor`) or your bundle won't appear in Bundle Management (`/admin/bundle-management`) and can't be toggled Inactive. `getSource()` must match the bundle's root PHP namespace segment, since `BundleStatusRepository::isActiveForInstance()` derives the source from the calculator instance's class name the same way.

## Example: TaxBCBundle

The simplest real Tax bundle — one province, GST + PST. `BCTaxCalculator` (`modules/TaxBCBundle/src/Tax/BCTaxCalculator.php`):

```php
public function supports(TaxContext $context): bool
{
    return $context->province === 'BC';
}
```

`calculate()` loads two `SalesTax` rows (lazily seeded via `SalesTaxRepository::getBySlug()` with defaults: GST 5%, PST 7%) and:

- Returns no lines at all when `taxClass === 'E'`.
- Always adds a GST line when GST is Active (unless exempt).
- Adds a PST line only when `taxClass === 'S'` **and** the company has no BC PST number on file **and** PST is Active. The number is a company custom field owned by `TaxBCBundle`, looked up through `companyId` — it is not on the context.

Its admin config screen (`BCTaxConfigController`, route `admin_bundle_tax_bc_config`) lets an admin edit the GST/PST rate and toggle each row's own Active/Inactive status independently of the bundle-level kill-switch — that's a data-level status on the `SalesTax` row itself, not the same thing as Bundle Management's Active/Inactive on the bundle as a whole.

For the sibling bundle that covers every other Canadian province in one calculator (dispatching on a per-province rate table, rather than one bundle per province), see `TaxCanadaSimpleBundle` (`modules/TaxCanadaSimpleBundle/src/Tax/CanadaSimpleTaxCalculator.php`) — note its `supports()` explicitly excludes `'BC'`, since `TaxBCBundle` owns that one.
