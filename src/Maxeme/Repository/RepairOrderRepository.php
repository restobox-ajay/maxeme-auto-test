<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\RepairOrderJob;
use App\Maxeme\Entity\Vehicle;
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

    /** @return ListPage<RepairOrder> a client's repair orders, newest first (the client profile's Work Orders tab) */
    public function findPageForClient(Client $client, ListQuery $list): ListPage
    {
        return ListPage::paginate($this->inView($list)->andWhere('r.client = :client')->setParameter('client', $client), $list, self::SORTS, self::SEARCH_COLUMNS, self::FILTERS);
    }

    /** @return list<RepairOrder> a client's latest repair orders, newest first (the appointment page's Repair Order) */
    public function findRecentForClient(Client $client, int $limit = 50): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('v')
            ->leftJoin('r.vehicle', 'v')
            ->andWhere('r.client = :client')->setParameter('client', $client)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * A vehicle's repair orders, newest first, with their services; only those with a service of
     * $serviceId, or of one of $categoryIds, when given (the vehicle page's history filters).
     *
     * @param list<int> $categoryIds
     *
     * @return list<RepairOrder>
     */
    public function findHistoryForVehicle(Vehicle $vehicle, ?int $serviceId = null, array $categoryIds = []): array
    {
        $query = $this->createQueryBuilder('r')
            ->addSelect('j', 's')
            ->leftJoin('r.jobs', 'j')
            ->leftJoin('j.service', 's')
            ->andWhere('r.vehicle = :vehicle')->setParameter('vehicle', $vehicle)
            ->orderBy('r.createdOn', 'DESC')
            ->addOrderBy('r.id', 'DESC');
        if ($serviceId !== null) {
            $query->andWhere(sprintf('EXISTS (SELECT 1 FROM %s fj WHERE fj.repairOrder = r AND IDENTITY(fj.service) = :service)', RepairOrderJob::class))->setParameter('service', $serviceId);
        }
        if ($categoryIds !== []) {
            $query->andWhere(sprintf('EXISTS (SELECT 1 FROM %s cj JOIN cj.service cs WHERE cj.repairOrder = r AND IDENTITY(cs.category) IN (:categories))', RepairOrderJob::class))->setParameter('categories', $categoryIds);
        }

        return $query->getQuery()->getResult();
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
