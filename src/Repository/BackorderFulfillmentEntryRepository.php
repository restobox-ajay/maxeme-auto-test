<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BackorderFulfillmentEntry;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BackorderFulfillmentEntry>
 */
class BackorderFulfillmentEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackorderFulfillmentEntry::class);
    }

    /** The at-most-one open episode for a line — see the entity docblock on why that is a code rule. */
    public function openForLine(int $lineId): ?BackorderFulfillmentEntry
    {
        return $this->findOneBy(['line' => $lineId, 'status' => BackorderFulfillmentEntry::STATUS_OPEN]);
    }

    /**
     * Everything still waiting on this product in this warehouse, oldest order first.
     *
     * FIFO by order id then line position, because nothing else in this schema ranks one waiting
     * customer above another — there is no tier, no SLA, no promised date but the one an admin
     * typed. Ordering by anything derived would be inventing a policy the business has not stated.
     *
     * @return list<BackorderFulfillmentEntry>
     */
    public function openFifoFor(ProductCore $product, Warehouse $warehouse): array
    {
        return array_values($this->createQueryBuilder('e')
            ->join('e.line', 'l')
            ->andWhere('e.status = :open')
            ->andWhere('e.product = :product')
            ->andWhere('e.warehouse = :warehouse')
            ->setParameter('open', BackorderFulfillmentEntry::STATUS_OPEN)
            ->setParameter('product', $product)
            ->setParameter('warehouse', $warehouse)
            ->orderBy('e.order', 'ASC')
            ->addOrderBy('l.sortOrder', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** @return list<BackorderFulfillmentEntry> */
    public function openForOrder(SalesOrder $order): array
    {
        return array_values($this->createQueryBuilder('e')
            ->join('e.line', 'l')
            ->andWhere('e.status = :open')
            ->andWhere('e.order = :order')
            ->setParameter('open', BackorderFulfillmentEntry::STATUS_OPEN)
            ->setParameter('order', $order)
            ->orderBy('l.sortOrder', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The queue listing: one page of episodes, narrowed by what somebody chasing goods would ask.
     *
     * Every filter here answers a question a person actually puts to this screen, and none of them
     * is a box for its own sake:
     *
     *  - `status`   — "what is still outstanding" versus "what happened to that one". Absent means
     *                 Open, which is the worklist default this screen has always had; the empty
     *                 string is the All tab and is a deliberate choice, not the lack of one.
     *  - `order`    — a customer is on the phone with an order number in front of them.
     *  - `customer` — the same call without the number.
     *  - `sku`      — a pallet has landed; who is waiting for it. Matched against the line's own
     *                 SKU, the product's, and the line name, because a line snapshots its SKU and
     *                 an imported product may have been re-SKU'd since.
     *  - `warehouse`— the same question, asked at one site, which is where stock actually arrives.
     *  - `etaBy`    — "what did we promise by Friday", and with today's date, "what is late". Rows
     *                 with no ETA are excluded by it deliberately: an unpromised line is not a
     *                 promise that has come due.
     *
     * There is deliberately NO "releasable now" filter, though the column is on screen. That figure
     * is ProductInventory::getStockAvailableToRelease(), and three of the terms in it are gated by
     * bundle-state flags that are set on the loaded entity rather than stored on the row — so it
     * cannot be computed in SQL without the query disagreeing with the column beside it whenever a
     * bundle is off. A filter that quietly means something different from the number it filters is
     * worse than no filter.
     *
     * Ordered Open before Complete — DESC, because 'Complete' sorts before 'Open' alphabetically
     * and ASC put the history above the worklist while the docblock here claimed the opposite —
     * then newest episode first within each.
     *
     * @param array<string, string> $filters
     * @return array{rows: list<BackorderFulfillmentEntry>, total: int}
     */
    public function search(array $filters, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('e')
            ->join('e.line', 'l')
            ->join('e.order', 'o')
            ->join('e.product', 'p')
            ->leftJoin('o.company', 'c')
            ->leftJoin('e.warehouse', 'w');

        if (($filters['status'] ?? '') !== '') {
            $qb->andWhere('e.status = :status')->setParameter('status', $filters['status']);
        }
        if (($filters['order'] ?? '') !== '') {
            $qb->andWhere('o.orderNumber LIKE :order')->setParameter('order', '%' . $filters['order'] . '%');
        }
        if (($filters['customer'] ?? '') !== '') {
            $qb->andWhere('c.name LIKE :customer')->setParameter('customer', '%' . $filters['customer'] . '%');
        }
        if (($filters['sku'] ?? '') !== '') {
            $qb->andWhere('l.sku LIKE :sku OR p.sku LIKE :sku OR l.name LIKE :sku')
                ->setParameter('sku', '%' . $filters['sku'] . '%');
        }
        if (($filters['warehouse'] ?? '') !== '') {
            $qb->andWhere('IDENTITY(e.warehouse) = :warehouse')->setParameter('warehouse', (int) $filters['warehouse']);
        }
        if (($filters['etaBy'] ?? '') !== '') {
            $eta = \DateTimeImmutable::createFromFormat('!Y-m-d', $filters['etaBy']);

            // A date the browser did not produce is not a date. Ignored rather than guessed at,
            // so a hand-edited URL narrows nothing instead of narrowing to something arbitrary.
            if ($eta instanceof \DateTimeImmutable) {
                $qb->andWhere('l.restockEta IS NOT NULL AND l.restockEta <= :etaBy')->setParameter('etaBy', $eta);
            }
        }

        $total = (int) (clone $qb)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        /** @var list<BackorderFulfillmentEntry> $rows */
        $rows = array_values($qb
            ->addSelect('l', 'o', 'p', 'c', 'w')
            ->orderBy('e.status', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());

        return ['rows' => $rows, 'total' => $total];
    }
}
