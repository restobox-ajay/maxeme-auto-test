<?php

declare(strict_types=1);

namespace ProcurementBundle\Hook;

use App\Contract\Inventory\ProductActivityProviderInterface;
use App\Contract\Inventory\ProductVendorProviderInterface;
use App\Entity\ProductCore;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\GoodsReceiptLine;
use ProcurementBundle\Entity\PurchaseOrderLine;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The Product Inventory Hub's "Purchase" row of its Recent Activity feed, and its "which vendor(s)"
 * identity-card line (docs/plans/2026-09-15-product-inventory-hub.md) — purchase orders AND goods
 * receipts for one product merged under one Activity type the same way the hub folds invoices under
 * "Order", plus the distinct vendor names on those purchase orders.
 *
 * Discovered purely by the `app.product_activity_provider`/`app.product_vendor_provider` tags and
 * {@see \App\Service\Inventory\ProductActivityFeedResolver}/{@see \App\Service\Inventory\ProductVendorResolver},
 * which is how the hub — living in the (deletable) InventoryDepthBundle — reads this module's
 * purchase history without ever importing one of its classes. Delete this module, or switch it
 * Inactive, and both resolvers simply get nothing back; the hub's own Activity type filter still
 * offers the "Purchase" button, it just never has rows under it, the same as any other provider seam
 * in this app answering "nothing" for an absent module.
 */
final class ProductPurchaseActivityProvider implements ProductActivityProviderInterface, ProductVendorProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public function getType(): string
    {
        return 'purchase';
    }

    public function getSource(): string
    {
        return 'ProcurementBundle';
    }

    public function activityFor(ProductCore $product, int $limit): array
    {
        /** @var list<PurchaseOrderLine> $poLines */
        $poLines = $this->em->createQueryBuilder()
            ->select('l', 'p')
            ->from(PurchaseOrderLine::class, 'l')
            ->innerJoin('l.purchaseOrder', 'p')->addSelect('p')
            ->andWhere('l.product = :product')->setParameter('product', $product)
            ->orderBy('p.documentDate', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $rows = array_map(fn (PurchaseOrderLine $line): array => [
            'date' => $line->getPurchaseOrder()->getDocumentDate(),
            'label' => 'PO ' . $line->getPurchaseOrder()->getPoNumber(),
            'quantity' => $line->getQuantityOrdered(),
            'url' => $this->router->generate('admin_bundle_procurement_purchase_order', ['id' => $line->getPurchaseOrder()->getId()]),
        ], $poLines);

        /** @var list<GoodsReceiptLine> $receiptLines */
        $receiptLines = $this->em->createQueryBuilder()
            ->select('l', 'r')
            ->from(GoodsReceiptLine::class, 'l')
            ->innerJoin('l.receipt', 'r')->addSelect('r')
            ->andWhere('l.product = :product')->setParameter('product', $product)
            ->orderBy('r.receivedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        foreach ($receiptLines as $line) {
            $receipt = $line->getReceipt();
            $rows[] = [
                'date' => $receipt->getReceivedAt()->format('Y-m-d'),
                'label' => 'Receipt ' . $receipt->getReceiptNumber(),
                'quantity' => $line->getQuantity(),
                'url' => $this->router->generate('admin_bundle_procurement_receipt', ['id' => $receipt->getId()]),
            ];
        }

        return $rows;
    }

    public function vendorNames(ProductCore $product, int $limit): array
    {
        /** @var list<string> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('p.vendorName')
            ->from(PurchaseOrderLine::class, 'l')
            ->innerJoin('l.purchaseOrder', 'p')
            ->andWhere('l.product = :product')->setParameter('product', $product)
            ->orderBy('p.documentDate', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getSingleColumnResult();

        return array_slice(array_values(array_unique($rows)), 0, $limit);
    }
}
