<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Repository;

use App\Entity\ProductCore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use InventoryDepthBundle\Entity\InventoryLot;

/**
 * @extends ServiceEntityRepository<InventoryLot>
 */
class InventoryLotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryLot::class);
    }

    /**
     * Lots whose last usable day falls on or before $through and which still hold sellable stock.
     *
     * Drives ExpireLotsCommand. The expiring-soon screen deliberately does NOT use it: that list is
     * about what an admin should go and look at, so it shows a lot whose stock has already been
     * moved out of `available` too, and it inlines its own window. Deliberately a lot-level query:
     * expiry is a property of the batch, and the sweep that acts on it writes real movements rather
     * than filtering expiry into an availability query — the latter would break
     * `quantity == SUM(available)`.
     *
     * @return list<InventoryLot>
     */
    public function expiringThrough(\DateTimeImmutable $through, bool $onlyWithStock = true): array
    {
        $qb = $this->createQueryBuilder('l')
            ->andWhere('l.expiry IS NOT NULL')
            ->andWhere('l.expiry <= :through')->setParameter('through', $through->format('Y-m-d'))
            ->orderBy('l.expiry', 'ASC')
            ->addOrderBy('l.id', 'ASC');

        if ($onlyWithStock) {
            $qb->andWhere(
                'EXISTS (SELECT 1 FROM ' . \InventoryDepthBundle\Entity\InventoryDetail::class
                . " d WHERE d.lot = l AND d.status = 'available' AND d.quantity > 0)"
            );
        }

        /** @var list<InventoryLot> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * Lots that share a `(product_id, code)` with another lot — the forks the edit form made before
     * it carried a row id (#590).
     *
     * **Reported, never merged.** `inventory_lot.code` is deliberately not unique per product: two
     * production runs sharing a batch code with different expiry dates ARE two lots, and collapsing
     * them would lose the earlier one's date. Nothing here can tell a genuine reuse from an
     * accidental fork, so nothing here decides. It surfaces the pairs and leaves every
     * `inventory_detail.lot_id` pointing exactly where it points; a human rules on each one.
     *
     * @return list<array{product: ProductCore, code: string, lots: list<InventoryLot>}>
     */
    public function duplicateGroups(): array
    {
        /** @var list<InventoryLot> $rows */
        $rows = $this->createQueryBuilder('l')
            ->innerJoin('l.product', 'p')->addSelect('p')
            ->andWhere(
                'EXISTS (SELECT 1 FROM ' . InventoryLot::class
                . ' o WHERE o.product = l.product AND o.code = l.code AND o.id <> l.id)'
            )
            ->orderBy('p.sku', 'ASC')
            ->addOrderBy('l.code', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        $groups = [];
        foreach ($rows as $lot) {
            $key = $lot->getProduct()->getId() . '|' . $lot->getCode();
            $groups[$key] ??= ['product' => $lot->getProduct(), 'code' => $lot->getCode(), 'lots' => []];
            $groups[$key]['lots'][] = $lot;
        }

        return array_values($groups);
    }

    /**
     * Lots of $product carrying any available stock at all, earliest expiry first — the picker
     * list behind the Order/Invoice line's lot select (2026-09-14 lot/serial/expiry plan).
     *
     * Product-wide rather than warehouse-scoped, deliberately: a lot's identity is its row id, not
     * a (product, warehouse) pair, and `InventoryLot` itself names no warehouse — its detail rows
     * may in principle span more than one. The same reasoning `InventoryDetailRepository::
     * namedSourceRows()` already gives for including other warehouses: filtering to one first would
     * be a second question the picker never asked. The line's own warehouse is resolved separately,
     * server-side, at save time, by AdminOrderStockValidator's lot-scoped check.
     *
     * A lot with zero available stock left (fully drawn down, or written off) is excluded: it is
     * history, not something to pick next. So is a lot that is On Hold or Recalled (#725) — the
     * picker is where a new sale would draw stock FROM, and a lot somebody has declared unfit to
     * sell must simply not be offered, not merely show a warning once one is chosen.
     *
     * @return list<InventoryLot>
     */
    public function withAvailableStock(ProductCore $product): array
    {
        /** @var list<InventoryLot> $rows */
        $rows = $this->createQueryBuilder('l')
            ->andWhere('l.product = :product')->setParameter('product', $product)
            ->andWhere('l.status = :status')->setParameter('status', InventoryLot::STATUS_AVAILABLE)
            ->andWhere(
                'EXISTS (SELECT 1 FROM ' . \InventoryDepthBundle\Entity\InventoryDetail::class
                . " d WHERE d.lot = l AND d.status = 'available' AND d.quantity > 0)"
            )
            ->orderBy('l.expiry', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The other lots on the same product carrying the same code as $lot — what a save has just
     * landed beside. Empty when that code is unambiguous on that product.
     *
     * Said out loud in a flash at the moment the ambiguity is created, which is the only moment
     * anybody still knows whether they meant it.
     *
     * @return list<InventoryLot>
     */
    public function othersSharingCode(InventoryLot $lot): array
    {
        if ($lot->getId() === null) {
            return [];
        }

        /** @var list<InventoryLot> $rows */
        $rows = $this->createQueryBuilder('l')
            ->andWhere('l.product = :product')->setParameter('product', $lot->getProduct())
            ->andWhere('l.code = :code')->setParameter('code', $lot->getCode())
            ->andWhere('l.id <> :id')->setParameter('id', $lot->getId())
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
