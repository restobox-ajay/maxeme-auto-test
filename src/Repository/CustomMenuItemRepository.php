<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomMenuItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomMenuItem>
 */
class CustomMenuItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomMenuItem::class);
    }

    /** @return CustomMenuItem[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    /** @return CustomMenuItem[] */
    public function findActiveOrdered(): array
    {
        return $this->findBy(['status' => CustomMenuItem::STATUS_ACTIVE], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }
}
