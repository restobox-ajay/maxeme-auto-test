<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Movement;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovement;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\ReversalPlanner;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryMovementRepository;

/**
 * Reversing a write-off, and the bound that makes it safe (#585).
 *
 *     remaining = original.quantity − SUM(quantity WHERE reverses_movement_id = original.id)
 *
 * Everything here is about that one expression. It is the whole guard on the operation — it refuses
 * "reverse 500 when 12 were written off" and "reverse the same 12 twice" as the same rule, not as
 * two validations somebody has to remember — so it gets tests that fail loudly the moment it is
 * weakened, and one of them (testAReversalCannotClaimMoreThanTheEntryEverLost) was checked by
 * deleting the guard and watching it go red.
 */
final class ReversingAWriteOffTest extends DoctrineIntegrationTestCase
{
    private StockMovementService $movements;
    private ReversalPlanner $reversals;
    private InventoryMovementRepository $ledger;
    private Warehouse $warehouse;
    private ProductCore $product;
    private WarehouseLocation $bin;
    private InventoryLot $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->movements = self::getContainer()->get(StockMovementService::class);
        $this->reversals = self::getContainer()->get(ReversalPlanner::class);
        $this->ledger = self::getContainer()->get(InventoryMovementRepository::class);

        $region = (new FulfillmentRegion())->setName('Reversal Region');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->product = (new ProductCore())
            ->setSku('REV-1')
            ->setName('Reversible Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);

        $this->bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('A-12')->setSortKey(10);
        $this->em->persist($this->bin);

        $this->lot = (new InventoryLot())->setProduct($this->product)->setCode('L-1')->setExpiry(new \DateTimeImmutable('2029-01-31'));
        $this->em->persist($this->lot);

        $this->em->flush();
    }

    private function available(?string $serial = null): DetailKey
    {
        return new DetailKey($this->warehouse, $this->bin, $this->lot, $serial, InventoryDetail::STATUS_AVAILABLE);
    }

