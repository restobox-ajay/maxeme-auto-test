<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportColumnMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportColumnMapping>
 */
final class ImportColumnMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportColumnMapping::class);
    }

    public function findOneForScope(string $importType, string $scope): ?ImportColumnMapping
    {
        return $this->findOneBy(['importType' => $importType, 'scope' => $scope]);
    }
}
