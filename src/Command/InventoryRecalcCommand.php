<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\QuantityScale;
use App\Entity\Invoice;
use App\Entity\InvoiceInventoryReservation;
use App\Entity\OrderInventoryReservation;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use App\Service\AuditLogger;
use App\Service\Inventory\InventoryBucketAuditLogger;
use App\Service\Inventory\InventoryOperationContext;
use App\Service\Inventory\InvoiceInventoryBucketResolver;
use App\Service\Inventory\InvoiceReservationSubject;
use App\Service\Inventory\OrderInventoryBucketResolver;
use App\Service\Inventory\SalesHoldSource;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * InventoryReconciliationSubscriber keeps ProductInventory's buckets in sync in the common case, but
 * is inherently vulnerable to drift — a crashed request mid-flush, a bug, a direct DB edit. This
 * command recomputes directly from source of truth (order and invoice statuses and lines) and
 * overwrites whatever's currently stored, logging + emailing every discrepancy found (a mismatch
 * here always means a bug in the incremental path). Meant to run hourly via the server's own crontab
 * (this app has no Symfony Scheduler bundle):
 *
 *   0 * * * * cd /path/to/app && php bin/console app:inventory-recalc >> /var/log/inventory-recalc.log 2>&1
 *
 * ## Which buckets this owns
 *
 * Sales Hold, Backordered and Pending — the three whose source of truth is a core document. Sales
 * Hold and Backordered are the two halves of every approved-or-later order's UNINVOICED quantity,
 * split by what the warehouse can actually send (#548); Pending is what every `Pending` invoice
 * bills, less anything it bills that its order line has backordered. That they are recomputed here
 * in one pass, from two different source tables, is the practical payoff of them being separate
 * buckets: a drift is attributable to the side that caused it, where one shared bucket fed by two
 * entities could only ever say "something is off by four".
 *
 * Recomputing Backordered is emphatically not RELEASING a backorder. For a manual-mode SKU, stock
 * sitting next to an open backorder is the expected state — nobody has released it yet — and this
 * command must never hand it out. It recomputes a cache of what the order lines say and nothing
 * else; only BackorderReleaseService moves a unit between the two, and this never calls it.
 *
 * Deliberately does NOT touch Cart Hold or Approved — Cart Hold's own recalc lives in CartHoldBundle
 * (CartHoldRecalcCommand) and Approved's in Number1ProductImportBundle
 * (ApprovedInventoryRecalcCommand), since each bundle is the one that owns/resets its own bucket and
 * therefore the one responsible for keeping it correct. Core has zero compile-time reference to
 * either bundle as a result.
 */
#[AsCommand(
    name: 'app:inventory-recalc',
    description: 'Recomputes the Sales Hold, Backordered and Pending inventory buckets from source of truth, correcting any drift. Run hourly via crontab.',
)]
final class InventoryRecalcCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly InventoryBucketAuditLogger $bucketAuditLogger,
        private readonly InventoryOperationContext $operations,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        /** @var iterable<SalesHoldSource> */
        #[AutowireIterator(SalesHoldSource::class)]
        private readonly iterable $salesHoldSources = [],
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

        // Documents name a REGION and stock lives in a WAREHOUSE, so a line's region name has to
        // be resolved to the warehouse serving it before anything can be summed (#546).
        $warehousesByLowerRegionName = $this->warehouses->warehousesByLowerRegionName();

        /** @var array<int, Warehouse> $warehousesById */
        $warehousesById = [];
        foreach ($this->entityManager->getRepository(Warehouse::class)->findAll() as $warehouse) {
            if ($warehouse->getId() !== null) {
                $warehousesById[$warehouse->getId()] = $warehouse;
            }
        }

        [$salesHoldSums, $backorderedSums] = $this->orderHoldSums($warehousesByLowerRegionName);
        $pendingSums = $this->pendingSums($warehousesByLowerRegionName);

        // Documents that are not sales orders but hold `sales_hold` too (SalesHoldSource), or this
        // recount would wipe their holds every hour.
        foreach ($this->salesHoldSources as $source) {
            foreach ($source->salesHoldSums() as $compositeKey => $quantity) {
                $salesHoldSums[$compositeKey] = QuantityScale::add($salesHoldSums[$compositeKey] ?? '0', $quantity);
            }
        }

        /** @var array<string, ProductInventory> $existingByKey */
        $existingByKey = [];
        foreach ($this->entityManager->getRepository(ProductInventory::class)->findAll() as $inventory) {
            $product = $inventory->getProduct();
            $warehouse = $inventory->getWarehouse();
            if ($product->getId() === null || !$warehouse instanceof Warehouse || $warehouse->getId() === null) {
                continue;
            }
            $existingByKey[$product->getId() . '|' . $warehouse->getId()] = $inventory;
        }

        $allKeys = array_unique(array_merge(
            array_keys($existingByKey),
            array_keys($salesHoldSums),
            array_keys($backorderedSums),
            array_keys($pendingSums),
        ));

        $touched = 0;
        foreach ($allKeys as $compositeKey) {
            [$productId, $warehouseId] = array_map('intval', explode('|', $compositeKey));

            $inventory = $existingByKey[$compositeKey] ?? null;
            if ($inventory === null) {
                $product = $this->entityManager->find(ProductCore::class, $productId);
                $warehouse = $warehousesById[$warehouseId] ?? null;
                if (!$product instanceof ProductCore || !$warehouse instanceof Warehouse) {
                    continue;
                }
                $inventory = (new ProductInventory())->setProduct($product)->setWarehouse($warehouse);
            }

            $newSalesHold = QuantityScale::canonical($salesHoldSums[$compositeKey] ?? 0);
            $newBackordered = QuantityScale::canonical($backorderedSums[$compositeKey] ?? 0);
            $newPending = QuantityScale::canonical($pendingSums[$compositeKey] ?? 0);

            $isExisting = $inventory->getId() !== null;
            if ($isExisting
                && QuantityScale::compare($inventory->getSalesHoldQuantity(), $newSalesHold) === 0
                && QuantityScale::compare($inventory->getBackorderedQuantity(), $newBackordered) === 0
                && QuantityScale::compare($inventory->getPendingQuantity(), $newPending) === 0
            ) {
                continue;
            }

            // One discrepancy row per bucket that actually disagrees, never one covering both: the
            // whole point of the buckets being separate is that a drift names the side it came from.
            if ($isExisting) {
                $this->bucketAuditLogger->logDiscrepancy(
                    $inventory->getProduct(),
                    $inventory->getWarehouse(),
                    OrderInventoryReservation::BUCKET_SALES_HOLD,
                    $inventory->getSalesHoldQuantity(),
                    $newSalesHold,
                    'inventory_recalc_cron',
                );
                $this->bucketAuditLogger->logDiscrepancy(
                    $inventory->getProduct(),
                    $inventory->getWarehouse(),
                    OrderInventoryReservation::BUCKET_BACKORDERED,
                    $inventory->getBackorderedQuantity(),
                    $newBackordered,
                    'inventory_recalc_cron',
                );
                $this->bucketAuditLogger->logDiscrepancy(
                    $inventory->getProduct(),
                    $inventory->getWarehouse(),
                    InvoiceInventoryReservation::BUCKET_PENDING,
                    $inventory->getPendingQuantity(),
                    $newPending,
                    'inventory_recalc_cron',
                );
            }

            $inventory->setSalesHoldQuantity($newSalesHold);
            $inventory->setBackorderedQuantity($newBackordered);
            $inventory->setPendingQuantity($newPending);
            $inventory->touch();
            $this->entityManager->persist($inventory);
            $touched++;
        }

        $this->entityManager->flush();

        if ($touched > 0) {
            $this->auditLogger->log(
                'System',
                'ProductInventory',
                null,
                'updated',
                sprintf('Inventory recalibration ran, recomputing the sales hold, backordered and pending buckets from source of truth (%d row(s) corrected).', $touched),
            );
        }

        $io->success(sprintf('Inventory recalibration complete. %d ProductInventory row(s) corrected.', $touched));

        return Command::SUCCESS;
    }

    /**
     * The order side's two buckets from source of truth, in one walk of the orders.
     *
     * Sales Hold is each approved-or-later order's uninvoiced remainder MINUS what the line has
     * backordered; Backordered is that backordered quantity. Split exactly as
     * SalesOrderReservationSubject and SalesOrderBackorderReservationSubject split it, which is the
     * point: the two answers have to come from the same arithmetic or a recalc would "correct" a
     * correct cache into a wrong one every hour.
     *
     * Computed together rather than in two passes because they are one subtraction over one set of
     * lines, and two passes over the same orders is two chances for the split to disagree with
     * itself.
     *
     * The remainder comes from SalesOrder::uninvoicedQuantityFor(), the same derivation the
     * incremental path reads, so a disagreement between the two can only ever be drift in the cache
     * — never two different opinions about what "uninvoiced" means.
     *
     * ## Recomputing the backorder bucket is not releasing anything
     *
     * Worth saying because it looks close to it: for a manual-mode SKU, stock sitting next to an
     * open backorder is the expected state — nobody has released it yet — and this must never
     * "fix" that by handing the stock out. It does not. It recomputes a CACHE of what the lines
     * say, exactly as it does for Sales Hold and Pending; only BackorderReleaseService moves a unit
     * from one bucket to the other, and this command never calls it.
     *
     * @param array<string, Warehouse> $warehousesByLowerRegionName
     * @return array{0: array<string, string>, 1: array<string, string>} sales-hold sums, then backordered sums, as decimal strings
     */
    private function orderHoldSums(array $warehousesByLowerRegionName): array
    {
        $orders = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(SalesOrder::class, 'o')
            ->where('LOWER(o.status) IN (:statuses)')
            ->setParameter('statuses', OrderInventoryBucketResolver::salesHoldStatuses())
            ->getQuery()
            ->getResult();

        $salesHoldSums = [];
        $backorderedSums = [];

        foreach ($orders as $order) {
            if (!$order instanceof SalesOrder) {
                continue;
            }

            if (OrderInventoryBucketResolver::bucketForStatus($order->getStatus()) !== OrderInventoryReservation::BUCKET_SALES_HOLD) {
                continue;
            }

            foreach ($order->getLines() as $line) {
                $product = $line->getProduct();
                if (!$product instanceof ProductCore || $product->getId() === null) {
                    continue;
                }

                $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse($line->getLocation(), $order->getFulfillmentRegion(), $warehousesByLowerRegionName);
                if (!$warehouse instanceof Warehouse || $warehouse->getId() === null) {
                    continue;
                }

                $key = $product->getId() . '|' . $warehouse->getId();

                $backordered = $line->getBackorderedUnits();
                if (QuantityScale::compare($backordered, 0) > 0) {
                    $backorderedSums[$key] = QuantityScale::add($backorderedSums[$key] ?? 0, $backordered);
                }

                // An exact decimal string: getBackorderedUnits() already is one, and the
                // (int) round() this replaces made a 2.5 remainder hold 3 and a 0.4 one hold none.
                $qty = QuantityScale::sub($order->uninvoicedQuantityFor($line), $backordered);
                if (QuantityScale::compare($qty, 0) <= 0) {
                    continue;
                }

                $salesHoldSums[$key] = QuantityScale::add($salesHoldSums[$key] ?? 0, $qty);
            }
        }

        return [$salesHoldSums, $backorderedSums];
    }

    /**
     * Pending from source of truth: what every issued, unfulfilled invoice bills.
     *
     * @param array<string, Warehouse> $warehousesByLowerRegionName
     * @return array<string, string> "productId|warehouseId" => summed pending quantity, as a decimal string
     */
    private function pendingSums(array $warehousesByLowerRegionName): array
    {
        $invoices = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.status IN (:statuses)')
            ->setParameter('statuses', InvoiceInventoryBucketResolver::pendingStatuses())
            ->getQuery()
            ->getResult();

        $sums = [];

        foreach ($invoices as $invoice) {
            if (!$invoice instanceof Invoice) {
                continue;
            }

            if (InvoiceInventoryBucketResolver::bucketForStatus($invoice->getStatus()) !== InvoiceInventoryReservation::BUCKET_PENDING) {
                continue;
            }

            foreach ($invoice->getLines() as $line) {
                $product = $line->getProduct();
                if (!$product instanceof ProductCore || $product->getId() === null) {
                    continue;
                }

                $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse($line->getLocation(), $invoice->getFulfillmentRegion(), $warehousesByLowerRegionName);
                if (!$warehouse instanceof Warehouse || $warehouse->getId() === null) {
                    continue;
                }

                // The same netting the incremental path applies, through the same implementation:
                // an invoice line billing units its order line has backordered holds only the part
                // with stock behind it, so the promise is not held here as well as in the order's
                // `backordered` bucket (#548). Identical to the line's own quantity for every line
                // with nothing backordered behind it.
                $qty = InvoiceReservationSubject::stockedQuantityFor($line);
                if (QuantityScale::compare($qty, 0) <= 0) {
                    continue;
                }

                $key = $product->getId() . '|' . $warehouse->getId();
                $sums[$key] = QuantityScale::add($sums[$key] ?? 0, $qty);
            }
        }

        return $sums;
    }
}
