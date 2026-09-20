<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Service\SuggestedPriceCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Renders "Price Suggested: $X" right after the real price on customer product pages
 * (templates/customer/catalog/detail.html.twig and _products.html.twig) — unconditional core
 * behavior now (SUGGESTED_PRICE_CORE_ADAPTATION_PLAN.md phase 2), no bundle/injection-point
 * indirection needed since it's no longer optional.
 *
 * The templates pass either a ProductCore entity (detail page) or a plain row array with an 'id'
 * key (catalog listing partial) — resolveProduct() handles both, same dual shape the original
 * bundle-owned injection point provider handled.
 */
final class SuggestedPriceExtension extends AbstractExtension
{
    public function __construct(
        private readonly SuggestedPriceCalculator $calculator,
        private readonly EntityManagerInterface $entityManager,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('suggested_price_html', [$this, 'render'], ['is_safe' => ['html']]),
        ];
    }

    public function render(mixed $product): string
    {
        $resolved = $this->resolveProduct($product);
        if (!$resolved instanceof ProductCore) {
            return '';
        }

        $effective = $this->calculator->getEffectivePrice($resolved);
        if ($effective === null) {
            return '';
        }

        // Don't show a "Price Suggested" line that's just a duplicate of the real price above it —
        // only surface it when there's an actual override in play. Skipped when
        // Number1HideRealPriceBundle is active: it hides the real price and relies on this line
        // being the only visible number, so it must render even when there's no override
        // (HIDE_REAL_PRICE_BUNDLE_PLAN.md §3's coordination decision).
        $base = $this->calculator->getBasePrice($resolved);
        $hideBundleActive = $this->bundleStatusRepo->isActive('Number1HideRealPriceBundle');
        if (!$hideBundleActive && $base !== null && abs((float) $effective - (float) $base) < 0.005) {
            return '';
        }

        // On the catalog listing, this renders *inside* <strong class="product-price">
        // (_products.html.twig — see HIDE_REAL_PRICE_BUNDLE_PLAN.md), so it must be an inline
        // element there — a <p> would force the browser to close the surrounding <strong> early,
        // breaking the nesting entirely. On the detail page it's a sibling <p> (unaffected, no grid
        // there).
        $isListing = is_array($product);
        $tag = $isListing ? 'span' : 'p';

        return "<{$tag} class=\"customer-product-price-suggested\"><strong>Price Suggested:</strong> $"
            . htmlspecialchars($effective, ENT_QUOTES) . "</{$tag}>";
    }

    private function resolveProduct(mixed $value): ?ProductCore
    {
        if ($value instanceof ProductCore) {
            return $value;
        }

        $id = is_array($value) && isset($value['id']) ? (int) $value['id'] : 0;
        if ($id <= 0) {
            return null;
        }

        $product = $this->entityManager->find(ProductCore::class, $id);

        return $product instanceof ProductCore ? $product : null;
    }
}
