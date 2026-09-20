<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Command;

use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
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
use WarehouseOpsBundle\Repository\TransferOrderLineRepository;

/**
 * The transfer documents checked against the stock they claim to have moved (#587).
 *
 * ## The check that stopped existing
 *
 * Before #584, `product_inventory.transfer_out_quantity` was a cached sum of the `in_transit`
 * detail rows. Bucket and rows had one source, so they could not disagree and there was nothing to
 * check. #584 fixed a real bug by making the pair cumulative and document-derived —
 *
 *     transfer_out(product, W) = SUM(transfer_order_line.quantity_dispatched)  over transfers FROM W
 *     transfer_in (product, W) = SUM(transfer_order_line.quantity_received)    over transfers TO   W
 *
 * — and in doing so left the bucket coming from the DOCUMENT while the `in_transit` rows come from
 * the MOVEMENTS, with nothing comparing them. A hand-edited `transfer_order_line`, or a detail row
 * adjusted behind the document's back, drifts silently while availability stays plausible.
 *
 * ## Three comparisons, per (product, warehouse) any transfer has touched
 *
 * | reported as    | one side                                        | other side                             |
 * |----------------|-------------------------------------------------|----------------------------------------|
 * | `transfer_out` | `product_inventory.transfer_out_quantity`       | `SUM(quantity_dispatched)` from W      |
 * | `transfer_in`  | `product_inventory.transfer_in_quantity`        | `SUM(quantity_received)` into W        |
 * | `in_transit`   | `SUM(dispatched − received)` per line, on transfers FROM W | `SUM(inventory_detail.quantity)` in_transit at W |
 *
 * The first two are the cached values availability actually reads, checked against the document
 * they are defined from. The third is the one nothing else can do: the document's opinion of what
 * is still on a truck, against the rows that say where those units physically are.
 *
 * ## The cumulative column is not a per-dispatch one
 *
 * `transfer_out_quantity` is a running total of everything that has ever left this warehouse on a
 * transfer, not the size of the last dispatch. A dispatch of 12 against a bucket reading 4 leaves
 * it reading 16, and 16 is what this command expects to find — subtracting the 12 anywhere would
 * report drift on a warehouse that is perfectly correct. It is the single easiest thing to get
 * wrong here, so the check never does arithmetic on a delta: it compares whole sums to whole sums.
 *
 * The same fact is why a shortfall is NOT drift. Dispatched 12, received 10 leaves `transfer_out`
 * 12 at the source, `transfer_in` 10 at the destination, and 2 units still standing on the source's
 * `in_transit` row. All three comparisons balance. The gap between 12 and 10 is stock lost in
 * transit, which the document is supposed to keep saying until somebody writes it off.
 *
 * ## Detection only — this command never corrects anything
 *
 * Its siblings that recompute a bucket write the correction as they go, because a bucket is a cache
 * of a document and recomputing it is always right. This one has no such mandate and deliberately
 * no flag to grant it one. Two of its three comparisons pit a document against physical rows, and
 * when those disagree the machine cannot tell which is the record of reality — overwriting either
 * destroys the evidence of whatever caused it. A finding here is for a human, who resolves it on
 * the transfer document or on the Inventory Depth adjustment screen, where the correction carries a
 * reason.
 *
 * Findings land in `inventory_reconciliation_discrepancy` through core's InventoryBucketAuditLogger
 * — the same table and the same admin email the other four recalc commands use — and are listed at
 * /admin/bundles/warehouse-ops/discrepancies.
 *
 * Run it alongside the hourly bucket recalcs:
 *
 *   10 * * * * cd /path/to/app && php bin/console app:warehouse-ops:transfer-check >> /var/log/transfer-check.log 2>&1
 */
#[AsCommand(
    name: 'app:warehouse-ops:transfer-check',
    description: 'Reports where transfer documents, transfer buckets and in-transit rows disagree. Never corrects.',
)]
final class TransferDriftCheckCommand extends Command
{
    public const SOURCE = 'transfer_drift_check';

