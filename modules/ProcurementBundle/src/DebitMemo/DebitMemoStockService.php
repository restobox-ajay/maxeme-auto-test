<?php

declare(strict_types=1);

namespace ProcurementBundle\DebitMemo;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\Inventory\InventoryModeResolver;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovement;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\AutomaticSourcePicker;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\ReversalPlanner;
use InventoryDepthBundle\Movement\StockMovementService;
use ProcurementBundle\Entity\DebitMemo;
use Psr\Log\LoggerInterface;

/**
 * The two debit memo transitions that move stock: issuing a memo that says the goods went back, and
 * voiding one afterwards.
 *
 * Issuing is the standalone restock path `DebitMemoController` used to report as deliberately
 * unbuilt: `DebitMemo::$restock` was mapped, saved, offered on the form and read by nothing at all,
 * so the warehouse went on showing units that had left the building.
 *
 * Voiding exists because issuing created the question. Once issuing a memo can take stock out,
 * voiding that memo has to say what became of the goods — see `void()` for the three answers and
 * why the app refuses to pick one on the operator's behalf.
 *
 * ## It is NOT a copy of CreditMemoRestockSubscriber, and the difference is the whole design
 *
 * The sell-side twin (`InventoryDepthBundle\EventSubscriber\CreditMemoRestockSubscriber`) fires off
 * `CreditMemoIssuedEvent` AFTER the controller's flush, and that is safe there because its movement
 * is `null → returned`: goods ARRIVING from outside the ledger. A receipt cannot fail for want of
 * stock, so a subscriber that runs after the document is already committed can never leave the
 * money and the goods disagreeing.
 *
 * Reverse the direction and that stops being true. A buy-side restock is a WITHDRAWAL — the units
 * leave us — so it can be refused: `AutomaticSourcePicker` may not find four units of a product the
 * warehouse holds three of. Dispatched after the flush, that refusal would arrive too late: the
 * memo would already be Open, permanently asserting goods went back that the ledger never let go.
 *
 * So this is shaped like `VendorReturnShipService` instead, which is this bundle's existing answer
 * to the same question: `wrapInTransaction()` around the transition AND the movement, so a refused
 * withdrawal leaves the memo a draft and nothing half-written. What is copied from the sell side is
 * its REASONING — one movement group per document, a derived idempotency reference, simple-inventory
 * products logged rather than thrown over — not its event indirection, which exists only because
 * core cannot depend on a bundle. This bundle already depends on InventoryDepthBundle directly.
 *
 * ## Where the units go
 *
 * `InventoryDetail::STATUS_RETURNED_TO_VENDOR`, drawn by `AutomaticSourcePicker::addWithdrawal()` —
 * byte-for-byte the destination and the picker `VendorReturnShipService` uses, because it is the
 * same physical event: goods leaving for a supplier. A standalone memo and a memo raised from a
 * vendor return must not produce two different ledger stories about the same act, and
 * `DebitMemo::assertRestockAndReturnAreExclusive()` already guarantees only one of them happens.
 *
 * ## What is refused, and why refusing is the point
 *
 * A memo that claims the goods went back but names no warehouse, or no product on any line, cannot
 * be honoured: there is no building to take them out of and nothing to take out. Both are refused
 * at issue rather than issued-and-ignored, because issued-and-ignored is exactly the silence this
 * service exists to end.
 */
final class DebitMemoStockService
{
    /**
     * What became of the goods, asked when a memo that moved stock is voided.
     *
     * Three, not two, and the third is the one this feature was first built without: a voided
     * restock usually means something went wrong with the goods, so "they came back but they are
     * scrap" is the common case and not an edge.
     */
    public const DISPOSITION_BACK_IN_STOCK = 'back_in_stock';

    public const DISPOSITION_WRITTEN_OFF = 'written_off';

    public const DISPOSITION_GONE = 'gone';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementService $movements,
        private readonly AutomaticSourcePicker $picker,
        private readonly ReversalPlanner $reversals,
        private readonly InventoryModeResolver $inventoryModes,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<string> */
    public static function dispositions(): array
    {
        return [self::DISPOSITION_BACK_IN_STOCK, self::DISPOSITION_WRITTEN_OFF, self::DISPOSITION_GONE];
    }

