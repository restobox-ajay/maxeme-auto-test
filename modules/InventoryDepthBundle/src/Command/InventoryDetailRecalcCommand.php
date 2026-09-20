<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Command;

use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\DisplayNumber;
use App\Service\Inventory\InventoryBucketAuditLogger;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The invariant, checked from outside the code that maintains it (#550).
 *
 *     product_inventory(product, warehouse).quantity + .received_quantity + .transfer_in_quantity
 *       − .transfer_out_quantity − .write_off_quantity − .quarantine_quantity
 *       − SUM(inventory_detail.quantity) WHERE status = 'sold'
 *       == SUM(inventory_detail.quantity) WHERE status = 'available'
 *
 * for every `dimensional` product, PLUS two narrower checks added by the simple-inventory bucket
 * parity plan (2026-09-15), now that `quarantine`/`write_off` are independently accumulated rather
 * than derived from these very sums:
 *
 *     product_inventory.quarantine_quantity == SUM(inventory_detail.quantity) WHERE status IN quarantineStatuses()
 *     product_inventory.write_off_quantity  == SUM(inventory_detail.quantity) WHERE status IN writeOffStatuses()
 *
 * The combined equation above could no longer catch either bucket drifting on its own — two buckets
 * off by the same amount in opposite directions cancel out there — which is exactly why an
 * independent figure needs an independent check, not a share of somebody else's.
 *
 * Lives in this bundle rather than as a third comparison inside
 * core's `app:inventory-recalc`, for the same reason CartHoldBundle and Number1ProductImportBundle
 * ship their own recalc commands: the bundle that owns a number is the one responsible for keeping
 * it correct, and core keeps zero compile-time reference to any of them. It reuses core's
 * InventoryBucketAuditLogger, so a drift found here lands in `inventory_reconciliation_discrepancy`
 * and emails admins through exactly the path a bucket drift does.
 *
 * ## Detection only — never auto-correct
 *
 * This is the one place it differs from its bucket siblings, and it is deliberate. A bucket is a
 * derived cache whose source of truth is a document, so recomputing it is always right. The physical
 * total is not derived from anything the computer knows: if `quantity` and the detail rows disagree,
 * one of them is a *record of reality* and the machine cannot tell which. Overwriting either would
 * destroy the evidence of whichever bug caused it. So this reports, and a human decides — with the
 * stock adjustment screen, which writes the correction as a real movement with a reason on it.
 *
 * Run alongside the hourly bucket recalc:
 *
 *   5 * * * * cd /path/to/app && php bin/console app:inventory-depth:detail-check >> /var/log/inventory-detail-check.log 2>&1
 */
#[AsCommand(
    name: 'app:inventory-depth:detail-check',
    description: 'Reports where a dimensional product\'s detail rows disagree with product_inventory (quantity + received). Never corrects.',
)]
final class InventoryDetailRecalcCommand extends Command
{
    /**
     * The `bucket` column on a discrepancy row names which claim drifted. A detail drift is not a
     * claim at all — it is the physical total itself — so it gets its own value rather than being
     * filed under one of the four. The column is VARCHAR(20) with no constraint, so this is
     * schema-safe; naming it after the column it is about keeps the row readable.
     */
    public const PSEUDO_BUCKET = 'quantity';

    public const SOURCE = 'inventory_detail_check';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryDetailRepository $details,
        private readonly InventoryBucketAuditLogger $bucketAuditLogger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<ProductCore> $products */
        $products = $this->entityManager->getRepository(ProductCore::class)
            ->findBy(['inventoryMode' => ProductCore::INVENTORY_MODE_DIMENSIONAL]);

        if ($products === []) {
            $io->success('No products are on dimensional inventory; nothing to check.');

            return Command::SUCCESS;
        }

        $checked = 0;
        $drifted = [];

