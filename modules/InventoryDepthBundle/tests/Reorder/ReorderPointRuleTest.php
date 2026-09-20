<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Reorder;

use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\Inventory\InboundTransferResolver;
use InventoryDepthBundle\Entity\ProductReorderRule;
use InventoryDepthBundle\Reorder\ReorderAssessment;
use InventoryDepthBundle\Reorder\ReorderPointRule;
use PHPUnit\Framework\TestCase;

/**
 * The reorder rule itself (#597): available, plus what is already on its way, against the level.
 *
 * No kernel and no database. Every term the rule reads is a figure on an entity, and the one piece
 * of ambient state it depends on — whether ProcurementBundle is Active — reaches it as a flag
 * `App\EventSubscriber\BundleBucketAvailabilityGate` stamps on the row, which a test can set
 * directly. So the arithmetic is testable exactly as arithmetic, which is the point of it being a
 * class of its own rather than a query inside the controller.
 *
 * `transferIncoming` (#706) is exercised by {@see \InventoryDepthBundle\Reorder\ReorderAssessment}'s
 * own test, not here: this class's `InboundTransferResolver` is wired with no providers, exactly
 * like {@see \App\Tests\Service\Inventory\InventoryModeResolverTest}'s no-provider case, so it
 * always reads 0 here and none of the arithmetic below changes shape.
 */
final class ReorderPointRuleTest extends TestCase
{
    private ReorderPointRule $rule;

    protected function setUp(): void
    {
        $bundleStatus = $this->createStub(BundleStatusRepository::class);
        $bundleStatus->method('isActive')->willReturn(true);

        $this->rule = new ReorderPointRule(new InboundTransferResolver([], $bundleStatus));
    }

    /**
     * The plain case. Nothing on order, sellable stock under the level: short by the difference.
     *
     * `product_inventory.quantity` 4, `incoming_quantity` 0, level 10 — available 4, projected 4,
     * short by 6.
     */
    public function testARowUnderItsLevelWithNothingComingIsShort(): void
    {
        $assessment = $this->rule->assess($this->level(10), $this->inventory(quantity: 4, incoming: 0));

        self::assertSame('4.0000', $assessment->available);
        self::assertSame('4.0000', $assessment->projected());
        self::assertTrue($assessment->belowPoint());
        self::assertTrue($assessment->short());
        self::assertFalse($assessment->covered());
        self::assertSame('6.0000', $assessment->shortfall());
        self::assertSame(ReorderAssessment::STATUS_SHORT, $assessment->status());
    }

    /**
     * THE case this issue waited on #583 for.
     *
     * The row is below its level on sellable stock and would have been flagged every day forever —
     * but a purchase order already covers the gap, so it is reported as covered and NOT as
     * something to act on. `quantity` 4, `incoming_quantity` 50, level 10: projected 54.
     */
    public function testAGapAlreadyCoveredByAPurchaseOrderIsNotShort(): void
    {
        $assessment = $this->rule->assess($this->level(10), $this->inventory(quantity: 4, incoming: 50));

        self::assertSame('4.0000', $assessment->available, 'incoming is not sellable and never enters availability');
        self::assertSame('54.0000', $assessment->projected());
        self::assertTrue($assessment->belowPoint(), 'it really is below its level on stock that exists');
        self::assertFalse($assessment->short(), 'but the order already closes the gap');
        self::assertTrue($assessment->covered());
        self::assertSame('0.0000', $assessment->shortfall());
        self::assertSame(ReorderAssessment::STATUS_COVERED, $assessment->status());
    }

    /** Partly covered is still short — by what the order does NOT cover, not by the whole gap. */
    public function testAnOrderThatOnlyPartlyCoversTheGapLeavesTheRemainderShort(): void
    {
        $assessment = $this->rule->assess($this->level(100), $this->inventory(quantity: 10, incoming: 40));

        self::assertSame('50.0000', $assessment->projected());
        self::assertTrue($assessment->short());
        self::assertSame('50.0000', $assessment->shortfall(), 'the 40 on order are counted, the remaining 50 are not');
    }

