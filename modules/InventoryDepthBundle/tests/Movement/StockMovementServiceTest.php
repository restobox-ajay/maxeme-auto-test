<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Movement;

use App\Service\QuantityScale;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\InsufficientStockException;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * The invariant, exercised through the operations that are supposed to preserve it.
 *
 *     product_inventory.quantity == SUM(inventory_detail.quantity WHERE status = 'available')
 */
final class StockMovementServiceTest extends DoctrineIntegrationTestCase
{
    private StockMovementService $movements;
    private InventoryDetailRepository $details;
    private Warehouse $warehouse;
    private ProductCore $product;
    private WarehouseLocation $binA;
    private WarehouseLocation $binB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->movements = self::getContainer()->get(StockMovementService::class);
        $this->details = self::getContainer()->get(InventoryDetailRepository::class);

        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->product = (new ProductCore())
            ->setSku('DEPTH-1')
            ->setName('Depth Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);

        // sort_key is the pick-path tiebreak: A is walked before B.
        $this->binA = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('A-12')->setSortKey(10);
        $this->binB = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('B-03')->setSortKey(20);
        $this->em->persist($this->binA);
        $this->em->persist($this->binB);

        $this->em->flush();
    }

    private function key(?WarehouseLocation $bin, ?InventoryLot $lot = null, string $status = InventoryDetail::STATUS_AVAILABLE, ?string $serial = null): DetailKey
    {
        return new DetailKey($this->warehouse, $bin, $lot, $serial, $status);
    }

