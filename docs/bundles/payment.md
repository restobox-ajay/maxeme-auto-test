# Building a Payment bundle

A Payment bundle offers one or more payment methods at checkout — rendering the checkout-page option, and validating the customer's input for it before an order is placed. There are two contracts here, and choosing the right one depends on whether your bundle backs a single fixed method or an admin-creatable set of them.

## The contracts

### PaymentMethodInterface — one bundle, one fixed method

`App\Contract\Payment\PaymentMethodInterface` (`src/Contract/Payment/PaymentMethodInterface.php`):

```php
interface PaymentMethodInterface
{
    public function getSlug(): string;

    public function getName(): string;

    public function getPriority(): int;

    /** @param array<string, mixed> $context */
    public function renderCheckoutOption(array $context): string;

    /** @param array<string, mixed> $context */
    public function validate(array $context): PaymentValidationResult;
}
```

Use this when your bundle represents exactly one payment method that isn't admin-configurable per instance — "Pay Upon Delivery", "Stripe". `renderCheckoutOption()` returns the HTML for the checkout radio option (a `context['selected']` key tells you if it's the currently-picked one, for the `checked` attribute). `validate()` runs at order-placement time and returns `App\Contract\Payment\PaymentValidationResult` (`success: bool`, `message: ?string`) — return a failure and the order won't go through.

### PaymentMethodProviderInterface — one bundle, N admin-created methods

`App\Contract\Payment\PaymentMethodProviderInterface` (`src/Contract/Payment/PaymentMethodProviderInterface.php`), additive to the above — `PaymentMethodInterface` itself is unchanged:

```php
interface PaymentMethodProviderInterface
{
    /** @return PaymentMethodInterface[] */
    public function getPaymentMethods(): array;
}
```

Use this when a single tagged service should back an arbitrary, admin-creatable list of methods — e.g. "Manual bank transfer", "Manual e-transfer", each created as its own row in the admin. Rather than tagging one `PaymentMethodInterface` service per method, you tag one `PaymentMethodProviderInterface` service, and its `getPaymentMethods()` turns each admin-created row into its own `PaymentMethodInterface` instance at resolve time. This mirrors how `FeeFieldProviderInterface` is an additional, optional tag alongside `FeeCalculatorInterface` in the Fee bundle type (see [fee.md](fee.md)) — same "one fixed thing" vs. "one thing backing N admin rows" split.

**Rule of thumb:** singular (`PaymentMethodInterface` alone) for one fixed method with no per-instance admin config beyond on/off; provider (`PaymentMethodProviderInterface`) when admins should be able to create/rename/delete multiple instances of "the same kind" of payment method.

## The tags

```yaml
# Singular — modules/MyPaymentBundle/config/services.yaml
MyPaymentBundle\Payment\MyPaymentMethod:
    tags: ['app.payment_method']

# Provider
MyPaymentBundle\Payment\MyPaymentMethodProvider:
    tags: ['app.payment_method_provider']
```

## The resolver

`PaymentBundle\Payment\PaymentMethodResolver` (`modules/PaymentBundle/src/Payment/PaymentMethodResolver.php`) takes both `!tagged_iterator app.payment_method` and `!tagged_iterator app.payment_method_provider`. For each Active `app.payment_method` instance it's used directly; for each Active `app.payment_method_provider` it calls `getPaymentMethods()` and adds every method returned. All singular `app.payment_method` instances are also lazily upserted into the `payment_method` table on every resolve (`ensureRegistered()`) — this has to happen before `CompanyPaymentMethodRepository::findEnabledForCompany()` is consulted, because that repo's opt-out model treats "no row yet" as enabled; a method missing its row at read time would wrongly look unavailable on a fresh install. Results are filtered to what's enabled for the requesting company and sorted by `getPriority()` (ascending — lower numbers show first).

## The kill-switch: BundleDescriptorInterface

As with every bundle type, implement `App\Contract\Bundle\BundleDescriptorInterface` (tag `app.bundle_descriptor`) or your bundle won't appear in Bundle Management (`/admin/bundle-management`) and can't be toggled Inactive. `getSource()` must match the bundle's root PHP namespace segment — `PaymentMethodResolver::allMethods()` checks `BundleStatusRepository::isActiveForInstance()` on both the singular methods and the providers before including them.

## Examples

### PaymentPayUponDeliveryBundle — singular

`PayUponDeliveryPaymentMethod` (`modules/PaymentPayUponDeliveryBundle/src/Payment/PayUponDeliveryPaymentMethod.php`) implements `PaymentMethodInterface` directly: fixed slug `pay-upon-delivery`, priority `10`, `validate()` always succeeds (no external check needed — there's nothing to verify before the order ships). Tagged `app.payment_method`. This bundle also demonstrates `AbstractFeeCalculator` (see [fee.md](fee.md)) — `PayUponDeliverySurchargeFeeCalculator` adds a flat surcharge whenever this method is selected, matched via `FeeContext::$paymentMethod`.

### PaymentManualBundle — provider

`ManualPaymentMethodProvider` (`modules/PaymentManualBundle/src/Payment/ManualPaymentMethodProvider.php`) implements `PaymentMethodProviderInterface`, tagged `app.payment_method_provider`. Its `getPaymentMethods()` loads every `PaymentMethod` entity row with `source = ManualPaymentMethod::SOURCE` and wraps each in a `ManualPaymentMethod` instance (slug + name from the row, fixed priority `20`, `validate()` always succeeds). The admin CRUD for creating/editing/deleting those rows lives in `ManualPaymentMethodController` (route `admin_bundle_payment_manual_index`) — see [modules/PaymentManualBundle/README.md](../../modules/PaymentManualBundle/README.md).

### PaymentStripeBundle — a real gateway with validate() doing real work

`StripePaymentMethod` (`modules/PaymentStripeBundle/src/Payment/StripePaymentMethod.php`) is singular (`app.payment_method`, slug `stripe`, priority `30`), but unlike the two examples above its `validate()` isn't a rubber stamp: it reads `context['stripe_payment_intent_id']`, retrieves the PaymentIntent from Stripe via `StripeClient`, and fails the order unless the intent's `status === 'succeeded'` and (when a `Company` is in context) the intent's `metadata['company_id']` matches the ordering company — preventing one company's completed payment from being replayed onto another company's order. Credentials come from `StripeConfigProvider` (`modules/PaymentStripeBundle/src/Service/StripeConfigProvider.php`), which reads dev/live publishable+secret keys from `AppSettings` (DB-stored, not `.env`) and is shared with the post-order invoice-payment flow in `App\Controller\Customer\OrderController` so both paths use one source of Stripe config.
