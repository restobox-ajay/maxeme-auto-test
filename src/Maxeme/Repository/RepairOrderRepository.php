<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\RepairOrderJob;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\AppointmentStatus;
use App\Maxeme\Enum\RepairOrderStatus;
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

    /** @return list<RepairOrder> every repair order of the current view (search boxes, status, sort; no page), for its CSV */
    public function findAllInView(ListQuery $list): array
    {
        return ListPage::filter($this->inView($list), $list, self::SORTS, self::SEARCH_COLUMNS, self::FILTERS)->getQuery()->getResult();
    }

    /** @return ListPage<RepairOrder> a client's repair orders, newest first (the client profile's Work Orders tab) */
    public function findPageForClient(Client $client, ListQuery $list): ListPage
    {
        return ListPage::paginate($this->inView($list)->andWhere('r.client = :client')->setParameter('client', $client), $list, self::SORTS, self::SEARCH_COLUMNS, self::FILTERS);
    }

    /** @return list<RepairOrder> every repair order of the client in the list's search and sort (its Export CSV) */
    public function findForClient(Client $client, ListQuery $list): array
    {
        return ListPage::filter($this->inView($list)->andWhere('r.client = :client')->setParameter('client', $client), $list, self::SORTS, self::SEARCH_COLUMNS, self::FILTERS)->getQuery()->getResult();
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
     * The client's open repair orders, newest first: estimate being built, pending on estimate,
     * authorized, in progress or awaiting parts; not Completed, Picked Up, Invoiced or Cancelled
     * (the ones a new repair-order appointment can be for, mockups 25368c7 and 84d136d).
     *
     * @return list<RepairOrder>
     */
    public function findOpenForClient(Client $client): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('v')
            ->leftJoin('r.vehicle', 'v')
            ->andWhere('r.client = :client')->setParameter('client', $client)
            ->andWhere('r.status IN (:open)')->setParameter('open', [RepairOrderStatus::EstimateBeingBuilt, RepairOrderStatus::EstimateApproval, RepairOrderStatus::Authorized, RepairOrderStatus::InProgress, RepairOrderStatus::AwaitingParts])
            ->orderBy('r.id', 'DESC')
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

    /**
     * The Repair Order Board's repair orders for a period (mockup 7bd9227), with customer, vehicle,
     * technician and appointments:
     * - one with appointments: when one of them starts in the period ($openAll: also every one not
     *   yet Picked Up, Invoiced or Cancelled, whatever the dates of its appointments);
     * - one with none: while still being worked on (before Completed); Completed or later only
     *   when it was created in the period and on or after $recentFromUtc, so old ones with no
     *   appointment never fill the finished columns.
     *
     * @return list<RepairOrder>
     */
    public function findForBoard(\DateTimeImmutable $startUtc, \DateTimeImmutable $endUtc, bool $openAll, \DateTimeImmutable $recentFromUtc): array
    {
        $appointment = Appointment::class;
        $query = $this->createQueryBuilder('r')
            ->addSelect('c', 'v', 't', 'a')
            ->leftJoin('r.client', 'c')
            ->leftJoin('r.vehicle', 'v')
            ->leftJoin('r.masterTechnician', 't')
            ->leftJoin('r.appointments', 'a')
            ->setParameter('start', $startUtc)
            ->setParameter('end', $endUtc)
            ->setParameter('recent', $recentFromUtc)
            ->setParameter('finished', [RepairOrderStatus::Completed, RepairOrderStatus::CompletedPickedUp, RepairOrderStatus::Invoiced, RepairOrderStatus::Cancelled]);
        $withAppointments = "EXISTS (SELECT 1 FROM $appointment ia WHERE ia.repairOrder = r AND ia.startTime BETWEEN :start AND :end)";
        if ($openAll) {
            $withAppointments = "($withAppointments OR (EXISTS (SELECT 1 FROM $appointment oa WHERE oa.repairOrder = r) AND r.status NOT IN (:terminal)))";
            $query->setParameter('terminal', [RepairOrderStatus::CompletedPickedUp, RepairOrderStatus::Invoiced, RepairOrderStatus::Cancelled]);
        }
        $none = "NOT EXISTS (SELECT 1 FROM $appointment na WHERE na.repairOrder = r) AND (r.status NOT IN (:finished) OR (r.createdOn BETWEEN :start AND :end AND r.createdOn >= :recent))";

        return $query->andWhere("$withAppointments OR ($none)")
            ->orderBy('r.lastUpdated', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** How many open repair orders (not Picked Up, Invoiced or Cancelled) have appointments, none of them in the period: the board's hint. */
    public function countOpenWithAppointmentsOutside(\DateTimeImmutable $startUtc, \DateTimeImmutable $endUtc): int
    {
        $appointment = Appointment::class;

        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.status NOT IN (:terminal)')->setParameter('terminal', [RepairOrderStatus::CompletedPickedUp, RepairOrderStatus::Invoiced, RepairOrderStatus::Cancelled])
            ->andWhere("EXISTS (SELECT 1 FROM $appointment oa WHERE oa.repairOrder = r)")
            ->andWhere("NOT EXISTS (SELECT 1 FROM $appointment ia WHERE ia.repairOrder = r AND ia.startTime BETWEEN :start AND :end)")
            ->setParameter('start', $startUtc)
            ->setParameter('end', $endUtc)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<RepairOrder> repair orders dated (created) within the period, newest first, with customer, vehicle and technician */
    public function findCreatedBetween(\DateTimeImmutable $startUtc, \DateTimeImmutable $endUtc): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('c', 'v', 't')
            ->leftJoin('r.client', 'c')
            ->leftJoin('r.vehicle', 'v')
            ->leftJoin('r.masterTechnician', 't')
            ->andWhere('r.createdOn BETWEEN :start AND :end')
            ->setParameter('start', $startUtc)
            ->setParameter('end', $endUtc)
            ->orderBy('r.createdOn', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
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
        // Appointment Status (mockup 551ab32): the repair order has an appointment in that status and,
        // for a finished one (completed, cancelled, no-show), none still pending.
        $appointmentStatus = AppointmentStatus::tryFrom($list->filters['appointmentStatus'] ?? '');
        if ($appointmentStatus !== null) {
            $query->andWhere('EXISTS (SELECT 1 FROM ' . Appointment::class . ' fa WHERE fa.repairOrder = r AND fa.status = :appointmentStatus)')
                ->setParameter('appointmentStatus', $appointmentStatus);
            if (!$appointmentStatus->isPending()) {
                $query->andWhere('NOT EXISTS (SELECT 1 FROM ' . Appointment::class . ' pa WHERE pa.repairOrder = r AND pa.status IN (:pendingStatuses))')
                    ->setParameter('pendingStatuses', AppointmentStatus::pending());
            }
        }

        return $query;
    }
}