        foreach ($products as $product) {
            /** @var list<ProductInventory> $rows */
            $rows = $this->entityManager->getRepository(ProductInventory::class)->findBy(['product' => $product]);

            foreach ($rows as $row) {
                $warehouse = $row->getWarehouse();
                if (!$warehouse instanceof Warehouse) {
                    continue;
                }

                $checked++;
                $recomputed = $this->details->availableTotal($product, $warehouse);

                // Against quantity + received, not quantity alone (#564). `quantity` is imported
                // from the client's own inventory system and this app no longer writes it; what the
                // movement layer maintains is `received`, the gap between their snapshot and what
                // is actually on the shelf. Comparing against `quantity` alone would report every
                // single receipt as drift.
                //
                // Since #581 the two sides no longer meet directly, because a departure from the
                // available pool is recorded by whichever bucket owns its destination instead of by
                // shrinking `received`. Every one of those has to be named here or it reads as
                // drift:
                //
                //     SUM(available detail)
                //         == quantity + received + transfer_in
                //            − transfer_out − write_off − quarantine
                //            − SUM(sold)
                //
                // `transfer_in` is #584's term and `transfer_out` no longer means what it did. The
                // pair are now cumulative and derived from `transfer_order_line` — everything that
                // has ever left this warehouse on a transfer, and everything that has ever arrived
                // on one — rather than a sum of the `in_transit` rows, which fell back to zero as
                // soon as the goods landed.
                //
                // The equation still balances at both ends, and it is worth walking, because
                // `in_transit` does NOT appear in it. Source warehouse, `quantity` 50, dispatches
                // 10 and 8 arrive: available detail 40, in-transit detail 2, transfer_out 10, and
                // 40 == 50 − 10. Destination: available detail 8, transfer_in 8, 8 == 0 + 8. The
                // two lost units are inside the source's withheld 10 and stay missing until
                // somebody writes them off, which is what the invariant should say about stock that
                // never turned up.
                //
                // WHAT IS NOT CHECKED FROM HERE, AND WHERE IT IS. While `transfer_out` was summed
                // from the `in_transit` rows, those rows could not disagree with the bucket. Now the
                // bucket comes from the document and the rows come from the movements, so a
                // `transfer_order_line` edited by hand, or an in-transit row adjusted behind the
                // document's back, can drift. It cannot be checked from here: this bundle has, and
                // should have, no compile-time reference to WarehouseOpsBundle's transfer tables,
                // and it is deliberately not smuggled in. #587 put the check in that bundle instead
                // — `app:warehouse-ops:transfer-check`, which compares both cached columns against
                // `transfer_order_line` and the in-transit rows against what the documents say is in
                // flight. Like this command, it reports and never corrects.
                //
                // `sold` is the odd term: it has no bucket, because the invoice that billed the
                // units holds them in `pending`/`approved`. Availability gets them off through that
                // hold, so nothing subtracts them from `received` — but they did leave the
                // available rows, so this equation still has to account for them.
                //
                // Without these terms the command reported drift on every product that had ever
                // been sold or written off, which is the loudest possible way to be wrong about
                // something that is working correctly.
                //
                // And since #572 this comparison finally has something to find. Both sides are now
                // maintained independently: `quantity` by the import, straight from the client's
                // file; `received` by StockMovementService, accumulating one movement's delta at a
                // time; the detail rows by the movements and the import's sentinel plug. While
                // `received` was written as `SUM(available detail) − quantity`, the left-hand side
                // below was the right-hand side rearranged, so the two could not disagree and this
                // command could only ever report success — a check that was true by construction.
                // Summed exactly, then rounded once at the end. Rounding both sides to the same
                // scale is also what lets them be compared as strings: `0` and `'0.0000'` are the
                // same figure and must not read as drift.
                $held = bcadd($row->getQuantity(), $row->getReceivedQuantity(), QuantityScale::MAX_DECIMALS);
                $held = bcadd($held, $row->getTransferInQuantity(), QuantityScale::MAX_DECIMALS);
                $held = bcsub($held, $row->getTransferOutQuantity(), QuantityScale::MAX_DECIMALS);
                $held = bcsub($held, $row->getWriteOffQuantity(), QuantityScale::MAX_DECIMALS);
                $held = bcadd($held, $row->getQuarantineQuantity(), QuantityScale::MAX_DECIMALS);
                $held = bcsub($held, $this->details->soldTotal($product, $warehouse), QuantityScale::MAX_DECIMALS);
                // Rounded to what this product's unit allows -- 'each' to 1, 'kg' to 0.001 --
                // falling back to the store setting when it has no unit.
                $held = QuantityScale::round($held, $product);
                $recomputed = QuantityScale::round($recomputed, $product);

                if ($held === $recomputed) {
                    continue;
                }

                $display = new DisplayNumber();
                $drifted[] = [
                    $product->getSku() ?: ('#' . ($product->getId() ?? '?')),
                    $warehouse->getName(),
                    sprintf('quantity+received: %s', $display->qty($held)),
                    sprintf('SUM(available): %s', $display->qty($recomputed)),
                    sprintf('%s', $display->qty(bcsub($recomputed, $held, QuantityScale::MAX_DECIMALS))),
                ];

                $this->bucketAuditLogger->logDiscrepancy(
                    $product,
                    $warehouse,
                    self::PSEUDO_BUCKET,
                    $held,
                    $recomputed,
                    self::SOURCE,
                );
            }

            foreach ($rows as $row) {
                $warehouse = $row->getWarehouse();
                if (!$warehouse instanceof Warehouse) {
                    continue;
                }

                // Simple-inventory bucket parity plan (2026-09-15): quarantine/write_off are now
                // independently accumulated by whatever action quarantines or writes off stock, the
                // same way `received` is accumulated by receiving — not recomputed from these very
                // sums, which is what made the equation above unable to say anything about either on
                // its own. These two checks are what that change was FOR: a real reconciliation
                // between two independently-maintained figures, narrower than the combined equation
                // above and therefore able to catch a case that equation would mask -- two buckets
                // drifting in opposite directions by the same amount cancels out there, but not here.
                $this->checkBucketAgainstDetail(
                    $product,
                    $warehouse,
                    'quarantine',
                    $row->getQuarantineQuantity(),
                    $this->details->quarantineTotal($product, $warehouse),
                    $drifted,
                );
                $this->checkBucketAgainstDetail(
                    $product,
                    $warehouse,
                    'write_off',
                    $row->getWriteOffQuantity(),
                    $this->details->writeOffTotal($product, $warehouse),
                    $drifted,
                );
            }
        }

