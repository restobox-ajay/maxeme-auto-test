<?php

declare(strict_types=1);

namespace BarcodeBundle\Barcode;

use App\Contract\Product\ProductBarcodeSearchProviderInterface;
use BarcodeBundle\Entity\ProductBarcode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The bundle's answer to core's product-picker question: which products does a typed/scanned
 * string name by barcode — UPC, EAN, GTIN, a vendor's own part number, a customer's own part
 * number — rather than by SKU or name.
 *
 * A plain substring match on `product_barcode.code`, the same LIKE shape
 * {@see \App\Service\Product\ProductPicker::searchPage()} already runs against name/SKU; a scanned
 * code is normally typed in full, but a partial vendor part number is a real search too. Deleted
 * products are not filtered here — the picker's own `p.deleted = false` already excludes them once
 * these ids are folded into its query, so filtering twice would only cost a join for nothing.
 */
final class ProductBarcodeSearchProvider implements ProductBarcodeSearchProviderInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function getSource(): string
    {
        return BarcodeDirectory::SOURCE;
    }

    public function matchingProductIds(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        /** @var list<array{productId: int}> $rows */
        $rows = $this->em->getRepository(ProductBarcode::class)->createQueryBuilder('b')
            ->select('DISTINCT IDENTITY(b.product) AS productId')
            ->where('b.code LIKE :term')
            ->setParameter('term', '%' . $term . '%')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['productId'], $rows);
    }

    public function codesForProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return [];
        }

        /** @var list<array{productId: int, code: string}> $rows */
        $rows = $this->em->getRepository(ProductBarcode::class)->createQueryBuilder('b')
            ->select('IDENTITY(b.product) AS productId', 'b.code AS code')
            ->where('b.product IN (:ids)')
            ->setParameter('ids', $productIds)
            ->getQuery()
            ->getArrayResult();

        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) $row['productId']][] = (string) $row['code'];
        }

        return $byProduct;
    }
}
