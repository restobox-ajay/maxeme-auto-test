# Building a Shipping bundle

A Shipping bundle offers one or more delivery options for a cart. Each option carries its own price, delivery estimate, and tax class — the customer picks one at checkout.

## The contract

Implement `App\Contract\Shipping\ShippingOptionInterface`:

```php
interface ShippingOptionInterface
{
    public function supports(AbstractSalesDocument $document): bool;

    /** @return ShippingOption[] */
    public function getOptions(AbstractSalesDocument $document): array;
}
```

`supports()` decides whether your bundle has anything to offer for this document (e.g. "only if every item is shippable by LTL", "only if the destination is BC"). `getOptions()` returns the actual offers — a bundle can return more than one `ShippingOption` (see `ShippingCanadaPostBundle`, which returns Ground and Express).

## What you're given

`App\Entity\AbstractSalesDocument` (`src/Entity/AbstractSalesDocument.php`) — the thing being shipped, whole. It may be a `Cart`, a `SalesOrder` or an `Estimate`, and **your calculator must not care which**: the same rows must produce the same offer whether a customer is looking at their cart, an admin is building an order, or a quote is being priced. Do not type-check the concrete class.

The five things a calculator almost always wants:

| | |
|---|---|
| `$document->getProvince()` | normalized two-letter code (e.g. `'BC'`), even when the stored address holds a full name like `'British Columbia'`. Never read the address's raw province instead — an unnormalized value silently matches no rule. |
| `$document->getCartItems()` | `list<array{product: ProductCore, qty: int}>`, product rows only, blanks and zero-quantity rows already dropped. |
| `$document->getHighestTaxClass()` | the highest tax class (`'E'`/`'G'`/`'S'`) across the rows. This is what a `ShippingOption`'s `taxClass` normally gets. |
| `$document->getCompany()` | the buying `Company`, **or `null`** — a guest cart has no buyer, so always null-check. |
| `$document->getEffectiveShippingAddress()?->getSourceAddress()?->getId()` | the address-**book** row's id. Use this, not `getShippingAddress()->getId()`, when you need the id of a `company_address` record: the document's own address row is a snapshot with its own id, which would match a `company_address`-keyed lookup only by coincidence. |

Everything else on the document is fair game too — `getSubtotal()`, `getFeeLineRows()`, `getCouponCodes()`, `getCompany()->getAddresses()`. That is the point of taking the document: a rule can read what it needs without core growing a parameter for it. The price is that a bundle can reach into the entity graph, so keep to accessors and never write to the document — it may be transient (the admin preview endpoints and the customer cart/checkout pages each build one that is deliberately never persisted) or it may be a live managed entity, and a calculator cannot tell.

**Money on the document is pre-discount.** `getSubtotal()` is the sum of the line rows. Discounts and fees are separate rows in `getFeeLineRows()`, typed `discount` and `fee`; sum those yourself if you want a post-discount figure. The free-shipping-threshold waiver that customer checkout applies is *not* a calculator concern — the controller compares the post-coupon subtotal against the threshold after your calculator has answered, and zeroes the amount it charges.

`App\Contract\Shipping\ShippingOption` (`src/Contract/Shipping/ShippingOption.php`) is the return type — a plain readonly DTO: `id`, `label`, `description`, `amount`, `deliveryDays` (nullable), `taxClass`, `forcesQuote` (defaults `false`). Set `forcesQuote: true` only if selecting this option means checkout must produce a quote (Estimate) instead of a real order — see the fallback-tier section below.

