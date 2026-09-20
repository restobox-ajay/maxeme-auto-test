# Number1HideRealPriceBundle

**Type:** Injection Point · **Name:** Number1 Hide Real Price · **Edit route:** none (no config screen)

## What it does

Hides the real, per-customer price on the customer catalog listing and product detail pages, showing
`Number1SuggestedPriceBundle`'s "Price Suggested" number instead — revealed by clicking a literal `...` toggle
next to the description. Direct port of `number1_inventory`'s own mechanism: the real price is present in the
rendered HTML the whole time, hidden by a plain `display: none` CSS rule and shown/hidden by a jQuery-style click
listener (`views/product/partials/_item.php`/`_rims-item.php`, `web/static/css/style.css:401-406`,
`web/static/js/product.js:51-59`). **This is a display-only UX trick, not a security boundary** — anyone viewing
page source sees the real price immediately, same as the reference app. It exists so a retail customer glancing at
a wholesale buyer's screen sees the marked-up "Price Suggested" number instead of what the shop actually paid — see
`HIDE_REAL_PRICE_BUNDLE_PLAN.md` §0 for the client's own explanation.

This bundle never computes or duplicates a price value itself. It only toggles the visibility of whichever
real-price element core already rendered correctly (`.product-price` on the listing page,
`.customer-product-price` on the detail page) — the exact price a specific customer would pay, already resolved
per company/price-list/region by `AbstractCustomerController::customerProductRow()`/`resolveCustomerPriceAmount()`.

## Where it's shown

- **Catalog listing** (`templates/customer/catalog/_products.html.twig`): via `product_description_after` — a new
  injection point added to that template as part of this bundle (it never rendered a visible description before;
  see `HIDE_REAL_PRICE_BUNDLE_PLAN.md` §2.3/§4). Positioned right next to the description, pixel-parity with the
  reference app, per explicit client confirmation.
- **Product detail** (`templates/customer/catalog/detail.html.twig`): via the already-existing
  `product_short_description_after` point, previously unused by any bundle.
- **Add to Cart quantity modal** (`templates/customer/catalog/_cart_qty_modal.html.twig`, shared by
  both the catalog listing and the home page's featured products): via `cart_modal_price_after` — a
  new injection point added to that template alongside this bundle. The modal's price `<span>` carries
  the same `.hrp-real-value` class the listing card's does, so it's picked up by the same shared
  hide/reveal JS with no extra wiring. No `CustomerUser` gate needed here (unlike the detail page) —
  the modal is only ever included from inside an `{% if app.user %}` block.

Both points render `Number1HideRealPriceBundle\Service\RealPriceToggleRenderer`'s shared output: the `...` toggle
plus one small `<style>`/`<script>` block (hides `.product-price`/`.customer-product-price` by default, reveals on
click) — same "ships its own inline style/script" convention `NewsBundle`'s scroller already uses. A
`window.__hrpInit` guard means the shared script's single delegated click listener only registers once, no matter
how many product rows call `render()` on the listing page.

## How it's configured

No config screen — this bundle either hides the real price everywhere it applies, or (via Bundle Management)
doesn't. Two conditions gate rendering, checked at render time rather than assumed:

- **`Number1SuggestedPriceBundle` must be Active.** If it's Inactive (or not installed), this bundle renders
  nothing and the real price stays visible plainly — there's deliberately no alternative number to hide it behind
  otherwise (`HIDE_REAL_PRICE_BUNDLE_PLAN.md` §5.5).
- **Detail page only: the viewer must be a logged-in `CustomerUser`.** Unlike the listing page's
  `product_description_after` call site (guarded by `{% if app.user %}` in the template itself), the detail page's
  `product_short_description_after` fires for guests too — checked directly (`Symfony\Bundle\SecurityBundle\Security`)
  so a guest never sees an inert toggle with no real-price element to reveal.

Coordination with `Number1SuggestedPriceBundle`'s own display: its `SuggestedPriceInjectionPointProvider` normally
suppresses its "Price Suggested" line when there's no override configured (to avoid a redundant duplicate of the
real price right above it). That provider now checks whether this bundle is Active first — if so, it always
renders, since once the real price is hidden, "Price Suggested" needs to be the only visible number even for
un-overridden products.

## Data

None — no entities, no Custom Fields, no schema changes.

## External dependencies

`Number1SuggestedPriceBundle` — checked by **source string** via `BundleStatusRepository::isActive()`, not a hard
class dependency. Deactivating or removing it makes this bundle a no-op (real price stays visible), same
loose-coupling convention other bundles in this project already use for cross-bundle awareness.

## Future direction

Client has flagged that `Number1SuggestedPriceBundle`'s mechanism is eventually moving into core, surfaced on the
admin product pricing screen (`/admin/product/price/index?lists=42`). When that happens, this bundle's
`BundleStatusRepository::isActive('Number1SuggestedPriceBundle')` check needs revisiting — a core concept isn't
something Bundle Management can toggle off the way a bundle can. See `HIDE_REAL_PRICE_BUNDLE_PLAN.md` §3.1.

## See also

[/HIDE_REAL_PRICE_BUNDLE_PLAN.md](../../HIDE_REAL_PRICE_BUNDLE_PLAN.md) — the full design review this bundle is
built from, including the reference app's exact code, every design decision, and the client's own confirmations.
