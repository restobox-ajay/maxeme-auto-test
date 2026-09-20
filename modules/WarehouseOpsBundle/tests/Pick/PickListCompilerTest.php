<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Pick;

use InventoryDepthBundle\Entity\WarehouseLocation;
use WarehouseOpsBundle\Entity\PickTask;
use WarehouseOpsBundle\Pick\PickListCompiler;
use WarehouseOpsBundle\Tests\Support\WarehouseOpsTestCase;

/**
 * Where a compiled round sends its pickers (#552, #592).
 *
 * The compiler writes no stock and holds none — the bin on a task is a hint for the printout, not a
 * reservation — but the hints it prints in ONE compile have to be consistent with each other.
 * Before #592 they were not: suggestBin() was called once per line with no memory of what the line
 * before it had been promised, so two orders for the same SKU were two lines and both were sent to
 * the same shelf. The first picker took everything and the second found it empty and typed 0.
 */
final class PickListCompilerTest extends WarehouseOpsTestCase
{
    private PickListCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = self::getContainer()->get(PickListCompiler::class);
    }

    /** @return list<PickTask> the compiled tasks, in the order the compiler produced them */
    private function tasksFor(int ...$quantities): array
    {
        $orders = [];
        foreach ($quantities as $index => $quantity) {
            $orders[] = $this->approvedOrder($quantity, sprintf('SO-%d', $index + 1));
        }

        $compiled = $this->compiler->compile($this->warehouse, $orders);

        return array_values($compiled->list->getTasks()->toArray());
    }

    /**
     * Two orders for the same SKU are sent to two different shelves (#592).
     *
     * Ten in bin A and ten in bin B, and two orders each wanting ten. Before the claim map both
     * tasks named bin A, because both asked pickableRows() the same question and got the same first
     * answer. Two pickers, one bin, one of them walking away with nothing.
     */
    public function testTwoOrdersForTheSameSkuAreRoutedToDifferentBins(): void
    {
        $this->receive(10, $this->binA);
        $this->receive(10, $this->binB);

        $tasks = $this->tasksFor(10, 10);

        self::assertCount(2, $tasks);
        self::assertSame($this->binA->getId(), $tasks[0]->getSuggestedLocation()?->getId(), 'the first walk starts at the lowest-sorted bin');
        self::assertSame($this->binB->getId(), $tasks[1]->getSuggestedLocation()?->getId(), 'the second is sent past it, because the first has spoken for bin A');
        self::assertSame($this->binB->getSortKey(), $tasks[1]->getSortKey(), 'and its route position is the bin it was actually given');
    }

    /**
     * A bin with something left in it is still offered.
     *
     * The claim map steps over a bin that earlier tasks have used up, not one they have merely
     * dipped into. Two tasks of four against a bin of ten both belong at that bin, and a hint that
     * says "start at A, there is some there" is worth more to a picker than a blank.
     */
    public function testABinThatCanStillAnswerBothLinesIsGivenToBoth(): void
    {
        $this->receive(10, $this->binA);

        $tasks = $this->tasksFor(4, 4);

        self::assertCount(2, $tasks);
        self::assertSame($this->binA->getId(), $tasks[0]->getSuggestedLocation()?->getId());
        self::assertSame($this->binA->getId(), $tasks[1]->getSuggestedLocation()?->getId(), 'two of the ten are still there for the second picker');
    }

    /**
     * When the claims use up every bin, the task keeps no bin at all — and that is the right answer.
     *
     * `pick_task.suggested_location_id` NULL is the compiler saying "we cannot tell you where to
     * stand". #589 already made that a safe thing to say: a task naming no bin writes nothing off
     * when it comes back short. Inventing a bin to fill the column would trade an honest blank for a
     * wrong address, and send somebody to a shelf the round has already emptied on paper.
     */
    public function testALineTheClaimsCannotCoverIsLeftWithNoBinRatherThanAWrongOne(): void
    {
        $this->receive(10, $this->binA);

        $tasks = $this->tasksFor(10, 10);

        self::assertCount(2, $tasks);
        self::assertSame($this->binA->getId(), $tasks[0]->getSuggestedLocation()?->getId());
        self::assertNull($tasks[1]->getSuggestedLocation(), 'there is no second shelf to send them to, and the round says so');
        self::assertSame(PickListCompiler::UNROUTED_SORT_KEY, $tasks[1]->getSortKey(), 'so it sorts to the end of the walk');
    }

    /**
     * The claim is the size of the promise, not of the bin.
     *
     * A first line asking for two against a bin of ten leaves eight for the next line, not zero. The
     * opposite — one line consuming a whole bin's worth of hint — would push every later line onto a
     * blank and undo the routing this class exists to produce.
     */
    public function testAClaimOnlyConsumesWhatTheLineAsksFor(): void
    {
        $this->receive(10, $this->binA);
        $this->receive(10, $this->binB);

        $tasks = $this->tasksFor(2, 8, 10);

        self::assertCount(3, $tasks);
        self::assertSame($this->binA->getId(), $tasks[0]->getSuggestedLocation()?->getId());
        self::assertSame($this->binA->getId(), $tasks[1]->getSuggestedLocation()?->getId(), 'two and eight fit in a bin of ten');
        self::assertSame($this->binB->getId(), $tasks[2]->getSuggestedLocation()?->getId(), 'and only the third is moved on');
    }

    /** Stock that sits in no bin routes nobody anywhere, exactly as it did before the claim map. */
    public function testStockInNoBinStillProducesAnUnroutedTask(): void
    {
        $this->receive(10, null);

        $tasks = $this->tasksFor(5);

        self::assertCount(1, $tasks);
        self::assertNull($tasks[0]->getSuggestedLocation());
        self::assertSame(PickListCompiler::UNROUTED_SORT_KEY, $tasks[0]->getSortKey());
    }

    /** A bin that is not this warehouse's is never suggested — the claim map did not change that. */
    public function testOnlyBinsInTheListsWarehouseAreEverSuggested(): void
    {
        $this->receive(10, $this->binA);

        $tasks = $this->tasksFor(10, 10);

        foreach ($tasks as $task) {
            $bin = $task->getSuggestedLocation();

            if ($bin instanceof WarehouseLocation) {
                self::assertSame($this->warehouse->getId(), $bin->getWarehouse()->getId());
            }
        }
    }
}
