# ShippingNeedQuoteBundle

**Type:** Shipping · **Name:** Need Quote For Unquoted Area · **Edit route:** none (no admin config)

## What it does

Offers a single "Need Quote" shipping option that forces the checkout into a quote (Estimate)
instead of a real Order. Unlike every other shipping calculator, `NeedQuoteShippingCalculator`
is tagged `app.shipping_option_fallback` instead of `app.shipping_option`, so `ShippingResolver`
only ever consults it when every normal-tier calculator returned no options for the cart/
destination — see `modules/ShippingBundle/src/Shipping/ShippingResolver.php`. When it does fire,
its single option has `forcesQuote: true`, which `CheckoutController` and
`StripeCheckoutIntentController` check to route the cart to an Estimate and to block card
payment respectively.

## How it's configured

Nothing to configure — the option's label, description, and $0 price are fixed in code
(`modules/ShippingNeedQuoteBundle/src/Shipping/NeedQuoteShippingCalculator.php`). Like every
bundle, it can still be turned off from Bundle Management (`/admin/bundle-management`); with it
Inactive, destinations with no normal shipping coverage go back to resolving zero shipping
options instead of showing "Need Quote".

## External dependencies

None.

## See also

[docs/bundles/shipping.md](../../docs/bundles/shipping.md) — the "how to build a Shipping
bundle" guide, including the fallback-tier mechanism this bundle uses.
