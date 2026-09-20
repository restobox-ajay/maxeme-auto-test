<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Pick;

use App\Entity\ProductCore;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use WarehouseOpsBundle\Entity\PickList;
use WarehouseOpsBundle\Entity\PickTask;
use WarehouseOpsBundle\Pick\PickConfirmationException;
use WarehouseOpsBundle\Pick\PickConfirmationService;
use WarehouseOpsBundle\Pick\PickListCompiler;
use WarehouseOpsBundle\Pick\PickSource;
use WarehouseOpsBundle\Tests\Support\WarehouseOpsTestCase;

/**
 * The two properties everything else in this bundle rests on:
 *
 *  1. **A confirmed pick does not change the product's total.** Picked stock has left the shelf and
 *     not the building, so it stays `available` in the staging bin and the number recomputes to
 *     itself. This is what keeps the order's sales hold from subtracting the same units a second
 *     time — the ordering constraint the plan calls the most consequential in the warehouse stack.
 *  2. **A short pick is three facts and produces two movement groups**, not one of either.
 */
final class PickConfirmationServiceTest extends WarehouseOpsTestCase
{
    private PickConfirmationService $confirmations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmations = self::getContainer()->get(PickConfirmationService::class);
    }

    /**
     * $bin is the bin the compiler would have printed on the task — and, since #589, the only bin a
     * short pick may be written off from. It is passed explicitly rather than defaulted, because
     * which bin a task names is now load-bearing and a fixture should have to say.
     */
    private function listWithTask(int $requested, ?WarehouseLocation $bin = null): PickTask
    {
        $list = (new PickList())
            ->setNumber('PL-000001')
            ->setWarehouse($this->warehouse)
            ->setStagingLocation($this->staging)
            ->setStatus(PickList::STATUS_RELEASED);

        $task = (new PickTask())
            ->setProduct($this->product)
            ->setOrderNumber('SO-1')
            ->setSku($this->product->getSku())
            ->setName($this->product->getName())
            ->setSuggestedLocation($bin)
            ->setQuantityRequested($requested);

        $list->addTask($task);
        $this->em->persist($list);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    public function testAFullPickLeavesTheProductTotalExactlyWhereItWas(): void
    {
        $this->receive(30, $this->binA);
        self::assertSame(30, $this->coreQuantity());

        $task = $this->listWithTask(12);
        $result = $this->confirmations->confirm($task, 12, 'op-1', 'tester');

        self::assertSame('12.0000', $result->picked);
        self::assertSame('0.0000', $result->missing);
        self::assertSame('0.0000', $result->outstanding);

        // The whole point: stock left the shelf, not the building.
        self::assertSame(30, $this->coreQuantity(), 'a pick must not change the product total');
        self::assertSame(18, $this->inBin($this->binA));
        self::assertSame(12, $this->inBin($this->staging));

        self::assertInstanceOf(InventoryMovementGroup::class, $result->pickGroup);
        self::assertSame(InventoryMovementGroup::TYPE_PICK, $result->pickGroup->getType());
        self::assertNull($result->adjustmentGroup, 'nothing was missing, so nothing was written off');
    }

    public function testTheInvariantStillHoldsAfterAPick(): void
    {
        $this->receive(30, $this->binA);
        $task = $this->listWithTask(12);

        $this->confirmations->confirm($task, 12, 'op-2', 'tester');

        self::assertSame(
            $this->wholeUnits($this->details->availableTotal($this->product, $this->warehouse)),
            $this->coreQuantity(),
            'product_inventory.quantity must still equal SUM(available detail)',
        );
    }

    public function testAShortPickWritesBothTheMovementAndTheAdjustment(): void
    {
        // The list says 20; the shelf holds 20 as far as the system knows; the picker finds 16.
        $this->receive(20, $this->binA);
        $task = $this->listWithTask(20, $this->binA);

        $result = $this->confirmations->confirm($task, 16, 'op-3', 'tester');

        // 1. what moved
        self::assertSame('16.0000', $result->picked);
        self::assertInstanceOf(InventoryMovementGroup::class, $result->pickGroup);
        self::assertSame(InventoryMovementGroup::TYPE_PICK, $result->pickGroup->getType());

        // 2. what the system expected and the shelf did not have — its own group, with a reason
        self::assertSame('4.0000', $result->missing);
        self::assertInstanceOf(InventoryMovementGroup::class, $result->adjustmentGroup);
        self::assertSame(InventoryMovementGroup::TYPE_ADJUSTMENT, $result->adjustmentGroup->getType());
        self::assertStringContainsString('Short pick', (string) $result->adjustmentGroup->getReason());

        // 3. what the order still needs
        self::assertSame('0.0000', $result->outstanding, '20 asked for, 16 found and 4 written off leaves nothing unaccounted');

        // The four are gone from the system, which is what "they are not there" means. The sixteen
        // are still in it, in staging.
        self::assertSame(16, $this->coreQuantity());
        self::assertSame(0, $this->inBin($this->binA));
        self::assertSame(16, $this->inBin($this->staging));
    }

    /**
     * Stock the picker could not find lands in the WRITE-OFF bucket, not in `received` (#581).
     *
     * Availability is the same either way, which is exactly why this needs its own test: the old
     * behaviour — a movement with no destination, so `received` absorbed the loss — gave an
     * identical Available figure and passed every assertion above. The difference is only visible
     * in WHICH column carries it, and that difference is the whole point. Shrinkage found at the
     * bin has to be legible as shrinkage; charged to `received` it is indistinguishable from a
     * delivery that never arrived, and accounting has nowhere to look for it.
     *
     * Asserted on the columns directly rather than through availability, for the same reason the
     * bundle-toggle Cest reads columns: two different bookkeepings of the same event agree on the
     * total and disagree about everything else.
     */
    public function testStockMissingAtTheBinIsWrittenOffRatherThanChargedToReceived(): void
    {
        $this->receive(20, $this->binA);
        $task = $this->listWithTask(20, $this->binA);

        $before = $this->inventoryRow();
        $receivedBefore = $before->getReceivedQuantity();

        $this->confirmations->confirm($task, 16, 'op-short-bucket', 'tester');

        $after = $this->inventoryRow();

        self::assertSame('4.0000', $after->getWriteOffQuantity(), 'the four nobody could find are a write-off');
        self::assertSame($receivedBefore, $after->getReceivedQuantity(), 'and receiving is not where a loss belongs');

        // The detail side agrees: four units carrying a status that says what happened to them,
        // with no bin, because `lost` is terminal and naming a bin would claim to know where they are.
        $lost = $this->em->getRepository(InventoryDetail::class)->findBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
            'status' => InventoryDetail::STATUS_LOST,
        ]);

        self::assertCount(1, $lost, 'one row, since all four came off the same bin, lot and serial');
        self::assertSame('4.0000', $lost[0]->getQuantity());
        self::assertNull($lost[0]->getLocation(), 'a terminal status drops the bin');

        // And the number the storefront reads is unchanged by the move between columns.
        self::assertSame(16, $this->coreQuantity(), 'availability is the same bookkeeping either way');
    }

    public function testUnitsTheSystemNeverHadAreNotWrittenOffAndStayOwedToTheOrder(): void
    {
        // The list asks for 20 because the ORDER owes 20. The shelf only ever had 5.
        $this->receive(5, $this->binA);
        $task = $this->listWithTask(20, $this->binA);

        $result = $this->confirmations->confirm($task, 5, 'op-4', 'tester');

        self::assertSame('5.0000', $result->picked);
        self::assertSame('0.0000', $result->missing, 'there is nothing to adjust for units the system never had');
        self::assertSame('15.0000', $result->outstanding, 'the order still needs them — top up or backorder');
        self::assertNull($result->adjustmentGroup);
        self::assertSame(5, $this->coreQuantity());
    }

    public function testTheRoundIsDrawnFromTheEarliestExpiringLotFirst(): void
    {
        $soon = $this->lot('EARLY', '2026-09-01');
        $later = $this->lot('LATE', '2027-09-01');

        // Deliberately received in the wrong order, and into the LOWER-sorted bin, so bin order
        // alone cannot produce the right answer.
        $this->receive(10, $this->binA, $later);
        $this->receive(10, $this->binB, $soon);

        $task = $this->listWithTask(10);
        $this->confirmations->confirm($task, 10, 'op-5', 'tester');

        self::assertSame(0, $this->inBin($this->binB, $soon), 'the earliest-expiring lot is taken first');
        self::assertSame(10, $this->inBin($this->binA, $later));
    }

    public function testAReplayedConfirmationMovesStockOnce(): void
    {
        $this->receive(30, $this->binA);
        $task = $this->listWithTask(10);

        $this->confirmations->confirm($task, 10, 'retry-me', 'tester');
        $this->confirmations->confirm($task, 10, 'retry-me', 'tester');

        self::assertSame(10, $this->inBin($this->staging), 'warehouse wi-fi is bad; a retried submit must apply once');
        self::assertSame(20, $this->inBin($this->binA));
        self::assertSame(30, $this->coreQuantity());
    }

    public function testAProductBackOnSimpleInventoryIsRefusedWithASentence(): void
    {
        $this->receive(10, $this->binA);
        $task = $this->listWithTask(5);

        $this->product->setInventoryMode(ProductCore::INVENTORY_MODE_SIMPLE);
        $this->em->flush();

        try {
            $this->confirmations->confirm($task, 5, 'op-6', 'tester');
            self::fail('a product back on simple inventory has no breakdown to pick from');
        } catch (PickConfirmationException $e) {
            self::assertMatchesRegularExpression('/simple inventory/', $e->getMessage());
        }

        // "It threw" is not "nothing moved" (#594). Defence in depth — StockMovementService refuses
        // a simple-inventory product on its own account, so relocating the isDimensional() guard
        // below the allocation cannot actually strand stock here — but the claim this method's name
        // makes is about the shelf, and the shelf was never read. The sibling refusal below already
        // asserts this way, and for the same reason.
        self::assertSame(10, $this->inBin($this->binA), 'nothing moved');
        self::assertSame(0, $this->inBin($this->staging));
        self::assertSame([], $this->lostRows(), 'and nothing was written off');
    }

    public function testAListWithNoStagingBinIsRefusedBeforeAnythingMoves(): void
    {
        $this->receive(10, $this->binA);

        $list = (new PickList())->setNumber('PL-000002')->setWarehouse($this->warehouse)->setStatus(PickList::STATUS_RELEASED);
        $task = (new PickTask())
            ->setProduct($this->product)
            ->setOrderNumber('SO-2')
            ->setName('Widget')
            ->setQuantityRequested(5);
        $list->addTask($task);
        $this->em->persist($list);
        $this->em->persist($task);
        $this->em->flush();

        try {
            $this->confirmations->confirm($task, 5, 'op-7', 'tester');
            self::fail('a list with nowhere to put picked stock must refuse');
        } catch (PickConfirmationException $e) {
            self::assertStringContainsString('staging bin', $e->getMessage());
        }

        self::assertSame(10, $this->inBin($this->binA), 'nothing moved');
    }

    public function testASecondRoundDoesNotDrawOnTheFirstRoundsStagedStock(): void
    {
        $this->receive(10, $this->binA);

        $first = $this->listWithTask(10);
        $this->confirmations->confirm($first, 10, 'op-8', 'tester');
        self::assertSame(10, $this->inBin($this->staging));

        // Nothing is left on any pick face. A second round must find nothing rather than taking
        // back what the first round staged.
        $second = (new PickTask())
            ->setProduct($this->product)
            ->setOrderNumber('SO-3')
            ->setName('Widget')
            ->setQuantityRequested(4);
        $first->getPickList()->addTask($second);
        $this->em->persist($second);
        $this->em->flush();

        $result = $this->confirmations->confirm($second, 4, 'op-9', 'tester');

        self::assertSame('0.0000', $result->picked);
        self::assertSame('4.0000', $result->outstanding);
        self::assertSame(10, $this->inBin($this->staging), 'the first round keeps what it staged');
    }

    /**
     * A picker cannot write off a bin they were never sent to (#589).
     *
     * This is the whole bug, and the reason it survived every other test in this file is structural:
     * the fixtures above put stock in ONE bin, so "the bin the picker was at" and "this product's
     * stock in this warehouse" were the same set of rows and nothing could tell the two apart.
     *
     * Two bins tell them apart. The picker is sent to A, which holds 5 of the 20 the list asks for.
     * They find 5 and type 5 — the ordinary thing to do, not a report of shrinkage. Bin B, three
     * aisles away, holds the other 15, and nobody has been near it.
     *
     * Before the fix the shortfall of 15 was allocated against every available row in the warehouse
     * and bin B was moved to `lost` with its bin dropped — 15 real units destroyed on the strength
     * of a count taken somewhere else. The row quantities are asserted directly rather than through
     * availability, because the destroyed units and the intact ones produce very different bin rows
     * and, in the case this guards, the same shape of success message.
     */
    public function testAShortPickLeavesStockInBinsThePickerWasNeverSentToAlone(): void
    {
        $this->receive(5, $this->binA);
        $this->receive(15, $this->binB);

        $task = $this->listWithTask(20, $this->binA);

        $result = $this->confirmations->confirm($task, 5, 'op-two-bins', 'tester');

        // First, and deliberately first, the physical fact — so that restoring the warehouse-wide
        // population makes this test fail naming bin B rather than tripping over a bookkeeping
        // assertion on the way there.
        self::assertSame(
            15,
            $this->inBin($this->binB),
            'bin B is three aisles away and nobody has been near it; a count taken at bin A is no evidence about it',
        );

        self::assertSame('5.0000', $result->picked, 'the five that were actually in bin A');
        self::assertSame(
            '0.0000',
            $result->missing,
            'bin A was emptied by the pick itself, so it holds nothing left to declare missing',
        );
        self::assertNull($result->adjustmentGroup, 'no adjustment, because nothing was counted short at the bin');

        self::assertSame(0, $this->inBin($this->binA), 'bin A gave up everything it had');
        self::assertSame(5, $this->inBin($this->staging));

        self::assertSame([], $this->lostRows(), 'nothing was lost — it is on a shelf, and the shelf can say which');
        self::assertSame('0.0000', $this->inventoryRow()?->getWriteOffQuantity(), 'and nothing reached the write-off bucket');

        // And the advice the screen gives is true again: there IS another lot to top up from.
        self::assertSame('15.0000', $result->outstanding, 'the order still needs 15, and bin B can still supply them');
        self::assertSame(20, $this->coreQuantity(), 'twenty units received, twenty units still sellable');
    }

    /**
     * The scope is a cap, not an off switch.
     *
     * Bin A really is short — the system says 10 and the picker finds 6 — so four units are written
     * off, exactly as #552 requires and #581 books them. What must NOT happen is the remaining ten
     * of the declared shortfall spilling onto bin B, which is the same allocation walking past the
     * end of its evidence.
     */
    public function testAGenuineShortfallIsWrittenOffButCappedAtWhatThatBinHeld(): void
    {
        $this->receive(10, $this->binA);
        $this->receive(15, $this->binB);

        $task = $this->listWithTask(20, $this->binA);

        $result = $this->confirmations->confirm($task, 6, 'op-capped', 'tester');

        self::assertSame('6.0000', $result->picked);
        self::assertSame('4.0000', $result->missing, 'bin A said 10 and held 6; the four it did not hold are a write-off');
        self::assertInstanceOf(InventoryMovementGroup::class, $result->adjustmentGroup);
        self::assertSame(InventoryMovementGroup::TYPE_ADJUSTMENT, $result->adjustmentGroup->getType());

        self::assertSame(0, $this->inBin($this->binA), 'six picked and four written off empties it');
        self::assertSame(15, $this->inBin($this->binB), 'the cap held: not one unit of bin B was reached');
        self::assertSame('4.0000', $this->inventoryRow()?->getWriteOffQuantity(), 'four, not fourteen');

        self::assertSame('10.0000', $result->outstanding, 'the order still needs ten — top up from bin B or backorder');
    }

    /**
     * No bin on the task means no write-off at all, and no fallback to the warehouse (#589).
     *
     * `suggested_location_id` is null when the compiler could not name a bin, which is precisely
     * when the system is least sure where anything is. Falling back to "well, the warehouse then"
     * reinstates the destructive behaviour for exactly those tasks.
     *
     * So nothing is written off. The shortfall does not vanish either: it stays on the task as
     * outstanding, and the confirmation reports it separately as unattributed, so the screen can say
     * that a count is owed rather than quietly implying one already happened.
     */
    public function testATaskWithNoBinWritesNothingOffAndReportsTheShortfallAsStillOwed(): void
    {
        $this->receive(5, $this->binA);
        $this->receive(15, $this->binB);

        $task = $this->listWithTask(20, null);
        self::assertNull($task->getSuggestedLocation(), 'the fixture under test: a task that names no bin');

        $result = $this->confirmations->confirm($task, 5, 'op-no-bin', 'tester');

        self::assertSame('5.0000', $result->picked);
        self::assertSame('0.0000', $result->missing, 'nothing may be destroyed on the word of a task that cannot say where it stood');
        self::assertNull($result->adjustmentGroup, 'and therefore no adjustment group at all');

        // The shortfall is still owed, and still says so.
        self::assertSame('15.0000', $result->outstanding, 'the order needs 15 more than it got');
        self::assertSame('15.0000', $task->outstanding(), 'and the task itself carries it, for a top-up or a backorder');
        self::assertSame('0.0000', $task->getQuantityMissing(), 'nothing was counted missing, because nobody could say at which bin');
        self::assertSame(
            '15.0000',
            $result->unattributed,
            'the number the old code destroyed is the number this one reports',
        );

        // Every unit that was on a shelf is still on that shelf.
        self::assertSame(0, $this->inBin($this->binA));
        self::assertSame(15, $this->inBin($this->binB), 'the warehouse-wide fallback is what this test exists to forbid');
        self::assertSame(5, $this->inBin($this->staging));
        self::assertSame([], $this->lostRows());
        self::assertSame(20, $this->coreQuantity());
    }

    /**
     * The pick is recorded against the shelf the round named, not against the earliest-expiring lot
     * in the building (#591).
     *
     * This is the bug in one fixture. Bin B holds a lot that expires first, so it is the front of
     * pickableRows() — which is correct for the question that query answers, "where SHOULD this be
     * picked from", and wrong for the one a confirmation answers, "where WAS it". The picker is sent
     * to bin A, finds five and takes five.
     *
     * Before the fix those five came off bin B. Both bins were then wrong in opposite directions,
     * the warehouse total was right, every unit was still `available`, and no screen anywhere
     * contradicted it — which is why this needs a test asserting the two bin rows directly rather
     * than any total.
     */
    public function testThePickComesOffTheBinTheRoundNamedAndNotTheEarliestExpiringOne(): void
    {
        $late = $this->lot('LATE', '2027-09-01');
        $soon = $this->lot('EARLY', '2026-09-01');

        $this->receive(10, $this->binA, $late);
        $this->receive(10, $this->binB, $soon);

        $task = $this->listWithTask(5, $this->binA);

        $result = $this->confirmations->confirm($task, 5, 'op-591-suggested', 'tester');

        self::assertSame('5.0000', $result->picked);
        self::assertSame(5, $this->inBin($this->binA, $late), 'the five came off the shelf the picker was sent to');
        self::assertSame(10, $this->inBin($this->binB, $soon), 'and bin B, which nobody visited, still holds all ten');
        self::assertSame(5, $this->inBin($this->staging, $late), 'staging carries the lot that was actually picked');
        self::assertSame(20, $this->coreQuantity(), 'a pick still leaves the total alone');
    }

    /**
     * A picker who went somewhere else has it recorded where they went (#591).
     *
     * The printout said bin A. The picker found bin A empty of what they wanted, walked to bin B and
     * took ten. Saying so on the form is the whole point of the field: scoping the record to
     * `pick_task.suggested_location_id` instead would put those ten on bin A, which is a different
     * wrong answer, and is why that shortcut was rejected rather than shipped.
     */
    public function testAPickerWhoWentToAnotherBinHasItRecordedAtThatBin(): void
    {
        $this->receive(10, $this->binA);
        $this->receive(10, $this->binB);

        $task = $this->listWithTask(10, $this->binA);

        $result = $this->confirmations->confirm($task, 10, 'op-591-elsewhere', 'tester', PickSource::bin($this->binB));

        self::assertSame('10.0000', $result->picked);
        self::assertSame($this->binB->getId(), $result->source?->bin?->getId(), 'the confirmation names where it happened');

        self::assertSame(10, $this->inBin($this->binA), 'bin A was not touched, because the picker said they were not there');
        self::assertSame(0, $this->inBin($this->binB), 'bin B gave up the ten');
        self::assertSame(10, $this->inBin($this->staging));
        self::assertSame(20, $this->coreQuantity());
    }

    /**
     * The write-off follows the picker, not the printout (#589 scoped by #591).
     *
     * The round said bin A. The picker went to bin B, where the system says ten and there are six.
     * Four are written off — at bin B, because that is where the count was taken. Bin A, which
     * nobody visited, keeps every unit it has.
     *
     * This is the case that shows taking the picker's answer over the task's is TIGHTER than #589
     * left it, not looser: before the field existed, this count of bin B was charged against bin A.
     */
    public function testAShortfallCountedAtAnotherBinIsWrittenOffAtThatBinAndNotAtTheSuggestedOne(): void
    {
        $this->receive(10, $this->binA);
        $this->receive(10, $this->binB);

        $task = $this->listWithTask(10, $this->binA);

        $result = $this->confirmations->confirm($task, 6, 'op-591-shortfall', 'tester', PickSource::bin($this->binB));

        self::assertSame(10, $this->inBin($this->binA), 'the suggested bin is untouched — nobody counted it');

        self::assertSame('6.0000', $result->picked);
        self::assertSame('4.0000', $result->missing, 'bin B said ten and held six');
        self::assertInstanceOf(InventoryMovementGroup::class, $result->adjustmentGroup);
        self::assertSame(0, $this->inBin($this->binB), 'six picked and four written off empties bin B');
        self::assertSame('4.0000', $this->inventoryRow()?->getWriteOffQuantity(), 'four, and none of bin A');
    }

    /**
     * Typing more than the named shelf holds does NOT quietly take the rest off another one (#591).
     *
     * The picker says twenty came off bin A. The system shows bin A holding five. The five move; the
     * other fifteen do not, because sourcing them from bin B would be recording a count taken at one
     * shelf against another — the same shape as the write-off bug #589 fixed, on the non-destructive
     * side of the ledger.
     *
     * The difference is reported rather than swallowed, so the screen can say a bin needs counting
     * instead of implying the order simply ran short.
     */
    public function testUnitsBeyondWhatTheNamedBinHoldsAreNotTakenFromAnotherBin(): void
    {
        $this->receive(5, $this->binA);
        $this->receive(15, $this->binB);

        $task = $this->listWithTask(20, $this->binA);

        $result = $this->confirmations->confirm($task, 20, 'op-591-overtyped', 'tester', PickSource::bin($this->binA));

        self::assertSame(15, $this->inBin($this->binB), 'bin B is three aisles away and was not made to cover the difference');

        self::assertSame('5.0000', $result->picked, 'only what bin A actually had');
        self::assertSame('15.0000', $result->unmoved, 'and the fifteen the picker claims are said out loud');
        self::assertSame('0.0000', $result->missing, 'bin A was emptied by the pick, so it holds nothing left to declare missing');
        self::assertNull($result->adjustmentGroup);
        self::assertSame([], $this->lostRows(), 'nothing was destroyed on the strength of an over-typed number');
        self::assertSame('15.0000', $result->outstanding, 'the order goes on owing them');
        self::assertSame(20, $this->coreQuantity());
    }

    /** Stock that sits in no bin is a real place, and the picker can name it (#591). */
    public function testStockInNoBinCanBeNamedAsTheSource(): void
    {
        $this->receive(8, null);
        $this->receive(10, $this->binA);

        $task = $this->listWithTask(8, $this->binA);

        $result = $this->confirmations->confirm($task, 8, 'op-591-unbinned', 'tester', PickSource::unbinned());

        self::assertSame('8.0000', $result->picked);
        self::assertSame(10, $this->inBin($this->binA), 'the binned stock was not touched');
        self::assertSame(8, $this->inBin($this->staging));
        self::assertSame(18, $this->coreQuantity());
    }

    /**
     * The staging bin is where picked stock is going, so it cannot be where it came from.
     *
     * It is already excluded from the pick faces, so naming it could only ever move nothing. A
     * picker who typed twelve and watched nothing happen is owed a sentence rather than a silence.
     */
    public function testNamingTheStagingBinAsTheSourceIsRefusedWithASentence(): void
    {
        $this->receive(10, $this->binA);
        $task = $this->listWithTask(5, $this->binA);

        try {
            $this->confirmations->confirm($task, 5, 'op-591-staging', 'tester', PickSource::bin($this->staging));
            self::fail('naming the staging bin as a source must refuse');
        } catch (PickConfirmationException $e) {
            self::assertStringContainsString('staging bin', $e->getMessage());
        }

        self::assertSame(10, $this->inBin($this->binA), 'nothing moved');
        self::assertSame(0, $this->inBin($this->staging));
    }

    /**
     * Picking into a staging bin that already holds the same lot lands in the row that is there.
     *
     * `inventory_detail` carries a unique index over
     * `(product, warehouse, COALESCE(location,0), COALESCE(lot,0), COALESCE(serial,''), status)`, so
     * a second staging row for the same lot is not a duplicate the app can live with — it is a
     * constraint violation in a picker's face on the migrated schema, and a silent duplicate on the
     * metadata-built one both test suites use. Neither is acceptable, which is why the destination is
     * resolved by LOOKUP through InventoryDetailRepository::findOrCreate() rather than created.
     *
     * Asserted as a row COUNT as well as a quantity, deliberately: the quantity assertion alone
     * passes on the duplicate too, since availability sums both rows.
     */
    public function testASecondPickIntoTheStagingBinMergesIntoTheRowAlreadyThere(): void
    {
        $lot = $this->lot('MERGE-1', '2027-01-01');
        $this->receive(20, $this->binA, $lot);

        $first = $this->listWithTask(6, $this->binA);
        $this->confirmations->confirm($first, 6, 'op-591-merge-a', 'tester', PickSource::bin($this->binA));

        $second = (new PickTask())
            ->setProduct($this->product)
            ->setOrderNumber('SO-MERGE')
            ->setName('Widget')
            ->setSuggestedLocation($this->binA)
            ->setQuantityRequested(4);
        $first->getPickList()->addTask($second);
        $this->em->persist($second);
        $this->em->flush();

        $this->confirmations->confirm($second, 4, 'op-591-merge-b', 'tester', PickSource::bin($this->binA));

        $staged = $this->em->getRepository(InventoryDetail::class)->findBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
            'location' => $this->staging,
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);

        self::assertCount(1, $staged, 'one staging row for that lot, not two — the unique index says so');
        self::assertSame('10.0000', $staged[0]->getQuantity());
        self::assertSame(10, $this->inBin($this->binA, $lot));
        self::assertSame(20, $this->coreQuantity());
    }

    /** @return list<InventoryDetail> */
    private function lostRows(): array
    {
        /** @var list<InventoryDetail> $rows */
        $rows = $this->em->getRepository(InventoryDetail::class)->findBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
            'status' => InventoryDetail::STATUS_LOST,
        ]);

        return $rows;
    }

    public function testCompilerAndConfirmationAgreeOnWhatAnOrderOwes(): void
    {
        $this->receive(30, $this->binA);
        $order = $this->approvedOrder(7);

        $compiler = self::getContainer()->get(PickListCompiler::class);
        $compiled = $compiler->compile($this->warehouse, [$order]);
        $compiled->list->setStagingLocation($this->staging);
        $compiler->persist($compiled->list);
        $this->em->flush();

        self::assertCount(1, $compiled->list->getTasks());

        /** @var PickTask $task */
        $task = $compiled->list->getTasks()->first();
        self::assertSame('7.0000', $task->getQuantityRequested(), 'the round asks for the uninvoiced remainder');
        self::assertSame($this->binA->getId(), $task->getSuggestedLocation()?->getId());
        self::assertSame(10, $task->getSortKey(), 'the route position is copied off the bin');

        $this->confirmations->confirm($task, 7, 'op-10', 'tester');
        // A pick moves stock from a pick face to the staging bin. Both are `available` rows in the
        // same warehouse, so nothing physical entered or left and no bucket may move.
        self::assertSame(30, $this->physicalTotal(), 'a pick moves stock, it does not consume it');

        // Availability is a different number, and always was: the approved order holds 7 of the 30.
        // This used to assert 30 here through a helper that returns availability, which is why it
        // read as a movement-layer failure when the 7 was the sales hold doing its job.
        self::assertSame(23, $this->coreQuantity(), '30 on the shelf, 7 committed to SO-1');
    }
}
