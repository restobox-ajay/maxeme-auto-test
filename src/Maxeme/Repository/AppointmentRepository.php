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
        return $this->createQueryBuilder('a')
            ->addSelect('c', 'v')
            ->join('a.client', 'c')
            ->join('a.vehicle', 'v')
            ->andWhere('a.startTime >= :from AND a.startTime < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.startTime', 'ASC')
            ->getQuery()
            ->getResult();
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

    /** @return list<Appointment> the client's new and in-progress appointments, soonest first */
    public function pendingForClient(Client $client): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('v')
            ->join('a.vehicle', 'v')
            ->andWhere('a.client = :client')->setParameter('client', $client)
            ->andWhere('a.status IN (:pending)')->setParameter('pending', AppointmentStatus::pending())
            ->orderBy('a.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return ListPage<Appointment> the client's complete appointments */
    public function findCompletedPageForClient(Client $client, ListQuery $list): ListPage
    {
        $query = $this->createQueryBuilder('a')
            ->addSelect('v')
            ->join('a.vehicle', 'v')
            ->andWhere('a.client = :client')->setParameter('client', $client)
            ->andWhere('a.status = :complete')->setParameter('complete', AppointmentStatus::Complete);

        return ListPage::paginate($query, $list, self::PAST_SORTS, ['a.note', 'v.manufacturer', 'v.model']);
    }
}
