<?php

declare(strict_types=1);

namespace Number1HideRealPriceBundle\Hook;

use App\Contract\Hook\InjectionPointProviderInterface;
use Number1HideRealPriceBundle\Service\RealPriceToggleRenderer;

/**
 * Same as HideListingPriceProvider, for the "Set quantity for your order" Add to Cart modal
 * (templates/customer/catalog/_cart_qty_modal.html.twig) — that modal is only ever included from
 * inside an `{% if app.user %}` block (both call sites: _products.html.twig and home.html.twig), so
 * no CustomerUser gate is needed here, same reasoning as HideListingPriceProvider. The modal's price
 * `<span>` is wrapped in `.hrp-real-value` just like the listing card's, so RealPriceToggleRenderer's
 * shared hideAll()/click-reveal JS picks it up automatically — nothing bundle-specific needed beyond
 * registering this injection point.
 */
final class HideModalPriceProvider implements InjectionPointProviderInterface
{
    public function __construct(
        private readonly RealPriceToggleRenderer $renderer,
    ) {}

    public function getPoint(): string
    {
        return 'cart_modal_price_after';
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
        return $this->renderer->render();
    }
}
