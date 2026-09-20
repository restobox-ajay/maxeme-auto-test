<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\QuantityScale;
use App\Contract\Cart\CartHeldQuantityProviderInterface;
use App\Entity\AbstractDocumentAddress;
use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Repository\CartRepository;
use App\Service\Inventory\BackorderSplit;
use App\Service\Inventory\BackorderSplitResolver;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * DB-backed cart for the no-JS cart/checkout flow — one Cart row per session, one CartItem row
 * per product line. Previously stored sku=>qty directly in the PHP session with nothing
 * persisted, which meant a cart was lost the moment the session expired; product name/price/
 * image are still always re-resolved fresh from the catalog at render time rather than cached,
 * so the cart can't drift from current pricing/availability.
 *
 * Every line is checked against ProductInventory::getAvailableQuantity() for the session's
 * CURRENT region ($region here, resolved by the caller the same way it already is everywhere
 * else — AbstractCustomerController::currentRegionForPricing() — since that resolution depends
 * on Security/session context that belongs at the controller layer, not here). A line's stored
 * region (CartItem::$fulfillmentRegion) always converts to match whatever's current — this app
 * has one active region per session applied to the whole cart, not a region fixed per item at
 * add time. Over-available quantities get capped, zero-available items get removed entirely —
 * both return a message for the caller to flash, never a silent change.
 */
