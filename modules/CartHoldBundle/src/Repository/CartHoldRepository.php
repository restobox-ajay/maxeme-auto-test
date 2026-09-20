<?php

declare(strict_types=1);

namespace CartHoldBundle\Repository;

use App\Entity\Cart;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Service\QuantityScale;
use CartHoldBundle\Entity\CartHold;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CartHold>
 */
class CartHoldRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CartHold::class);
    }

    /** @return list<CartHold> */
    public function findExpired(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    public function findSoonestExpiryForSession(string $sessionId): ?CartHold
    {
        return $this->createQueryBuilder('h')
            ->join('h.cartItem', 'ci')
            ->join('ci.cart', 'c')
            ->where('c.sessionId = :sessionId')
            ->setParameter('sessionId', $sessionId)
            ->orderBy('h.expiresAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<CartHold> */
    public function findBySessionId(string $sessionId): array
    {
        return $this->createQueryBuilder('h')
            ->join('h.cartItem', 'ci')
            ->join('ci.cart', 'c')
            ->where('c.sessionId = :sessionId')
            ->setParameter('sessionId', $sessionId)
            ->getQuery()
            ->getResult();
    }

    /** @return list<CartHold> every hold currently belonging to any item of this cart. */
    public function findByCart(Cart $cart): array
    {
        return $this->createQueryBuilder('h')
            ->join('h.cartItem', 'ci')
            ->where('ci.cart = :cart')
            ->setParameter('cart', $cart)
            ->getQuery()
            ->getResult();
    }

    /**
     * The live held quantity for one (product, region) belonging to a single cart.
     *
     * Same shape as sumHeldQuantity() but narrowed to one cart, because a cart's own hold has to be
     * added back before its own line is measured against availability — otherwise the customer is
     * refused stock they are already holding (issue #214).
     */
    public function sumHeldQuantityForCart(Cart $cart, ProductCore $product, FulfillmentRegion $region, \DateTimeImmutable $now): string
    {
        $total = $this->createQueryBuilder('h')
            ->select('SUM(ci.quantity)')
            ->join('h.cartItem', 'ci')
            ->where('ci.cart = :cart')
            ->andWhere('ci.product = :product')
            ->andWhere('ci.fulfillmentRegion = :region')
            ->andWhere('h.expiresAt > :now')
            ->setParameter('cart', $cart)
            ->setParameter('product', $product)
            ->setParameter('region', $region)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();

        return QuantityScale::canonical($total ?? 0);
    }

    /**
     * Source-of-truth total currently held for one (product, region), across every session's
     * non-expired holds — recomputed (not delta-adjusted) whenever a hold for this pair
     * changes, since CartHold no longer stores its own quantity to diff against.
     */
    public function sumHeldQuantity(ProductCore $product, FulfillmentRegion $region, \DateTimeImmutable $now): string
    {
        $total = $this->createQueryBuilder('h')
            ->select('SUM(ci.quantity)')
            ->join('h.cartItem', 'ci')
            ->where('ci.product = :product')
            ->andWhere('ci.fulfillmentRegion = :region')
            ->andWhere('h.expiresAt > :now')
            ->setParameter('product', $product)
            ->setParameter('region', $region)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();

        return QuantityScale::canonical($total ?? 0);
    }
}
