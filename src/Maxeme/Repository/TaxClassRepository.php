<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\GovtFee;
use App\Maxeme\Entity\Labour;
use App\Maxeme\Entity\TaxClass;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaxClass>
 */
final class TaxClassRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxClass::class);
    }

    /** @return list<TaxClass> by code */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['code' => 'ASC']);
    }

    public function findOneByCode(string $code): ?TaxClass
    {
        return $this->findOneBy(['code' => strtoupper(trim($code))]);
    }

    /** How many labour rates and government fees use $class. */
    public function countUses(TaxClass $class): int
    {
        $em = $this->getEntityManager();

        return $em->getRepository(Labour::class)->count(['taxClass' => $class])
            + $em->getRepository(GovtFee::class)->count(['taxClass' => $class]);
    }
}
