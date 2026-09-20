<?php

namespace App\Repository;

use App\Entity\Redirect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Redirect>
 */
class RedirectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Redirect::class);
    }

    /** @return Redirect[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['sourcePath' => 'ASC']);
    }

    public function findOneBySourcePath(string $sourcePath): ?Redirect
    {
        return $this->findOneBy(['sourcePath' => $sourcePath]);
    }
}
