<?php

declare(strict_types=1);

namespace WooCommerceBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use WooCommerceBundle\Entity\WooCommerceConnection;

/** @extends ServiceEntityRepository<WooCommerceConnection> */
class WooCommerceConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WooCommerceConnection::class);
    }

    /** @return list<WooCommerceConnection> */
    public function findAllOrderedByName(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /** @return list<WooCommerceConnection> Every connection that accepts orders and pushes stock. */
    public function findAllActive(): array
    {
        return $this->findBy(['active' => true], ['name' => 'ASC']);
    }
}
