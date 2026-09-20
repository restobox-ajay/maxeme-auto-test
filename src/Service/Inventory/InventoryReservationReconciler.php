<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\InventoryReservation;
use App\Entity\InvoiceInventoryReservation;
use App\Entity\OrderInventoryReservation;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Event\InventoryBucketsReconciledEvent;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Keeps ProductInventory's hold buckets in sync with ONE document, by diffing what that document
 * should hold against a durable per-document ledger — so triggering this once after any mutation is
 * enough, and no bespoke increment/decrement code is needed per action (placed, approved, invoiced,
 * cancelled, edited, quote-converted all fall out of the same diff).
 *
 * ## One reconciler, two documents (#539 stage 3)
 *
 * Stage 3 gave the app a second reservation entity, and this logic is the reason it did not also
 * give it a second copy of this file. Everything that differs between a sales order holding
 * `sales_hold` on its uninvoiced quantity and an invoice holding `pending`/`approved` on what it
 * bills is behind InventoryReservationSubject — which lines to read, which bucket resolver to ask,
 * which reservation class to write. Everything below is the part that is identical, and it is
 * identical because it is arithmetic about a ledger, not about a document.
 *
 * Two divergent copies of it is precisely how a bucket ends up disagreeing with its own ledger:
 * both copies keep looking correct in isolation, and the drift only ever surfaces as a number that
 * is slightly wrong forever.
 *
 * Not called directly from controllers — see InventoryReconciliationSubscriber, a Doctrine
 * onFlush/postFlush listener that triggers reconcile() for every SalesOrder/SalesOrderLine/Invoice/
 * InvoiceLine change regardless of which code path caused it.
 */
