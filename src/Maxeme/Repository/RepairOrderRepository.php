<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RepairOrder>
 */
final class RepairOrderRepository extends ServiceEntityRepository
{
    /** Repair Orders sort key => column (first = default sort, newest first). */
    public const SORTS = [
        'id' => 'r.id',
        'createdOn' => 'r.createdOn',
        'customer' => 'c.lastName',
        'name' => 'r.name',
        'status' => 'r.status',
        'total' => 'r.total',
    ];

    /** The column search boxes (filters[field]) => column; status is matched exactly. */
    public const FILTERS = [
        'customer' => "CONCAT(COALESCE(c.firstName, ''), ' ', COALESCE(c.lastName, ''), ' ', COALESCE(c.preferredName, ''))",
        'vehicle' => "CONCAT(COALESCE(v.year, ''), ' ', COALESCE(v.manufacturer, ''), ' ', COALESCE(v.model, ''), ' ', COALESCE(v.licensePlate, ''))",
        'name' => 'r.name',
    ];

    private const SEARCH_COLUMNS = ['r.name', 'r.concern', 'r.tagKey', 'c.firstName', 'c.lastName', 'c.preferredName', 'v.licensePlate', 'v.vin'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RepairOrder::class);
    }

    /** @return ListPage<RepairOrder> */
    public function findPage(ListQuery $list): ListPage
    {
        return ListPage::paginate($this->inView($list), $list, self::SORTS, self::SEARCH_COLUMNS, self::FILTERS);
    }

    /** One repair order with its services, their lines and their items, for the edit page. */
    public function findForEdit(int $id): ?RepairOrder
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'j', 'l', 'labour', 'product', 'fee', 'ch')
            ->leftJoin('r.jobs', 'j')
            ->leftJoin('j.lines', 'l')
            ->leftJoin('l.labour', 'labour')
            ->leftJoin('l.product', 'product')
            ->leftJoin('l.govtFee', 'fee')
            ->leftJoin('r.charges', 'ch')
            ->andWhere('r.id = :id')->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function inView(ListQuery $list): QueryBuilder
    {
        $query = $this->createQueryBuilder('r')
            ->addSelect('c', 'v')
            ->leftJoin('r.client', 'c')
            ->leftJoin('r.vehicle', 'v');

        $status = $list->filters['status'] ?? '';
        if ($status !== '') {
            $query->andWhere('r.status = :status')->setParameter('status', $status);
        }

        return $query;
    }
}
