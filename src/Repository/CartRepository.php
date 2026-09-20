<?php

namespace App\Repository;

use App\Entity\Cart;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Cart>
 */
class CartRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cart::class);
    }

    /**
     * One page of carts, narrowed by what somebody looking at this screen is actually doing.
     *
     * This is a support screen — "what does that customer have in their cart right now" — so the
     * filters are the ways support arrives at one cart: the company it belongs to, the session id
     * out of a log line, and the two questions that make a cart interesting at all rather than one
     * of thousands of abandoned rows.
     *
     *  - `hasItems`    — an empty cart is a session that visited and left. `SIZE()` rather than a
     *                    join, so a cart with six lines is still one row.
     *  - `held`        — the cart is holding stock RIGHT NOW, which is the one thing a cart can do
     *                    that shows up on somebody else's availability. Compared against the clock
     *                    at request time, so an expired hold is not a hold.
     *  - `updatedFrom` — on or after, for "what has been active today".
     *
     * Newest first, because a support call is always about something that just happened.
     *
     * @param array<string, string> $filters
     * @return array{rows: list<Cart>, total: int}
     */
    public function search(array $filters, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('cart')->leftJoin('cart.company', 'company');

        if (($filters['session'] ?? '') !== '') {
            $qb->andWhere('cart.sessionId LIKE :session')->setParameter('session', '%' . $filters['session'] . '%');
        }
        if (($filters['company'] ?? '') !== '') {
            $qb->andWhere('company.name LIKE :company')->setParameter('company', '%' . $filters['company'] . '%');
        }
        if (($filters['itemCount'] ?? '') !== '' && ctype_digit($filters['itemCount'])) {
            $qb->andWhere('SIZE(cart.items) = :itemCount')->setParameter('itemCount', (int) $filters['itemCount']);
        }
        if (($filters['held'] ?? '') === 'holding') {
            $qb->andWhere('cart.holdExpiresAt IS NOT NULL AND cart.holdExpiresAt > :now')
                ->setParameter('now', new \DateTimeImmutable());
        } elseif (($filters['held'] ?? '') === 'expired') {
            $qb->andWhere('cart.holdExpiresAt IS NOT NULL AND cart.holdExpiresAt <= :now')
                ->setParameter('now', new \DateTimeImmutable());
        } elseif (($filters['held'] ?? '') === 'none') {
            $qb->andWhere('cart.holdExpiresAt IS NULL');
        }
        if (($filters['updatedFrom'] ?? '') !== '') {
            $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $filters['updatedFrom']);

            // A date the browser did not produce is not a date: ignored rather than guessed at.
            if ($from instanceof \DateTimeImmutable) {
                $qb->andWhere('cart.updatedAt >= :updatedFrom')->setParameter('updatedFrom', $from);
            }
        }

        $total = (int) (clone $qb)->select('COUNT(cart.id)')->getQuery()->getSingleScalarResult();

        /** @var list<Cart> $rows */
        $rows = array_values($qb
            ->addSelect('company')
            ->orderBy('cart.updatedAt', 'DESC')
            ->addOrderBy('cart.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());

        return ['rows' => $rows, 'total' => $total];
    }

    public function findOrCreateForSession(string $sessionId): Cart
    {
        $cart = $this->findOneBy(['sessionId' => $sessionId]);
        if ($cart instanceof Cart) {
            return $cart;
        }

        $cart = (new Cart())->setSessionId($sessionId);
        $this->getEntityManager()->persist($cart);

        return $cart;
    }
}