final class InventoryReservationReconciler
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly InventoryOperationContext $operations,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly InventoryModeResolver $inventoryModes,
    ) {
    }

    /**
     * The whole pass runs inside ONE ambient operation named by the subject (#582).
     *
     * That name — 'order_reconciled', 'invoice_reconciled', 'order_backorder_reconciled' — used to
     * be handed to InventoryBucketAuditLogger::logChange() beside each of the three bucket writes
     * below. Those calls are gone: App\EventSubscriber\InventoryBucketChangeLogger writes the log
     * rows off the changeset, and the only thing it cannot see is which document caused them. So
     * the subject says so once, here, and every bucket this pass moves carries it.
     *
     * The operation deliberately spans the flush() at the bottom rather than each write, because
     * the changeset does not exist until then — see InventoryOperationContext, "THE ONE RULE". It
     * also carries NO movement group, and that is the correct record rather than a gap: a document
     * reserving stock has not moved any, so `inventory_bucket_change_log.group_id` is NULL for
     * every row this produces, by construction.
     */
    public function reconcile(InventoryReservationSubject $subject, EntityManagerInterface $entityManager): void
    {
        $this->operations->run(
            $subject->changeAction(),
            fn (): null => $this->reconcileWithinOperation($subject, $entityManager),
        );
    }

    private function reconcileWithinOperation(InventoryReservationSubject $subject, EntityManagerInterface $entityManager): null
    {
        $targetBucket = $subject->targetBucket();

        // A document names a REGION; stock lives in a WAREHOUSE. One map, built once, rather than
        // a lookup per line (#546).
        $warehousesByLowerRegionName = $this->warehouses->warehousesByLowerRegionName();

        $targetByKey = $this->targetQuantities($subject, $targetBucket, $warehousesByLowerRegionName);

        /** @var array<string, InventoryReservation> $previousByKey */
        $previousByKey = [];
        foreach ($subject->existingReservations($entityManager) as $reservation) {
            $key = self::reservationKey(
                $reservation->getProduct()->getId(),
                $reservation->getWarehouse()->getId(),
                $reservation->getLotId(),
                $reservation->getSerial(),
            );
            $previousByKey[$key] = $reservation;
        }

        $changes = [];
        $keys = array_unique(array_merge(array_keys($targetByKey), array_keys($previousByKey)));

        foreach ($keys as $key) {
            $target = $targetByKey[$key] ?? null;
            $previous = $previousByKey[$key] ?? null;

            // Every quantity on this path is an exact decimal string from here down, compared and
            // subtracted via QuantityScale's bcmath helpers — `0.1 + 0.2 !== 0.3` in binary is
            // exactly how a bucket would drift a ten-thousandth per edit and never settle. The
            // subjects hand their held lines over already in this shape; see
            // InventoryReservationSubject::heldLines().
            $previousBucket = $previous?->getBucket();
            $previousQuantity = QuantityScale::canonical($previous?->getQuantity() ?? 0);
            $previousSyncedQuantity = QuantityScale::canonical($previous?->getSyncedQuantity() ?? 0);
            $newQuantity = $target['quantity'] ?? QuantityScale::canonical(0);

            if ($previousBucket === $targetBucket && QuantityScale::compare($previousQuantity, $newQuantity) === 0) {
                continue; // no-op
            }

            $product = $target !== null ? $target['product'] : $previous->getProduct();
            $warehouse = $target !== null ? $target['warehouse'] : $previous->getWarehouse();
            $lot = $target !== null ? $target['lot'] : $previous->getLotId();
            $serial = $target !== null ? $target['serial'] : $previous->getSerial();

            // A synced-quantity baseline (stamped by a product import's approved/shipped reset, see
            // ProductImportService::stampApprovedShippedReservationBaselines()) only carries forward while this
            // reservation stays within the "released by import/recount only" group of buckets —
            // approved and shipped are the same claim relabeled (2026-09-15), so Processing ->
            // Completed carries the baseline exactly like an edit that leaves the bucket unchanged
            // would. A fresh transition INTO that group from anywhere else starts with no baseline,
            // since nothing has recounted against it yet.
            $syncedQuantityCarried = ($this->releasedByRecountOnly($previousBucket) && $this->releasedByRecountOnly($targetBucket))
                ? $previousSyncedQuantity
                : QuantityScale::canonical(0);

            $previousEffective = $this->effectiveQuantity($previousBucket, $previousQuantity, $previousSyncedQuantity);
            $newEffective = $this->effectiveQuantity($targetBucket, $newQuantity, $syncedQuantityCarried);

            $inventory = $entityManager->getRepository(ProductInventory::class)->findOneBy([
                'product' => $product,
                'warehouse' => $warehouse,
            ]) ?? (new ProductInventory())->setProduct($product)->setWarehouse($warehouse);

            if ($previousBucket === $targetBucket) {
                // Same bucket (or both null — already filtered out above): one net cache delta.
                if (QuantityScale::compare($previousEffective, $newEffective) !== 0) {
                    $this->applyBucketDelta($inventory, $targetBucket, QuantityScale::sub($newEffective, $previousEffective));
                }
            } else {
                // Bucket changed (including to/from no reservation at all): two buckets move, and
                // they stay two separate deltas rather than being conflated into one. The log rows
                // follow automatically, one per column that actually changed, because that is what
                // the changeset says happened.
                if ($previousBucket !== null && QuantityScale::compare($previousEffective, 0) > 0) {
                    $this->applyBucketDelta($inventory, $previousBucket, QuantityScale::sub(0, $previousEffective));
                }
                if ($targetBucket !== null && QuantityScale::compare($newEffective, 0) > 0) {
                    $this->applyBucketDelta($inventory, $targetBucket, $newEffective);
                }
            }

            $inventory->touch();
            $entityManager->persist($inventory);

            if ($targetBucket !== null && QuantityScale::compare($newQuantity, 0) > 0) {
                $reservation = $previous ?? $subject->newReservation($product, $warehouse);
                $reservation->setBucket($targetBucket)
                    ->setQuantity($newQuantity)
                    ->setSyncedQuantity($syncedQuantityCarried)
                    ->setLotId($lot)->setSerial($serial)->touch();
                $entityManager->persist($reservation);
            } elseif ($previous !== null) {
                $entityManager->remove($previous);
            }

            $changes[] = [
                'product' => $product,
                'warehouse' => $warehouse,
                'bucket' => $targetBucket,
                'previousQuantity' => $previousEffective,
                'newQuantity' => $newEffective,
            ];
        }

        if ($changes === []) {
            return null;
        }

        $entityManager->flush();

        $this->eventDispatcher->dispatch(new InventoryBucketsReconciledEvent($subject->document(), $changes));

        return null;
    }

    /**
     * What the document should hold, summed per product and warehouse.
     *
     * Empty when the document holds no bucket at all. It is ALSO legitimately empty when it holds a
     * bucket and every line's quantity is zero — a fully invoiced order's sales hold — and the two
     * are the same thing from here: nothing is held, so every existing ledger row for it is removed
     * below. That is why the bucket and the quantity are asked separately rather than one being
     * inferred from the other.
     *
     * @param array<string, Warehouse> $warehousesByLowerRegionName
     * @return array<string, array{product: ProductCore, warehouse: Warehouse, quantity: string, lot: ?int, serial: ?string}> quantity as a decimal string
     */
    private function targetQuantities(InventoryReservationSubject $subject, ?string $targetBucket, array $warehousesByLowerRegionName): array
    {
        if ($targetBucket === null) {
            return [];
        }

        $targetByKey = [];

        foreach ($subject->heldLines() as $line) {
            $product = $line['product'];

            $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse($line['location'], $subject->fallbackRegionName(), $warehousesByLowerRegionName);
            if (!$warehouse instanceof Warehouse) {
                $this->logger->warning('Inventory reconciliation: could not resolve a fulfillment region for a document line, skipping.', [
                    'document' => $subject->reference(),
                    'product' => $product->getSku(),
                    'regionName' => trim($line['location'] ?? '') ?: trim((string) $subject->fallbackRegionName()),
                ]);
                continue;
            }

            if (QuantityScale::compare($line['quantity'], 0) <= 0) {
                continue;
            }

            [$lot, $serial] = $this->heldLotAndSerial($product, $line['lot'] ?? null, $line['serial'] ?? null);

            $key = self::reservationKey($product->getId(), $warehouse->getId(), $lot, $serial);
            if (!isset($targetByKey[$key])) {
                $targetByKey[$key] = ['product' => $product, 'warehouse' => $warehouse, 'quantity' => QuantityScale::canonical(0), 'lot' => $lot, 'serial' => $serial];
            }
            $targetByKey[$key]['quantity'] = QuantityScale::add($targetByKey[$key]['quantity'], $line['quantity']);
        }

        return $targetByKey;
    }

    /**
     * Whether a line's raw lot/serial actually narrows this hold, or gets blanked back to "no
     * identity" (2026-09-14 lot/serial/expiry plan).
     *
     * The composite gate the plan calls for, in the one place every subject shares: a product must
     * BOTH have opted into lot or serial tracking on the way out (TrackingPolicy) AND actually carry
     * a dimensional ledger behind it (InventoryModeResolver::isDimensional()) — confirmed the two are
     * currently uncoupled, so a stray lot/serial value on a product that fails either check is
     * treated exactly as though the line named none, which is what keeps every product nobody has
     * opted fully into behaving exactly as it did before this widening.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function heldLotAndSerial(ProductCore $product, ?int $lot, ?string $serial): array
    {
        $policy = $product->getTrackingPolicy();
        $tracksLots = $policy instanceof TrackingPolicy && $policy->tracksLotsOutbound();
        $tracksSerials = $policy instanceof TrackingPolicy && $policy->tracksSerialsOutbound();

        if (!($tracksLots || $tracksSerials) || !$this->inventoryModes->isDimensional($product)) {
            return [null, null];
        }

        return [$tracksLots ? $lot : null, $tracksSerials ? $serial : null];
    }

    /** The one diff key every lookup below shares — product, warehouse, and now lot/serial. */
    private static function reservationKey(int $productId, int $warehouseId, ?int $lot, ?string $serial): string
    {
        return $productId . '|' . $warehouseId . '|' . ($lot ?? 0) . '|' . ($serial ?? '');
    }

    /** What a bucket/quantity pair actually contributes to ProductInventory, baseline included. */
    private function effectiveQuantity(?string $bucket, string $quantity, string $syncedQuantity): string
    {
        if (!$this->releasedByRecountOnly($bucket)) {
            return $quantity;
        }

        $net = QuantityScale::sub($quantity, $syncedQuantity);

        return QuantityScale::compare($net, 0) > 0 ? $net : QuantityScale::canonical(0);
    }

    /**
     * `approved` and `shipped` are the one pair of buckets a recount, not a document edit, releases
     * (2026-09-15) — see InvoiceInventoryReservation::BUCKET_SHIPPED. Named after the RULE rather
     * than the bucket names themselves, so a future bucket needing the same treatment has an obvious
     * place to join rather than a third bucket name added to an ad hoc `in_array()` at each call site.
     */
    private function releasedByRecountOnly(?string $bucket): bool
    {
        return $bucket === InvoiceInventoryReservation::BUCKET_APPROVED
            || $bucket === InvoiceInventoryReservation::BUCKET_SHIPPED;
    }

    /**
     * Every bucket a reservation may name has to appear here. A bucket nothing applies is silent —
     * the reservation row is written, the cached number never moves, and availability is wrong with
     * nothing to show for it — which is why this is a match with no default rather than an if/elseif
     * chain that falls off its end.
     */
    private function applyBucketDelta(ProductInventory $inventory, string $bucket, string $delta): void
    {
        match ($bucket) {
            OrderInventoryReservation::BUCKET_SALES_HOLD => $inventory->adjustSalesHold($delta),
            OrderInventoryReservation::BUCKET_BACKORDERED => $inventory->adjustBackordered($delta),
            InvoiceInventoryReservation::BUCKET_PENDING => $inventory->adjustPending($delta),
            InvoiceInventoryReservation::BUCKET_APPROVED => $inventory->adjustApproved($delta),
            InvoiceInventoryReservation::BUCKET_SHIPPED => $inventory->adjustShipped($delta),
            default => throw new \LogicException(sprintf('Unknown inventory bucket "%s".', $bucket)),
        };
    }
}
