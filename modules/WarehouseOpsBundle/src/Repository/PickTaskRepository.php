<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Repository;

use App\Entity\SalesOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use WarehouseOpsBundle\Entity\PickList;
use WarehouseOpsBundle\Entity\PickTask;

/**
 * @extends ServiceEntityRepository<PickTask>
 */
class PickTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PickTask::class);
    }

    /**
     * The round, in the order it is walked: **`warehouse_location.sort_key` ascending, across every
     * order on the list**.
     *
     * The sort is over the batch as a whole rather than per order, which is the entire reason batch
     * picking is faster: one walk of the aisles serving four orders, not four walks. `sort_key` is
     * snapshotted onto the task at compile time, so a bin renumbered mid-round cannot reshuffle a
     * printout somebody is already holding.
     *
     * @return list<PickTask>
     */
    public function inRouteOrder(PickList $list): array
    {
        /** @var list<PickTask> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.pickList = :list')->setParameter('list', $list)
            ->orderBy('t.sortKey', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Tasks already on an OPEN list for these order lines, so the compiler never puts the same line
     * on two rounds at once — which is how two pickers get sent for the same units.
     *
     * @return array<int, true> keyed by sales_order_line id
     */
    public function orderLineIdsOnOpenLists(SalesOrder $order): array
    {
        /** @var list<array{lineId: int}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.orderLine) AS lineId')
            ->innerJoin('t.pickList', 'l')
            ->andWhere('t.order = :order')->setParameter('order', $order)
            ->andWhere('l.status IN (:open)')->setParameter('open', [PickList::STATUS_DRAFT, PickList::STATUS_RELEASED])
            ->andWhere('t.orderLine IS NOT NULL')
            ->getQuery()
            ->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['lineId']] = true;
        }

        return $ids;
    }
}
