<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Inventory;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * A recall's two questions, for one lot (#725, the sharpest finding in the wholesale-inventory-core
 * parity audit): where is it now, and who was it sold to.
 *
 * ## Why this lives in InventoryDepthBundle and reads App\Entity\InvoiceLine directly
 *
 * Only the REVERSE direction is forbidden here — core, and sibling bundles, must not import this
 * bundle's classes, which is what makes the whole depth layer deletable without breaking anyone
 * (`ThisBundleNamesNoOtherBundleTest` is this bundle's own guard against reaching for a SIBLING
 * bundle, not against reading core). A bundle reading core entities is the ordinary, unrestricted
 * direction — `InvoiceLine`, `ProductCore` and `Warehouse` are already imported straight into this
 * bundle's other classes (see `ShipmentLine`, `AdjustmentController`). `InvoiceLine::$lotId` is a
 * bare int rather than a Doctrine relation for exactly the opposite reason: core cannot point INTO
 * this bundle, so the link back only ever travels this way.
 *
 * ## What counts as "sold to"
 *
 * Every `InvoiceLine` naming this lot, whose invoice has actually been ISSUED — `isDraft()` false —
 * matching `Invoice::isDraft()`'s own docblock: a draft is "written but not issued... counting for
 * nothing", the same reasoning `CompanyCreditExposureCalculator` (#724) already applies to what
 * counts as a receivable. Cancelled invoices are NOT excluded here, deliberately, unlike that
 * calculator: a cancelled invoice can still represent units that physically left the building before
 * the cancellation (paperwork undone after the fact does not un-ship stock), and a recall needs to
 * find every customer who may be holding the product, not only the ones whose invoice is still
 * collectible.
 */
final class LotTraceService
{
    public function __construct(
        private readonly InventoryDetailRepository $details,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Where this lot currently is, one row per (warehouse, location, status) it is sitting on.
     *
     * @return list<array{warehouse: string, location: string, status: string, quantity: int}>
     */
    public function currentLocations(InventoryLot $lot): array
    {
        return array_map(
            static fn (\InventoryDepthBundle\Entity\InventoryDetail $row): array => [
                'warehouse' => $row->getWarehouse()->getName(),
                'location' => $row->getLocation()?->getCode() ?? '—',
                'status' => $row->getStatus(),
                'quantity' => $row->getQuantity(),
            ],
            $this->details->rowsForLot($lot),
        );
    }

    /**
     * Every invoice line that shipped units of this lot — a recall notice list, ready to generate.
     *
     * @return list<array{invoice: Invoice, companyName: string, quantity: string, invoiceDate: ?string, status: string}>
     */
    public function soldTo(InventoryLot $lot): array
    {
        $lotId = $lot->getId();
        if ($lotId === null) {
            return [];
        }

        /** @var list<InvoiceLine> $lines */
        $lines = $this->em->getRepository(InvoiceLine::class)->createQueryBuilder('il')
            ->innerJoin('il.invoice', 'i')->addSelect('i')
            ->innerJoin('i.company', 'c')->addSelect('c')
            ->andWhere('il.lotId = :lotId')->setParameter('lotId', $lotId)
            ->andWhere('i.status <> :draft')->setParameter('draft', 'Draft')
            ->orderBy('i.invoiceDate', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (InvoiceLine $line): array => [
            'invoice' => $line->getInvoice(),
            'companyName' => $line->getInvoice()->getCompany()->getName(),
            'quantity' => $line->getQuantity(),
            'invoiceDate' => $line->getInvoice()->getInvoiceDate(),
            'status' => $line->getInvoice()->getStatus(),
        ], $lines);
    }
}
