# InjectionPointExampleBundle

**Type:** Injection Point · **Name:** Injection Point Example · **Edit route:** none (no admin config)

## What it does

Renders a fixed message into the `cart_before_checkout` injection point (shown on the cart page just before the customer proceeds to checkout — see `templates/customer/cart/index.html.twig`). `CartCheckoutMessageProvider` implements `InjectionPointProviderInterface`, ignores the passed context entirely, and always returns the same static paragraph: "Example bundle content: your order will be reviewed before shipping." See [docs/bundles/injection-points.md](../../docs/bundles/injection-points.md) for the full list of injection points available in the app.

## How it's configured

No admin config screen — the target point and the rendered message are fixed in code (`modules/InjectionPointExampleBundle/src/Hook/CartCheckoutMessageProvider.php`).

## External dependencies

None.

## See also

[docs/bundles/injection-points.md](../../docs/bundles/injection-points.md) — the "how to build an Injection Point bundle" guide, which uses this bundle as its worked example.
