<?php

declare(strict_types=1);

namespace Number1ProductImportBundle\Command;

use App\Entity\Warehouse;
use App\Entity\Invoice;
use App\Entity\InvoiceInventoryReservation;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Service\Inventory\InventoryBucketAuditLogger;
use App\Service\Inventory\InventoryOperationContext;
use App\Service\Inventory\InvoiceInventoryBucketResolver;
use App\Service\Inventory\OrderInventoryBucketResolver;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recomputes ProductInventory.approvedQuantity from source of truth (Invoice/InvoiceLine currently
 * Processing/Completed), subtracting each invoice's InvoiceInventoryReservation syncedQuantity
 * baseline before aggregating — that baseline is what makes this bundle's own approved-balance reset
 * (see ProductImportService::applyInventory()'s clear_approved_balance handling) stick, so this
 * recalc won't silently undo it the way a naive re-sum would.
 *
 * The source is the INVOICE from #539 stage 3 on, not the sales order. Approved means "being
 * fulfilled or fulfilled", which is a statement about the document that bills the goods; the order
 * holds only what it has not yet billed, in `sales_hold`, and core's app:inventory-recalc owns that.
 *
 * Lives in this bundle rather than the core cron because this bundle is what resets Approved in the
 * first place, so it's the one that owns keeping it correct afterward. Meant to run hourly via
 * crontab, same cadence as the core recalc:
 *
 *   0 * * * * cd /path/to/app && php bin/console app:product-import:approved-recalc >> /var/log/approved-recalc.log 2>&1
 */
#[AsCommand(
    name: 'app:product-import:approved-recalc',
    description: 'Recomputes the Approved inventory bucket from source of truth (baseline-aware), correcting any drift.',
)]
final class ApprovedInventoryRecalcCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryBucketAuditLogger $bucketAuditLogger,
        private readonly InventoryOperationContext $operations,
        private readonly WarehouseFulfillmentRegionService $warehouses,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The whole run is one operation named 'cron_correction' (#582), so every bucket this
        // command corrects is recorded in inventory_bucket_change_log under that action instead of
        // as an unattributed write. It has to span the flush below rather than each setter, because
        // that is when App\EventSubscriber\InventoryBucketChangeLogger reads the changeset — and it
        // spans the whole body rather than just the flush, because logDiscrepancy() sends mail and
        // anything that flushes an email log en route would otherwise settle a bucket outside it.
        //
        // No movement group: a recalc corrects a cached number, it does not move stock. The rows it
        // produces are group_id NULL by construction, which is the correct record.
        return $this->operations->run('cron_correction', fn (): int => $this->recalculate($input, $output));
    }

    /** The body of execute(), inside the ambient operation that names it in the bucket change log. */
    private function recalculate(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // An invoice line names a REGION and the bucket belongs to the WAREHOUSE serving it (#546).
        $warehousesByLowerRegionName = $this->warehouses->warehousesByLowerRegionName();

        $approvedSums = $this->approvedSums($warehousesByLowerRegionName);

        $touched = 0;
        foreach ($this->entityManager->getRepository(ProductInventory::class)->findAll() as $inventory) {
            $product = $inventory->getProduct();
            $warehouse = $inventory->getWarehouse();
            if ($product->getId() === null || !$warehouse instanceof Warehouse || $warehouse->getId() === null) {
                continue;
            }

            $newApproved = $approvedSums[$product->getId() . '|' . $warehouse->getId()] ?? 0;
            if ($inventory->getApprovedQuantity() === $newApproved) {
                continue;
            }

            $this->bucketAuditLogger->logDiscrepancy(
                $product,
                $warehouse,
                InvoiceInventoryReservation::BUCKET_APPROVED,
                $inventory->getApprovedQuantity(),
                $newApproved,
                'product_import_approved_recalc',
            );

            $inventory->setApprovedQuantity($newApproved)->touch();
            $this->entityManager->persist($inventory);
            $touched++;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Approved inventory recalibration complete. %d row(s) corrected.', $touched));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, Warehouse> $warehousesByLowerRegionName
     * @return array<string, int> "productId|warehouseId" => summed effective (baseline-subtracted) approved quantity
     */
    private function approvedSums(array $warehousesByLowerRegionName): array
    {
        $invoices = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.status IN (:statuses)')
            ->setParameter('statuses', InvoiceInventoryBucketResolver::approvedStatuses())
            ->getQuery()
            ->getResult();

        // Baselines are keyed per (invoice, product, warehouse) — read straight from the ledger,
        // since that's specifically where a product import stamps them (see
        // ProductImportService::applyInventory()).
        $syncedByInvoiceProductWarehouse = [];
        foreach ($this->entityManager->getRepository(InvoiceInventoryReservation::class)->findBy(['bucket' => InvoiceInventoryReservation::BUCKET_APPROVED]) as $reservation) {
            $invoice = $reservation->getInvoice();
            $product = $reservation->getProduct();
            $warehouse = $reservation->getWarehouse();
            if ($invoice->getId() === null || $product->getId() === null || $warehouse->getId() === null) {
                continue;
            }
            $syncedByInvoiceProductWarehouse[$invoice->getId() . '|' . $product->getId() . '|' . $warehouse->getId()] = $reservation->getSyncedQuantity();
        }

        $sums = [];

        foreach ($invoices as $invoice) {
            if (!$invoice instanceof Invoice || $invoice->getId() === null) {
                continue;
            }

            if (InvoiceInventoryBucketResolver::bucketForStatus($invoice->getStatus()) !== InvoiceInventoryReservation::BUCKET_APPROVED) {
                continue;
            }

            /** @var array<string, int> $rawByProductWarehouse */
            $rawByProductWarehouse = [];
            foreach ($invoice->getLines() as $line) {
                $product = $line->getProduct();
                if (!$product instanceof ProductCore || $product->getId() === null) {
                    continue;
                }

                $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse($line->getLocation(), $invoice->getFulfillmentRegion(), $warehousesByLowerRegionName);
                if (!$warehouse instanceof Warehouse || $warehouse->getId() === null) {
                    continue;
                }

                $qty = (int) round((float) $line->getQuantity());
                if ($qty <= 0) {
                    continue;
                }

                $key = $product->getId() . '|' . $warehouse->getId();
                $rawByProductWarehouse[$key] = ($rawByProductWarehouse[$key] ?? 0) + $qty;
            }

            foreach ($rawByProductWarehouse as $productWarehouseKey => $rawQty) {
                $synced = $syncedByInvoiceProductWarehouse[$invoice->getId() . '|' . $productWarehouseKey] ?? 0;
                $effective = max(0, $rawQty - $synced);
                if ($effective <= 0) {
                    continue;
                }

                $sums[$productWarehouseKey] = ($sums[$productWarehouseKey] ?? 0) + $effective;
            }
        }

        return $sums;
    }
}
