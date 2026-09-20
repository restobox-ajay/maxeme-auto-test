<?php

declare(strict_types=1);

namespace App\Contract\Cart;

use App\Entity\Cart;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;

/**
 * How much of a product a *given cart* is already holding against stock.
 *
 * `ProductInventory::getAvailableQuantity()` subtracts every hold on the product, which is the right
 * answer for "what can a stranger still buy" — the catalog, the admin inventory grid. It is the
 * wrong answer for "may this cart hold N", because the cart's own hold is inside the figure it is
 * then measured against, so a customer is blocked by themselves: with 50 in stock and 49 already in
 * their cart, availability reads 1 and their own line gets capped down to it (issue #214).
 *
 * Core cannot ask CartHoldBundle directly — holds are a bundle concern and the bundle has a kill
 * switch — so core asks whoever is listening. With no provider registered nothing holds anything,
 * every provider returns 0, and availability is already correct as it stands.
 *
 * Tagged app.cart_held_quantity, summed by CartService.
 */
interface CartHeldQuantityProviderInterface
{
    /**
     * The quantity of $product in $region that $cart itself is holding, and which is therefore
     * already deducted from ProductInventory::getAvailableQuantity().
     *
     * Counts only live holds — an expired one is not deducted from availability either, so adding it
     * back would credit the cart twice.
     */
    public function heldQuantityForCart(Cart $cart, ProductCore $product, FulfillmentRegion $region): string;
}
