<?php

declare(strict_types=1);

namespace BarcodeBundle\Repository;

use App\Entity\ProductCore;
use BarcodeBundle\Entity\ProductBarcode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reading `product_barcode` (#607).
 *
 * The one method that matters is {@see forCode()}: it returns EVERY row carrying a scanned string,
 * never "the first one". A scanned value that lands on two products is a fact about the data, and
 * the caller's job is to say so and refuse — the resolver cannot do that if the query has already
 * picked a winner.
 *
 * @extends ServiceEntityRepository<ProductBarcode>
 */
class ProductBarcodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductBarcode::class);
    }

    /**
     * Every barcode row whose code is exactly this string, on a product that has not been deleted.
     *
     * Deleted products are excluded here rather than at the call site because a soft-deleted
     * product is not something anyone can receive against, and leaving its barcodes in the result
     * would manufacture ambiguity out of a product nobody can pick.
     *
     * @return list<ProductBarcode>
     */
    public function forCode(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return [];
        }

        /** @var list<ProductBarcode> $rows */
        $rows = $this->createQueryBuilder('b')
            ->innerJoin('b.product', 'p')
            ->andWhere('b.code = :code')->setParameter('code', $code)
            ->andWhere('p.deleted = false')
            ->orderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The distinct products a scanned string names. One is a resolution; two is a collision.
     *
     * @return list<ProductCore>
     */
    public function productsForCode(string $code): array
    {
        $products = [];
        foreach ($this->forCode($code) as $barcode) {
            $product = $barcode->getProduct();
            if ($product instanceof ProductCore) {
                $products[$product->getId() ?? 0] = $product;
            }
        }

        return array_values($products);
    }

    /**
     * Every barcode on one product, the primary first.
     *
     * @return list<ProductBarcode>
     */
    public function forProduct(ProductCore $product): array
    {
        /** @var list<ProductBarcode> $rows */
        $rows = $this->createQueryBuilder('b')
            ->andWhere('b.product = :product')->setParameter('product', $product)
            ->orderBy('b.primary', 'DESC')
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The barcode a label encodes for this product, or null when it has none.
     *
     * Null is the answer for a product nobody has given a barcode to, and every caller has to keep
     * behaving exactly as it did before this table existed when it gets one.
     */
    public function primaryFor(ProductCore $product): ?ProductBarcode
    {
        $rows = $this->forProduct($product);

        foreach ($rows as $row) {
            if ($row->isPrimary()) {
                return $row;
            }
        }

        // No explicit primary: the oldest row is the one that has been on the box longest, which is
        // a better default than nothing and is the same answer every time it is asked.
        return $rows[0] ?? null;
    }

    public function exists(ProductCore $product, string $code): bool
    {
        return $this->findOneBy(['product' => $product, 'code' => trim($code)]) instanceof ProductBarcode;
    }

    /**
     * The list screen: filtered, sorted, paged, server-side.
     *
     * @param array<string, string> $filters
     *
     * @return array{rows: list<ProductBarcode>, total: int}
     */
    public function search(array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $qb = $this->createQueryBuilder('b')->innerJoin('b.product', 'p')->addSelect('p');

        if (($filters['code'] ?? '') !== '') {
            $qb->andWhere('b.code LIKE :code')->setParameter('code', '%' . $filters['code'] . '%');
        }
        if (($filters['product'] ?? '') !== '') {
            $qb->andWhere('(p.sku LIKE :product OR p.name LIKE :product)')
                ->setParameter('product', '%' . $filters['product'] . '%');
        }
        if (($filters['kind'] ?? '') !== '') {
            $qb->andWhere('b.kind = :kind')->setParameter('kind', $filters['kind']);
        }
        if (($filters['party'] ?? '') !== '') {
            $qb->andWhere('b.party LIKE :party')->setParameter('party', '%' . $filters['party'] . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(b.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        $field = match ($sort) {
            'code' => 'b.code',
            'kind' => 'b.kind',
            'party' => 'b.party',
            'product' => 'p.sku',
            default => 'b.id',
        };

        /** @var list<ProductBarcode> $rows */
        $rows = $qb->orderBy($field, $dir === 'ASC' ? 'ASC' : 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }
}
