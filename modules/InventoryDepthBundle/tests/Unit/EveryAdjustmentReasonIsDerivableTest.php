<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Unit;

use InventoryDepthBundle\Entity\InventoryAdjustmentReason;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use PHPUnit\Framework\TestCase;

/**
 * The reason list and the (from_status, to_status) mapping it derives cannot drift apart (#585).
 *
 * Same job EveryStatusIsAllocatedTest does for the status groups, and the same shape of argument:
 * everything here is derived from ONE source — InventoryAdjustmentReason::defaults() — and what is
 * asserted is the PARTITION over it, not a second copy of the table.
 *
 * The failures this catches are all one-liners somebody makes while adding a reason, and none of
 * them shows up in a test about movements:
 *
 *  - a reason naming a status that does not exist. DetailKey silently coerces an unrecognised status
 *    to `available`, so the adjustment would appear to work and would put the stock back on the
 *    shelf instead of writing it off.
 *  - a reason naming a DOCUMENT-backed status, which hands this screen exactly the power #581 took
 *    away from it: stock that is sold with no invoice billing it.
 *  - a write-off status with no reason producing it. That is the `expired` hole this issue exists to
 *    fix — spoilage could not be recorded at all, so people mis-filed it as `scrapped`.
 *  - a second reason with `from = NULL`. "Found stock has two correct answers and the screen guides
 *    neither" is the original complaint; the answer is exactly one inbound reason and exactly one
 *    reversal, and a third would put the operator back to guessing.
 */
final class EveryAdjustmentReasonIsDerivableTest extends TestCase
{
    /** @return list<InventoryAdjustmentReason> */
    private function catalogue(): array
    {
        $reasons = [];

        foreach (InventoryAdjustmentReason::defaults() as $default) {
            $reasons[] = (new InventoryAdjustmentReason())
                ->setCode($default['code'])
                ->setLabel($default['label'])
                ->setFromStatus($default['from'])
                ->setToStatus($default['to'])
                ->setReversal($default['reversal'])
                ->setHelp($default['help']);
        }

        return $reasons;
    }

    public function testEveryReasonNamesRealStatusesAndNoneThatADocumentOwns(): void
    {
        foreach ($this->catalogue() as $reason) {
            foreach (['from' => $reason->getFromStatus(), 'to' => $reason->getToStatus()] as $side => $status) {
                if ($status === null) {
                    continue;
                }

                self::assertContains(
                    $status,
                    InventoryDetail::statuses(),
                    sprintf(
                        'reason "%s" moves stock %s "%s", which is not a status — DetailKey would coerce it to '
                        . 'available and the adjustment would silently do the opposite of what it says',
                        $reason->getCode(),
                        $side,
                        $status,
                    ),
                );

                self::assertNotContains(
                    $status,
                    InventoryDetail::documentBackedStatuses(),
                    sprintf(
                        'reason "%s" moves stock %s "%s", and a document owns that status. Stock becomes sold '
                        . 'through the invoice that bills it and in transit through a transfer order (#581)',
                        $reason->getCode(),
                        $side,
                        $status,
                    ),
                );
            }
        }
    }

    public function testTheCodesAreUniqueBecauseEverythingLooksThemUpByCode(): void
    {
        $codes = array_map(static fn (InventoryAdjustmentReason $r): string => $r->getCode(), $this->catalogue());

        self::assertSame(
            array_values(array_unique($codes)),
            $codes,
            'a duplicated code makes findActiveByCode() return whichever row the database felt like, '
            . 'and the seed migration would trip uniq_adjustment_reason_code on the way in',
        );
    }

    /**
     * The write-off statuses this SCREEN can reach partition across its reasons: each is produced by
     * exactly one, and none is left with no way to record it.
     *
     * `expired` having no producer is the concrete bug #585 was raised over. It is asserted by name
     * as well as by the partition, because deriving the expectation from the same list the code
     * under test uses would assert nothing about that particular hole.
     *
     * Document-backed write-off statuses are excluded from the "needs a producer" half on purpose.
     * `returned_to_vendor` (#638) is the first status ever to be BOTH a write-off status (it is
     * folded into the existing `write_off` bucket — see its own docblock for why) AND document-backed
     * (only `VendorReturnShipService` may write it, exactly like `sold`/`in_transit`/`returned`
     * before it — see `testEveryReasonNamesRealStatusesAndNoneThatADocumentOwns()` above, which
     * already refuses any reason naming a document-backed status on either side). Requiring THIS
     * test's producer check to cover it as well would be asking for the one thing the other test
     * forbids: an adjustment-screen reason that reaches a status a document owns.
     */
    public function testEveryWriteOffStatusIsProducedByExactlyOneReason(): void
    {
        $producers = [];

        foreach ($this->catalogue() as $reason) {
            $to = $reason->getToStatus();
            if ($to !== null && in_array($to, InventoryDetail::writeOffStatuses(), true)) {
                self::assertArrayNotHasKey(
                    $to,
                    $producers,
                    sprintf('"%s" is written by both %s and %s — two reasons for one outcome is two reports', $to, $producers[$to] ?? '', $reason->getCode()),
                );
                $producers[$to] = $reason->getCode();
            }
        }

        $reachableWriteOffStatuses = array_values(array_diff(InventoryDetail::writeOffStatuses(), InventoryDetail::documentBackedStatuses()));

        self::assertSame(
            [],
            array_values(array_diff($reachableWriteOffStatuses, array_keys($producers))),
            'a write-off status no reason produces cannot be recorded at all, and gets mis-filed as one that can — '
            . 'which is exactly what happened to spoilage while `expired` was missing from the withdraw list',
        );

        self::assertSame('spoiled', $producers[InventoryDetail::STATUS_EXPIRED] ?? null, 'spoilage is the reason this issue exists');
    }

