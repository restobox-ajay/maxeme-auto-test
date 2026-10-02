<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Entity\ProductCore;
use App\Maxeme\Entity\GovtFee;
use App\Maxeme\Entity\Labour;
use App\Maxeme\Entity\ServiceLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceLine>
 */
final class ServiceLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceLine::class);
    }

    /** @return list<string> the names of the services with a line of $item, by name */
    public function serviceNamesUsing(Labour|ProductCore|GovtFee $item): array
    {
        $field = match (true) {
            $item instanceof Labour => 'labour',
            $item instanceof ProductCore => 'product',
            $item instanceof GovtFee => 'govtFee',
        };

        return $this->createQueryBuilder('l')
            ->select('DISTINCT s.name')
            ->join('l.service', 's')
            ->andWhere(sprintf('l.%s = :item', $field))->setParameter('item', $item)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }
}
