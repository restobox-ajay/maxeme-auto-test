<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\GovtFee;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractChargeRepository<GovtFee>
 */
final class GovtFeeRepository extends AbstractChargeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GovtFee::class);
    }

    /**
     * @param list<string> $codes
     *
     * @return list<GovtFee> the active fees with these codes
     */
    public function findActiveByCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return $this->findBy(['code' => array_values(array_unique(array_map(static fn (string $code): string => strtoupper(trim($code)), $codes))), 'active' => true], ['code' => 'ASC']);
    }
}
