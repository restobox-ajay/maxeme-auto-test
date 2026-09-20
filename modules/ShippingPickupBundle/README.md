# ShippingPickupBundle

**Type:** Shipping · **Name:** Pickup Shipping · **Edit route:** none (no admin config)

## What it does

Offers a single, always-available "Pickup" shipping option — free, no delivery estimate, pick up the order at the warehouse. `PickupShippingCalculator::supports()` always returns `true`, so this option shows up on every checkout regardless of cart contents or destination province.

## How it's configured

Nothing to configure — the option's label, description, and $0 price are fixed in code (`modules/ShippingPickupBundle/src/Shipping/PickupShippingCalculator.php`). Like every bundle, it can still be turned off from Bundle Management (`/admin/bundle-management`); with it Inactive, "Pickup" no longer appears as a checkout option.

## External dependencies

None.

## See also

[docs/bundles/shipping.md](../../docs/bundles/shipping.md) — the "how to build a Shipping bundle" guide, which uses this bundle as its worked example.
