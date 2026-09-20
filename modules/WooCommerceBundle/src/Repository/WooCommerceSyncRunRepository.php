<?php

declare(strict_types=1);

namespace WooCommerceBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use WooCommerceBundle\Entity\WooCommerceSyncRun;

/** @extends ServiceEntityRepository<WooCommerceSyncRun> */
class WooCommerceSyncRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WooCommerceSyncRun::class);
    }

    /** @return list<WooCommerceSyncRun> Every run, newest first — the sync run history grid. */
    public function findAllNewestFirst(): array
    {
        return $this->findBy([], ['startedAt' => 'DESC']);
    }
}
