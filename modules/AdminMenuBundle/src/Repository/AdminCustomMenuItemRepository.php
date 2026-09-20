<?php

declare(strict_types=1);

namespace AdminMenuBundle\Repository;

use AdminMenuBundle\Entity\AdminCustomMenuItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminCustomMenuItem>
 */
class AdminCustomMenuItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminCustomMenuItem::class);
    }

    /** @return AdminCustomMenuItem[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    public function findByKey(string $key): ?AdminCustomMenuItem
    {
        return $this->findOneBy(['itemKey' => $key]);
    }
}
