<?php

declare(strict_types=1);

namespace Number1HideRealPriceBundle\Hook;

use App\Contract\Hook\InjectionPointProviderInterface;
use App\Entity\CustomerUser;
use Number1HideRealPriceBundle\Service\RealPriceToggleRenderer;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Same as HideListingPriceProvider, for the product detail page
 * (templates/customer/catalog/detail.html.twig) — renders right after the existing
 * `product_short_description_after` point (already wired there, unused before this bundle), which
 * sits in the same `.customer-product-info` container as the real price
 * (`.customer-product-price`), giving RealPriceToggleRenderer's shared JS the same scope to find it.
 *
 * Unlike the listing page's product_description_after call site (guarded by `{% if app.user %}`
 * at the template level), product_short_description_after fires for guests too — the real price
 * line further down is separately guarded and simply doesn't exist in the DOM for a guest, so this
 * checks the user itself rather than rendering an inert toggle with nothing to reveal.
 */
final class HideDetailPriceProvider implements InjectionPointProviderInterface
{
    public function __construct(
        private readonly RealPriceToggleRenderer $renderer,
        private readonly Security $security,
    ) {}

    public function getPoint(): string
    {
        return 'product_short_description_after';
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
        if (!$this->security->getUser() instanceof CustomerUser) {
            return '';
        }

        // Suggested Price is unconditional core behavior now (SUGGESTED_PRICE_CORE_ADAPTATION_PLAN.md
        // phase 2) — always has a number to reveal, so no bundle-active gate needed here anymore
        // (previously checked Number1SuggestedPriceBundle::isActive(), which no longer exists).
        return $this->renderer->render();
    }
}
