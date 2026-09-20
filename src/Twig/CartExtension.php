<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\CartService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CartExtension extends AbstractExtension
{
    public function __construct(private readonly CartService $cartService) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('customer_cart_count', [$this, 'getCartCount']),
            new TwigFunction('customer_cart_items', [$this, 'getCartItems']),
        ];
    }

    public function getCartCount(): int
    {
        return (int) array_sum($this->getCartItems());
    }

    /**
     * The cart as sku => qty, for templates that need to show what is already in it — the catalog
     * and product-detail buttons render the quantity in place of "Add to Cart".
     *
     * Callers that need it per row should hold it in a template variable rather than calling this
     * inside the loop — it is a database read, not a lookup.
     *
     * @return array<string, int>
     */
    public function getCartItems(): array
    {
        return $this->cartService->getItems();
    }
}