final class CartService
{
    /** @param iterable<CartHeldQuantityProviderInterface> $heldQuantityProviders */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly CartRepository $cartRepository,
        private readonly Security $security,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly BackorderSplitResolver $backorderSplits,
        private readonly iterable $heldQuantityProviders = [],
    ) {
    }

    /**
     * Stamp the cart with the logged-in customer's company so the admin carts list can show it.
     * A cart is session-keyed and can start life as a guest cart, so the company is filled in here
     * from the current user rather than at creation. See issue #126.
     *
     * Also points the cart's address rows at the company's current defaults. Only points: the row
     * holds the link and nothing else, so the cart follows the customer's address book until it is
     * converted, which is when a document freezes what it shipped to. That link is what lets
     * Cart::getEffectiveShippingAddress() and Cart::getProvince() answer at all — without it a cart
     * has no province, and a shipping or tax rule keyed on one silently matches nothing.
     */
    private function stampCompany(Cart $cart): void
    {
        $user = $this->security->getUser();
        $company = $user instanceof CustomerUser ? $user->getCompany() : null;
        if ($company === null) {
            return;
        }

        if ($cart->getCompany() !== $company) {
            $cart->setCompany($company);
        }

        $cart->linkAddress(AbstractDocumentAddress::TYPE_BILLING, $company->getDefaultBillingAddress());
        $cart->linkAddress(AbstractDocumentAddress::TYPE_SHIPPING, $company->getDefaultShippingAddress());
    }

    /** @return array<string, int> sku => qty */
    public function getItems(): array
    {
        $cart = $this->findCart();
        if ($cart === null) {
            return [];
        }

        $items = [];
        foreach ($cart->getItems() as $item) {
            $items[$item->getProduct()->getSku()] = $item->getQuantity();
        }

        return $items;
    }

    public function add(string $sku, int $qty, ?FulfillmentRegion $region): ?string
    {
        $sku = trim($sku);
        if ($sku === '' || $qty <= 0) {
            return null;
        }

        $product = $this->entityManager->getRepository(ProductCore::class)->findOneBy(['sku' => $sku]);
        if (!$product instanceof ProductCore) {
            return null;
        }

        $cart = $this->cartRepository->findOrCreateForSession($this->sessionId());
        $this->stampCompany($cart);
        $item = $this->findItem($cart, $product);
        $requested = $item === null ? max(1, min(999, $qty)) : max(1, min(999, $item->getQuantity() + $qty));

        if ($item === null) {
            $item = (new CartItem())->setProduct($product)->setQuantity($requested);
            $cart->addItem($item);
        } else {
            $item->setQuantity($requested)->touch();
        }
        if ($region !== null) {
            $item->setFulfillmentRegion($region);
        }

        $cart->touch();
        $this->entityManager->persist($item);

        $message = $region === null ? null : $this->capOrRemove($cart, $item, $region, (string) $requested);

        $this->entityManager->flush();

        return $message;
    }

    public function setQuantity(string $sku, int $qty, ?FulfillmentRegion $region): ?string
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $product = $this->entityManager->getRepository(ProductCore::class)->findOneBy(['sku' => $sku]);
        if (!$product instanceof ProductCore) {
            return null;
        }

        $cart = $this->findCart();
        if ($cart === null) {
            return null;
        }

        $item = $this->findItem($cart, $product);
        $message = null;

        if ($qty <= 0) {
            if ($item !== null) {
                $cart->removeItem($item);
            }
        } elseif ($item !== null) {
            $item->setQuantity(min(999, $qty))->touch();
            if ($region !== null) {
                $item->setFulfillmentRegion($region);
            }
            $this->entityManager->persist($item);
            $message = $region === null ? null : $this->capOrRemove($cart, $item, $region, (string) min(999, $qty));
        }

        $cart->touch();
        $this->entityManager->flush();

        return $message;
    }

    public function remove(string $sku): void
    {
        $sku = trim($sku);
        $cart = $this->findCart();
        if ($cart === null || $sku === '') {
            return;
        }

        foreach ($cart->getItems()->toArray() as $item) {
            if ($item->getProduct()->getSku() === $sku) {
                $cart->removeItem($item);
            }
        }

        $cart->touch();
        $this->entityManager->flush();
    }

    public function clear(): void
    {
        $cart = $this->findCart();
        if ($cart === null) {
            return;
        }

        foreach ($cart->getItems()->toArray() as $item) {
            $cart->removeItem($item);
        }

        $cart->touch();
        $this->entityManager->flush();
    }

    public function isEmpty(): bool
    {
        return $this->getItems() === [];
    }

    public function getCart(): ?Cart
    {
        return $this->findCart();
    }

    public function getSessionId(): string
    {
        return $this->sessionId();
    }

    /**
     * Re-checks every existing line against the given (possibly just-switched-to) region —
     * catches the case where the session's region changed since a line was last touched, not
     * just the one line a mutation action just modified. Called from cart/checkout page views.
     *
     * @return list<string> one message per line that was capped or removed
     */
    public function reconcileAgainstRegion(?FulfillmentRegion $region): array
    {
        if ($region === null) {
            return [];
        }

        $cart = $this->findCart();
        if ($cart === null) {
            return [];
        }

        $messages = [];
        foreach ($cart->getItems()->toArray() as $item) {
            $item->setFulfillmentRegion($region);
            $message = $this->capOrRemove($cart, $item, $region, $item->getQuantity());
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        $cart->touch();
        $this->entityManager->flush();

        return $messages;
    }

    /**
     * Caps $item to what the region can cover, or removes it entirely if it can cover nothing.
     *
     * The ceiling is what a stranger could still buy *plus what this cart is already holding*.
     * Holding stock is what having it in your cart means, so a cart's own hold cannot be a reason to
     * refuse it: without the add-back, 49 of 50 in a cart reads as 1 available and the line is capped
     * down to 1 on the next request (issue #214). With no hold provider registered the add-back is 0
     * and this is the plain availability check it has always been.
     *
     * ## What "cover" means since #548
     *
     * Stock, plus whatever backorder capacity the SKU has been given for this warehouse. A product
     * nobody has opted in has none, so `accepted` is `min(requested, available)` and every branch
     * below behaves — and reads — exactly as it did before backorders existed. A product that HAS
     * been opted in keeps the line at the full quantity and says which part of it will wait, which
     * is the point: the customer is being allowed to order beyond stock, not quietly trimmed to it.
     *
     * Nothing about the split is persisted here. CartItem is a live line re-priced on every page,
     * and the split is re-decided at checkout against availability as it stands then — a cart is
     * not a promise.
     */
    private function capOrRemove(Cart $cart, CartItem $item, FulfillmentRegion $region, string $requested): ?string
    {
        $product = $item->getProduct();
        $available = QuantityScale::add($this->availableQuantity($product, $region), $this->heldByCart($cart, $product, $region));
        $sku = $product->getSku();

        $warehouse = $this->warehouses->warehouseForRegion($region);
        $split = $this->backorderSplits->splitFor(
            $warehouse instanceof Warehouse
                ? $this->entityManager->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse])
                : null,
            $requested,
            $available,
        );
        $accepted = $split->accepted();

        if (QuantityScale::compare($accepted, 0) <= 0) {
            $cart->removeItem($item);

            return sprintf('%s was removed from your cart — it\'s not available in %s.', $sku, $region->getName());
        }

        if (QuantityScale::compare($requested, $accepted) > 0) {
            $item->setQuantity($accepted)->touch();
            $this->entityManager->persist($item);

            // The pre-#548 sentence when nothing is backordered, since then $accepted IS the
            // availability and repeating it twice is what the message has always said.
            if (QuantityScale::compare($split->backordered, 0) <= 0) {
                $acceptedText = QuantityScale::trim($accepted);

                return sprintf('%s was reduced to %s — only %s available in %s.', $sku, $acceptedText, $acceptedText, $region->getName());
            }

            return sprintf(
                '%s was reduced to %s — %s available in %s and %s more on backorder.',
                $sku,
                QuantityScale::trim($accepted),
                QuantityScale::trim($split->fulfilled),
                $region->getName(),
                QuantityScale::trim($split->backordered),
            );
        }

        // Covered in full, so the line was not touched, so there is nothing to report — including
        // when part of it will wait for stock.
        //
        // Deliberately silent rather than informative, because a returned message is not a notice
        // here: CheckoutController::buildAndPersistOrder() re-runs this immediately before saving
        // and treats ANY message as "the cart changed under the customer, start again". Saying
        // "4 will ship now, 2 are on backorder" through this channel would abort every backordered
        // checkout in an endless loop. The customer is told on the cart page instead, by
        // backorderSplitFor(), which reports without changing anything.
        return null;
    }

    /**
     * How a quantity of one product would split in one region, for display (#548).
     *
     * The same resolver, the same add-back and the same numbers capOrRemove() enforces with —
     * because a cart page that promised a different split from the one checkout applies would be
     * worse than a page that showed nothing. Read-only: nothing here caps, removes or persists.
     */
    public function backorderSplitFor(ProductCore $product, ?FulfillmentRegion $region, string|int|float $requested): BackorderSplit
    {
        $requested = QuantityScale::canonical($requested);
        $cart = $this->findCart();
        if ($region === null || $cart === null) {
            return $this->backorderSplits->split($requested, QuantityScale::canonical(0), false, QuantityScale::canonical(0));
        }

        $warehouse = $this->warehouses->warehouseForRegion($region);

        return $this->backorderSplits->splitFor(
            $warehouse instanceof Warehouse
                ? $this->entityManager->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse])
                : null,
            $requested,
            QuantityScale::add($this->availableQuantity($product, $region), $this->heldByCart($cart, $product, $region)),
        );
    }

    /** What $cart itself already holds of $product — see CartHeldQuantityProviderInterface. */
    private function heldByCart(Cart $cart, ProductCore $product, FulfillmentRegion $region): string
    {
        // A cart that has never been flushed has no id to query holds by, and holds nothing anyway:
        // its first line is being decided right now. Guarded here rather than in each provider so a
        // provider cannot forget and take the cart page down with a bound-entity error.
        if ($cart->getId() === null) {
            return QuantityScale::canonical(0);
        }

        $held = QuantityScale::canonical(0);
        foreach ($this->heldQuantityProviders as $provider) {
            $providerHeld = QuantityScale::canonical($provider->heldQuantityForCart($cart, $product, $region));
            if (QuantityScale::compare($providerHeld, 0) > 0) {
                $held = QuantityScale::add($held, $providerHeld);
            }
        }

        return $held;
    }

    /**
     * What the cart may draw on for this product in this region: the stock of the warehouse the
     * region is served by (#546). No warehouse serving it means no stock to draw on — treated as
     * zero rather than unlimited, the same way an absent inventory row always has been.
     *
     * A decimal string, matching what capOrRemove() and splitFor() compare against.
     */
    private function availableQuantity(ProductCore $product, FulfillmentRegion $region): string
    {
        $warehouse = $this->warehouses->warehouseForRegion($region);
        if (!$warehouse instanceof Warehouse) {
            return QuantityScale::canonical(0);
        }

        $inventory = $this->entityManager->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $warehouse,
        ]);

        if ($inventory === null) {
            return QuantityScale::canonical(0);
        }

        $available = QuantityScale::canonical($inventory->getAvailableQuantity());

        return QuantityScale::compare($available, 0) > 0 ? $available : QuantityScale::canonical(0);
    }

    private function findCart(): ?Cart
    {
        return $this->entityManager->getRepository(Cart::class)->findOneBy(['sessionId' => $this->sessionId()]);
    }

    private function findItem(Cart $cart, ProductCore $product): ?CartItem
    {
        foreach ($cart->getItems() as $item) {
            if ($item->getProduct()->getId() === $product->getId()) {
                return $item;
            }
        }

        return null;
    }

    private function sessionId(): string
    {
        return $this->requestStack->getSession()->getId();
    }
}
