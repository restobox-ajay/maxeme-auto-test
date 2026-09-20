# ShippingFreeBundle

**Type:** Shipping · **Name:** Free Shipping · **Edit route:** none (no admin config)

## What it does

Offers a single, always-available "Free Shipping" option — $0, no delivery estimate, no
conditions on cart contents or destination. `FreeShippingCalculator::supports()` always returns
`true`, so this option shows up on every checkout alongside whatever other shipping bundles also
match.

## How it's configured

Nothing to configure — the option's label, description, and $0 price are fixed in code
(`modules/ShippingFreeBundle/src/Shipping/FreeShippingCalculator.php`). Like every bundle, it can
still be turned off from Bundle Management (`/admin/bundle-management`); with it Inactive, "Free
Shipping" no longer appears as a checkout option.

## External dependencies

None.

## See also

[docs/bundles/shipping.md](../../docs/bundles/shipping.md) — the "how to build a Shipping
bundle" guide. `ShippingPickupBundle` is the closest sibling: same always-supports/$0 shape, but
framed as in-person warehouse pickup rather than a delivered free-shipping option.