    /**
     * Quarantine is reachable and releasable, and `returned` is not reachable from this screen AT
     * ALL — not as a destination, and since #596 not as a source either.
     *
     * ## What changed, and what did not
     *
     * When this test was written the argument was that `returned` had no producer: a customer return
     * needed a credit memo linked to the invoice (#586, then unbuilt), so `returned` was in the same
     * state `sold` is in while there is no dispatch layer. Both producers now exist — #586's restock
     * flag and #596's `sales_return` receipt — and `returned` moved into
     * InventoryDetail::documentBackedStatuses() as a result, which is what
     * testEveryReasonNamesRealStatusesAndNoneThatADocumentOwns() above now enforces for both sides
     * of every reason.
     *
     * **No shipped reason broke.** None of the eight ever named `returned` on either side, so the
     * partition below is unchanged, and "Release from hold" still works because it moves
     * `quarantine → available` and `quarantine` is not document-backed.
     *
     * ## The gap this now records, honestly
     *
     * A `returned` row cannot be moved out of by ANY shipped reason. "Release from hold" names the
     * `quarantine` STATUS, not the quarantine BUCKET, so it never could touch one — before #596 or
     * after it. What #596 changed is that an admin can no longer configure a custom reason to do it
     * either, because AdjustmentController re-checks both sides against documentBackedStatuses().
     *
     * That matters for one case in particular: a return received and then DECLINED leaves units in
     * `returned` that nobody has ruled on and no credit is coming for. #596 surfaces them on the
     * RMA's detail screen rather than inventing a disposition, and deliberately does not add a
     * `returned → available` reason here — such a reason would put the ruling on a screen that
     * cannot see which RMA the units came in on, which is the shape this whole list exists to
     * prevent. The ruling belongs on the return, and #596 does not build it.
     *
     * Asserted rather than left to be noticed, exactly as the previous version of this docblock
     * asserted the gap it found.
     */
    public function testQuarantineIsReachableAndReturnedIsDeliberatelyNot(): void
    {
        $into = [];
        $outOf = [];

        foreach ($this->catalogue() as $reason) {
            if ($reason->getToStatus() !== null) {
                $into[$reason->getToStatus()][] = $reason->getCode();
            }
            if ($reason->getFromStatus() !== null) {
                $outOf[$reason->getFromStatus()][] = $reason->getCode();
            }
        }

        self::assertSame(['hold'], $into[InventoryDetail::STATUS_QUARANTINE] ?? [], 'one way to hold stock back');
        self::assertSame(['release_hold'], $outOf[InventoryDetail::STATUS_QUARANTINE] ?? [], 'and one way to let it go again');

        self::assertArrayNotHasKey(
            InventoryDetail::STATUS_RETURNED,
            $into,
            'a customer return is authorised by a sales return and received against it (#596), or credited with '
            . 'restock on a standalone note (#586). A reason here would record the stock half with no document '
            . 'behind it — the forgery #581 removed, in a new place',
        );

        // The other side, added in #596. `returned` is document-backed now, and
        // AdjustmentController refuses it as a SOURCE as well as a destination, so a reason naming
        // it here would be refused at submit() and the screen would offer a choice that cannot be
        // taken. Asserted because the gap it leaves is real and should be found by whoever next
        // wonders why a declined return's units cannot be released from this screen.
        self::assertArrayNotHasKey(
            InventoryDetail::STATUS_RETURNED,
            $outOf,
            'no shipped reason draws FROM `returned`, and since #596 none may: the ruling on returned goods '
            . 'belongs on the RMA that brought them in, not on a screen that cannot see which one that was',
        );

        // Stated as a fact rather than derived, because it is the sentence somebody will come
        // looking for: releasing a hold acts on the `quarantine` STATUS, not on the quarantine
        // BUCKET, and `returned` is the other half of that bucket.
        $releaseHold = null;
        foreach (InventoryAdjustmentReason::defaults() as $default) {
            if ($default['code'] === InventoryAdjustmentReason::CODE_RELEASE_HOLD) {
                $releaseHold = $default;
            }
        }

        self::assertSame(
            InventoryDetail::STATUS_QUARANTINE,
            $releaseHold['from'] ?? null,
            '"Release from hold" releases `quarantine` rows specifically — it has never been able to release a '
            . '`returned` one, which is why #596 breaking it was checked and it was not broken',
        );
    }

