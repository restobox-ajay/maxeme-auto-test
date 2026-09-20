<?php

declare(strict_types=1);

namespace Number1HideRealPriceBundle\Service;

/**
 * Renders the "..." toggle plus the shared JS that hides the real price and reveals it, on click,
 * next to the description text itself — pixel-parity with number1_inventory's own mechanism
 * (views/product/partials/_item.php: description + "..." + revealed price all in the same cell;
 * see HIDE_REAL_PRICE_BUNDLE_PLAN.md §1). Deliberately does not duplicate or recompute the price
 * *value*: it copies the text of whichever real-price element core already rendered correctly
 * into a reveal slot next to the toggle, the same way the reference app's JS works from its own
 * nearby price element rather than being told the value directly.
 *
 * Client explicitly confirmed "Price Suggested" (now core's own always-visible line, see
 * App\Twig\SuggestedPriceExtension — originally Number1SuggestedPriceBundle's, promoted to core in
 * SUGGESTED_PRICE_CORE_ADAPTATION_PLAN.md phase 2) stays exactly as-is — only *where the real price
 * reveals* changes, from in-place (the Price column) to next to the description. This matters for
 * the listing page specifically: `.product-price` there is `<strong class="product-price">
 * <span class="hrp-real-value">...</span>{{ suggested_price_html(product) }}</strong>`
 * (_products.html.twig) — "Price Suggested"
 * is *nested inside* `.product-price` alongside the real value, so `.product-price` itself must
 * stay visible (hiding it would hide Price Suggested too); only the inner `.hrp-real-value` span
 * gets hidden, permanently — never revealed in place, only read from for the copy. On the detail
 * page, `.customer-product-price` has no such inner span (Price Suggested is a separate sibling
 * <p> there) — the whole element hides permanently, same "copy the text out" treatment.
 *
 * Hides via an inline `style.setProperty('display', 'none', 'important')` rather than a shared
 * <style> rule — this app's List View has its own highly-specific `!important` rules for
 * `.product-price` (e.g. `.site-customer .catalog-shell .catalog-products.is-list-view
 * .product-price { display: flex !important; ... }`), and CSS only lets `!important` skip
 * specificity comparisons against *other stylesheet rules*, not against an element's own inline
 * style — an inline declaration always wins regardless of any selector's specificity.
 *
 * Called once per product on the listing page (inside its `{% for %}` loop) and once on the detail
 * page — the `window.__hrpInit` guard means the shared <script>'s hideAll()/click listener only
 * ever get registered once no matter how many times render() runs on a page. hideAll() itself runs
 * after the whole page has parsed (DOMContentLoaded), not at the point this inline <script> tag
 * executes — this row's own `.product-price` sibling in `.product-footer` hasn't been parsed yet
 * at that point (`.product-main`, where this renders, comes first in the DOM), so hiding has to be
 * deferred rather than done synchronously against `document.currentScript`'s own row.
 */
final class RealPriceToggleRenderer
{
    public function render(): string
    {
        return <<<'HTML'
             <span class="hrp-toggle" style="text-decoration: none !important; display: inline !important;" role="button" tabindex="0" aria-label="Show real price">...</span><span class="hrp-reveal-slot" style="text-decoration: none !important; display: inline !important; white-space: nowrap; margin-left: 10px;"></span>
            <script>
            if (!window.__hrpInit) {
                window.__hrpInit = true;

                var hrpFindPriceEl = function (scope) {
                    return scope.querySelector('.hrp-real-value') || scope.querySelector('.product-price, .customer-product-price');
                };

                // .hrp-real-value (listing page) already holds only the number. The detail page's
                // .customer-product-price is `<strong>Price:</strong> $X` with no such wrapper —
                // the price text there is just the last child node, so grab that instead of the
                // whole element's text (which would include the "Price:" label).
                var hrpExtractValue = function (priceEl) {
                    if (priceEl.classList.contains('hrp-real-value')) {
                        return priceEl.textContent.trim();
                    }
                    var last = priceEl.lastChild;
                    return (last ? last.textContent : priceEl.textContent).trim();
                };

                var hrpHideAll = function () {
                    document.querySelectorAll('.hrp-real-value').forEach(function (el) {
                        el.style.setProperty('display', 'none', 'important');
                    });
                    document.querySelectorAll('.product-price, .customer-product-price').forEach(function (el) {
                        if (el.querySelector('.hrp-real-value')) {
                            return;
                        }
                        el.style.setProperty('display', 'none', 'important');
                    });
                };

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', hrpHideAll);
                } else {
                    hrpHideAll();
                }

                document.addEventListener('click', function (e) {
                    var toggle = e.target.closest('.hrp-toggle');
                    if (!toggle) {
                        return;
                    }
                    var slot = toggle.nextElementSibling;
                    if (!slot || !slot.classList.contains('hrp-reveal-slot')) {
                        return;
                    }
                    if (slot.textContent !== '') {
                        slot.textContent = '';
                        return;
                    }
                    var scope = toggle.closest('.product-card') || toggle.closest('.customer-product-info') || document;
                    var priceEl = hrpFindPriceEl(scope);
                    if (!priceEl) {
                        return;
                    }
                    slot.textContent = hrpExtractValue(priceEl);
                });
                document.addEventListener('keydown', function (e) {
                    if (e.key !== 'Enter' && e.key !== ' ') {
                        return;
                    }
                    if (!e.target.classList || !e.target.classList.contains('hrp-toggle')) {
                        return;
                    }
                    e.preventDefault();
                    e.target.click();
                });
            }
            </script>
            HTML;
    }
}
