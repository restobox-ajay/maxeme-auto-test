<?php

declare(strict_types=1);

namespace Number1HideRealPriceBundle\Hook;

use App\Contract\Hook\InjectionPointProviderInterface;
use Number1HideRealPriceBundle\Service\RealPriceToggleRenderer;

/**
 * Renders the "..." toggle right after the product description on the customer catalog listing
 * (templates/customer/catalog/_products.html.twig), pixel-parity with number1_inventory's own
 * positioning there — see HIDE_REAL_PRICE_BUNDLE_PLAN.md §2.3/§3. The real price itself
 * (`.product-price`, already correctly resolved per company/price-list/region by
 * AbstractCustomerController::customerProductRow()) is hidden by RealPriceToggleRenderer's shared
 * CSS and revealed by its shared JS — this provider never reads or duplicates the price value.
 */
final class HideListingPriceProvider implements InjectionPointProviderInterface
{
    public function __construct(
        private readonly RealPriceToggleRenderer $renderer,
    ) {}

    public function getPoint(): string
    {
        return 'product_description_after';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getSource(): string
    {
        return 'Number1HideRealPriceBundle';
    }

    public function render(array $context): string
    {
        // Suggested Price is unconditional core behavior now (SUGGESTED_PRICE_CORE_ADAPTATION_PLAN.md
        // phase 2) — always has a number to reveal, so no bundle-active gate needed here anymore
        // (previously checked Number1SuggestedPriceBundle::isActive(), which no longer exists).
        return $this->renderer->render();
    }
}
