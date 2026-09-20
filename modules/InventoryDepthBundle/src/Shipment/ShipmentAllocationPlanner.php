<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Shipment;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\QuantityScale;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use InventoryDepthBundle\Repository\InventoryLotRepository;

/**
 * Prefills the step-2 lot/serial breakdown a shipment form offers for a tracked line
 * (`docs/plans/2026-09-14-shipment-dispatch.md`).
 *
 * ## Not authoritative — a suggestion, not a reservation
 *
 * Nothing here writes anything or holds a lock. `ShipmentService::ship()` re-resolves and re-draws
 * for real, inside its own transaction, the moment the form is actually submitted — the same
 * two-phase shape `StockMovementService::assertSourcesCanCover()`'s docblock describes for its own
 * pre-transaction check: a plan can go stale between rendering the form and submitting it, and the
 * real write is what catches that, not this class.
 *
 * ## Two different pick philosophies, on purpose
 *
 * `planLotAllocations()` auto-picks FEFO — the same rule `AutomaticSourcePicker` already uses for
 * adjustments — because a lot's quantity split is a mechanical question with no reason to make a
 * human answer it by hand every time, though the form renders the result as editable rows so an
 * admin can override it (a customer request to ship the newer batch first, say).
 *
 * `availableSerials()` never picks anything. A serial identifies one physical unit, and the owner's
 * ruling is that which unit ships is always a human's dropdown choice, not something this class
 * decides for them.
 *
 * ## Every figure is a DECIMAL STRING
 *
 * This docblock used to say they were physical stock units and that there was "no fraction to lose,
 * because there was never one to begin with — a lot holds 30 units or it does not". That rested on
 * `InventoryDetail::getQuantity()` being an `int` column, which it never was: the column is
 * `NUMERIC(14, 4)` and the `int` was `App\Doctrine\Type\QuantityType` throwing the decimals away on
 * read. A lot can hold 30.6, and this screen could not suggest picking any of the 0.6.
 *
 * So `available`, `suggested` and the outstanding quantity the caller hands in are all decimal
 * strings, run through `App\Service\QuantityScale`'s bcmath helpers — and the suggestion is now the
 * exact remainder rather than a whole unit floored out of it. `ShipmentService::remainingToShip()`
 * already answers in this shape, so the caller converts nothing at all.
 */
final class ShipmentAllocationPlanner
{
    public function __construct(
        private readonly InventoryLotRepository $lots,
        private readonly InventoryDetailRepository $details,
    ) {
    }

    /**
     * Every lot with real stock at `$warehouse`, FEFO order, each row carrying a FEFO-suggested
     * quantity towards shipping `$outstanding` in total (0 for a lot the suggestion has no further
     * use for once earlier rows already cover it).
     *
     * **Every candidate lot gets a row, including the zero-suggested ones** — deliberately, so the
     * shipment form can render one editable quantity box per lot and never need a second "add
     * another lot" control or any client-side row-adding at all. An admin overriding the suggestion
     * (ship the newer batch first, say) just changes which boxes carry numbers; the form never has
     * to conjure a row for a lot the suggestion happened not to reach.
     *
     * Scoped to `$warehouse`, unlike `InventoryLotRepository::withAvailableStock()`'s own cross-
     * warehouse view — a shipment ships from one warehouse, and a lot's stock sitting in another one
     * cannot answer this pick.
     *
     * `$outstanding`, `available` and `suggested` are all DECIMAL STRINGS — see
     * {@see \App\Service\QuantityScale}. They were physical units, which is what made this screen
     * unable to suggest 0.6 of a lot at all: the figure it prefilled was floored to a whole unit
     * before it was ever offered.
     *
     * @return list<array{lotId: int, label: string, available: string, suggested: string}>
     */
    public function planLotAllocations(ProductCore $product, Warehouse $warehouse, string $outstanding): array
    {
        $rows = [];
        $outstanding = QuantityScale::canonical($outstanding);

        foreach ($this->lots->withAvailableStock($product) as $lot) {
            $availableHere = QuantityScale::canonical(0);
            foreach ($this->details->availableRowsForLot($lot, $warehouse) as $row) {
                $availableHere = QuantityScale::add($availableHere, $row->getQuantity());
            }

            if (QuantityScale::compare($availableHere, 0) <= 0) {
                continue;
            }

            $suggested = QuantityScale::compare($outstanding, $availableHere) <= 0 ? $outstanding : $availableHere;
            $suggested = QuantityScale::compare($suggested, 0) > 0 ? $suggested : QuantityScale::canonical(0);
            $outstanding = QuantityScale::sub($outstanding, $suggested);

            $rows[] = [
                'lotId' => $lot->getId(),
                'label' => $lot->getLabel(),
                'available' => $availableHere,
                'suggested' => $suggested,
            ];
        }

        // A short plan (every row's `available` summed short of `$wholeUnits`) is not refused here —
        // the form shows exactly what's on the shelf and an admin decides what to do about a
        // shortfall. Refusing outright belongs to ship() itself, once a real submission is on the
        // table to refuse.
        return $rows;
    }

    /** @return list<string> */
    public function availableSerials(ProductCore $product, Warehouse $warehouse): array
    {
        return $this->details->availableSerialsFor($product, $warehouse);
    }
}
