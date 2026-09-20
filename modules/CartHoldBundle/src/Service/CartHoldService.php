<?php

declare(strict_types=1);

namespace CartHoldBundle\Service;

use App\Entity\Cart;
use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Repository\BundleStatusRepository;
use App\Service\AppSettings;
use App\Service\CartService;
use App\Service\QuantityScale;
use App\Service\Inventory\InventoryOperationContext;
use App\Service\WarehouseFulfillmentRegionService;
use CartHoldBundle\Entity\CartHold;
use CartHoldBundle\Repository\CartHoldRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reserves (holds) inventory for whatever is currently in a customer's session cart, for a
 * configurable duration, so Available stock accounts for items other customers can't also
 * check out. Cart Hold as a whole is optional — toggled via this bundle's own Active/Inactive
 * status on the admin Bundle Management page (same mechanism every other optional feature in
 * this app uses), not a bespoke on/off setting.
 */
final class CartHoldService
{
    /**
     * The ambient operation name every cart-hold bucket change is recorded under (#582).
     *
     * Public because CartHoldBundle\EventSubscriber\CartItemCleanupSubscriber opens the same
     * operation around its own recompute-and-flush pass — that path reaches recomputeCache()
     * without going through any method here, and a second spelling of the string is a second thing
     * to keep in step.
     */
    public const CHANGE_ACTION = 'cart_sync';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppSettings $appSettings,
        private readonly BundleStatusRepository $bundleStatusRepo,
        private readonly InventoryOperationContext $operations,
        private readonly WarehouseFulfillmentRegionService $warehouses,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->bundleStatusRepo->isActiveForInstance($this);
    }

    public function durationSeconds(): int
    {
        return max(1, (int) $this->appSettings->get('cart_hold_duration_seconds', '300'));
    }

    /**
     * Upserts hold rows to match the live cart and resets every row's expiry to
     * now + duration (a whole-cart timer reset) — called (via CartSyncSubscriber, reacting to
     * CartSyncedEvent) on cart mutations and cart/checkout page views. If disabled or no region
     * is resolved, releases any existing holds for this session instead. Stamps
     * Cart::$holdExpiresAt as a side effect, the core-owned cache column the banner reads —
     * core never needs to ask this bundle for that value directly.
     */
    public function syncForCurrentCart(?Cart $cart, ?FulfillmentRegion $region, string $sessionId): void
    {
        if (!$this->isEnabled() || $region === null || $cart === null || $cart->getItems()->isEmpty()) {
            $this->releaseAllForSession($sessionId);
            return;
        }

        // 'cart_sync' names the operation for App\EventSubscriber\InventoryBucketChangeLogger, which
        // is what writes the `cart_hold` rows in inventory_bucket_change_log now (#582). The
        // operation has to WRAP the flushes below, not just the recomputeCache() calls that move
        // the bucket, because the changeset the listener reads does not exist until flush time.
        $this->operations->run(self::CHANGE_ACTION, function () use ($cart): void {
            $this->syncWithinOperation($cart);
        });
    }

    /** The body of syncForCurrentCart(), split out only so the operation above can wrap the flushes. */
    private function syncWithinOperation(Cart $cart): void
    {
        $expiresAt = new \DateTimeImmutable(sprintf('+%d seconds', $this->durationSeconds()));
        $holdRepo = $this->entityManager->getRepository(CartHold::class);

        $existingByCartItemId = [];
        foreach ($holdRepo->findByCart($cart) as $row) {
            $existingByCartItemId[$row->getCartItem()->getId()] = $row;
        }

        /** @var array<string, array{0: ProductCore, 1: FulfillmentRegion}> $touchedPairs */
        $touchedPairs = [];
        $seenItemIds = [];

        foreach ($cart->getItems() as $item) {
            if (QuantityScale::compare($item->getQuantity(), 0) <= 0) {
                continue;
            }
            $seenItemIds[$item->getId()] = true;

            $row = $existingByCartItemId[$item->getId()] ?? null;
            if ($row === null) {
                $row = (new CartHold())->setCartItem($item);
                $this->entityManager->persist($row);
            }
            $row->setExpiresAt($expiresAt)->touch();

            $touchedPairs[$item->getProduct()->getId() . '|' . $item->getFulfillmentRegion()->getId()] = [$item->getProduct(), $item->getFulfillmentRegion()];
        }

        foreach ($existingByCartItemId as $itemId => $row) {
            if (isset($seenItemIds[$itemId])) {
                continue;
            }
            $touchedPairs[$row->getProduct()->getId() . '|' . $row->getFulfillmentRegion()->getId()] = [$row->getProduct(), $row->getFulfillmentRegion()];
            $this->entityManager->remove($row);
        }

        // Flush hold-row changes first so the source-of-truth SUM query below (recomputeCache)
        // sees the final state, not what's about to be deleted/inserted.
        $this->entityManager->flush();

        foreach ($touchedPairs as [$product, $touchedRegion]) {
            $this->recomputeCache($product, $touchedRegion);
        }

        $cart->setHoldExpiresAt($expiresAt);
        $this->entityManager->persist($cart);
        $this->entityManager->flush();
    }

    /**
     * Called on cart/checkout view actions, after releaseExpired(). Any cart item with no
     * matching hold row is unambiguous evidence its hold expired and was already swept (by
     * this session's own request or another one's) — mutation actions always sync first, so a
     * genuinely fresh addition never reaches a view action without a row already existing.
     *
     * @return list<string> evicted SKUs, so the caller can flash a notice
     */
    public function reconcileExpiredForSession(CartService $cartService, string $sessionId): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $cart = $cartService->getCart();
        if ($cart === null || $cart->getItems()->isEmpty()) {
            return [];
        }

        $heldItemIds = [];
        foreach ($this->entityManager->getRepository(CartHold::class)->findByCart($cart) as $row) {
            $heldItemIds[$row->getCartItem()->getId()] = true;
        }

        $evicted = [];
        foreach ($cart->getItems() as $item) {
            if (!isset($heldItemIds[$item->getId()])) {
                $evicted[] = $item->getProduct()->getSku();
            }
        }

        foreach ($evicted as $sku) {
            $cartService->remove($sku);
        }

        return $evicted;
    }

    /** Globally releases every expired hold row (any session), recomputing affected caches. */
    public function releaseExpired(): void
    {
        $expired = $this->entityManager->getRepository(CartHold::class)->findExpired(new \DateTimeImmutable());
        if ($expired === []) {
            return;
        }

        $this->removeRowsAndRecompute($expired);
    }

    public function releaseAllForSession(string $sessionId): void
    {
        $rows = $this->entityManager->getRepository(CartHold::class)->findBySessionId($sessionId);
        if ($rows === []) {
            return;
        }

        $this->removeRowsAndRecompute($rows);
    }

    /**
     * @param list<CartHold> $rows
     */
    private function removeRowsAndRecompute(array $rows): void
    {
        // Same operation name as a live sync, and deliberately so: releasing an expired hold and
        // re-syncing a live cart are the same bucket being recomputed from the same source of
        // truth, and the log row already says which direction it went.
        $this->operations->run(self::CHANGE_ACTION, function () use ($rows): void {
            $this->removeRowsWithinOperation($rows);
        });
    }

    /** @param list<CartHold> $rows */
    private function removeRowsWithinOperation(array $rows): void
    {
        /** @var array<string, array{0: ProductCore, 1: FulfillmentRegion}> $touchedPairs */
        $touchedPairs = [];
        /** @var array<int, Cart> $touchedCarts */
        $touchedCarts = [];
        foreach ($rows as $row) {
            $touchedPairs[$row->getProduct()->getId() . '|' . $row->getFulfillmentRegion()->getId()] = [$row->getProduct(), $row->getFulfillmentRegion()];
            $cart = $row->getCartItem()->getCart();
            $touchedCarts[$cart->getId()] = $cart;
            $this->entityManager->remove($row);
        }

        $this->entityManager->flush();

        foreach ($touchedPairs as [$product, $region]) {
            $this->recomputeCache($product, $region);
        }

        // Rows just got removed above — clear the whole-cart timer cache column for any cart
        // left with no hold rows at all, otherwise Cart::$holdExpiresAt keeps pointing at an
        // already-past timestamp indefinitely (nothing else ever nulls it out), and the
        // cart-hold banner + its JS countdown would keep re-rendering and immediately
        // reloading the page on every catalog/product view.
        foreach ($touchedCarts as $cart) {
            $this->clearExpiryCacheIfNoHoldsRemain($cart);
        }

        $this->entityManager->flush();
    }

    /**
     * Nulls Cart::$holdExpiresAt once nothing is holding it anymore — otherwise that
     * core-owned cache column keeps pointing at whatever timestamp it was last set to forever,
     * since nothing else ever clears it back out. Public so CartItemCleanupSubscriber can call
     * it too: that listener deletes a cart's last CartHold row via the CartItem's cascade,
     * entirely bypassing releaseExpired()/releaseAllForSession() above.
     */
    public function clearExpiryCacheIfNoHoldsRemain(Cart $cart): void
    {
        if ($this->entityManager->getRepository(CartHold::class)->findByCart($cart) === []) {
            $cart->setHoldExpiresAt(null);
            $this->entityManager->persist($cart);
        }
    }

    /**
     * Recomputes cartHoldQuantity for (product, region) from source of truth (non-expired
     * holds). Public so CartItemCleanupSubscriber can call it after removing an orphaned
     * CartHold row (core deleting a CartItem via CartService has no other way to trigger this).
     *
     * The holds are counted per REGION — a cart item names the region the customer is shopping in
     * — and the bucket they are written into belongs to the WAREHOUSE serving that region (#546).
     * A region no warehouse serves has no bucket to write, so there is nothing to recompute.
     */
    public function recomputeCache(ProductCore $product, FulfillmentRegion $region): void
    {
        $warehouse = $this->warehouses->warehouseForRegion($region);
        if (!$warehouse instanceof Warehouse) {
            return;
        }

        $inventory = $this->entityManager->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $warehouse,
        ]) ?? (new ProductInventory())->setProduct($product)->setWarehouse($warehouse);

        $newTotal = $this->entityManager->getRepository(CartHold::class)->sumHeldQuantity($product, $region, new \DateTimeImmutable());
        $previous = $inventory->getCartHoldQuantity();
        if (QuantityScale::compare($previous, $newTotal) === 0) {
            return;
        }

        $inventory->setCartHoldQuantity($newTotal)->touch();
        $this->entityManager->persist($inventory);

        // No logChange() call here any more (#582). The bucket write above is the whole of the job;
        // App\EventSubscriber\InventoryBucketChangeLogger sees it in the changeset of whichever
        // flush settles it and writes the row, with 'cart_sync' coming from the operation the
        // caller opened. This method does not flush, so it cannot open that operation itself —
        // every caller that does flush opens one, including CartItemCleanupSubscriber.
    }
}
