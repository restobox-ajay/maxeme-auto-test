<?php

declare(strict_types=1);

namespace AdminMenuBundle\Repository;

use AdminMenuBundle\Entity\AdminMenuItemStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminMenuItemStatus>
 */
class AdminMenuItemStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminMenuItemStatus::class);
    }

    public function findByKey(string $key): ?AdminMenuItemStatus
    {
        return $this->findOneBy(['itemKey' => $key]);
    }

    /** @return AdminMenuItemStatus[] */
    public function findAll(): array
    {
        return parent::findAll();
    }

    /**
     * Lazy upsert: finds by key; if missing, creates an untouched (not hidden, no order/parent
     * override) row and returns it. The builder UI's per-row edits call this before mutating, the
     * same shape as FrontendMenuItemStatusRepository::ensureByKey().
     */
    public function ensureByKey(string $key): AdminMenuItemStatus
    {
        $status = $this->findByKey($key);
        if ($status !== null) {
            return $status;
        }

        $status = (new AdminMenuItemStatus())->setItemKey($key);

        $em = $this->getEntityManager();
        $em->persist($status);
        $em->flush();

        return $status;
    }
}
