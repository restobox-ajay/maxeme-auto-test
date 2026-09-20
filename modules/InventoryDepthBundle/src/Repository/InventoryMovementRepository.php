<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Repository;

use App\Entity\ProductCore;
use App\Service\QuantityScale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovement;

/**
 * @extends ServiceEntityRepository<InventoryMovement>
 */
class InventoryMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryMovement::class);
    }

    /**
     * The audit surface: "who moved this and why", filterable without a database client.
     *
     * Both detail sides are LEFT JOINed because either can be NULL — stock entering the system has
     * no `from`, stock leaving it has no `to` — and an INNER JOIN here would silently hide exactly
     * the movements a recall or a write-off investigation is looking for.
     *
     * @param array{product?:string,productId?:string,warehouse?:string,location?:string,lot?:string,serial?:string,type?:string,actor?:string,reference?:string,from?:string,to?:string} $filters
     */
    public function filteredQueryBuilder(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('m')
            ->innerJoin('m.group', 'g')->addSelect('g')
            ->innerJoin('m.product', 'p')->addSelect('p')
            ->leftJoin('m.fromDetail', 'fd')->addSelect('fd')
            ->leftJoin('m.toDetail', 'td')->addSelect('td');

        $text = static fn (string $key): string => trim((string) ($filters[$key] ?? ''));

        if (($product = $text('product')) !== '') {
            $qb->andWhere('p.sku LIKE :fproduct OR p.name LIKE :fproduct')->setParameter('fproduct', '%' . $product . '%');
        }
        // Product Inventory Hub's "Recent Activity" View more link (build order step 1) — a separate
        // key from `product` above, kept exact-id rather than folded into that LIKE match, because
        // `product` is this screen's own human-typed "SKU or name" search box (movements.html.twig)
        // and a SKU can be a substring of another SKU (e.g. "WIDGET-1" inside "WIDGET-10"), the exact
        // same ambiguity the Company screen's name-LIKE gap has for a different entity.
        if (($productId = $text('productId')) !== '' && ctype_digit($productId)) {
            $qb->andWhere('IDENTITY(m.product) = :fproductId')->setParameter('fproductId', (int) $productId);
        }
        if (($warehouse = $text('warehouse')) !== '' && ctype_digit($warehouse)) {
            // Either side, rather than COALESCE over the two: a transfer names two warehouses and
            // filtering on one of them should find it from both ends.
            $qb->andWhere('IDENTITY(fd.warehouse) = :fwarehouse OR IDENTITY(td.warehouse) = :fwarehouse')
                ->setParameter('fwarehouse', (int) $warehouse);
        }
        if (($location = $text('location')) !== '' && ctype_digit($location)) {
            $qb->andWhere('IDENTITY(fd.location) = :flocation OR IDENTITY(td.location) = :flocation')
                ->setParameter('flocation', (int) $location);
        }
        if (($lot = $text('lot')) !== '' && ctype_digit($lot)) {
            $qb->andWhere('IDENTITY(fd.lot) = :flot OR IDENTITY(td.lot) = :flot')->setParameter('flot', (int) $lot);
        }
        if (($serial = $text('serial')) !== '') {
            $qb->andWhere('fd.serial LIKE :fserial OR td.serial LIKE :fserial')->setParameter('fserial', '%' . $serial . '%');
        }
        if (($type = $text('type')) !== '') {
            $qb->andWhere('g.type = :ftype')->setParameter('ftype', $type);
        }
        if (($actor = $text('actor')) !== '') {
            $qb->andWhere('g.actor LIKE :factor')->setParameter('factor', '%' . $actor . '%');
        }
        if (($reference = $text('reference')) !== '') {
            $qb->andWhere('g.reference LIKE :freference')->setParameter('freference', '%' . $reference . '%');
        }
        if (($from = $text('from')) !== '') {
            $qb->andWhere('g.occurredAt >= :ffrom')->setParameter('ffrom', $from . ' 00:00:00');
        }
        if (($to = $text('to')) !== '') {
            $qb->andWhere('g.occurredAt <= :fto')->setParameter('fto', $to . ' 23:59:59');
        }

        return $qb;
    }

    /**
     * How much of $original has already been given back by reversals (#585).
     *
     * Summed from `reverses_movement_id` rather than tracked as a column on the original, and that
     * is the whole reason the link is a column at all: a "reversed_quantity" counter on the original
     * would be a second copy of a number the reversals themselves already state, and the two would
     * disagree the first time one was rolled back. This can only ever be right, because it IS the
     * reversals.
     */
    public function reversedTotal(InventoryMovement $original): string
    {
        $sum = $this->createQueryBuilder('m')
            ->select('COALESCE(SUM(m.quantity), 0)')
            ->andWhere('m.reversesMovement = :original')->setParameter('original', $original)
            ->getQuery()
            ->getSingleScalarResult();

        return QuantityScale::canonical((string) $sum);
    }

    /**
     * `original.quantity − SUM(already reversed)`: how many units of one write-off entry can still
     * be put back (#585).
     *
     * The bound the whole reversal flow is built on. It is what makes "you cannot reverse 500 when
     * 12 were written off" and "you cannot reverse the same 12 twice" the same rule rather than two
     * validations somebody has to remember to write, and it is why the screen can LIST what is
     * reversible instead of showing an empty quantity box and hoping.
     *
     * Clamped at zero. A negative could only come from data written outside this flow, and a
     * negative bound reads as "some reversal is owed back", which is not a thing.
     */
    public function remainingReversible(InventoryMovement $original): string
    {
        $remaining = QuantityScale::sub($original->getQuantity(), $this->reversedTotal($original));

        return QuantityScale::compare($remaining, 0) < 0 ? QuantityScale::canonical(0) : $remaining;
    }

    /**
     * The write-off entries for one product that still have units left to give back, newest first.
     *
     * ## What qualifies, and why each test is there
     *
     *  - the movement LANDED in a write-off status (damaged, expired, scrapped, lost). Those are the
     *    entries "reverse a write-off" exists to undo; a quarantine is released with its own reason
     *    and a sale is undone by a credit memo, not here.
     *  - it CAME FROM `available`. A reversal is the original with its sides swapped, so the
     *    original's from-side is where the units go back to — and it has to be somewhere they can be
     *    sold from, or the reversal would move stock between two unsellable states and the
     *    `write_off` bucket would not fall.
     *  - it has a from-row at all. A write-off with no source invented the loss out of nothing, and
     *    swapping its sides would send the units out of the ledger rather than back onto a shelf.
     *
     * A reversal itself can never appear here: its from-side is a write-off status and its to-side
     * is `available`, which fails both status tests. So there is no need for an explicit "not a
     * reversal" clause, and adding one would hide that the shape already forbids it.
     *
     * The remaining figure is computed in PHP over the candidate ids rather than as a correlated
     * subquery in the ORDER BY, because the candidate set is one product's write-offs and the
     * two-query version stays readable — and because a DQL scalar subquery in a SELECT cannot be
     * ordered by on every platform this app supports.
     *
     * @return list<array{movement: InventoryMovement, remaining: int, reversed: int}>
     */
    public function reversibleWriteOffs(ProductCore $product, int $limit = 100): array
    {
        /** @var list<InventoryMovement> $candidates */
        $candidates = $this->createQueryBuilder('m')
            ->innerJoin('m.group', 'g')->addSelect('g')
            ->innerJoin('m.fromDetail', 'fd')->addSelect('fd')
            ->innerJoin('m.toDetail', 'td')->addSelect('td')
            ->andWhere('m.product = :product')->setParameter('product', $product)
            ->andWhere('td.status IN (:writeOffs)')->setParameter('writeOffs', InventoryDetail::writeOffStatuses())
            ->andWhere('fd.status = :available')->setParameter('available', InventoryDetail::STATUS_AVAILABLE)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        if ($candidates === []) {
            return [];
        }

        $ids = array_values(array_filter(array_map(static fn (InventoryMovement $m): ?int => $m->getId(), $candidates)));

        /** @var array<int, int> $reversed */
        $reversed = [];
        if ($ids !== []) {
            /** @var list<array{original: int, total: int|string}> $sums */
            $sums = $this->createQueryBuilder('r')
                ->select('IDENTITY(r.reversesMovement) AS original', 'SUM(r.quantity) AS total')
                ->andWhere('r.reversesMovement IN (:ids)')->setParameter('ids', $ids)
                ->groupBy('r.reversesMovement')
                ->getQuery()
                ->getArrayResult();

            foreach ($sums as $sum) {
                $reversed[(int) $sum['original']] = (int) $sum['total'];
            }
        }

        $rows = [];
        foreach ($candidates as $movement) {
            $already = QuantityScale::canonical($reversed[$movement->getId() ?? 0] ?? 0);
            $remaining = QuantityScale::sub($movement->getQuantity(), $already);

            // Fully reversed entries are dropped rather than shown greyed out. The list is the
            // operator's whole choice of source for this reason, and an option that cannot be acted
            // on is an invitation to pick it and read an error.
            if (QuantityScale::compare($remaining, 0) > 0) {
                $rows[] = [
                    'movement' => $movement,
                    'remaining' => $remaining,
                    'reversed' => $already,
                ];
            }
        }

        return $rows;
    }

    /**
     * Every movement that ever touched one serial, oldest first — "received when, put away where,
     * shipped on which order".
     *
     * @return list<InventoryMovement>
     */
    public function historyForSerial(string $serial): array
    {
        /** @var list<InventoryMovement> $rows */
        $rows = $this->createQueryBuilder('m')
            ->innerJoin('m.group', 'g')->addSelect('g')
            ->leftJoin('m.fromDetail', 'fd')->addSelect('fd')
            ->leftJoin('m.toDetail', 'td')->addSelect('td')
            ->andWhere('fd.serial = :serial OR td.serial = :serial')->setParameter('serial', $serial)
            ->orderBy('g.occurredAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
