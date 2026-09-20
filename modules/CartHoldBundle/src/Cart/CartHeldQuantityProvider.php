<?php

declare(strict_types=1);

namespace CartHoldBundle\Cart;

use App\Contract\Cart\CartHeldQuantityProviderInterface;
use App\Entity\Cart;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Service\QuantityScale;
use CartHoldBundle\Repository\CartHoldRepository;
use CartHoldBundle\Service\CartHoldService;

/**
 * Tells core how much of a product this cart is holding, so its own hold is not counted against it.
 *
 * `ProductInventory::getAvailableQuantity()` subtracts `cart_hold_quantity`, which is the SUM of
 * every live hold — including this cart's. Checking a cart's own line against that figure means the
 * customer competes with themselves: 49 of 50 in the cart leaves 1 "available", and the line is
 * capped down to 1 (issue #214). Core adds this number back before deciding.
 *
 * Returns 0 when the bundle is switched off, which is also correct: nothing is held then, so
 * availability already has nothing of this cart's in it to add back.
 */
final class CartHeldQuantityProvider implements CartHeldQuantityProviderInterface
{
    public function __construct(
        private readonly CartHoldRepository $cartHoldRepository,
        private readonly CartHoldService $cartHoldService,
    ) {
    }

    public function heldQuantityForCart(Cart $cart, ProductCore $product, FulfillmentRegion $region): string
    {
        if (!$this->cartHoldService->isEnabled()) {
            return QuantityScale::canonical(0);
        }

        return $this->cartHoldRepository->sumHeldQuantityForCart($cart, $product, $region, new \DateTimeImmutable());
    }
}