        // The discrepancy rows are the only thing written. Nothing above touches a quantity.
        $this->entityManager->flush();

        return $this->report($io, $checked, $drifted);
    }

    /**
     * `quarantine`/`write_off` reported by their own real bucket name, not `self::PSEUDO_BUCKET` --
     * unlike the combined equation's `quantity + received` side, these two now ARE real,
     * independently-accumulated buckets (2026-09-15), so a drift here is exactly the same kind of
     * finding `InventoryBucketAuditLogger` already reports for any other bucket claim.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $drifted
     */
    private function checkBucketAgainstDetail(
        ProductCore $product,
        Warehouse $warehouse,
        string $bucket,
        string|int|float $held,
        string|int|float $recomputed,
        array &$drifted,
    ): void {
        if (QuantityScale::compare($held, $recomputed) === 0) {
            return;
        }

        $display = new DisplayNumber();
        $drifted[] = [
            $product->getSku() ?: ('#' . ($product->getId() ?? '?')),
            $warehouse->getName(),
            sprintf('%s: %s', $bucket, $display->qty($held)),
            sprintf('SUM(detail): %s', $display->qty($recomputed)),
            $display->qty(QuantityScale::sub($recomputed, $held)),
        ];

        $this->bucketAuditLogger->logDiscrepancy($product, $warehouse, $bucket, $held, $recomputed, self::SOURCE);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $drifted
     */
    private function report(SymfonyStyle $io, int $checked, array $drifted): int
    {
        if ($drifted === []) {
            $io->success(sprintf('%d dimensional stock row(s) checked; every one reconciles to its detail.', $checked));

            return Command::SUCCESS;
        }

        $io->table(['Product', 'Warehouse', 'Held', 'Recomputed from detail', 'Difference'], $drifted);
        $io->warning(sprintf(
            '%d of %d dimensional stock row(s) disagree with their detail. Logged and emailed; NOT corrected — '
            . 'resolve each one through Stock Adjustment so the correction carries a reason.',
            \count($drifted),
            $checked,
        ));

        // Still SUCCESS: a drift is a finding this command is supposed to surface, not a failure of
        // the command. A non-zero exit would make the hourly cron mail its own output on top of the
        // notification the finding already sent.
        return Command::SUCCESS;
    }
}