    public function issue(DebitMemo $memo, ?Warehouse $warehouse = null, ?string $actor = null, ?\DateTimeImmutable $at = null): DebitMemo
    {
        return $this->em->wrapInTransaction(function () use ($memo, $warehouse, $actor, $at): DebitMemo {
            if (!$memo->isRestock()) {
                $memo->issue();
                $this->em->flush();

                return $memo;
            }

            // Checked BEFORE issue(), so a memo refused for want of a warehouse is still a draft
            // the admin can fix rather than a document stuck between two states.
            if (!$warehouse instanceof Warehouse) {
                throw new \DomainException(sprintf(
                    'Debit memo %s says the goods physically went back, so issuing it moves stock. '
                    . 'Say which warehouse they left from.',
                    $memo->getDocumentNumber(),
                ));
            }

            if (!$this->namesAProduct($memo)) {
                throw new \DomainException(sprintf(
                    'Debit memo %s says the goods physically went back, but no line on it names a product, '
                    . 'so there is nothing to take out of %s. Name the products being returned, or untick '
                    . '"the goods went back" and raise a vendor return instead.',
                    $memo->getDocumentNumber(),
                    $warehouse->getName(),
                ));
            }

            $memo->issue();

            $movement = MovementRequest::of(
                // The same group type a vendor return ships under. This IS a vendor return in
                // everything but paperwork — the collapsed, single-document case — and giving it a
                // second type would split one physical event across two names in the movement
                // history for no reader's benefit.
                InventoryMovementGroup::TYPE_VENDOR_RETURN,
                // Derived from the memo's identity, so a retried request applies nothing a second
                // time — the idempotency property CreditMemoRestockSubscriber draws out at length.
                'debit-memo-restock-' . (string) $memo->getId(),
                sprintf('Debit memo %s to %s', $memo->getDocumentNumber(), $memo->getVendorName()),
                $actor,
                $memo->getDocumentNumber(),
                $at ?? new \DateTimeImmutable(),
            );

            $stocked = false;
            foreach ($memo->getLines() as $line) {
                $product = $line->getProduct();
                if (!$product instanceof ProductCore) {
                    // A fee, a freight adjustment, a pricing correction: a debit memo line is
                    // allowed to carry no SKU at all, and such a line moves no goods by definition.
                    continue;
                }

                $units = $this->unitsOf($line->getQuantity());
                if ($units <= 0) {
                    continue;
                }

                if (!$this->inventoryModes->isDimensional($product)) {
                    // A simple-mode product still has no `inventory_detail` row and the movement
                    // layer still refuses to make it one — but it is no longer INVISIBLE to
                    // `product_inventory`, and that is what changed under
                    // docs/plans/2026-09-15-simple-inventory-bucket-parity.md. Receiving credits
                    // such a line on the way in, so an outbound document has to debit it on the
                    // way out or the ledger only ever ratchets upwards: goods received and then
                    // returned to the vendor stayed on hand forever, because this branch used to
                    // `continue` and record nothing at all.
                    //
                    // The crossing written is the SAME one the picker builds for a dimensional
                    // line below — `available → returned_to_vendor`, in this warehouse, on a bare
                    // key carrying no bin/lot/serial because none of that identity means anything
                    // here. The bucket arithmetic therefore lands identically in both modes:
                    // `returned_to_vendor` is one of InventoryDetail::writeOffStatuses(), so
                    // MovementRequest::writeOffDelta() credits `write_off` (+units) while
                    // receivedDelta() stays flat — the destination is a status accounted for
                    // elsewhere — and availability drops by exactly the units that left.
                    //
                    // It cannot go through AutomaticSourcePicker: that plans against detail rows
                    // and a simple product has none, which is why this line is added directly.
                    $from = new DetailKey($warehouse);
                    $movement->move($product, $from, $from->forStatus(InventoryDetail::STATUS_RETURNED_TO_VENDOR), $units);
                    $stocked = true;

                    $this->logger->info('Debit memo restock: product is not on dimensional inventory, bucket debited without a detail row.', [
                        'debitMemo' => $memo->getDocumentNumber(),
                        'product' => $product->getSku(),
                        'units' => $units,
                    ]);

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

            return $memo;
        });
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Voiding, and what became of the goods
     * ------------------------------------------------------------------------------------------
     */

    /**
     * Voids a memo, and records what happened to any stock its issuing moved.
     *
     * ## Why the caller must say, and the app must not work it out
     *
     * A restock memo took units OUT of a warehouse and wrote them off to the vendor. Voiding that
     * memo is not by itself a statement about where the goods are, and there are three real
     * answers with three different correct ledger entries:
     *
     *  - `back_in_stock` — the goods returned and are sellable. The write-off is reversed, so the
     *    units go back into the exact bin, lot and serial they were taken from.
     *  - `written_off` — the goods came back damaged or unusable. They must NOT become sellable, so
     *    they move from `returned_to_vendor` into `damaged`: still the write-off bucket, and
     *    `available` never rises. This is deliberately not modelled as "put it back, then write it
     *    off", because a person reading the ledger would see stock briefly become sellable that
     *    never was.
     *  - `gone` — the goods are with the vendor and are not coming back. Nothing moves.
     *
     * Guessing is wrong in two of the three cases whichever way it guesses, so the screen asks and
     * this refuses without an answer.
     *
     * ## All three write a group, `gone` included
     *
     * The movement ledger is the chronological record of everything that happened to this stock, so
     * a decision that deliberately moved nothing is still an event in it. If `gone` were the one
     * outcome with no entry, the case hardest to explain later would be the case with no trace.
     * `gone` therefore writes one zero-quantity movement per product — see
     * `InventoryMovementGroup`'s docblock, which records that this can happen so that anybody
     * COUNTING movements knows before they are surprised by it.
     *
     * ## Atomic, and the void goes first
     *
     * Everything runs inside one transaction: if the stock entry cannot be made, the memo does not
     * void. `DebitMemo::void()` is called before any movement is built so that its own refusals —
     * already void, money applied or refunded against it — happen before stock is touched at all.
     */
    public function void(DebitMemo $memo, ?string $disposition = null, ?string $actor = null, ?\DateTimeImmutable $at = null): DebitMemo
    {
        return $this->em->wrapInTransaction(function () use ($memo, $disposition, $actor, $at): DebitMemo {
            $restock = $this->restockGroupFor($memo);

            // Nothing moved when this memo was issued, so nothing has to be said about goods. Voids
            // exactly as it always did: no question, no group, no movement.
            if (!$restock instanceof InventoryMovementGroup) {
                $memo->void();
                $this->em->flush();

                return $memo;
            }

            if (!in_array((string) $disposition, self::dispositions(), true)) {
                throw new \DomainException(sprintf(
                    'Issuing debit memo %s took stock out to the vendor. Voiding it has to say what became of '
                    . 'those goods — back in stock, written off, or gone — because the three leave the warehouse '
                    . 'in three different states and this cannot be worked out from the memo.',
                    $memo->getDocumentNumber(),
                ));
            }

            // First, so "already void" and "money is applied against it" refuse before any stock
            // entry is built. A refusal here leaves the ledger untouched.
            $memo->void();

            $request = MovementRequest::of(
                InventoryMovementGroup::TYPE_ADJUSTMENT,
                // Derived from the memo, and UNIQUE per memo: a retried submission re-applies
                // nothing, and the same void cannot put the same units back twice.
                'debit-memo-void-' . (string) $memo->getId(),
                $this->reasonFor($memo, (string) $disposition),
                $actor,
                $memo->getDocumentNumber(),
                $at ?? new \DateTimeImmutable(),
            );

            match ((string) $disposition) {
                self::DISPOSITION_BACK_IN_STOCK => $this->addReturnToStock($request, $restock),
                self::DISPOSITION_WRITTEN_OFF => $this->addWriteOff($request, $restock),
                self::DISPOSITION_GONE => $this->writeNothingMovedGroup($request, $restock),
                default => null,
            };

            if ((string) $disposition !== self::DISPOSITION_GONE) {
                try {
                    $this->movements->apply($request);
                } catch (\InvalidArgumentException $exception) {
                    // The movement layer states its refusals as sentences meant for the operator
                    // (a reversal bound, a simple-inventory product, a source that cannot cover).
                    // Rethrown as the type the controller already catches, so the sentence reaches
                    // the screen instead of a 500 — and the transaction still rolls the void back.
                    throw new \DomainException($exception->getMessage(), 0, $exception);
                }
            }

            $this->em->flush();

            return $memo;
        });
    }

    /**
     * The group the memo's issuing wrote, or null when issuing moved no stock.
     *
     * Derived from the ledger rather than stored as a flag on the memo, deliberately. The ledger is
     * where "did this memo move stock" is actually true, so reading it there cannot drift from it —
     * and it needs no column, so no migration. It is also correct for free in the case a flag would
     * have had to special-case: a restock memo whose products are all on simple inventory writes no
     * group, has nothing outstanding, and voids with no question asked.
     */
    public function restockGroupFor(DebitMemo $memo): ?InventoryMovementGroup
    {
        return $this->groupByOperationId('debit-memo-restock-' . (string) $memo->getId());
    }

    /** The group the memo's voiding wrote, for the detail screen to say what became of the goods. */
    public function voidGroupFor(DebitMemo $memo): ?InventoryMovementGroup
    {
        return $this->groupByOperationId('debit-memo-void-' . (string) $memo->getId());
    }

    private function groupByOperationId(string $operationId): ?InventoryMovementGroup
    {
        $group = $this->em->getRepository(InventoryMovementGroup::class)->findOneBy(['clientOperationId' => $operationId]);

        return $group instanceof InventoryMovementGroup ? $group : null;
    }

    /**
     * What each outcome would actually do, in the terms the confirmation screen has to state: the
     * product, the units, the warehouse and the bucket they land in.
     *
     * Read off the movements the restock really wrote rather than off the memo's lines, because the
     * ledger is what the void has to undo — a line whose product was on simple inventory moved
     * nothing and correctly appears nowhere here.
     *
     * @return list<array{product: ProductCore, quantity: int, warehouse: ?Warehouse, location: ?string}>
     */
    public function plannedOutcome(InventoryMovementGroup $restock): array
    {
        $rows = [];

        foreach ($restock->getMovements() as $movement) {
            $from = $movement->getFromDetail();
            $rows[] = [
                'product' => $movement->getProduct(),
                'quantity' => $movement->getQuantity(),
                'warehouse' => $from?->getWarehouse() ?? $movement->getToDetail()?->getWarehouse(),
                'location' => $from?->getLocation()?->getCode(),
            ];
        }

        return $rows;
    }

    /**
     * The goods are back and sellable: the write-off reversed, sides swapped.
     *
     * Through `ReversalPlanner`, which is this app's existing answer to "put that write-off back" —
     * `returned_to_vendor` is one of `InventoryDetail::writeOffStatuses()`, so the restock's own
     * movements are reversible entries like any other. It is used here rather than a hand-built
     * pair of DetailKeys for the two properties it carries: both sides come off the original's own
     * rows, so the units land in the exact bin, lot and serial they were taken from; and the bound
     * `original.quantity − SUM(already reversed)` refuses putting the same units back twice.
     */
    private function addReturnToStock(MovementRequest $request, InventoryMovementGroup $restock): void
    {
        foreach ($restock->getMovements() as $movement) {
            $remaining = $this->reversals->remaining($movement);
            if ($remaining <= 0) {
                continue;
            }

            $this->reversals->addReversal($request, $movement, $remaining);
        }
    }

    /**
     * The goods are back but unusable: `returned_to_vendor` -> `damaged`.
     *
     * Both are write-off statuses, so `product_inventory.write_off_quantity` does not jump and
     * `available` does not rise — which is the whole requirement. The units stop being described as
     * sitting at the vendor, because they are not, and start being described as damaged here.
     *
     * Built as an explicit status move rather than through `AutomaticSourcePicker`, which only ever
     * reads `available` rows and so cannot source stock that is already written off. No stored
     * `InventoryAdjustmentReason` is attached for the same reason the restock group attaches none:
     * those rows classify the ADJUSTMENT SCREEN's operations and each declares its own
     * (from_status, to_status) pair, and `damaged` declares `available -> damaged`. Borrowing it
     * for a movement that starts somewhere else would make the row say something untrue.
     */
    private function addWriteOff(MovementRequest $request, InventoryMovementGroup $restock): void
    {
        foreach ($restock->getMovements() as $movement) {
            $writtenOffInto = $movement->getToDetail();
            if (!$writtenOffInto instanceof InventoryDetail || $movement->getQuantity() <= 0) {
                continue;
            }

            $from = new DetailKey(
                $writtenOffInto->getWarehouse(),
                $writtenOffInto->getLocation(),
                $writtenOffInto->getLot(),
                $writtenOffInto->getSerial(),
                $writtenOffInto->getStatus(),
            );

            $request->move($movement->getProduct(), $from, $from->forStatus(InventoryDetail::STATUS_DAMAGED), $movement->getQuantity());
        }
    }

    /**
     * The goods are gone: one zero-quantity movement per product, so the ledger says so.
     *
     * Written directly rather than through `StockMovementService`, because there is no stock write
     * to make and that service is the one implementation of the stock WRITE — it refuses an empty
     * request outright, and `MovementRequest::move()` refuses a zero quantity and a line with
     * neither side, both correctly: every other caller expressing a zero would be a bug. This is
     * the one case where the absence of a movement IS the fact being recorded, so it is built here,
     * once, with the reason and the reference on the group that explains it.
     */
    private function writeNothingMovedGroup(MovementRequest $request, InventoryMovementGroup $restock): void
    {
        $group = (new InventoryMovementGroup())
            ->setClientOperationId($request->clientOperationId)
            ->setType($request->type)
            ->setReason($request->reason)
            ->setActor($request->actor)
            ->setReference($request->reference)
            ->setOccurredAt($request->occurredAt);

        $this->em->persist($group);

        $seen = [];
        foreach ($restock->getMovements() as $movement) {
            $productId = (int) $movement->getProduct()->getId();
            if (isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;

            $entry = (new InventoryMovement())
                ->setGroup($group)
                ->setProduct($movement->getProduct())
                ->setQuantity(0);

            $group->addMovement($entry);
            $this->em->persist($entry);
        }
    }

    /** The sentence the group carries, which is what a person reading the ledger actually gets. */
    private function reasonFor(DebitMemo $memo, string $disposition): string
    {
        return match ($disposition) {
            self::DISPOSITION_BACK_IN_STOCK => sprintf('Debit memo %s voided; the goods came back and are sellable again.', $memo->getDocumentNumber()),
            self::DISPOSITION_WRITTEN_OFF => sprintf('Debit memo %s voided; the goods came back damaged and were written off, not returned to sellable stock.', $memo->getDocumentNumber()),
            self::DISPOSITION_GONE => sprintf('Debit memo %s voided; the goods stayed with the vendor. Nothing moved — this entry records that decision.', $memo->getDocumentNumber()),
            default => sprintf('Debit memo %s voided.', $memo->getDocumentNumber()),
        };
    }

    private function namesAProduct(DebitMemo $memo): bool
    {
        foreach ($memo->getLines() as $line) {
            if ($line->getProduct() instanceof ProductCore && $this->unitsOf($line->getQuantity()) > 0) {
                return true;
            }
        }

        return false;
    }

    /** Whole units, rounded exactly the way VendorReturnLine::getUnits() rounds. */
    private function unitsOf(string $quantity): int
    {
        return max(0, (int) round((float) $quantity));
    }
}
