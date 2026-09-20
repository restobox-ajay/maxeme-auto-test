<?php

declare(strict_types=1);

namespace WooCommerceBundle\Repository;

use App\Entity\ProductCore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommerceProductMapping;

/** @extends ServiceEntityRepository<WooCommerceProductMapping> */
class WooCommerceProductMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WooCommerceProductMapping::class);
    }

    public function findOneForConnectionAndSku(WooCommerceConnection $connection, string $wooSku): ?WooCommerceProductMapping
    {
        return $this->findOneBy(['connection' => $connection, 'wooSku' => $wooSku]);
    }

    /** The outbound direction's lookup: given a queue item's (connection, product), what does Woo call it? */
    public function findOneForConnectionAndProduct(WooCommerceConnection $connection, ProductCore $product): ?WooCommerceProductMapping
    {
        return $this->findOneBy(['connection' => $connection, 'product' => $product]);
    }

    /** @return list<WooCommerceProductMapping> Every mapping still awaiting admin review, oldest first — the reconciliation queue. */
    public function findAllFlagged(): array
    {
        return $this->findBy(['flagged' => true], ['createdAt' => 'ASC']);
    }
}
