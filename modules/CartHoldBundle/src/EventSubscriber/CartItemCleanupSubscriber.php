<?php

declare(strict_types=1);

namespace CartHoldBundle\EventSubscriber;

use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Service\Inventory\InventoryOperationContext;
use CartHoldBundle\Entity\CartHold;
use CartHoldBundle\Service\CartHoldService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Doctrine has no way to know that deleting a CartItem should also delete its CartHold row —
 * the FK's ON DELETE CASCADE handles that at the database level, but Doctrine's own in-memory
 * UnitOfWork doesn't know about DB-level cascades, so a still-managed CartHold left referencing
 * a just-deleted CartItem confuses later flushes (e.g. AuditLogSubscriber's own deferred
 * postFlush persist finds a "new" entity through a relationship that's actually just stale).
 *
 * This listener watches every flush app-wide — core legitimately deletes CartItem rows via
 * CartService with zero knowledge of this bundle — and explicitly schedules the matching
 * CartHold for deletion in the SAME pass (onFlush), keeping Doctrine's bookkeeping consistent
 * with what the database is about to do anyway. It then recomputes the affected product/
 * region's cartHoldQuantity cache in postFlush, since that's otherwise only ever touched from
 * inside CartHoldService's own sync methods, none of which core's CartService::remove()/
 * setQuantity()/clear() call. Same "watch a core entity via Doctrine listener from a bundle"
 * pattern as InventoryReconciliationSubscriber watching SalesOrderLine.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class CartItemCleanupSubscriber
{
    /** @var array<string, array{0: ProductCore, 1: FulfillmentRegion}> keyed by "productId|regionId" */
    private array $touchedPairs = [];

    /** @var array<int, Cart> */
    private array $touchedCarts = [];

    public function __construct(
        private readonly CartHoldService $cartHoldService,
        private readonly InventoryOperationContext $operations,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (!$entity instanceof CartItem) {
                continue;
            }

            $hold = $em->getRepository(CartHold::class)->findOneBy(['cartItem' => $entity]);
            if ($hold === null || $uow->isScheduledForDelete($hold)) {
                continue;
            }

            $product = $entity->getProduct();
            $region = $entity->getFulfillmentRegion();
            $this->touchedPairs[$product->getId() . '|' . $region->getId()] = [$product, $region];
            $this->touchedCarts[$entity->getCart()->getId()] = $entity->getCart();

            $em->remove($hold);
            $uow->computeChangeSet($em->getClassMetadata(CartHold::class), $hold);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->touchedPairs === []) {
            return;
        }

        // Clear before recomputing: recomputeCache() flushes internally, which would trigger
        // this listener's onFlush/postFlush again — clearing first makes that inner pass a
        // no-op instead of infinite recursion.
        $pairs = $this->touchedPairs;
        $carts = $this->touchedCarts;
        $this->touchedPairs = [];
        $this->touchedCarts = [];

        // Named for the change log (#582). This path reaches recomputeCache() without going
        // through any CartHoldService entry point, so the operation has to be opened here — and it
        // has to stay open across the flush() at the bottom, which is what actually writes the
        // bucket column the listener reads. Without it every cart_hold row produced by a
        // cascade-deleted CartItem would be recorded as 'unattributed'.
        $this->operations->run(CartHoldService::CHANGE_ACTION, function () use ($pairs, $carts, $args): void {
            foreach ($pairs as [$product, $region]) {
                $this->cartHoldService->recomputeCache($product, $region);
            }

            // This cascade-delete path bypasses CartHoldService::releaseExpired()/
            // releaseAllForSession() entirely (the CartHold row is already gone by the time either
            // would run), so Cart::$holdExpiresAt has to be cleared here too — otherwise removing
            // the last item in your cart leaves the hold banner showing a live countdown for a cart
            // that's now empty.
            foreach ($carts as $cart) {
                $this->cartHoldService->clearExpiryCacheIfNoHoldsRemain($cart);
            }

            /** @var EntityManagerInterface $em */
            $em = $args->getObjectManager();
            $em->flush();
        });
    }
}