    /**
     * With ProcurementBundle Inactive, `incoming_quantity` is read as 0 rather than trusted.
     *
     * The column keeps whatever it last held — `IncomingStockReconciler` zeroes nothing on the way
     * out — so the same row that read "covered by 50" a moment ago reads short here. That direction
     * is deliberate: with procurement off there is no receiving screen to receive that order on, so
     * treating the stale forecast as real would hide a shortage behind stock that cannot arrive.
     */
    public function testWithProcurementOffIncomingIsNotCountedAndTheRowIsShortAgain(): void
    {
        $inventory = $this->inventory(quantity: 4, incoming: 50);
        $inventory->setPositiveBucketsCount(false);

        $assessment = $this->rule->assess($this->level(10), $inventory);

        self::assertFalse($assessment->incomingMaintained, 'the screen has to be able to say so');
        self::assertSame('0.0000', $assessment->incoming, 'the column still holds 50; it is not read');
        self::assertSame('4.0000', $assessment->projected());
        self::assertTrue($assessment->short());
        self::assertFalse($assessment->covered(), 'nothing can be covered when nothing is counting what is coming');
        self::assertSame('6.0000', $assessment->shortfall());
    }

    /**
     * Sitting exactly ON the level counts as reaching it.
     *
     * #597 writes the comparison as `projected < reorder_point` but also calls 0 a real reorder
     * point meaning "order when you hit nothing left" — and under `<` a point of 0 fires only once
     * the row is oversold. `<=` is the reading that makes both halves of the issue true.
     */
    public function testTheLevelItselfCounts(): void
    {
        $atTheLine = $this->rule->assess($this->level(10), $this->inventory(quantity: 10, incoming: 0));
        self::assertTrue($atTheLine->short(), 'at the level is at the level');
        self::assertSame('0.0000', $atTheLine->shortfall());

        $oneAbove = $this->rule->assess($this->level(10), $this->inventory(quantity: 11, incoming: 0));
        self::assertFalse($oneAbove->belowPoint());
        self::assertFalse($oneAbove->short());
        self::assertSame(ReorderAssessment::STATUS_ABOVE, $oneAbove->status());
    }

    /** A level of 0 is real, and means "tell me when there is nothing sellable left". */
    public function testALevelOfZeroFiresWhenThereIsNothingLeft(): void
    {
        self::assertTrue($this->rule->assess($this->level(0), $this->inventory(quantity: 0, incoming: 0))->short());
        self::assertFalse($this->rule->assess($this->level(0), $this->inventory(quantity: 1, incoming: 0))->short());
    }

    /**
     * Everything already subtracted from availability stays subtracted, and is not added back.
     *
     * `backordered` in particular: it is inside `getAvailableQuantity()` already, and units owed to
     * a customer are not units available to the next one. quantity 100, cart hold 10, sales hold 5,
     * pending 20, approved 30, backordered 25 — available 10, under a level of 20.
     */
    public function testTheClaimsAgainstStockAreAlreadyOutOfAvailabilityAndStayOut(): void
    {
        $inventory = $this->inventory(quantity: 100, incoming: 0)
            ->setCartHoldQuantity(10)
            ->setSalesHoldQuantity(5)
            ->setPendingQuantity(20)
            ->setApprovedQuantity(30)
            ->setBackorderedQuantity(25);

        $assessment = $this->rule->assess($this->level(20), $inventory);

        self::assertSame('10.0000', $assessment->available);
        self::assertSame('10.0000', $assessment->projected(), 'backordered is not added back on top of availability');
        self::assertTrue($assessment->short());
        self::assertSame('10.0000', $assessment->shortfall());
    }

