<?php

declare(strict_types=1);

namespace ProcurementBundle\Repository;

use App\Entity\ProductCore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorPrice;

/**
 * @extends ServiceEntityRepository<VendorPrice>
 */
class VendorPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VendorPrice::class);
    }

    /** The one row a purchase order line prices itself from — active only. Null means "type it in". */
    public function findOneActiveFor(Vendor $vendor, ProductCore $product): ?VendorPrice
    {
        return $this->findOneBy(['vendor' => $vendor, 'product' => $product, 'isActive' => true]);
    }

    /**
     * The row a vendor sheet import refreshes when a row names their SKU but not ours — see
     * VendorSkuResolver. Not restricted to isActive: refreshing a price the admin deactivated is
     * still the row a repeat sheet is naming, not a reason to create a duplicate.
     */
    public function findOneByVendorSku(Vendor $vendor, string $vendorSku): ?VendorPrice
    {
        return $this->findOneBy(['vendor' => $vendor, 'vendorSku' => $vendorSku]);
    }

    /**
     * What one vendor charges for each of a set of products, keyed by product id — the batched
     * form of findOneActiveFor() for the product picker.
     *
     * One query for a whole page of search results rather than one per row. The picker fetches up
     * to SEARCH_PAGE_SIZE matches per keystroke, so the per-product form would be fifty extra round
     * trips every time somebody typed a letter; that is the N+1 the sell side's picker had to go
     * back and fix (see OrderController::pricingForProducts()).
     *
     * A product with no active row for this vendor is ABSENT from the result rather than present
     * with a zero. "This vendor has no rate for it" and "this vendor charges nothing for it" are
     * different answers and the caller has to be able to tell them apart.
     *
     * @param list<ProductCore> $products
     *
     * @return array<int, string> product id => unit cost, as a decimal string
     */
    public function unitCostsFor(Vendor $vendor, array $products): array
    {
        $ids = [];
        foreach ($products as $product) {
            if ($product instanceof ProductCore && $product->getId() !== null) {
                $ids[] = (int) $product->getId();
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var list<VendorPrice> $rows */
        $rows = $this->createQueryBuilder('vp')
            ->andWhere('vp.vendor = :vendor')
            ->andWhere('vp.isActive = true')
            ->andWhere('IDENTITY(vp.product) IN (:ids)')
            ->setParameter('vendor', $vendor)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $costs = [];
        foreach ($rows as $row) {
            $product = $row->getProduct();
            if ($product !== null && $product->getId() !== null) {
                $costs[(int) $product->getId()] = $row->getUnitCost();
            }
        }

        return $costs;
    }

    /**
     * The same batched lookup as unitCostsFor(), but the whole row — for the product picker's
     * autofill, which needs the vendor's own SKU beside their cost rather than the cost alone
     * (#full-parity, 2026-09-15). Kept as its own method rather than widening unitCostsFor()'s
     * return shape: that method is also the search picker's, and a caller asking for one figure
     * should not receive a row shaped for a form it has never heard of.
     *
     * @param list<ProductCore> $products
     *
     * @return array<int, VendorPrice> product id => this vendor's active rate row
     */
    public function ratesFor(Vendor $vendor, array $products): array
    {
        $ids = [];
        foreach ($products as $product) {
            if ($product instanceof ProductCore && $product->getId() !== null) {
                $ids[] = (int) $product->getId();
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var list<VendorPrice> $rows */
        $rows = $this->createQueryBuilder('vp')
            ->andWhere('vp.vendor = :vendor')
            ->andWhere('vp.isActive = true')
            ->andWhere('IDENTITY(vp.product) IN (:ids)')
            ->setParameter('vendor', $vendor)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $rates = [];
        foreach ($rows as $row) {
            $product = $row->getProduct();
            if ($product !== null && $product->getId() !== null) {
                $rates[(int) $product->getId()] = $row;
            }
        }

        return $rates;
    }

    /**
     * The rate card screen's query: one vendor's prices, or every price for one product, searched
     * and paged from GET params exactly like every other list screen in this bundle.
     *
     * @param array<string, string> $filters
     *
     * @return array{rows: list<VendorPrice>, total: int}
     */
    public function search(array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $sortable = [
            'vendor' => 'ven.name',
            'product' => 'p.name',
            'unitCost' => 'vp.unitCost',
            'id' => 'vp.id',
        ];
        $orderBy = $sortable[$sort] ?? 'ven.name';

        $qb = $this->createQueryBuilder('vp')
            ->join('vp.vendor', 'ven')
            ->join('vp.product', 'p');

        if (($filters['vendor_id'] ?? '') !== '') {
            $qb->andWhere('ven.id = :vendorId')->setParameter('vendorId', (int) $filters['vendor_id']);
        }
        if (($filters['q'] ?? '') !== '') {
            $qb->andWhere('p.name LIKE :q OR p.sku LIKE :q OR vp.vendorSku LIKE :q OR ven.name LIKE :q')
                ->setParameter('q', '%' . $filters['q'] . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(vp.id)')->getQuery()->getSingleScalarResult();

        /** @var list<VendorPrice> $rows */
        $rows = $qb->orderBy($orderBy, $dir)
            ->addOrderBy('vp.id', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }
}
