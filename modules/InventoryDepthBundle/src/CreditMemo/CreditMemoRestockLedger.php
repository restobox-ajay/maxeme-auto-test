<?php

declare(strict_types=1);

namespace InventoryDepthBundle\CreditMemo;

use App\Contract\Inventory\CreditMemoRestockLedgerInterface;
use App\Entity\CreditMemo;
use App\Enum\CreditMemoVoidDisposition;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovement;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;

/**
 * The ledger half of voiding a restocking credit note (item 38) — the sell-side twin of
 * `ProcurementBundle\DebitMemo\DebitMemoStockService::void()`.
 *
 * `CreditMemoRestockSubscriber` next door records goods arriving from a customer when the note is
 * issued: `null → returned`, into `quarantine_quantity`, present but not sellable. This is what
 * answers for those units when the note is withdrawn.
 *
 * ## No new column, and no migration
 *
 * "Did this note move stock" is read from the LEDGER, by the restock group's
 * `client_operation_id` — `credit-memo-restock-{id}`, the idempotency key the subscriber already
 * derives. That is where the fact is actually true, so it cannot drift from a flag, and it is right
 * for free in the case a flag would have had to special-case: a restocking note whose products are
 * all on simple inventory wrote no group, has nothing outstanding, and voids with no question
 * asked. `credit_memo.restock` is NOT the right question — it says what the note CLAIMS, not what
 * the ledger DID.
 *
 * ## ReversalPlanner is deliberately NOT used here, and it is not an oversight
 *
 * The buy side's `back_in_stock` goes through `ReversalPlanner` and gets two properties free: both
 * sides come off the original rows, and `original.quantity − SUM(already reversed)` makes putting
 * the same units back twice impossible. Neither is available on this side, because that planner
 * refuses this movement outright and is right to:
 *
 *  - it requires the entry being undone to be a WRITE-OFF (`InventoryDetail::writeOffStatuses()`),
 *    and a restock's destination is `returned`, a QUARANTINE status;
 *  - it requires the entry to have a source row to give the stock back to, and a restock's source
 *    is `null` by construction — the goods entered the ledger from outside it. Its own refusal
 *    names this case: "swapping its sides would push the units OUT of the ledger instead of back
 *    onto a shelf". Pushing them out is exactly what {@see CreditMemoVoidDisposition::NotHere}
 *    must do, so what that planner calls a mistake is this side's correct answer.
 *
 * The double-apply bound is therefore carried by two other things that already exist and are
 * enough: `CreditMemo::void()` refuses a note that is already void, so a second void never runs;
 * and the group's own `client_operation_id` — `credit-memo-void-{id}`, unique per note — makes a
 * retried request find the existing group and apply nothing.
 *
 * Both sides of every movement here still come off the restock's OWN detail rows rather than being
 * rebuilt from the note's lines, which is the property that mattered most: the units leave the
 * exact bin, lot and serial they arrived into, and a line whose product was on simple inventory
 * moved nothing and correctly takes no part.
 */