Two more interfaces exist in `src/Contract/Shipping/` for optional pieces: `ShippingMenuItemInterface` (tag `app.shipping_menu_item`, adds an entry to the admin sidebar under Shipping if your bundle has a config screen) and `ShippingFieldProviderInterface` (a per-product field on the admin product form, mirroring the Fee bundle's `FeeFieldProviderInterface` — see [fee.md](fee.md)).

## The tag

Tag your calculator service with `app.shipping_option`:

```yaml
# modules/MyShippingBundle/config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    MyShippingBundle\:
        resource: '../src/'

    MyShippingBundle\Shipping\MyShippingCalculator:
        tags: ['app.shipping_option']
```

## The resolver

`ShippingBundle\Shipping\ShippingResolver` (`modules/ShippingBundle/src/Shipping/ShippingResolver.php`) collects every `app.shipping_option`-tagged service via `!tagged_iterator app.shipping_option`, skips any whose owning bundle is Inactive (see below), and — for the rest — calls `supports()` then `getOptions()`, flattening everything into one list. It never throws; a cart with no matching shipping bundle just gets an empty list.

## The fallback tier

`ShippingResolver` also accepts a second, separately-tagged pool: `app.shipping_option_fallback`. `getAvailableOptions()` runs the normal `app.shipping_option` pool first; only if that pool comes back empty does it fall through and run the fallback pool. Tag your calculator `app.shipping_option_fallback` instead of `app.shipping_option` when it should only ever be consulted as a last resort — a bundle offering every destination unconditionally (like `ShippingPickupBundle`) means the normal pool is essentially never empty, so a fallback bundle exists specifically for destinations nothing else covers.

`ShippingNeedQuoteBundle` is the fallback example in this repo: its single option sets `forcesQuote: true`, which `CheckoutController` and `StripeCheckoutIntentController` check to route the cart to a quote (Estimate) instead of a real order, and to block card payment respectively — the same outcome as the old "no shipping options ⇒ quote" behavior, just as a toggleable bundle instead of implicit empty-list logic.

## The kill-switch: BundleDescriptorInterface

Every bundle also needs an `App\Contract\Bundle\BundleDescriptorInterface` implementation (`src/Contract/Bundle/BundleDescriptorInterface.php`) tagged `app.bundle_descriptor`, or it won't show up in Bundle Management (`/admin/bundle-management`) and can't be turned off:

```php
final class MyShippingBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string { return 'My Shipping Bundle'; }
    public function getType(): string { return 'Shipping'; }
    public function getSource(): string { return 'MyShippingBundle'; }
    public function getEditRoute(): ?string { return null; } // or your config route name
    public function getDocsUrl(): ?string { return 'https://github.com/.../modules/MyShippingBundle/README.md'; }
}
```

```yaml
    MyShippingBundle\Bundle\MyShippingBundleDescriptor:
        tags: ['app.bundle_descriptor']
```

`getSource()` must be the bundle's root PHP namespace segment (e.g. `'MyShippingBundle'`, matching `MyShippingBundle\Shipping\MyShippingCalculator`'s namespace) — `BundleStatusRepository::isActiveForInstance()` derives the source from an instance's class name the same way, so a mismatch here silently breaks the toggle. When Inactive, `ShippingResolver` skips your calculator entirely — it stops offering options everywhere, not just in one place.

## Example: ShippingPickupBundle

The simplest real shipping bundle in the repo. `PickupShippingCalculator` (`modules/ShippingPickupBundle/src/Shipping/PickupShippingCalculator.php`):

```php
final class PickupShippingCalculator implements ShippingOptionInterface
{
    public function supports(AbstractSalesDocument $document): bool
    {
        return true;
    }

    public function getOptions(AbstractSalesDocument $document): array
    {
        return [
            new ShippingOption(
                id:           'pickup',
                label:        'Pickup',
                description:  'Pick up your order at our warehouse.',
                amount:       0.0,
                deliveryDays: null,
                taxClass:     $document->getHighestTaxClass(),
            ),
        ];
    }
}
```

It always supports (every cart can be picked up) and always returns a single free option. Its `config/services.yaml` tags the calculator `app.shipping_option` and its descriptor `app.bundle_descriptor`; there's no admin config screen, so `getEditRoute()` returns `null`.

For a more involved example — one that inspects `getCartItems()`, checks a per-product `shippingClass`, and returns multiple priced options — see `ShippingCanadaPostBundle` or `ShippingBulkBundle`.

For an example keyed on *who's buying* rather than cart contents, see `ShippingArrangementBundle`: `ArrangementShippingCalculator::supports()` checks the document's company id and shipping address-book id against a custom-field flag (`company` OR `company_address` — either makes the destination eligible), and the eligibility itself is managed from the bundle's own dedicated admin config screen rather than through `CustomFieldRenderer`'s generic per-record form — see [custom-fields.md](custom-fields.md) for when that pattern makes sense.
