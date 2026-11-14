<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\AppointmentStatus;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Appointment>
 */
final class AppointmentRepository extends ServiceEntityRepository
{
    /** Past Appointments sort key => column (legacy grid: newest first by default). */
    public const PAST_SORTS = [
        'startTime' => 'a.startTime',
        'note' => 'a.note',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Appointment::class);
    }

    /**
     * Appointments starting in [$from, $to) (UTC), with their client and vehicle. Deleted clients'
     * appointments are included, as in the legacy feed.
     *
     * @return list<Appointment>
     */
    public function startingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // With the repair order: the event's description and links (its services no longer colour it).
        return $this->createQueryBuilder('a')
            ->addSelect('c', 'v', 'ro')
            ->join('a.client', 'c')
            ->leftJoin('a.vehicle', 'v')
            ->leftJoin('a.repairOrder', 'ro')
            ->andWhere('a.startTime >= :from AND a.startTime < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Appointments › List sort key => column (first = default sort). */
    public const LIST_SORTS = [
        'start' => 'a.startTime',
        'status' => 'a.status',
        'type' => 'a.type',
        'client' => 'c.firstName',
        'vehicle' => 'v.manufacturer',
        'repairOrder' => 'ro.id',
    ];

    /** Appointments › List column search boxes (filters[field]) => column. */
    public const LIST_FILTERS = [
        'status' => 'a.status',
        'type' => 'a.type',
        'client' => "CONCAT(COALESCE(c.firstName, ''), ' ', COALESCE(c.lastName, ''), ' ', COALESCE(c.preferredName, ''))",
        'phone' => "CONCAT(COALESCE(c.phone1, ''), ' ', COALESCE(c.phone2, ''))",
        'vehicle' => "CONCAT(COALESCE(v.year, ''), ' ', COALESCE(v.manufacturer, ''), ' ', COALESCE(v.model, ''))",
        'description' => "CONCAT(COALESCE(ro.name, ''), ' ', COALESCE(a.note, ''))",
    ];

    /**
     * Appointments › List (mockup deba1ef): every appointment, or those starting in [$from, $to) (UTC),
     * searchable by client, phone, vehicle and description.
     *
     * @return ListPage<Appointment>
     */
    public function findListPage(ListQuery $list, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): ListPage
    {
        $query = $this->createQueryBuilder('a')
            ->addSelect('c', 'v', 'ro')
            ->join('a.client', 'c')
            ->leftJoin('a.vehicle', 'v')
            ->leftJoin('a.repairOrder', 'ro');
        if ($from !== null && $to !== null) {
            $query->andWhere('a.startTime >= :from AND a.startTime < :to')->setParameter('from', $from)->setParameter('to', $to);
        }

        return ListPage::paginate($query, $list, self::LIST_SORTS, ['c.firstName', 'c.lastName', 'c.preferredName', 'c.phone1', 'v.manufacturer', 'v.model', 'a.note', 'ro.name'], self::LIST_FILTERS);
    }

    /** Whether the vehicle has an appointment starting at $since (UTC) or later. */
    public function hasStartingSince(Vehicle $vehicle, \DateTimeImmutable $since): bool
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.vehicle = :vehicle')->setParameter('vehicle', $vehicle)
            ->andWhere('a.startTime >= :since')->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** @return list<Appointment> the client's new, in-progress and extended appointments, soonest first */
    public function pendingForClient(Client $client): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('v', 'ro')
            ->leftJoin('a.vehicle', 'v')
            ->leftJoin('a.repairOrder', 'ro')
            ->andWhere('a.client = :client')->setParameter('client', $client)
            ->andWhere('a.status IN (:pending)')->setParameter('pending', AppointmentStatus::pending())
            ->orderBy('a.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The Client Profile tab's Appointments card (mockup e0fde70): up to $limit appointments, pending
     * first then past, each newest first, with how many there are of each.
     *
     * @return array{pending: list<Appointment>, past: list<Appointment>, pendingCount: int, pastCount: int, total: int}
     */
    public function recentForClient(Client $client, int $limit = 10): array
    {
        $query = fn (bool $pending) => $this->createQueryBuilder('a')
            ->addSelect('v', 'ro')
            ->leftJoin('a.vehicle', 'v')
            ->leftJoin('a.repairOrder', 'ro')
            ->andWhere('a.client = :client')->setParameter('client', $client)
            ->andWhere($pending ? 'a.status IN (:pending)' : 'a.status NOT IN (:pending)')->setParameter('pending', AppointmentStatus::pending())
            ->orderBy('a.startTime', 'DESC');
        $count = fn (bool $pending): int => (int) (clone $query($pending))->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        $pending = $query(true)->setMaxResults($limit)->getQuery()->getResult();
        $past = $limit > count($pending) ? $query(false)->setMaxResults($limit - count($pending))->getQuery()->getResult() : [];
        $pendingCount = $count(true);
        $pastCount = $count(false);

        return ['pending' => $pending, 'past' => $past, 'pendingCount' => $pendingCount, 'pastCount' => $pastCount, 'total' => $pendingCount + $pastCount];
    }

    /** @return ListPage<Appointment> the client's past appointments: completed, cancelled, no-show */
    public function findCompletedPageForClient(Client $client, ListQuery $list): ListPage
    {
        $query = $this->createQueryBuilder('a')
            ->addSelect('v', 'ro')
            ->leftJoin('a.vehicle', 'v')
            ->leftJoin('a.repairOrder', 'ro')
            ->andWhere('a.client = :client')->setParameter('client', $client)
            ->andWhere('a.status NOT IN (:pending)')->setParameter('pending', AppointmentStatus::pending());

        return ListPage::paginate($query, $list, self::PAST_SORTS, ['a.note', 'v.manufacturer', 'v.model']);
    }
}