final class CreditMemoRestockLedger implements CreditMemoRestockLedgerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementService $movements,
    ) {
    }

    public function getSource(): string
    {
        return 'InventoryDepthBundle';
    }

    public function restockedUnits(CreditMemo $memo): array
    {
        $group = $this->restockGroupFor($memo);
        if (!$group instanceof InventoryMovementGroup) {
            return [];
        }

        $rows = [];
        foreach ($group->getMovements() as $movement) {
            $into = $movement->getToDetail();
            $rows[] = [
                'product' => $movement->getProduct(),
                'quantity' => $movement->getQuantity(),
                'warehouse' => $into?->getWarehouse() ?? $movement->getFromDetail()?->getWarehouse(),
                'location' => $into?->getLocation()?->getCode(),
            ];
        }

        return $rows;
    }

    public function recordVoidDisposition(CreditMemo $memo, CreditMemoVoidDisposition $disposition, ?string $actor): void
    {
        $restock = $this->restockGroupFor($memo);
        if (!$restock instanceof InventoryMovementGroup) {
            return;
        }

        $request = MovementRequest::of(
            InventoryMovementGroup::TYPE_ADJUSTMENT,
            // Derived from the note, and UNIQUE per note: a retried submission re-applies nothing.
            $this->voidOperationId($memo),
            $disposition->reasonFor($memo->getDocumentNumber()),
            $actor,
            $memo->getDocumentNumber(),
            new \DateTimeImmutable(),
        );

        match ($disposition) {
            CreditMemoVoidDisposition::NotHere => $this->addTakeBackOffTheBooks($request, $restock),
            CreditMemoVoidDisposition::WrittenOff => $this->addWriteOff($request, $restock),
            CreditMemoVoidDisposition::StillHere => $this->writeNothingMovedGroup($request, $restock),
        };

        if ($disposition === CreditMemoVoidDisposition::StillHere) {
            return;
        }

        try {
            $this->movements->apply($request);
        } catch (\InvalidArgumentException $exception) {
            // The movement layer states its refusals as sentences meant for the operator (a source
            // that cannot cover, a simple-inventory product). Rethrown as the type the controller
            // already catches, so the sentence reaches the screen instead of a 500 — and the
            // caller's transaction still rolls the void back with it.
            throw new \DomainException($exception->getMessage(), 0, $exception);
        }
    }

    public function voidOutcome(CreditMemo $memo): ?string
    {
        return $this->groupByOperationId($this->voidOperationId($memo))?->getReason();
    }

    /**
     * "Not here": the receipt is undone and the units come off the books.
     *
     * `returned → nothing`, out of the very rows the restock created. `MovementRequest::remove()`
     * is the withdrawal with no destination, which is what "these goods are not in the building"
     * means in this ledger — the mirror image of the `receive()` the restock used to put them in.
     *
     * `received_quantity` is untouched, and that is the correct answer rather than an omission:
     * `MovementRequest::receivedDelta()` only counts crossings where one side is `available` in the
     * warehouse, and neither side of this one is — exactly as neither side of the restock was. The
     * arithmetic is symmetrical, so undoing a receipt that never credited `received` must not debit
     * it now.
     */
    private function addTakeBackOffTheBooks(MovementRequest $request, InventoryMovementGroup $restock): void
    {
        foreach ($restock->getMovements() as $movement) {
            $arrivedInto = $movement->getToDetail();
            if (!$arrivedInto instanceof InventoryDetail || $movement->getQuantity() <= 0) {
                continue;
            }

            $request->remove($movement->getProduct(), self::keyFor($arrivedInto), $movement->getQuantity());
        }
    }

    /**
     * "Here, but written off": `returned → damaged`.
     *
     * The units stay in the building and stay unsellable. `quarantine_quantity` falls and
     * `write_off_quantity` rises by the same figure; `quantity` — the sellable count — never moves,
     * which is the load-bearing property of this answer. Deliberately not modelled as "take them
     * off the books, then write them on again", because a person reading the ledger would see the
     * units leave and re-enter when in fact they never went anywhere.
     *
     * Built as an explicit status move rather than through `AutomaticSourcePicker`, which only ever
     * reads `available` rows and so cannot source stock sitting in quarantine. No stored
     * `InventoryAdjustmentReason` is attached, for the reason the restock group attaches none and
     * the buy side's void attaches none: those rows classify the ADJUSTMENT SCREEN's operations and
     * each declares its own (from_status, to_status) pair — `damaged` declares
     * `available → damaged` — so borrowing one for a movement that starts at `returned` would make
     * the row assert something untrue. The classification lives in the group's reason text.
     */
    private function addWriteOff(MovementRequest $request, InventoryMovementGroup $restock): void
    {
        foreach ($restock->getMovements() as $movement) {
            $arrivedInto = $movement->getToDetail();
            if (!$arrivedInto instanceof InventoryDetail || $movement->getQuantity() <= 0) {
                continue;
            }

            $from = self::keyFor($arrivedInto);

            $request->move(
                $movement->getProduct(),
                $from,
                $from->forStatus(InventoryDetail::STATUS_DAMAGED),
                $movement->getQuantity(),
            );
        }
    }

    /**
     * "Still here": one zero-quantity movement per product, so the ledger says nothing moved.
     *
     * Written directly rather than through `StockMovementService`, because there is no stock write
     * to make and that service is the one implementation of the stock WRITE — it refuses an empty
     * request outright, and `MovementRequest::move()` refuses a zero quantity and a line with
     * neither side, both correctly: every other caller expressing a zero would be a bug. This is
     * the one case where the ABSENCE of a movement is the fact being recorded, so it is built here,
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

    private function restockGroupFor(CreditMemo $memo): ?InventoryMovementGroup
    {
        // The same key CreditMemoRestockSubscriber derives when it writes the receipt.
        return $this->groupByOperationId('credit-memo-restock-' . (string) $memo->getId());
    }

    private function voidOperationId(CreditMemo $memo): string
    {
        return 'credit-memo-void-' . (string) $memo->getId();
    }

    private function groupByOperationId(string $operationId): ?InventoryMovementGroup
    {
        $group = $this->em->getRepository(InventoryMovementGroup::class)
            ->findOneBy(['clientOperationId' => $operationId]);

        return $group instanceof InventoryMovementGroup ? $group : null;
    }

    /**
     * A detail row read back as the key that resolves to it.
     *
     * Round-trips exactly, because DetailKey's five identity fields are the same five
     * InventoryDetailRepository::findOrCreate() looks a row up by — so this cannot silently create
     * a second row for stock that is already somewhere.
     */
    private static function keyFor(InventoryDetail $row): DetailKey
    {
        return new DetailKey(
            $row->getWarehouse(),
            $row->getLocation(),
            $row->getLot(),
            $row->getSerial(),
            $row->getStatus(),
        );
    }
}
