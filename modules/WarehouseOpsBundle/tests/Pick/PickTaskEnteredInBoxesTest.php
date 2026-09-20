<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Pick;

use App\Entity\UnitOfMeasure;
use App\Service\Uom\UnitOfMeasureService;
use WarehouseOpsBundle\Entity\PickList;
use WarehouseOpsBundle\Entity\PickTask;
use WarehouseOpsBundle\Pick\PickConfirmationService;
use WarehouseOpsBundle\Tests\Support\WarehouseOpsTestCase;

/**
 * The depth half of the one property: **`inventory_detail` sees only the base figure.**
 *
 * `DenominationNeverReachesTheInventoryLayerTest` covers the bucket layer, where a sales hold moves
 * by 36 rather than 3. This covers the layer under it, where stock actually moves between bins — and
 * it is the one #601 argues hardest for keeping the ENTERED figure on:
 *
 * > A pick list saying "50 boxes" is a different physical instruction from "600 eaches" — the picker
 * > takes boxes off a pallet rather than unpacking. The unit must survive into `pick_task`, not be
 * > flattened at the door.
 *
 * Both halves therefore have to hold at once. The task says **2 BOX-12** and keeps saying it; the
 * bins move **24**.
 *
 * #659 changed where the twelve comes from — `unit_of_measure`, global, against the product's base
 * unit — and changed nothing about either half.
 */
final class PickTaskEnteredInBoxesTest extends WarehouseOpsTestCase
{
    private PickConfirmationService $confirmations;
    private UnitOfMeasure $each;
    private UnitOfMeasure $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmations = self::getContainer()->get(PickConfirmationService::class);

        $units = self::getContainer()->get(UnitOfMeasureService::class);
        $this->each = $units->add('EA-PICK', 'Each', UnitOfMeasure::FAMILY_QUANTITY, '1', '1');
        $this->box = $units->add('BOX-12-PICK', 'Box of 12', UnitOfMeasure::FAMILY_QUANTITY, '12', '1');
    }

    public function testATaskEnteredInBoxesMovesBaseUnitsBetweenBins(): void
    {
        $this->receive(30, $this->binA);
        $this->receive(30, $this->binB);

        $task = $this->taskEnteredAs('2', $this->box);

        // pick_task.quantity_requested is the base figure and always was; only its neighbours are new.
        self::assertSame('24.0000', $task->getQuantityRequested());
        self::assertSame('2.0000', $task->getQuantityEntered());
        self::assertSame($this->box->getId(), $task->getUnitOfMeasure()?->getId());

        $result = $this->confirmations->confirm($task, 24, 'op-cases-1', 'tester');

        self::assertSame('24.0000', $result->picked);
        self::assertSame('0.0000', $result->missing);

        // inventory_detail moved by 24 — not by 2, and not by 26.
        self::assertSame(6, $this->inBin($this->binA), 'the picked bin gave up 24 base units');
        self::assertSame(24, $this->inBin($this->staging));

        // The bin that should NOT have changed.
        self::assertSame(30, $this->inBin($this->binB), 'binB was never on this task');

        // And the invariant the whole design exists to leave alone.
        self::assertSame(
            $this->wholeUnits($this->details->availableTotal($this->product, $this->warehouse)),
            $this->coreQuantity(),
            'product_inventory.quantity must still equal SUM(available detail)',
        );
    }

    /**
     * Columns, not accessors.
     *
     * A getter that quietly multiplied on the way out would satisfy every assertion above and still
     * leave 2 in `pick_task.quantity_requested`, where the confirmation service, the pick screen and
     * the label printer would each find a different number than the one that moved the stock.
     */
    public function testTheThreeColumnsHoldWhatTheyClaimTo(): void
    {
        $this->receive(30, $this->binA);
        $task = $this->taskEnteredAs('2', $this->box);
        $this->em->flush();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT quantity_requested, quantity_entered, unit_id FROM pick_task WHERE id = ?',
            [(int) $task->getId()],
        );

        self::assertIsArray($row);
        self::assertSame(24.0, (float) $row['quantity_requested']);
        self::assertSame(2.0, (float) $row['quantity_entered']);
        self::assertSame($this->box->getId(), (int) $row['unit_id']);
    }

    /** A task entered the way every task is entered today: in base units, both columns NULL. */
    public function testATaskEnteredInBaseUnitsIsUnchangedFromBeforeThisPhase(): void
    {
        $this->receive(30, $this->binA);

        $task = $this->taskEnteredAs('24', null);

        self::assertSame('24.0000', $task->getQuantityRequested());
        self::assertTrue($task->isEnteredInBaseUnits());

        $this->confirmations->confirm($task, 24, 'op-base-1', 'tester');

        self::assertSame(6, $this->inBin($this->binA));
        self::assertSame(24, $this->inBin($this->staging));
    }

    private function taskEnteredAs(string $entered, ?UnitOfMeasure $unit): PickTask
    {
        $list = (new PickList())
            ->setNumber('PL-' . uniqid())
            ->setWarehouse($this->warehouse)
            ->setStagingLocation($this->staging)
            ->setStatus(PickList::STATUS_RELEASED);

        $task = (new PickTask())
            ->setProduct($this->product)
            ->setOrderNumber('SO-1')
            ->setSku($this->product->getSku())
            ->setName($this->product->getName())
            ->setSuggestedLocation($this->binA);
        $task->setEnteredQuantity($entered, $unit, $this->each);

        $list->addTask($task);
        $this->em->persist($list);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }
}
