<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Service\Inventory\BackorderSplitResolver;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * The arithmetic on its own (#548), with no database in the way.
 *
 * Worth isolating because every refusal point in the app now defers to it: the admin save, the
 * customer cart and the pre-persist re-check at checkout all ask this one question, and a wrong
 * answer here is three wrong behaviours.
 */
final class BackorderSplitResolverTest extends DoctrineIntegrationTestCase
{
    private BackorderSplitResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new BackorderSplitResolver(self::getContainer()->get(WarehouseFulfillmentRegionService::class));
    }

    /**
     * The invariant the whole feature rests on: with backorder off, this IS the check the three
     * refusal points performed before it existed.
     */
    public function testWithBackorderOffTheRemainderIsRefusedExactlyAsBefore(): void
    {
        $split = $this->resolver->split('10', '6', false, '0');

        self::assertSame('6.0000', $split->fulfilled);
        self::assertSame('0.0000', $split->backordered, 'nothing may be promised on a SKU nobody opted in');
        self::assertSame('4.0000', $split->uncovered);
        self::assertFalse($split->isFullyCovered());
    }

    public function testWithBackorderOffAndEnoughStockNothingIsRefused(): void
    {
        $split = $this->resolver->split('6', '6', false, '0');

        self::assertSame(['6.0000', '0.0000', '0.0000'], [$split->fulfilled, $split->backordered, $split->uncovered]);
        self::assertTrue($split->isFullyCovered());
    }

    public function testTheRemainderIsPromisedWhenThereIsCapacityForIt(): void
    {
        $split = $this->resolver->split('10', '6', true, '50');

        self::assertSame('6.0000', $split->fulfilled);
        self::assertSame('4.0000', $split->backordered);
        self::assertSame('0.0000', $split->uncovered);
        self::assertSame('10.0000', $split->accepted());
    }

    /** The cap is a ceiling, not a queue: what will not fit under it is refused, never waitlisted. */
    public function testWhatExceedsTheCapIsRefusedNotQueued(): void
    {
        $split = $this->resolver->split('10', '6', true, '1');

        self::assertSame('6.0000', $split->fulfilled);
        self::assertSame('1.0000', $split->backordered);
        self::assertSame('3.0000', $split->uncovered);
        self::assertFalse($split->isFullyCovered());
    }

    /**
     * Availability is unclamped on purpose (#539) so an admin sees a real oversell as a negative,
     * which means this can be handed one. A negative availability covers nothing — it must never
     * read as "minus four units of stock" and reduce the promise by four.
     */
    public function testNegativeAvailabilityFulfilsNothingRatherThanBorrowingAgainstIt(): void
    {
        $split = $this->resolver->split('10', '-4', true, '50');

        self::assertSame('0.0000', $split->fulfilled);
        self::assertSame('10.0000', $split->backordered);
        self::assertSame('0.0000', $split->uncovered);
    }

    /** A cap already fully spent by other orders refuses the whole shortfall. */
    public function testAnExhaustedCapRefusesTheWholeShortfall(): void
    {
        $split = $this->resolver->split('10', '6', true, '0');

        self::assertSame(['6.0000', '0.0000', '4.0000'], [$split->fulfilled, $split->backordered, $split->uncovered]);
    }

    /** A cap overspent — possible after backorder_cap_shrinks_on_restock drops it below what is outstanding. */
    public function testAnOverspentCapIsTreatedAsNoCapacityAndNotAsNegativeCapacity(): void
    {
        $split = $this->resolver->split('10', '6', true, '-20');

        self::assertSame(['6.0000', '0.0000', '4.0000'], [$split->fulfilled, $split->backordered, $split->uncovered]);
    }

    /** The three parts always add back up, which is what stops a caller losing units by reading one. */
    public function testTheThreePartsAlwaysSumToWhatWasRequested(): void
    {
        foreach ([['0', '0', '0'], ['3', '10', '1'], ['25', '4', '7'], ['8', '8', '0'], ['12', '0', '100']] as [$requested, $available, $capacity]) {
            $split = $this->resolver->split($requested, $available, true, $capacity);

            self::assertSame(
                QuantityScale::canonical($requested),
                QuantityScale::add(QuantityScale::add($split->fulfilled, $split->backordered), $split->uncovered),
                sprintf('requested %s against %s available and %s capacity', $requested, $available, $capacity),
            );
        }
    }

    /**
     * A product never stocked in a warehouse has no ProductInventory row, and that is read as zero
     * available AND as not opted in — the alternative would let a typo'd region bypass both the
     * stock check and the cap.
     */
    public function testAMissingInventoryRowCoversNothingAndPromisesNothing(): void
    {
        $split = $this->resolver->splitFor(null, '5', '0');

        self::assertSame(['0.0000', '0.0000', '5.0000'], [$split->fulfilled, $split->backordered, $split->uncovered]);
    }
}
