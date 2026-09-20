# CartHoldBundle

**Type:** Inventory · **Name:** Cart Hold · **Edit route:** `admin_bundle_cart_hold_config`

## What it does

Reserves inventory for whatever a customer currently has in their session cart, for a
configurable duration, so `Available = Starting Inventory - Cart Hold - Pending Orders -
Approved Orders` accounts for items other customers can't also check out. Adding to cart,
removing from cart, updating quantity, or viewing the cart/checkout page resets the hold timer
for the whole cart. When a hold expires, the held items are removed from the session cart and
the customer sees a notice on their next page load.

Since there's no fast/frequent cron in this app, expired holds are swept lazily instead:
`CartHoldSweepSubscriber` runs on every customer catalog/cart/checkout page load and every admin
product/inventory page load, releasing any expired `CartHold` row it finds — not just the
current visitor's — so the inventory numbers shown afterward are always correct regardless of
who triggers the sweep. `app:inventory-recalc` (core) also recomputes the Cart Hold bucket from
source of truth hourly, as a backstop against drift.

## How it's configured

- **On/off**: the bundle's own Active/Inactive status on the admin Bundle Management page —
  the same mechanism every other optional feature in this app uses. Inactive means no holds are
  created or enforced at all.
- **Duration**: one setting, "Cart Hold Duration (seconds)", edited at `/admin/bundles/cart-hold`
  (stored as a plain `AppSetting` row, not a bespoke table — same pattern
  `FeeBCTireBundle\Controller\BCTireFeeConfigController` uses for its own settings).

## External dependencies

Reads `App\Entity\ProductInventory` (writes its `cartHoldQuantity` bucket),
`App\Service\CartService` (the session-backed cart), `App\Entity\Company`/`ProductCore`/
`FulfillmentRegion`, and `App\Repository\BundleStatusRepository` (for its own on/off check) —
all core. Core code calls back into this bundle's `CartHoldBundle\Service\CartHoldService` from
`CartController`/`CheckoutController` on cart mutations and cart/checkout views.

## See also

`src/Service/Inventory/InventoryReservationReconciler.php` (core) — the sibling mechanism for the
Sales Hold, Pending and Approved buckets. Cart Hold is intentionally the only bucket implemented as
its own bundle: it's genuinely optional and doesn't need the deep `SalesOrder`/`Invoice` coupling the
other three buckets require.
