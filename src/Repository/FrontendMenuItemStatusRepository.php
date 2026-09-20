<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FrontendMenuItemStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FrontendMenuItemStatus>
 */
class FrontendMenuItemStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FrontendMenuItemStatus::class);
    }

    public function findByKey(string $key): ?FrontendMenuItemStatus
    {
        return $this->findOneBy(['menuKey' => $key]);
    }

    /** A menu item is Active until a status row explicitly says otherwise. */
    public function isActive(string $key): bool
    {
        $status = $this->findByKey($key);

        return $status === null || $status->getStatus() === FrontendMenuItemStatus::STATUS_ACTIVE;
    }

    /** A menu item sorts to the front (0) until a status row explicitly says otherwise. */
    public function sortOrderFor(string $key): int
    {
        return $this->findByKey($key)?->getSortOrder() ?? 0;
    }

    /**
     * Lazy upsert: finds by key; if missing, creates as Active and returns it.
     */
    public function ensureByKey(string $key): FrontendMenuItemStatus
    {
        $status = $this->findByKey($key);
        if ($status !== null) {
            return $status;
        }

        $status = (new FrontendMenuItemStatus())
            ->setMenuKey($key)
            ->setStatus(FrontendMenuItemStatus::STATUS_ACTIVE);

        $em = $this->getEntityManager();
        $em->persist($status);
        $em->flush();

        return $status;
    }
}
