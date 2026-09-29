<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Reminder;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\ReminderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reminder>
 */
final class ReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reminder::class);
    }

    /** @return list<Reminder> new, unavailable and delayed reminders, with what the list shows */
    public function findOpen(): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('c', 'v', 'i', 'a', 's')
            ->join('r.client', 'c')
            ->join('r.vehicle', 'v')
            ->leftJoin('r.invoice', 'i')
            ->leftJoin('i.appointment', 'a')
            ->leftJoin('i.serviceLines', 's')
            ->andWhere('r.status IN (:open)')
            ->setParameter('open', ReminderStatus::open())
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Whether the vehicle already has a reminder dated $since or later. */
    public function hasSince(Vehicle $vehicle, \DateTimeImmutable $since): bool
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.vehicle = :vehicle')->setParameter('vehicle', $vehicle)
            ->andWhere('r.reminderDate >= :since')->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
