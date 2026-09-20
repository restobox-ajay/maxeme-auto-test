<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Inventory;

use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use App\Service\Inventory\InventoryOperation;
use App\Service\Inventory\InventoryOperationContext;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * Moves one product between `simple` and `dimensional` (#550).
 *
 * The asymmetry falls straight out of the invariant, and is worth stating because it is the whole
 * reason the bundle is removable:
 *
 * | | needs |
 * |---|---|
 * | `simple → dimensional` | an **opening balance movement** — detail rows have to come from somewhere |
 * | `dimensional → simple` | **nothing at all** — the total is already there |
 *
 * The opening balance is `quantity` in, **location and lot unspecified**. That is honest: "we have
 * 47, we don't yet know where." Inventing a bin would be worse than admitting the gap, because a
 * wrong bin is indistinguishable from a right one on every screen that follows.
 *
 * Crucially, the switch does **not change the number**. The opening balance is read from the
 * existing `product_inventory.quantity` and moved in as `available`, so
 * `SUM(available) == quantity` holds from the first instant and the value is byte-identical either
 * side of the switch. Switching back keeps the detail rows dormant rather than deleting them, so a
 * product can go out and come back without losing its history.
 *
 * **A `simple` product can already have detail rows** (2026-09-18: StockMovementService resolves a
 * real one for every product now, location included — see that class's own docblock). Real
 * pre-switch activity (a Stock Found, a write-off) already shapes ITS OWN portion the moment it
 * happens — it credits `received`/`write_off`/etc. AND writes a real detail row together, in one
 * transaction, the same as any other movement. What such activity never shapes is `quantity`
 * itself, the import's own baseline — nothing but this method's opening balance ever gives that
 * portion a row. So the amount to open is not "all of `quantity`, or nothing" per warehouse; it is
 * exactly the GAP between what SHOULD be available there (InventoryDetailRepository::
 * heldAvailableTotal() — the same reconciliation StockController::byProduct() uses to say whether a
 * product's numbers agree) and what real detail rows already account for. Seeding the whole
 * `quantity` again on top of real pre-switch activity would double it; seeding nothing at all would
 * leave `quantity`'s own portion permanently unshaped once dimensional, which is the one thing that
 * portion needs this method FOR.
 */
final class InventoryModeSwitcher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementService $movements,
        private readonly InventoryDetailRepository $details,
        private readonly ManagerRegistry $registry,
        private readonly InventoryOperationContext $operations,
    ) {
    }

    /**
     * Returns the opening-balance group, or null when there was no stock anywhere to open with —
     * a product at zero everywhere needs no movement, and writing an empty group would be a record
     * of nothing.
     */
    public function toDimensional(ProductCore $product, ?string $actor = null): ?InventoryMovementGroup
    {
        if ($product->getInventoryMode() === ProductCore::INVENTORY_MODE_DIMENSIONAL) {
            return null;
        }

        /** @var list<ProductInventory> $rows */
        $rows = $this->em->getRepository(ProductInventory::class)->findBy(['product' => $product]);

        // Set BEFORE the movement: StockMovementService refuses to touch a product that has not
        // opted in, which is the guard that keeps every existing product's number out of its reach.
        //
        // Flushed, and therefore committed, before apply() opens its own transaction — Doctrine
        // gives no way to nest one. So the flip and the opening balance are two commits, and if the
        // second fails the product is left `dimensional` with no detail rows: the invariant broken
        // from the first instant, and the quantity no longer editable in the grid or the product
        // form. The NUMBER is never damaged (nothing has touched product_inventory at this point),
        // which is what the acceptance criterion turns on, but the state needs rescuing by hand.
        //
        // Guarded by putting it back rather than by pretending it cannot happen: the catch below
        // restores `simple`, which needs nothing installed to be true and leaves the product exactly
        // as it was found.
        $product->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->flush();

        $request = MovementRequest::of(
            InventoryMovementGroup::TYPE_ADJUSTMENT,
            null,
            'Opening balance on switching to dimensional inventory',
            $actor,
            null,
        );

        // Keyed by row, not a plain list: the write-back below needs to subtract back out exactly
        // what THIS call added to `received` per warehouse — never the whole bucket, which may
        // also hold real, earned received from pre-switch activity this call never touched.
        /** @var array<int, string> $openedAmountByRowId */
        $openedAmountByRowId = [];
        /** @var array<int, ProductInventory> $rowById */
        $rowById = [];

        foreach ($rows as $row) {
            $warehouse = $row->getWarehouse();
            $rowId = $row->getId();
            if (!$warehouse instanceof Warehouse || $rowId === null) {
                continue;
            }

            $gap = QuantityScale::sub(
                $this->details->heldAvailableTotal($product, $warehouse, $row),
                $this->details->availableTotal($product, $warehouse),
            );
            if (QuantityScale::compare($gap, 0) <= 0) {
                continue;
            }

            $request->receive(
                $product,
                // Bin and lot unspecified, deliberately.
                new DetailKey($warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE),
                $gap,
            );
            $openedAmountByRowId[$rowId] = $gap;
            $rowById[$rowId] = $row;
        }

        if ($request->isEmpty()) {
            return null;
        }

        try {
            $group = $this->movements->apply($request);

            // The opening balance is not stock ARRIVING, so it must not leave anything in `received`.
            //
            // Since #572 a movement that puts units into `available` accumulates them there, which is
            // right for every real receipt and wrong for this one: the units being moved in are the
            // ones already sitting in `quantity`, being given a shape for the first time. Leaving the
            // delta accumulated would make `quantity + received` twice the number the switch exists to
            // preserve — and `received` is sellable, so the product would double on every screen.
            //
            // Written back here rather than suppressed in the movement layer because the movement
            // layer genuinely cannot tell an arrival from a re-statement; only the caller knows which
            // it just issued.
            //
            // SUBTRACTED, not zeroed (2026-09-18): a warehouse can reach this point already carrying
            // real, earned `received` from genuine pre-switch activity (StockMovementService now
            // shapes that the moment it happens, whether the product is dimensional yet or not) —
            // only THIS call's own gap-opening addition belongs to the re-statement being corrected
            // here, and zeroing the whole bucket would erase received that was never this method's to
            // touch.
            //
            // Inside its own ambient operation (#582) so the change log says what this write-back
            // is: 'inventory_mode_opening_balance', carrying the same movement group as the
            // movements it corrects. Recorded rather than hidden, because a `received` bucket
            // moving with no explanation is exactly the kind of unattributable change that made this
            // table worth fixing.
            $this->operations->run('inventory_mode_opening_balance', function (InventoryOperation $operation) use ($openedAmountByRowId, $rowById, $group): void {
                $operation->attachGroup($group);

                foreach ($openedAmountByRowId as $rowId => $openedAmount) {
                    $row = $rowById[$rowId];
                    $row->setReceivedQuantity(QuantityScale::sub($row->getReceivedQuantity(), $openedAmount))->touch();
                }

                $this->em->flush();
            });

            return $group;
        } catch (\Throwable $e) {
            // apply() rolled its own transaction back and closed the EntityManager with it, so the
            // repair needs a fresh one. Without this the product is stranded `dimensional` with an
            // empty breakdown and an un-editable quantity; `simple` is the mode that needs nothing
            // installed, so putting it back is always available and always correct.
            $manager = $this->registry->resetManager();
            $stranded = $manager->find(ProductCore::class, $product->getId());

            if ($stranded instanceof ProductCore) {
                $stranded->setInventoryMode(ProductCore::INVENTORY_MODE_SIMPLE);
                $manager->flush();
            }

            throw $e;
        }
    }

    /**
     * No movement, no conversion, no data loss. `product_inventory.quantity` already holds the
     * total and always did; the detail rows simply stop being maintained and stay where they are.
     */
    public function toSimple(ProductCore $product): void
    {
        $product->setInventoryMode(ProductCore::INVENTORY_MODE_SIMPLE);
        $this->em->flush();
    }
}
