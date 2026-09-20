<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Enum\PurchaseOrderStatus;
use ProcurementBundle\Enum\VendorBillStatus;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The bundle's landing screen (#555): what it is, and what the buy side looks like right now.
 *
 * The route every other entry point resolves to — the sidebar item, the App Management descriptor
 * — so it has to explain the feature to somebody who has just switched it on as well as report on
 * it for somebody who uses it daily.
 */
#[Route('/admin/bundles/procurement')]
final class DashboardController extends AbstractProcurementController
{
    #[Route('', name: 'admin_bundle_procurement_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyIfInactive();

        return $this->render('@Procurement/index.html.twig', [
            'vendorCount' => $this->count(Vendor::class, ['status' => Vendor::STATUS_ACTIVE]),
            'openOrders' => $this->count(PurchaseOrder::class, ['status' => [PurchaseOrderStatus::Issued, PurchaseOrderStatus::PartiallyReceived]]),
            'receiptCount' => $this->count(GoodsReceipt::class, []),
            'unorderedReceipts' => (int) $this->em->createQueryBuilder()
                ->select('COUNT(r.id)')->from(GoodsReceipt::class, 'r')
                ->andWhere('r.purchaseOrder IS NULL')
                ->getQuery()->getSingleScalarResult(),
            'payableBills' => $this->count(VendorBill::class, ['status' => [VendorBillStatus::Open, VendorBillStatus::PartiallyPaid, VendorBillStatus::Disputed]]),
            'disputedBills' => $this->count(VendorBill::class, ['status' => VendorBillStatus::Disputed]),
        ]);
    }

    /**
     * @param class-string $entity
     * @param array<string, mixed> $criteria
     */
    private function count(string $entity, array $criteria): int
    {
        return $this->em->getRepository($entity)->count($criteria);
    }
}