    /** The two cached columns, named after themselves so a discrepancy row says which one drifted. */
    public const BUCKET_TRANSFER_OUT = 'transfer_out';
    public const BUCKET_TRANSFER_IN = 'transfer_in';

    /**
     * Not a bucket at all: nothing caches in-flight units since #584, and nothing may. It is the
     * document's in-flight figure against the `in_transit` rows, and it takes the name of the
     * detail status it is about, the way InventoryDetailRecalcCommand's pseudo-bucket takes the
     * name of the column it is about. `bucket` is VARCHAR(20) with no constraint, so this is
     * schema-safe.
     */
    public const BUCKET_IN_TRANSIT = 'in_transit';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransferOrderLineRepository $transferLines,
        private readonly InventoryDetailRepository $details,
        private readonly InventoryBucketAuditLogger $bucketAuditLogger,
        private readonly BundleStatusRepository $bundleStatuses,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // With the bundle Inactive, BundleBucketAvailabilityGate EXCLUDES both transfer terms from
        // availability rather than zeroing them (#584). Every column this command compares is
        // therefore reaching nobody: no screen shows it, no sale is withheld or credited by it, and
        // no new transfer can be raised to change it. Reporting drift on it would email admins
        // hourly about a feature the instance has switched off, and each mail would be about a
        // number that currently affects nothing.
        //
        // Nothing is zeroed, cleared or "tidied" on the way past. The columns and the in-transit
        // rows sit exactly where they are, so switching the bundle back on resumes the check with
        // its findings intact — including any drift that happened while it was off.
        if (!$this->bundleStatuses->isActive('WarehouseOpsBundle')) {
            $io->note(
                'Warehouse Operations is Inactive, so the transfer terms are excluded from availability and '
                . 'no transfer can be raised. Nothing checked and nothing changed; switch the bundle on to '
                . 'resume the check.'
            );

            return Command::SUCCESS;
        }

        $pairs = $this->transferLines->pairsTouchedByTransfers();

        if ($pairs === []) {
            $io->success('No transfer has ever named a product, so there is nothing to check.');

            return Command::SUCCESS;
        }

        $checked = 0;
        $drifted = [];

        foreach ($pairs as $pair) {
            $product = $this->em->find(ProductCore::class, $pair['productId']);
            $warehouse = $this->em->find(Warehouse::class, $pair['warehouseId']);

            if (!$product instanceof ProductCore || !$warehouse instanceof Warehouse) {
                continue;
            }

            $checked++;

            foreach ($this->comparisonsFor($product, $warehouse) as $comparison) {
                [$bucket, $cached, $recomputed] = $comparison;

                // Compared as decimals, never as strings: the two sides come from different places
                // — a bucket column and a SUM over document lines — and one may arrive as
                // `'12.0000'` and the other as `'12'`. `===` on those calls a settled pair drift
                // and files a discrepancy row saying the ledger has broken when nothing has moved.
                if (QuantityScale::compare($cached, $recomputed) === 0) {
                    continue;
                }

                $display = new DisplayNumber();
                $drifted[] = [
                    $product->getSku() ?: ('#' . ($product->getId() ?? '?')),
                    $warehouse->getName(),
                    $bucket,
                    // Through DisplayNumber, so the table reads "12" and not "12.0000" — and so the
                    // two columns beside each other are spelt the same way whichever side they came
                    // from.
                    $display->qty($cached),
                    $display->qty($recomputed),
                    $display->qty(QuantityScale::sub($recomputed, $cached)),
                ];

                // No-ops when the two agree, which is why the guard above is about the console
                // table rather than about whether to call this.
                $this->bucketAuditLogger->logDiscrepancy($product, $warehouse, $bucket, $cached, $recomputed, self::SOURCE);
            }
        }

        // The discrepancy rows are the ONLY thing this flush writes. Nothing above sets a quantity,
        // a bucket or a detail row.
        $this->em->flush();

        if ($drifted === []) {
            $io->success(sprintf(
                '%d product/warehouse pair(s) touched by a transfer checked; documents, buckets and in-transit rows all agree.',
                $checked,
            ));

            return Command::SUCCESS;
        }

