<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Labour;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractChargeRepository<Labour>
 */
final class LabourRepository extends AbstractChargeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Labour::class);
    }
}