    /**
     * Safety stock is a second band and changes no arithmetic.
     *
     * The row is short either way; what the buffer decides is only whether it is shown as urgent.
     */
    public function testSafetyStockIsASecondBandAndNotAnAdjustmentToTheLevel(): void
    {
        $rule = $this->level(20)->setSafetyStockQuantity(5);

        $intoTheBuffer = $this->rule->assess($rule, $this->inventory(quantity: 3, incoming: 0));
        self::assertTrue($intoTheBuffer->short());
        self::assertSame('17.0000', $intoTheBuffer->shortfall(), 'measured against the level, not the buffer');
        self::assertTrue($intoTheBuffer->belowSafetyStock());

        $aboveTheBuffer = $this->rule->assess($rule, $this->inventory(quantity: 12, incoming: 0));
        self::assertTrue($aboveTheBuffer->short());
        self::assertFalse($aboveTheBuffer->belowSafetyStock());

        $noBuffer = $this->rule->assess($this->level(20), $this->inventory(quantity: 0, incoming: 0));
        self::assertFalse($noBuffer->belowSafetyStock(), 'no safety stock set is not a safety stock of zero');
    }

    /** Incoming counts toward the buffer too — the buffer is measured on the same projection. */
    public function testSafetyStockIsMeasuredOnTheProjectionLikeEverythingElse(): void
    {
        $rule = $this->level(20)->setSafetyStockQuantity(5);

        self::assertFalse(
            $this->rule->assess($rule, $this->inventory(quantity: 3, incoming: 10))->belowSafetyStock(),
            'ten on order lift it clear of the buffer',
        );
    }

    /**
     * A standing order quantity is suggested when set; the shortfall is suggested when it is not.
     *
     * Both are numbers on a screen. Nothing acts on either — #597 rules out auto-raising a purchase
     * order outright.
     */
    public function testTheSuggestionIsTheStandingQuantityOrElseTheShortfall(): void
    {
        self::assertSame(
            '6.0000',
            $this->rule->assess($this->level(10), $this->inventory(quantity: 4, incoming: 0))->suggestedOrder(),
            'no standing answer, so the smallest order that clears the flag',
        );

        self::assertSame(
            '144.0000',
            $this->rule->assess($this->level(10)->setReorderQuantity(144), $this->inventory(quantity: 4, incoming: 0))->suggestedOrder(),
            'a case of 144 is a case of 144, not six loose units',
        );
    }

    /**
     * A level set for a pair with no `product_inventory` row at all is as short as it gets, and is
     * NOT silently skipped — nothing there is exactly the state a reorder level exists to catch.
     */
    public function testAPairWithNoInventoryRowIsShortByTheWholeLevel(): void
    {
        $assessment = $this->rule->assess($this->level(25), null);

        self::assertSame('0.0000', $assessment->available);
        self::assertSame('0.0000', $assessment->incoming);
        self::assertTrue($assessment->short());
        self::assertSame('25.0000', $assessment->shortfall());
    }

    /** A level clamps at 0 rather than storing a negative one, which is not a level. */
    public function testALevelCannotBeNegative(): void
    {
        self::assertSame('0.0000', $this->level(-5)->getReorderPoint());
    }

    /** An unset optional stays NULL — it is a distinct state from zero and must survive as one. */
    public function testTheOptionalQuantitiesKeepNullAsItsOwnAnswer(): void
    {
        $rule = $this->level(10);

        self::assertNull($rule->getReorderQuantity());
        self::assertNull($rule->getSafetyStockQuantity());

        self::assertSame('0.0000', $rule->setReorderQuantity(0)->getReorderQuantity(), 'a typed zero is a zero');
        self::assertNull($rule->setReorderQuantity(null)->getReorderQuantity(), 'and clearing it returns it to null');
    }

    private function level(int $point): ProductReorderRule
    {
        return (new ProductReorderRule())
            ->setProduct((new ProductCore())->setSku('REORDER-1')->setName('Reorder Product'))
            ->setWarehouse((new Warehouse())->setName('Warehouse 2'))
            ->setReorderPoint($point);
    }

    private function inventory(int $quantity, int $incoming): ProductInventory
    {
        return (new ProductInventory())
            ->setProduct((new ProductCore())->setSku('REORDER-1')->setName('Reorder Product'))
            ->setWarehouse((new Warehouse())->setName('Warehouse 2'))
            ->setQuantity($quantity)
            ->setIncomingQuantity($incoming);
    }
}