    private function coreQuantity(): string
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
        ]);
        // The invariant, read off the entity rather than re-spelled here:
        //
        //     SUM(available detail) == quantity + received − transfer_out − write_off − quarantine
        //
        // which is exactly getAvailableQuantity() in a test with no orders or invoices, since the
        // sell-side holds are all zero. Asking the entity means a bucket added later cannot leave
        // this helper quietly asserting an older invariant — which is what happened twice.
        return QuantityScale::canonical($row instanceof ProductInventory ? $row->getAvailableQuantity() : 0);
    }

    /**
     * The invariant, with the term #581 added to it:
     *
     *     SUM(available detail) + SUM(sold, staged) == availability, when nothing is on order
     *
     * The extra term is not slack. Units that are sold or staged were sold or staged BY an invoice,
     * and that invoice is already holding them in `pending`/`approved` — so this layer leaves them
     * in the sellable figure on purpose and lets the hold take them off. Subtract them here as well
     * and the same units come off twice; SellingADimensionalProductTest is the transaction that
     * shows it, reading 25 sellable of 30 after 5 are sold rather than 20.
     *
     * In most of these tests there is no invoice, so the holds are zero and the sold units stay in
     * the figure. That is the correct reading of a warehouse where somebody marked stock sold with
     * nothing billing it — which the admin screens no longer allow.
     */
    private function assertInvariantHolds(string $after): void
    {
        $left = QuantityScale::sub(
            QuantityScale::add($this->details->availableTotal($this->product, $this->warehouse), $this->soldUnits()),
            $this->holds(),
        );

        self::assertSame(
            $left,
            $this->coreQuantity(),
            sprintf('SUM(available detail) + sold + staged − holds must equal availability after %s', $after),
        );
    }

    /** Everything the sell side has claimed against this row. Zero unless a test puts an order in. */
    private function holds(): string
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
        ]);

        if (!$row instanceof ProductInventory) {
            return '0.0000';
        }

        $held = QuantityScale::add($row->getCartHoldQuantity(), $row->getSalesHoldQuantity());
        $held = QuantityScale::add($held, $row->getPendingQuantity());
        $held = QuantityScale::add($held, $row->getApprovedQuantity());

        return QuantityScale::add($held, $row->getBackorderedQuantity());
    }

    private function soldUnits(): string
    {
        return QuantityScale::canonical($this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail
              WHERE product_id = ? AND warehouse_id = ? AND status = ?',
            [
                $this->product->getId(),
                $this->warehouse->getId(),
                InventoryDetail::STATUS_SOLD,
            ],
        ));
    }

    /** The plan's own acceptance test: receive, move, pick, damage, scrap — invariant checked at each step. */
    public function testTheInvariantHoldsAfterEveryKindOfOperation(): void
    {
        $lot = (new InventoryLot())->setProduct($this->product)->setCode('L1');
        $this->em->persist($lot);
        $this->em->flush();

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-receive')
                ->receive($this->product, $this->key($this->binA, $lot), 30)
        );
        $this->assertInvariantHolds('a receipt');
        self::assertSame('30.0000', $this->coreQuantity());

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_MOVE, 'op-move')
                ->move($this->product, $this->key($this->binA, $lot), $this->key($this->binB, $lot), 10)
        );
        $this->assertInvariantHolds('a bin move');

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'op-damage')
                ->move($this->product, $this->key($this->binB, $lot), $this->key($this->binB, $lot, InventoryDetail::STATUS_DAMAGED), 4)
        );
        $this->assertInvariantHolds('damaging some of it');
        self::assertSame('26.0000', $this->coreQuantity(), 'damaged stock is present but not sellable, so it leaves the total');

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_SHIP, 'op-ship', null, null, 'SO-1001')
                ->move($this->product, $this->key($this->binA, $lot), $this->key($this->binA, $lot, InventoryDetail::STATUS_SOLD), 5)
        );
        $this->assertInvariantHolds('a shipment');
        // Unchanged at 26, deliberately (#581). A shipment is the SELL side's to account for: the
        // invoice that bills those units holds them in `approved`, and that hold is what takes them
        // off availability. The `sold` detail rows record which lot and serial went where — they
        // are provenance, not a second subtraction.
        //
        // This used to assert 21, because the movement layer also drove `received` down by 5. With
        // an invoice holding the same 5 in `approved`, that subtracted them twice.
        self::assertSame('26.0000', $this->coreQuantity(), 'a shipment is accounted for by the invoice that bills it, not here');

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_ADJUSTMENT, 'op-scrap')
                ->move($this->product, $this->key($this->binB, $lot, InventoryDetail::STATUS_DAMAGED), $this->key($this->binB, $lot, InventoryDetail::STATUS_SCRAPPED), 4)
        );
        $this->assertInvariantHolds('scrapping the damaged stock');
        self::assertSame('26.0000', $this->coreQuantity(), 'moving between two non-sellable statuses cannot change the total');
    }

    /**
     * The cheapest regression test there is for someone recomputing the total from the wrong status
     * set: both sides are `available`, so the number must not move.
     */
    public function testABinMoveDoesNotChangeTheQuantity(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-1')->receive($this->product, $this->key($this->binA), 47)
        );
        self::assertSame('47.0000', $this->coreQuantity());

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_MOVE, 'op-2')
                ->move($this->product, $this->key($this->binA), $this->key($this->binB), 17)
        );

        self::assertSame('47.0000', $this->coreQuantity(), 'a product at 47 across two bins is still a product at 47');
        $this->assertInvariantHolds('a bin move');
    }

    /**
     * Nothing guards non-serial quantity at the database level in a metadata-built schema, so the
     * conditional decrement is what has to. Two withdrawals of 20 from a row of 25 must not reach
     * −15: the first wins, the second is refused, and the row never goes negative.
     */
    public function testTwoOverlappingDecrementsCannotGoNegative(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-1')->receive($this->product, $this->key($this->binA), 25)
        );

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_PICK, 'op-2')->remove($this->product, $this->key($this->binA), 20)
        );

        try {
            $this->movements->apply(
                MovementRequest::of(InventoryMovementGroup::TYPE_PICK, 'op-3')->remove($this->product, $this->key($this->binA), 20)
            );
            self::fail('the second withdrawal should have been refused');
        } catch (InsufficientStockException) {
            // "Someone got there first", which is what a picker needs to be told.
        }

        $this->em->clear();
        self::assertSame('5.0000', $this->coreQuantity(), 'the refused withdrawal must have left the total alone');
        self::assertGreaterThanOrEqual(
            0,
            $this->details->availableTotal($this->product, $this->warehouse),
            'no detail row may pass through a negative quantity',
        );
    }

    /**
     * The ordinary "there isn't that much" answer must not cost the caller its EntityManager.
     *
     * wrapInTransaction() closes the EM on any exception, so a refusal raised inside it would leave
     * the rest of the request unable to touch the database — an expensive way to say "you asked for
     * 9 and there are 5". The pre-flight read is what keeps this case cheap; the assertion is that
     * the very next query still works.
     */
    public function testAnOrdinaryRefusalLeavesTheEntityManagerUsable(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-1')->receive($this->product, $this->key($this->binA), 5)
        );

        try {
            $this->movements->apply(
                MovementRequest::of(InventoryMovementGroup::TYPE_PICK, 'op-2')->remove($this->product, $this->key($this->binA), 9)
            );
            self::fail('the withdrawal should have been refused');
        } catch (InsufficientStockException $e) {
            self::assertStringContainsString('holds 5 unit(s)', $e->getMessage());
        }

        self::assertTrue($this->em->isOpen(), 'a refusal that never wrote anything must not close the EntityManager');
        self::assertSame('5.0000', $this->coreQuantity());
    }

    /**
     * Two lines drawing from the same row are one demand on it. Checking line by line would pass
     * both 15s against a row of 20 and only discover the problem mid-write.
     */
    public function testTwoLinesDrawingOnTheSameRowAreSummedBeforeBeingChecked(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-1')->receive($this->product, $this->key($this->binA), 20)
        );

        $overdrawn = MovementRequest::of(InventoryMovementGroup::TYPE_MOVE, 'op-2')
            ->move($this->product, $this->key($this->binA), $this->key($this->binB), 15)
            ->move($this->product, $this->key($this->binA), $this->key($this->binB, null, InventoryDetail::STATUS_DAMAGED), 15);

        $this->expectException(InsufficientStockException::class);

        try {
            $this->movements->apply($overdrawn);
        } finally {
            self::assertSame('20.0000', $this->coreQuantity(), 'nothing may have been applied');
        }
    }

    /**
     * A line may legitimately draw on stock an earlier line in the same request put there — move
     * A→B, then B somewhere else. The pre-flight check is a running simulation for exactly this;
     * a per-row sum of the demands would refuse it.
     *
     * The flush before each decrement is what makes it true at write time as well as at check time:
     * B's delivery has to be on disk before the conditional UPDATE reads it.
     */
    public function testALineMayDrawOnStockAnEarlierLineInTheSameRequestDelivered(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-1')->receive($this->product, $this->key($this->binA), 10)
        );

        $chained = MovementRequest::of(InventoryMovementGroup::TYPE_MOVE, 'op-2')
            ->move($this->product, $this->key($this->binA), $this->key($this->binB), 10)
            // binB held nothing before this request; it holds 10 by the time this line runs.
            ->move($this->product, $this->key($this->binB), $this->key($this->binB, null, InventoryDetail::STATUS_DAMAGED), 6);

        $this->movements->apply($chained);
        $this->em->clear();

        self::assertSame('4.0000', $this->coreQuantity(), '10 received, 6 of them damaged in the same operation');
        $this->assertInvariantHolds('a chained move within one request');
    }

    /**
     * An unrecognised status must not become a second `available` row.
     *
     * A key is used first to LOOK UP a row and then to build one if the lookup missed, so the two
     * must agree about what the status is. Coercing only on the entity would search for rows with
     * the bogus status (finding none, since nothing can hold it) and then store the new row as
     * `available` — a duplicate of the one that already existed. Against a migrated database that
     * trips uniq_inventory_detail; against the metadata-built schema the tests use, where that index
     * cannot exist, it silently succeeds. Reachable from the adjustment form, which reads the status
     * straight out of the POST body.
     */
    public function testAnUnrecognisedStatusIsCoercedBeforeTheLookupNotAfterIt(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-1')->receive($this->product, $this->key($this->binA), 10)
        );

        // Straight into the constructor, the way the controller builds it from request input.
        $bogus = new DetailKey($this->warehouse, $this->binA, null, null, 'teleported');
        self::assertSame(InventoryDetail::STATUS_AVAILABLE, $bogus->status);

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-2')->receive($this->product, $bogus, 5)
        );

        $this->em->clear();

        /** @var list<InventoryDetail> $rows */
        $rows = $this->em->getRepository(InventoryDetail::class)->findBy(['status' => InventoryDetail::STATUS_AVAILABLE]);

        self::assertCount(1, $rows, 'the bogus status must land on the row that already exists, not beside it');
        self::assertSame('15.0000', $rows[0]->getQuantity());
        self::assertSame('15.0000', $this->coreQuantity());
    }

    /**
     * The decrement reads its result back instead of computing it.
     *
     * The managed entity is loaded before the transaction opens, and Doctrine does not refresh a
     * managed entity's scalars on a later query — so (in-memory value − amount taken) can be older
     * than the row. That value then becomes an ABSOLUTE write at the next flush, over a relative
     * UPDATE that was correct: a lost update. Simulated here by moving the row under the entity with
     * raw SQL, which is what a concurrent request looks like from in here.
     */
    public function testTheDecrementDoesNotWriteBackAStaleAbsoluteQuantity(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-1')->receive($this->product, $this->key($this->binA), 20)
        );

        $row = $this->details->findExisting($this->product, $this->warehouse, $this->binA, null, null, InventoryDetail::STATUS_AVAILABLE);
        self::assertInstanceOf(InventoryDetail::class, $row);
        self::assertSame('20.0000', $row->getQuantity(), 'the entity is warm in the identity map at 20');

        // Somebody else takes 6. The entity still believes 20.
        $this->em->getConnection()->executeStatement(
            'UPDATE inventory_detail SET quantity = quantity - 6 WHERE id = :id',
            ['id' => $row->getId()],
        );

        self::assertTrue($this->details->decrement($row, 4));

        self::assertSame('10.0000', $row->getQuantity(), '20 − 6 − 4, not the 16 a stale subtraction would give');

        $this->em->flush();
        $this->em->clear();

        $after = $this->details->findExisting($this->product, $this->warehouse, $this->binA, null, null, InventoryDetail::STATUS_AVAILABLE);
        self::assertInstanceOf(InventoryDetail::class, $after);
        self::assertSame('10.0000', $after->getQuantity(), 'the flush must not have written 16 over the 10 on disk');
    }

    /** A resubmitted form or a retried command applies once, because clientOperationId is unique. */
    public function testTheSameOperationIdAppliesOnlyOnce(): void
    {
        $first = $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-idempotent')->receive($this->product, $this->key($this->binA), 12)
        );
        $second = $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-idempotent')->receive($this->product, $this->key($this->binA), 12)
        );

        self::assertSame($first->getId(), $second->getId());
        self::assertSame('12.0000', $this->coreQuantity(), 'the second apply must not have added a second 12');
    }

    /**
     * The two layers stack; they do not compete.
     *
     * `quantity` is the physical total and this layer explains where it is. The hold buckets are
     * CLAIMS against that number, maintained by their own ledgers
     * (OrderInventoryReservation / InvoiceInventoryReservation through
     * InventoryReservationReconciler), and nothing here has any business touching them. If a
     * movement ever moved a bucket, `getAvailableQuantity()` would stop meaning what it has always
     * meant — the same units would be subtracted twice, once as stock that left and once as a claim
     * still outstanding.
     *
     * This is the closest thing the codebase has to the plan's "reservation release handshake". The
     * plan assumed shipping decrements `quantity` and therefore has to release `approved` in the
     * same flush; here nothing in the order or invoice lifecycle decrements `quantity` at all, so
     * the property to protect is the opposite one: the buckets must survive a movement untouched.
     */
    public function testAMovementNeverTouchesAHoldBucket(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-1')->receive($this->product, $this->key($this->binA), 30)
        );

        $inventory = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
        ]);
        self::assertInstanceOf(ProductInventory::class, $inventory);

        // Claims of the kind the reservation ledgers maintain, standing against the 30.
        $inventory->setCartHoldQuantity(3)->setSalesHoldQuantity(4)->setPendingQuantity(5)->setApprovedQuantity(6);
        $this->em->flush();

        self::assertSame('12.0000', $inventory->getAvailableQuantity(), '30 − 3 − 4 − 5 − 6');

        // A bin move, a status change and a withdrawal — one of each kind.
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_MOVE, 'op-2')
                ->move($this->product, $this->key($this->binA), $this->key($this->binB), 10)
        );
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'op-3')
                ->move($this->product, $this->key($this->binB), $this->key($this->binB, null, InventoryDetail::STATUS_DAMAGED), 5)
        );

        $this->em->clear();

        $after = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
        ]);
        self::assertInstanceOf(ProductInventory::class, $after);

        self::assertSame('3.0000', $after->getCartHoldQuantity());
        self::assertSame('4.0000', $after->getSalesHoldQuantity());
        self::assertSame('5.0000', $after->getPendingQuantity());
        self::assertSame('6.0000', $after->getApprovedQuantity());

        // The 5 damaged units come off through `write_off` now, not by shrinking `received` (#581),
        // so the arrivals figure still reads the '30.0' that actually turned up and the write-off is
        // itemised beside it instead of being netted into it.
        self::assertSame(
            '30.0000',
            QuantityScale::add($after->getQuantity(), $after->getReceivedQuantity()),
            'still 30 arrivals on record',
        );
        self::assertSame('5.0000', $after->getWriteOffQuantity(), 'the damage is named, not netted away');
        self::assertSame(
            '25.0000',
            QuantityScale::sub(QuantityScale::add($after->getQuantity(), $after->getReceivedQuantity()), $after->getWriteOffQuantity()),
            'and 25 of them are still good',
        );
        self::assertSame('7.0000', $after->getAvailableQuantity(), 'the claims still subtract exactly what they always did');
        $this->assertInvariantHolds('movements against a product with outstanding claims');
    }

    /**
     * The structural form of the acceptance criterion: this layer cannot reach the quantity of any
     * product that has not opted in, which is every product that exists today.
     */
    /**
     * Revised for the simple-inventory bucket parity plan (2026-09-15): `apply()` no longer refuses
     * a simple product's line outright. `quantity` stays exactly as untouchable as it always has —
     * that invariant does not move — but `received` now gets credited the same way it would for a
     * dimensional line, and no `InventoryDetail` row is ever created for it.
     */
    public function testASimpleProductCreditsReceivedAndWritesARealDetailRow(): void
    {
        $simple = (new ProductCore())->setSku('SIMPLE-1')->setName('Simple')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($simple);
        $inventory = (new ProductInventory())->setProduct($simple)->setWarehouse($this->warehouse)->setQuantity(99);
        $this->em->persist($inventory);
        $this->em->flush();

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-simple')
                ->receive($simple, $this->key($this->binA), 5)
        );

        $this->em->refresh($inventory);
        self::assertSame('99.0000', $inventory->getQuantity(), 'a simple product\'s imported number must be untouched');
        self::assertSame('5.0000', $inventory->getReceivedQuantity(), 'received must still be credited');

        // "Dimensional" gates lot/serial identity, not WHERE stock is — a simple product still
        // resolves a real detail row, bin included, same as a dimensional one would.
        $row = $this->details->findExisting($simple, $this->warehouse, $this->binA, null, null, InventoryDetail::STATUS_AVAILABLE);
        self::assertInstanceOf(InventoryDetail::class, $row);
        self::assertSame('5.0000', $row->getQuantity());
    }

    /**
     * Simple-inventory bucket parity plan: `quarantine`/`write_off` are now independently
     * accumulated (`MovementRequest::quarantineDelta()`/`writeOffDelta()`), not recomputed from the
     * detail-row sum — so for a dimensional product, the bucket and the detail row are written by
     * the SAME call and must agree the moment they're written, and releasing back to `available`
     * must bring the bucket back to zero exactly as it brought the detail row back.
     */
    public function testQuarantiningAndReleasingADimensionalProductMovesTheBucketWithTheDetailRow(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-seed')
                ->receive($this->product, $this->key($this->binA), 20)
        );

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_ADJUSTMENT, 'op-quarantine')
                ->move($this->product, $this->key($this->binA), $this->key($this->binA, null, InventoryDetail::STATUS_QUARANTINE), '5.0000')
        );

        $inventory = $this->coreInventory();
        self::assertSame('5.0000', $inventory->getQuarantineQuantity());
        self::assertSame(
            '5.0000',
            $this->details->findExisting($this->product, $this->warehouse, $this->binA, null, null, InventoryDetail::STATUS_QUARANTINE)?->getQuantity(),
        );
        self::assertSame(
            '15.0000',
            $this->details->findExisting($this->product, $this->warehouse, $this->binA, null, null, InventoryDetail::STATUS_AVAILABLE)?->getQuantity(),
        );

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_ADJUSTMENT, 'op-release')
                ->move($this->product, $this->key($this->binA, null, InventoryDetail::STATUS_QUARANTINE), $this->key($this->binA), 5)
        );

        self::assertSame('0.0000', $this->coreInventory()->getQuarantineQuantity(), 'releasing back to available must bring the bucket back to zero');
    }

    /** @see testQuarantiningAndReleasingADimensionalProductMovesTheBucketWithTheDetailRow() */
    public function testWritingOffADimensionalProductMovesTheBucketWithTheDetailRow(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-seed')
                ->receive($this->product, $this->key($this->binA), 20)
        );

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_ADJUSTMENT, 'op-scrap')
                ->move($this->product, $this->key($this->binA), $this->key($this->binA, null, InventoryDetail::STATUS_SCRAPPED), '3.0000')
        );

        $inventory = $this->coreInventory();
        self::assertSame('3.0000', $inventory->getWriteOffQuantity());
        self::assertSame(
            // A terminal status drops its bin on the way in (DetailKey::normalized()) -- scrapped
            // stock keeps its warehouse but not which shelf it used to sit on.
            '3.0000',
            $this->details->findExisting($this->product, $this->warehouse, null, null, null, InventoryDetail::STATUS_SCRAPPED)?->getQuantity(),
        );
    }

    /**
     * The point of the parity plan for these two buckets: a simple product can be quarantined or
     * written off too, with no `InventoryDetail` row and no source-sufficiency check to fail (there
     * is nothing on disk to check), and it still gets a real, logged bucket credit.
     */
    public function testQuarantiningASimpleProductCreditsTheBucketAndMovesTheUnspecifiedDetailRow(): void
    {
        $simple = (new ProductCore())->setSku('SIMPLE-2')->setName('Simple Two')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($simple);
        $inventory = (new ProductInventory())->setProduct($simple)->setWarehouse($this->warehouse)->setQuantity(50);
        $this->em->persist($inventory);
        $this->em->flush();

        // Everything is "unspecified" (bin/lot/serial all null) until something says otherwise —
        // the same available row a plain receive() into no named bin would resolve.
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-simple-seed')
                ->receive($simple, new DetailKey($this->warehouse), 50)
        );

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_ADJUSTMENT, 'op-simple-quarantine')
                ->move(
                    $simple,
                    new DetailKey($this->warehouse),
                    new DetailKey($this->warehouse, null, null, null, InventoryDetail::STATUS_QUARANTINE),
                    4,
                )
        );

        $this->em->refresh($inventory);
        self::assertSame('50.0000', $inventory->getQuantity(), 'quantity stays untouched');
        self::assertSame('4.0000', $inventory->getQuarantineQuantity());

        $quarantined = $this->details->findExisting($simple, $this->warehouse, null, null, null, InventoryDetail::STATUS_QUARANTINE);
        self::assertInstanceOf(InventoryDetail::class, $quarantined);
        self::assertSame('4.0000', $quarantined->getQuantity());

        $available = $this->details->findExisting($simple, $this->warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE);
        self::assertInstanceOf(InventoryDetail::class, $available);
        self::assertSame('46.0000', $available->getQuantity());
    }

    private function coreInventory(): ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $this->product->getId(), 'warehouse' => $this->warehouse->getId()]);
        self::assertInstanceOf(ProductInventory::class, $row);
        $this->em->refresh($row);

        return $row;
    }

    /**
     * A terminal `sold` row is one row per (product, warehouse, lot) by definition, so the only
     * thing that can answer "to whom" is the group's reference. Requiring it is therefore not
     * validation fussiness — without it a recall is unanswerable.
     */
    public function testAShipMovementRequiresAReference(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-1')->receive($this->product, $this->key($this->binA), 5)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_SHIP, 'op-2')
                ->move($this->product, $this->key($this->binA), $this->key($this->binA, null, InventoryDetail::STATUS_SOLD), 5)
        );
    }

    /** Sold, scrapped and lost keep their warehouse but drop their bin — rule 3. */
    public function testATerminalRowDropsItsLocationAndIsSharedAcrossShipments(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-1')->receive($this->product, $this->key($this->binA), 10)
        );

        foreach (['SO-1', 'SO-2'] as $i => $reference) {
            $this->movements->apply(
                MovementRequest::of(InventoryMovementGroup::TYPE_SHIP, 'op-ship-' . $i, null, null, $reference)
                    ->move($this->product, $this->key($this->binA), $this->key($this->binA, null, InventoryDetail::STATUS_SOLD), 3)
            );
        }

        $this->em->clear();

        /** @var list<InventoryDetail> $sold */
        $sold = $this->em->getRepository(InventoryDetail::class)->findBy(['status' => InventoryDetail::STATUS_SOLD]);

        self::assertCount(1, $sold, 'two shipments of the same lot grow ONE row rather than making two');
        self::assertSame('6.0000', $sold[0]->getQuantity());
        self::assertNull($sold[0]->getLocation(), 'a sold row keeps its warehouse but drops its bin');

        self::assertSame('4.0000', $this->details->availableTotal($this->product, $this->warehouse), '10 received, 6 shipped');
        self::assertSame('10.0000', $this->coreQuantity(), 'the 6 sold units come off via the invoice, not here');
        $this->assertInvariantHolds('two shipments against the same row');
    }
}