        $io->table(['Product', 'Warehouse', 'Check', 'Cached / documents', 'Source of truth', 'Difference'], $drifted);

        // Said plainly, because the first run on a real instance that reads as a disaster is a run
        // that gets ignored. Two of these are ordinary history rather than corruption, and an
        // operator who cannot tell them apart from the console output will treat all of them as
        // noise.
        $io->note([
            'Not every row here is corruption:',
            '• in_transit — units lost in transit and then WRITTEN OFF at the source leave the document still '
                . 'saying they are in flight while the rows are gone. That gap is permanent and correct.',
            '• transfer_out / transfer_in are CUMULATIVE (#584): everything that has ever left or arrived on a '
                . 'transfer, not what is on a truck now. A dispatch of 12 takes the bucket from 4 to 16, and 16 '
                . 'is right. A re-import that rebaselines `quantity` after transfers have happened does not move '
                . 'these columns and cannot cause a row here — it shows up in app:inventory-depth:detail-check '
                . 'instead.',
            'What IS corruption: a bucket that disagrees with its own document, or in-transit rows that no '
                . 'dispatch accounts for.',
        ]);

        $io->warning(sprintf(
            '%d disagreement(s) across %d pair(s). Logged to inventory_reconciliation_discrepancy and emailed; '
            . 'NOTHING corrected — review each at /admin/bundles/warehouse-ops/discrepancies and resolve it on '
            . 'the transfer document or through Stock Adjustment, so the correction carries a reason.',
            \count($drifted),
            $checked,
        ));

        // Still SUCCESS. A drift is the finding this command exists to surface, not a failure of the
        // command, and a non-zero exit would make the hourly cron mail its own output on top of the
        // notification the finding already sent.
        return Command::SUCCESS;
    }

    /**
     * The three comparisons for one pair, as [bucket, cached, recomputed] triples.
     *
     * Whole sums against whole sums, never a delta against a column — see the note on the class
     * about the cumulative pair. `transfer_out` and `transfer_in` are computed for every pair even
     * though one of them is always zero for a warehouse that is only ever a destination: a non-zero
     * `transfer_out_quantity` at a warehouse that has never dispatched anything is precisely the
     * hand-edited or half-written row worth finding, and skipping the comparison would be assuming
     * the answer.
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function comparisonsFor(ProductCore $product, Warehouse $warehouse): array
    {
        $dispatched = $this->transferLines->dispatchedFromTotal($product, $warehouse);
        $received = $this->transferLines->receivedIntoTotal($product, $warehouse);

        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $warehouse,
        ]);

        // A missing stock row reads as zeros rather than being skipped. TransferOrderService creates
        // one at both ends of every transfer, so its absence beside a non-zero document sum is
        // itself the finding — the row was deleted, or the transfer was written without the service.
        $cachedOut = $row instanceof ProductInventory ? $row->getTransferOutQuantity() : 0;
        $cachedIn = $row instanceof ProductInventory ? $row->getTransferInQuantity() : 0;

        return [
            [self::BUCKET_TRANSFER_OUT, $cachedOut, $dispatched],
            [self::BUCKET_TRANSFER_IN, $cachedIn, $received],
            // In flight per the DOCUMENTS against in flight per the ROWS.
            //
            // inFlightFromTotal() and NOT `$dispatched - $received`: those two sums are different
            // sides of different documents — what left W, and what arrived at W on somebody else's
            // transfer — so subtracting one from the other would report drift at both ends of every
            // pair of transfers running in opposite directions along one route. The subtraction has
            // to happen between the two columns of the same LINE.
            //
            // Which side is "cached": the document, by the same convention
            // InventoryDetailRecalcCommand uses — the claim goes in `cached_quantity` and what the
            // stock rows actually hold goes in `recomputed_quantity`.
            [
                self::BUCKET_IN_TRANSIT,
                $this->transferLines->inFlightFromTotal($product, $warehouse),
                $this->details->inTransitTotal($product, $warehouse),
            ],
        ];
    }
}
