<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Entity\ProductCore;
use App\Maxeme\Entity\GovtFee;
use App\Maxeme\Entity\ServiceItem;
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
    /**
     * What uses the class (audit fix 8b30420): "3 services, 1 labour rate and 2 products", or '' when nothing does.
     */
    public function describeUses(TaxClass $class): string
    {
        $em = $this->getEntityManager();
        $counts = [
            'service|services' => $em->getRepository(ServiceItem::class)->count(['taxClass' => $class]),
            'labour rate|labour rates' => $em->getRepository(Labour::class)->count(['taxClass' => $class]),
            'government fee|government fees' => $em->getRepository(GovtFee::class)->count(['taxClass' => $class]),
            'product|products' => $em->getRepository(ProductCore::class)->count(['salesTaxCode' => $class->getCode()]),
        ];
        $parts = [];
        foreach ($counts as $words => $count) {
            if ($count > 0) {
                [$one, $many] = explode('|', $words);
                $parts[] = sprintf('%d %s', $count, $count === 1 ? $one : $many);
            }
        }

        return match (count($parts)) {
            0 => '',
            1 => $parts[0],
            default => implode(', ', array_slice($parts, 0, -1)) . ' and ' . $parts[count($parts) - 1],
        };
    }
}
