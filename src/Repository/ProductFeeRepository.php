<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Fee;
use App\Entity\ProductCore;
use App\Entity\ProductFee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductFee>
 */
class ProductFeeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductFee::class);
    }

    public function getValueForProduct(ProductCore $product, Fee $fee): ?float
    {
        $pivot = $this->findOneBy(['product' => $product, 'fee' => $fee]);

        return $pivot?->getValue();
    }

    public function setValue(ProductCore $product, Fee $fee, float $value): void
    {
        $pivot = $this->findOneBy(['product' => $product, 'fee' => $fee]);

        if ($pivot === null) {
            $pivot = (new ProductFee())->setProduct($product)->setFee($fee);
            $this->getEntityManager()->persist($pivot);
        }

        $pivot->setValue($value);
    }

    public function deleteValue(ProductCore $product, Fee $fee): void
    {
        $pivot = $this->findOneBy(['product' => $product, 'fee' => $fee]);

        if ($pivot !== null) {
            $this->getEntityManager()->remove($pivot);
        }
    }
}