    /**
     * Exactly one reason invents stock, exactly one gives written-off stock back, and the two are
     * different reasons.
     *
     * This is the complaint #585 opens with: found stock has two correct answers — `— → available`
     * for goods never counted and `write-off → available` for goods previously written off — and the
     * old screen guided neither, so the first silently invented stock while leaving the loss on the
     * books. The fix is that both exist, are named, and are distinguishable; a third would put the
     * guessing back.
     */
    public function testThereIsExactlyOneWayToInventStockAndExactlyOneWayToGiveItBack(): void
    {
        $inbound = [];
        $reversals = [];

        foreach ($this->catalogue() as $reason) {
            if ($reason->isInbound()) {
                $inbound[] = $reason->getCode();
            }
            if ($reason->isReversal()) {
                $reversals[] = $reason->getCode();
            }
        }

        self::assertSame(['stock_found'], $inbound, 'only one reason may put units into the ledger from nowhere');
        self::assertSame(['reverse_write_off'], $reversals, 'and only one may undo an entry that took them out');

        // The two are not the same row, and neither is both. isInbound() excludes reversals on
        // purpose — a reversal carries a NULL from_status too, and treating it as inbound would
        // receive the units a second time while leaving the write-off standing.
        self::assertSame([], array_intersect($inbound, $reversals));
    }

    /**
     * The movement type is derived from the sides, never chosen.
     *
     * The old form offered all eight types in a dropdown unrelated to what it did, so a write-off
     * could be filed as a `receipt` and the ledger's type filter meant nothing. Each derived value
     * is asserted by name, because deriving the expectation from movementType() itself would assert
     * only that the method is deterministic.
     */
    public function testTheMovementTypeFollowsFromTheSides(): void
    {
        $types = [];

        foreach ($this->catalogue() as $reason) {
            self::assertContains(
                $reason->movementType(),
                InventoryMovementGroup::types(),
                sprintf('reason "%s" derives a movement type the group entity would silently replace with `adjustment`', $reason->getCode()),
            );
            $types[$reason->getCode()] = $reason->movementType();
        }

        self::assertSame([
            'stock_found' => InventoryMovementGroup::TYPE_RECEIPT,
            'reverse_write_off' => InventoryMovementGroup::TYPE_STATUS_CHANGE,
            'damaged' => InventoryMovementGroup::TYPE_STATUS_CHANGE,
            'spoiled' => InventoryMovementGroup::TYPE_STATUS_CHANGE,
            'scrapped' => InventoryMovementGroup::TYPE_STATUS_CHANGE,
            'lost' => InventoryMovementGroup::TYPE_STATUS_CHANGE,
            'hold' => InventoryMovementGroup::TYPE_STATUS_CHANGE,
            'release_hold' => InventoryMovementGroup::TYPE_STATUS_CHANGE,
        ], $types);
    }

    /**
     * The bucket effect the screen shows an operator agrees with what the buckets will actually do.
     *
     * It is display-only and nothing writes from it, which is precisely why it can go quietly wrong:
     * the number recomputed by StockMovementService would still be right and the sentence under the
     * radio button would be lying. The two reasons that differ ONLY here are the ones an operator
     * has to tell apart.
     */
    public function testTheBucketEffectShownMatchesWhatTheBucketsDo(): void
    {
        $effects = [];
        foreach ($this->catalogue() as $reason) {
            $effects[$reason->getCode()] = $reason->bucketEffect();
        }

        self::assertSame([
            'stock_found' => 'received +',
            'reverse_write_off' => 'write_off −',
            'damaged' => 'write_off +',
            'spoiled' => 'write_off +',
            'scrapped' => 'write_off +',
            'lost' => 'write_off +',
            'hold' => 'quarantine +',
            'release_hold' => 'quarantine −',
        ], $effects);
    }

    /**
     * "Spoiled / expired" is the only reason that starts its lot list at batches past their date,
     * and it is derived from the destination rather than ticked on a column.
     */
    public function testOnlyTheSpoilageReasonPrefersExpiredLots(): void
    {
        $prefers = [];
        foreach ($this->catalogue() as $reason) {
            if ($reason->prefersExpiredLots()) {
                $prefers[] = $reason->getCode();
            }
        }

        self::assertSame(['spoiled'], $prefers);
    }
}