    /** Puts $quantity units of sellable stock on the shelf, the way a receipt would. */
    private function receive(int $quantity, ?string $serial = null): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'rev-receive-' . uniqid('', true), 'Delivered')
                ->receive($this->product, $this->available($serial), $quantity)
        );
    }

    /** Writes $quantity units off into $status and returns the movement that did it. */
    private function writeOff(int $quantity, string $status = InventoryDetail::STATUS_DAMAGED, ?string $serial = null): InventoryMovement
    {
        $from = $this->available($serial);

        $group = $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'rev-writeoff-' . uniqid('', true), 'Broken on arrival')
                ->move($this->product, $from, $from->forStatus($status), $quantity)
        );

        $movement = $group->getMovements()->first();
        self::assertInstanceOf(InventoryMovement::class, $movement);

        return $movement;
    }

    private function detailTotal(string $status): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
            [$this->product->getId(), $status],
        );
    }

    private function coreRow(): ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
        ]);
        self::assertInstanceOf(ProductInventory::class, $row);
        $this->em->refresh($row);

        return $row;
    }

    /**
     * The reversal is the original with its sides swapped, so the units go back to the exact bin and
     * batch they were taken from — nothing is retyped and nothing can be put back in the wrong place.
     */
    public function testAFoundBoxOfPreviouslyWrittenOffStockGoesBackWhereItCameFrom(): void
    {
        $this->receive(50);
        $original = $this->writeOff(12);

        self::assertSame(12, $this->detailTotal(InventoryDetail::STATUS_DAMAGED), 'twelve units were damaged');
        self::assertSame(38, $this->detailTotal(InventoryDetail::STATUS_AVAILABLE));

        $this->movements->apply(
            $this->reversals->addReversal(
                MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'rev-1', 'They turned up intact'),
                $original,
                12,
            )
        );

        self::assertSame(0, $this->detailTotal(InventoryDetail::STATUS_DAMAGED), 'the write-off is undone');
        self::assertSame(50, $this->detailTotal(InventoryDetail::STATUS_AVAILABLE), 'and the units are sellable again');

        $back = $this->em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $this->product,
            'location' => $this->bin,
            'lot' => $this->lot,
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);
        self::assertInstanceOf(InventoryDetail::class, $back);
        self::assertSame('50.0000', $back->getQuantity(), 'back in A-12 on lot L-1, which is where the write-off took them from');
    }

    /**
     * A reversal is NOT the same thing as finding stock, and the difference is the `write_off`
     * bucket falling rather than `received` rising.
     *
     * This is the distinction the old screen could not express and the reason both reasons exist:
     * recording found stock that was previously written off as a receipt invents units while leaving
     * the loss on the books, so availability is right by accident and both figures are wrong.
     */
    public function testTheReversalTakesTheLossOffTheBooksRatherThanInventingUnits(): void
    {
        $this->receive(50);
        $original = $this->writeOff(12);

        $received = $this->coreRow()->getReceivedQuantity();
        self::assertSame('12.0000', $this->coreRow()->getWriteOffQuantity(), 'the write-off bucket carries the loss');

        $this->movements->apply(
            $this->reversals->addReversal(
                MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'rev-2', 'They turned up intact'),
                $original,
                12,
            )
        );

        self::assertSame('0.0000', $this->coreRow()->getWriteOffQuantity(), 'the loss comes off the books');
        self::assertSame(
            $received,
            $this->coreRow()->getReceivedQuantity(),
            'and nothing was received — these units were already in the ledger, which is exactly what makes '
            . 'this a different reason from "stock found"',
        );
    }

    /**
     * The bound, stated as the operator meets it: an entry that lost 12 cannot give back 500.
     *
     * **This is the mutation-checked test.** Deleting the `remaining` comparison in
     * ReversalPlanner::addReversal() makes it fail — verified, not assumed.
     *
     * Note what would NOT catch it: StockMovementService::assertSourcesCanCover(). The `damaged` row
     * holds only 12 in this test, so the stock check would refuse 500 as well, for a different
     * reason — and the moment a SECOND write-off of the same product exists the row holds enough and
     * the stock check waves it through. testTwoWriteOffsShareARowAndStillCannotBeOverReversed is
     * that case, and it is the one that makes the bound load-bearing rather than decorative.
     */
    public function testAReversalCannotClaimMoreThanTheEntryEverLost(): void
    {
        $this->receive(50);
        $original = $this->writeOff(12);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 12 can be put back/');

        $this->reversals->addReversal(
            MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'rev-3', 'Optimism'),
            $original,
            500,
        );
    }

    /**
     * The same 12 cannot be reversed twice, and a partial reversal moves the bound rather than
     * resetting it.
     *
     * One rule doing two jobs: `remaining` is the original less what has already come back, so the
     * second reversal is measured against 5 and not against 12.
     */
    public function testAPartialReversalLeavesOnlyTheRestAvailableToReverse(): void
    {
        $this->receive(50);
        $original = $this->writeOff(12);

        $this->movements->apply(
            $this->reversals->addReversal(
                MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'rev-4a', 'Seven turned up'),
                $original,
                7,
            )
        );

        self::assertSame('5.0000', $this->reversals->remaining($original), 'twelve lost, seven back, five outstanding');

        // The rest goes back fine.
        $this->movements->apply(
            $this->reversals->addReversal(
                MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'rev-4b', 'And the other five'),
                $original,
                5,
            )
        );

        self::assertSame('0.0000', $this->reversals->remaining($original), 'nothing left on the entry');
        self::assertSame(50, $this->detailTotal(InventoryDetail::STATUS_AVAILABLE));

        // A third attempt has nothing to claim against, even though the shelf could physically
        // absorb it — the entry is what is exhausted, not the row.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 0 can be put back/');

        $this->reversals->addReversal(
            MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'rev-4c', 'Again'),
            $original,
            1,
        );
    }

    /**
     * Two write-offs of the same goods share ONE `damaged` detail row, and the bound is still per
     * entry.
     *
     * The case that proves the bound is not a restatement of the stock check. After 12 and 40 are
     * written off, the damaged row holds 52 — so reversing "the 12 entry" for 40 units passes
     * assertSourcesCanCover() comfortably and is still a lie about which entry lost what. Without
     * `remaining`, the ledger would end up claiming 52 units reversed against an entry that lost 12
     * and an entry that lost 40, with the arithmetic hiding behind a shared row.
     */
    public function testTwoWriteOffsShareARowAndStillCannotBeOverReversed(): void
    {
        $this->receive(100);
        $small = $this->writeOff(12);
        $large = $this->writeOff(40);

        self::assertSame(52, $this->detailTotal(InventoryDetail::STATUS_DAMAGED), 'one row, two entries behind it');

        // 40 units are physically sitting in the damaged row, so nothing about stock levels refuses
        // this. Only the entry does.
        //
        // Driven through try/catch rather than expectException (#594): everything that used to
        // stand after the throwing call was dead code, and the file said so. What matters after a
        // refusal is that the request was not mutated on the way to it — addReversal() appending
        // its 40-unit line BEFORE evaluating `remaining` is invisible here, but the controller
        // builds one request, catches the exception and applies whatever else is on it, so a
        // half-populated request puts 40 units back against an entry that lost 12.
        $request = MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'rev-5', 'Wrong entry');

        try {
            $this->reversals->addReversal($request, $small, 40);
            self::fail('reversing 40 against an entry that lost 12 must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertMatchesRegularExpression('/at most 12 can be put back/', $e->getMessage());
        }

        self::assertSame([], $request->lines(), 'a refused reversal leaves nothing on the request behind it');
        self::assertSame(52, $this->detailTotal(InventoryDetail::STATUS_DAMAGED), 'and the damaged row is untouched');
        self::assertSame('12.0000', $this->reversals->remaining($small), 'the small entry still has its whole 12 to give back');
        self::assertSame('40.0000', $this->reversals->remaining($large), 'and the large one its 40');
    }

    /**
     * A serial reverses itself. Nothing is retyped, which is the point.
     *
     * The old screen had one serial text box against a service that correctly refuses more than one
     * unit per serial row, so putting 300 found serialised units back meant 300 submissions and 300
     * chances to type a number that belongs to a different physical unit. Reversing the entries that
     * wrote them off needs neither.
     */
    public function testReversingAWriteOffOfASerialPutsThatExactSerialBack(): void
    {
        $this->receive(1, 'ABC-0042');
        $original = $this->writeOff(1, InventoryDetail::STATUS_LOST, 'ABC-0042');

        $lost = $this->em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $this->product,
            'serial' => 'ABC-0042',
            'status' => InventoryDetail::STATUS_LOST,
        ]);
        self::assertInstanceOf(InventoryDetail::class, $lost);
        self::assertSame('1.0000', $lost->getQuantity());

        $this->movements->apply(
            $this->reversals->addReversal(
                MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'rev-6', 'Found behind the racking'),
                $original,
                1,
            )
        );

        $back = $this->em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $this->product,
            'serial' => 'ABC-0042',
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);
        self::assertInstanceOf(InventoryDetail::class, $back);
        self::assertSame('1.0000', $back->getQuantity(), 'ABC-0042 specifically, not "a unit"');
        self::assertSame($this->bin->getId(), $back->getLocation()?->getId(), 'and in the bin it was taken from');
    }

    /**
     * The picker offers what is actually reversible, and stops offering an entry once it is spent.
     *
     * An option that cannot be acted on is an invitation to pick it and read an error, which is the
     * "empty quantity box" problem this list replaces.
     */
    public function testTheReversiblePickerDropsAnEntryOnceItHasNothingLeft(): void
    {
        $this->receive(50);
        $original = $this->writeOff(12);

        $offered = $this->ledger->reversibleWriteOffs($this->product);
        self::assertCount(1, $offered);
        self::assertSame('12.0000', $offered[0]['remaining']);
        self::assertSame('0.0000', $offered[0]['reversed']);

        $this->movements->apply(
            $this->reversals->addReversal(
                MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'rev-7a', 'Four back'),
                $original,
                4,
            )
        );

        $offered = $this->ledger->reversibleWriteOffs($this->product);
        self::assertCount(1, $offered);
        self::assertSame('8.0000', $offered[0]['remaining'], 'partially reversed entries stay on the list');
        self::assertSame('4.0000', $offered[0]['reversed']);

        $this->movements->apply(
            $this->reversals->addReversal(
                MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'rev-7b', 'And the rest'),
                $original,
                8,
            )
        );

        self::assertSame([], $this->ledger->reversibleWriteOffs($this->product), 'a spent entry is off the list entirely');
    }

    /**
     * A reversal is not itself reversible, and the shape forbids it rather than a flag.
     *
     * Its from-side is a write-off status and its to-side is `available`, which fails both tests the
     * picker applies. Worth asserting because the alternative — an explicit "not a reversal" clause
     * — would hide that fact and would be the thing somebody deleted while tidying.
     */
    public function testAReversalCannotItselfBeReversed(): void
    {
        $this->receive(50);
        $original = $this->writeOff(12);

        $group = $this->movements->apply(
            $this->reversals->addReversal(
                MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'rev-8', 'Back on the shelf'),
                $original,
                12,
            )
        );

        $reversal = $group->getMovements()->first();
        self::assertInstanceOf(InventoryMovement::class, $reversal);
        self::assertSame($original->getId(), $reversal->getReversesMovement()?->getId(), 'the link is on the row');

        self::assertSame([], $this->ledger->reversibleWriteOffs($this->product), 'and nothing here is reversible any more');
    }

    /**
     * Releasing a hold is not a reversal, and a hand-built POST naming a quarantine entry is refused
     * rather than quietly treated as one.
     *
     * The picker never offers it, so this is only reachable by someone constructing the request —
     * which is exactly the case a dropdown cannot stop, and the same argument #581 makes about the
     * status dropdowns it removed.
     */
    public function testAQuarantineEntryIsNotAWriteOffAndCannotBeReversedHere(): void
    {
        $this->receive(50);
        $held = $this->writeOff(9, InventoryDetail::STATUS_QUARANTINE);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a write-off/');

        $this->reversals->addReversal(
            MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'rev-9', 'Wrong reason'),
            $held,
            9,
        );
    }
}
