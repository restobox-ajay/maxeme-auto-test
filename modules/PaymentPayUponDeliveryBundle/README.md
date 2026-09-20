# PaymentPayUponDeliveryBundle

**Type:** Payment · **Name:** Pay Upon Delivery · **Edit route:** none (no admin config)

## What it does

Offers a single fixed payment method — "Pay Upon Delivery" (slug `pay-upon-delivery`, priority 10) — implementing `PaymentMethodInterface` directly (singular, not a provider; see [docs/bundles/payment.md](../../docs/bundles/payment.md)). `validate()` always succeeds — there's no external check to make before the order goes through, since payment happens later at delivery.

It also ships `PayUponDeliverySurchargeFeeCalculator`, a `Fee` bundle-side calculator (via `App\Contract\Fee\AbstractFeeCalculator`) that adds a flat $2.00 surcharge whenever this payment method is the one selected at checkout — see [docs/bundles/fee.md](../../docs/bundles/fee.md).

## How it's configured

No admin config screen — the method's slug, name, priority, and the surcharge amount are fixed in code. Whether a company can use this method at all is controlled generically via each company's enabled-payment-methods list, not by this bundle.

## External dependencies

None.

## See also

[docs/bundles/payment.md](../../docs/bundles/payment.md) — the "how to build a Payment bundle" guide, which uses this bundle as its singular-method worked example.
