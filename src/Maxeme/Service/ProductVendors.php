<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Entity\ProductCore;
use App\Maxeme\Entity\ProductVendor;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\HttpFoundation\Request;

/**
 * A product's vendor (ProductVendor): read for the product form and list, and saved from the
 * form's "vendor_id" select. The change itself reaches the Activity Log through core's automatic
 * entity diff (filed under Parts by AuditLogEnricher).
 */
final class ProductVendors
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function vendorOf(ProductCore $product): ?Vendor
    {
        return $product->getId() !== null ? $this->link($product)?->getVendor() : null;
    }

    /**
     * The form's choices: the active vendors by name, plus the product's current one when it has
     * since been made inactive (so opening and saving the form does not silently drop it).
     *
     * @return list<array{id: int, name: string, active: bool}>
     */
    public function options(?ProductCore $product = null): array
    {
        $current = $product !== null ? $this->vendorOf($product) : null;
        $vendors = $this->entityManager->getRepository(Vendor::class)->findBy(['status' => Vendor::STATUS_ACTIVE], ['name' => 'ASC']);
        if ($current !== null && !in_array($current, $vendors, true)) {
            $vendors[] = $current;
        }

        return array_map(static fn (Vendor $vendor): array => ['id' => (int) $vendor->getId(), 'name' => $vendor->getName(), 'active' => $vendor->isActive()], $vendors);
    }

    /**
     * The vendor names of a page of products, for the product list's Vendor column.
     *
     * @param iterable<int|string> $productIds
     *
     * @return array<int, string> product id => vendor name
     */
    public function namesFor(iterable $productIds): array
    {
        $ids = [];
        foreach ($productIds as $id) {
            if ((int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(pv.product) AS product', 'v.name AS name')
            ->from(ProductVendor::class, 'pv')
            ->join('pv.vendor', 'v')
            ->andWhere('pv.product IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getArrayResult();

        return array_column($rows, 'name', 'product');
    }

    /**
     * Saves the form's Vendor select ("vendor_id": a vendor id, or blank for none). Does nothing when
     * the request has no such field, so a post from elsewhere never clears it. Not flushed here.
     */
    public function saveFromRequest(ProductCore $product, Request $request): void
    {
        if (!$request->request->has('vendor_id')) {
            return;
        }
        $raw = trim((string) $request->request->get('vendor_id'));
        $vendor = ctype_digit($raw) ? $this->entityManager->find(Vendor::class, (int) $raw) : null;
        $link = $this->link($product);

        if ($vendor === null) {
            if ($link !== null) {
                $this->entityManager->remove($link);
            }

            return;
        }
        if ($link === null) {
            $this->entityManager->persist(new ProductVendor($product, $vendor));
        } elseif ($link->getVendor() !== $vendor) {
            $link->setVendor($vendor);
        }
    }

    private function link(ProductCore $product): ?ProductVendor
    {
        return $this->entityManager->getRepository(ProductVendor::class)->findOneBy(['product' => $product]);
    }
}
