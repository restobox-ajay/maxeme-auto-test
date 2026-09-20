<?php

declare(strict_types=1);

namespace ProcurementBundle\VendorReturn;

use App\Entity\Warehouse;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\AutomaticSourcePicker;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use ProcurementBundle\Entity\VendorReturn;

/**
 * Ships a vendor return (#638): the one place stock actually leaves for a vendor, mirroring
 * `ReceivingService`'s relationship to `StockMovementService` exactly, direction reversed.
 *
 * ## It does not write stock. It asks InventoryDepthBundle to.
 *
 * This builds a `MovementRequest` and hands it to `StockMovementService::apply()` — the same
 * service the adjustment screen, the cycle count, and receiving itself call — rather than touching
 * `inventory_detail` directly. #638 names this requirement explicitly: "Whatever writes stock goes
 * through the same movement request the stock adjustment screen builds, as receiving does. One
 * implementation of the stock write, several ways to trigger it."
 *
 * ## Which physical units leave — AutomaticSourcePicker, not the operator
 *
 * A vendor return names a PRODUCT and a QUANTITY, not a bin, lot or serial — see `VendorReturn`'s
 * class docblock for why. `AutomaticSourcePicker::addWithdrawal()` is what turns that into real
 * `inventory_detail` rows: earliest-expiry-first, then lowest bin, the identical FEFO tiebreak the
 * adjustment screen's own withdrawals use. No operator picks a bin for a vendor return any more than
 * one picks a bin for a scrap adjustment.
 *
 * ## The destination status
 *
 * `InventoryDetail::STATUS_RETURNED_TO_VENDOR` (#638) — see that constant's docblock for why it is
 * folded into the existing `write_off` bucket rather than given a schema change of its own, and why
 * it is terminal (drops its bin) exactly like `sold`/`scrapped`/`lost`.
 *
 * ## Products on simple inventory
 *
 * Skipped, exactly as `ReceivingService::reachesStock()` skips them: a `simple`-mode product's
 * quantity is a number an admin types, and `StockMovementService` refuses to touch it by design.
 * The return still records what left on the document — the paperwork is a fact regardless of
 * whether the depth ledger tracks this product — it simply moves no `inventory_detail` row for that
 * line.
 */
final class VendorReturnShipService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementService $movements,
        private readonly AutomaticSourcePicker $picker,
        private readonly InventoryModeResolver $inventoryModes,
    ) {
    }

    public function ship(VendorReturn $vendorReturn, Warehouse $warehouse, ?string $actor = null, ?\DateTimeImmutable $at = null): VendorReturn
    {
        return $this->em->wrapInTransaction(function () use ($vendorReturn, $warehouse, $actor, $at): VendorReturn {
            // Refuses outright unless Authorised, and stamps warehouse/shippedAt in the same call —
            // "the goods left" and "they left from here" are one fact. Thrown before anything below
            // is built, so a refused ship touches no movement at all.
            $vendorReturn->ship($warehouse, $at);

            $movement = MovementRequest::of(
                InventoryMovementGroup::TYPE_VENDOR_RETURN,
                null,
                sprintf('Vendor return %s to %s', $vendorReturn->getDocumentNumber(), $vendorReturn->getVendor()->getName()),
                $actor,
                // The return's own number, so the movement history answers "where did these units
                // go" without needing this bundle installed to read it — the same property a ship
                // group's reference gives in the other direction.
                $vendorReturn->getDocumentNumber(),
                $at ?? new \DateTimeImmutable(),
            );

            $stocked = false;
            foreach ($vendorReturn->getLines() as $line) {
                $product = $line->getProduct();
                $units = $line->getUnits();
                if (QuantityScale::compare($units, 0) <= 0) {
                    continue;
                }

                if (!$this->inventoryModes->isDimensional($product)) {
                    // The same debit DebitMemoStockService now writes, and for the same reason:
                    // since docs/plans/2026-09-15-simple-inventory-bucket-parity.md receiving
                    // credits a simple-mode line's `received` bucket, so shipping those goods back
                    // to the vendor has to take them off again. Skipping the line entirely — which
                    // is what this branch used to do — left the inbound credit standing and the
                    // units readable as on hand after they had physically gone.
                    //
                    // `available -> returned_to_vendor` on a bare warehouse key, byte-for-byte the
                    // crossing AutomaticSourcePicker builds for a dimensional line below, so the
                    // bucket arithmetic is mode-independent: `returned_to_vendor` is a
                    // writeOffStatuses() member, so `write_off` takes the units and availability
                    // falls by exactly what left. The picker itself plans against detail rows a
                    // simple product does not have, hence the direct move().
                    $from = new DetailKey($warehouse);
                    $movement->move($product, $from, $from->forStatus(InventoryDetail::STATUS_RETURNED_TO_VENDOR), $units);
                    $stocked = true;

                    continue;
                }

                $this->picker->addWithdrawal($movement, $product, $warehouse, $units, InventoryDetail::STATUS_RETURNED_TO_VENDOR);
                $stocked = true;
            }

            $this->em->flush();

            if ($stocked) {
                $this->movements->apply($movement);
            }

            $this->em->flush();

            return $vendorReturn;
        });
    }
}
