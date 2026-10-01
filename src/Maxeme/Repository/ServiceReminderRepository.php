<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\ServiceReminder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceReminder>
 */
final class ServiceReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceReminder::class);
    }

    /** @return list<ServiceReminder> every service reminder, by service name */
    public function findAllByService(): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('s', 't')
            ->join('r.service', 's')
            ->leftJoin('r.template', 't')
            ->orderBy('s.name', 'ASC')
            ->getQuery()->getResult();
    }
}
