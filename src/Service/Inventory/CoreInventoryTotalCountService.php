<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * The one place a fresh count of a non-dimensional product's stock gets written — `quantity`, any
 * of the other thirteen buckets a recount explicitly says to clear, and the one detail row behind
 * it (every dimension null), together.
 *
 * Before this, three call sites wrote `product_inventory.quantity` by hand, each its own copy of
 * "find or create the row, set the figure, persist, flush" — the import, the inline "Starting" box
 * on `/admin/inventory`, and the product form's per-region quantity fields. None of them left
 * anything behind in `inventory_detail`, so a product whose stock only ever arrived this way read
 * as holding zero the moment a transfer or an adjustment checked for it — a real, reproducible
 * refusal, not a hypothetical one.
 *
 * ## Why `$clearBuckets` is a list the caller states, never a guess this method makes
 *
 * A recount is an accounting exercise: it tells this app what is physically true right now, and
 * whether that truth already accounts for stock mid-transfer, quarantined, written off but not yet
 * pulled, held in a cart, reserved, or already committed to an invoice is information ONLY the
 * person running the count has. This method cannot infer it and must not guess it — so every
 * clearable bucket is named explicitly by the caller, one at a time, none bundled and none
 * defaulted. `received` and `approved` used to each hide this behind their own boolean, and
 * `approved` used to silently take `shipped` down with it; both were the same unstated assumption
 * this method now refuses to make on anyone's behalf.
 *
 * Its counterpart is {@see \InventoryDepthBundle\Movement\StockMovementService}: that answers "what
 * moved" (a relative delta, audited as a real movement, and never ambiguous — every call site names
 * its own bucket by construction, because the action performed IS the bucket). This answers "what's
 * the count now" (an absolute figure, not a delta). They cannot be the same method — every line
 * `StockMovementService::apply()` credits into an AVAILABLE row from nowhere is counted as
 * `received_quantity` by `MovementRequest::receivedDelta()`, so routing a recount through it would
 * silently misattribute the correction as a receipt.
 *
 * The detail row is a plain set, not a movement: nothing physically moved, core just decided a new
 * count, and the one row that has no bin, lot or serial of its own mirrors it — the same shape
 * {@see \InventoryDepthBundle\Import\DimensionalImportProvider::applyTotalOnly()} already uses for
 * a dimensional product's own sentinel row when a file gives a total with no breakdown. It is
 * reconciled unconditionally, whether or not any cleared bucket actually fed that reconciliation —
 * a bucket that doesn't (Reserved, Approved, ...) simply reconciles to the figure it already held,
 * which costs nothing and needs no special case to detect.
 *
 * A dimensional product is refused here rather than silently accepted: its quantity is maintained
 * by its bin/lot/serial breakdown, and a caller that reaches this method for one has a bug to fix,
 * not a figure for this method to overwrite.
 */
final class CoreInventoryTotalCountService
{
    /** Every clearable bucket but `quantity` itself, keyed by the name a caller states. */
    private const CLEARABLE_BUCKETS = [
        'received' => 'setReceivedQuantity',
        'transferIn' => 'setTransferInQuantity',
        'transferOut' => 'setTransferOutQuantity',
        'quarantine' => 'setQuarantineQuantity',
        'writeOff' => 'setWriteOffQuantity',
        'cartHold' => 'setCartHoldQuantity',
        'salesHold' => 'setSalesHoldQuantity',
        'pending' => 'setPendingQuantity',
        'approved' => 'setApprovedQuantity',
        'shipped' => 'setShippedQuantity',
        'backordered' => 'setBackorderedQuantity',
        'reserved' => 'setReservedQuantity',
        'incoming' => 'setIncomingQuantity',
        'manualAdjustment' => 'setManualAdjustment',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InventoryModeResolver $modeResolver,
        private readonly InventoryDetailRepository $details,
    ) {
    }

    /**
     * @param list<string> $clearBuckets keys of {@see self::CLEARABLE_BUCKETS} to zero alongside the count
     *
     * @throws \LogicException          when the product is dimensional — its quantity has a different owner
     * @throws \InvalidArgumentException when $clearBuckets names anything not in {@see self::CLEARABLE_BUCKETS}
     */
    public function setCount(
        ProductCore $product,
        Warehouse $warehouse,
        string|int $quantity,
        array $clearBuckets = [],
    ): ProductInventory {
        if ($this->modeResolver->isDimensional($product)) {
            throw new \LogicException(sprintf(
                '%s is on dimensional inventory; its quantity is maintained by its bin, lot and serial breakdown, not set directly.',
                $product->getSku() ?: ('#' . ($product->getId() ?? '?')),
            ));
        }

        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $warehouse,
        ]) ?? (new ProductInventory())->setProduct($product)->setWarehouse($warehouse);

        $row->setQuantity($quantity)->touch();

        foreach ($clearBuckets as $bucket) {
            $setter = self::CLEARABLE_BUCKETS[$bucket] ?? throw new \InvalidArgumentException(sprintf(
                '"%s" is not a clearable bucket. The buckets that exist are: %s.',
                $bucket,
                implode(', ', array_keys(self::CLEARABLE_BUCKETS)),
            ));
            $row->$setter(0);
        }

        $this->em->persist($row);
        $this->em->flush();

        // The one row's own quantity is excluded from "already accounted for elsewhere" — it is
        // the balancing figure being solved for, not a term in its own total. See
        // DimensionalImportProvider::applyTotalOnly()'s identical reasoning for its sentinel.
        $unspecified = $this->details->findOrCreate($product, $warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE);
        $identifiedElsewhere = QuantityScale::sub($this->details->availableTotal($product, $warehouse), $unspecified->getQuantity());
        $target = QuantityScale::sub($this->details->heldAvailableTotal($product, $warehouse, $row), $identifiedElsewhere);

        $unspecified->setQuantity($target)->touch();
        $this->em->persist($unspecified);
        $this->em->flush();

        return $row;
    }
}
