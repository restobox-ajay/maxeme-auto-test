<?php

namespace App\Repository;

use App\Entity\DbConsoleSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DbConsoleSession>
 */
final class DbConsoleSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DbConsoleSession::class);
    }

    /** Drop every session that has already lapsed. Returns the number removed. */
    public function purgeExpired(\DateTimeImmutable $now): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }

    /**
     * Drop every console session belonging to an admin. Returns the number removed.
     *
     * Used on logout: the gateway never reads the Symfony session, so without this a token stays
     * valid after the admin has logged out. All of the admin's sessions go, not just the one behind
     * the current request — logging out means none of them should survive.
     */
    public function deleteForAdmin(int $adminId): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.admin = :admin')
            ->setParameter('admin', $adminId)
            ->getQuery()
            ->execute();
    }
}
