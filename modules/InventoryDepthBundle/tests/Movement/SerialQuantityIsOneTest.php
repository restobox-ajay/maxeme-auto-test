<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Movement;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * **A serial row is always quantity 1** (#573).
 *
 * Nothing enforced this before. `uniq_live_serial` constrains how many ROWS a serial may have
 * holding stock — exactly one — and says nothing whatever about the quantity sitting on that row,
 * so `S/N ABC123 × 40` satisfied every guard in the schema and every guard in the service. It is
 * what makes a serial a serial rather than a lot, and Dynamics BC, AX, NetSuite and Zoho all
 * enforce it.
 *
 * Two layers, tested separately because they fail differently:
 *
 *  - `InventoryDetail::setQuantity()` / `setSerial()` — the guard that cannot be bypassed, including
 *    by code that never goes near a movement.
 *  - `StockMovementService::apply()` — the pre-flight, so the ordinary case is a sentence an
 *    operator can act on and nothing is half-written when it fires.
 *
 * **And what it is not about: a sentinel.** The policy's `sentinel_in` is a label on the
 * unidentified row, not a serial number — it identifies no physical unit, so N unidentified units
 * are one row of N wearing it. Both layers exempt it, and the tests below check the exemption is
 * the VALUE and not the mode: a genuine serial on the very same policy is still held to one.
 */
final class SerialQuantityIsOneTest extends DoctrineIntegrationTestCase
{
    private StockMovementService $movements;
    private InventoryDetailRepository $details;
    private Warehouse $warehouse;
    private ProductCore $product;
    private WarehouseLocation $bin;

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
            ->setSku('SER-1')
            ->setName('Serialised Product')
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);

        $this->bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('A-01');
        $this->em->persist($this->bin);
        $this->em->flush();
    }

    public function testARowCarryingASerialRefusesAQuantityAboveOne(): void
    {
        $row = (new InventoryDetail())
            ->setProduct($this->product)
            ->setWarehouse($this->warehouse)
            ->setSerial('SN-1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/one unit/');

        $row->setQuantity(2);
    }

    /** One is a serial. Zero is a serial that has moved on, and a row at 0 is history, never deleted. */
    public function testOneAndZeroAreBothFine(): void
    {
        $row = (new InventoryDetail())
            ->setProduct($this->product)
            ->setWarehouse($this->warehouse)
            ->setSerial('SN-1');

        self::assertSame('1.0000', $row->setQuantity(1)->getQuantity());
        self::assertSame('0.0000', $row->setQuantity(0)->getQuantity());
    }

    /** The same ceiling from the other side: a row already holding 40 cannot be told it is one unit. */
    public function testARowHoldingManyUnitsCannotBeGivenASerial(): void
    {
        $row = (new InventoryDetail())
            ->setProduct($this->product)
            ->setWarehouse($this->warehouse);
        $row->setQuantity(40);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/one unit/');

        $row->setSerial('SN-1');
    }

    /**
     * A sentinel is exempt, because it is a label on the unidentified row and not a serial number.
     *
     * The rule this class guards is about genuine serial numbers, each of which identifies one
     * physical unit. `[PENDING]` identifies none, so five unidentified units are one row of five
     * wearing the label — and `uniq_live_serial` is satisfied either way, since it allows one live
     * row per serial VALUE and there is one row.
     */
    public function testARowCarryingThePolicySentinelIsExemptFromTheCeiling(): void
    {
        $this->product->setTrackingPolicy($this->serialPolicyWithSentinel('[PENDING]'));

        $row = (new InventoryDetail())
            ->setProduct($this->product)
            ->setWarehouse($this->warehouse)
            ->setSerial('[PENDING]');

        self::assertSame('5.0000', $row->setQuantity(5)->getQuantity());
        self::assertTrue($row->carriesPlaceholderSerial());
    }

    /** And from the other side: a row already holding five may still be given the label. */
    public function testARowHoldingManyUnitsMayStillBeGivenTheSentinel(): void
    {
        $this->product->setTrackingPolicy($this->serialPolicyWithSentinel('[PENDING]'));

        $row = (new InventoryDetail())
            ->setProduct($this->product)
            ->setWarehouse($this->warehouse);
        $row->setQuantity(5);

        self::assertSame('[PENDING]', $row->setSerial('[PENDING]')->getSerial());
    }

    /** The exemption is the sentinel VALUE, not the mode: a real serial on the same policy still holds one. */
    public function testAGenuineSerialOnASentinelBearingPolicyIsStillHeldToOne(): void
    {
        $this->product->setTrackingPolicy($this->serialPolicyWithSentinel('[PENDING]'));

        $row = (new InventoryDetail())
            ->setProduct($this->product)
            ->setWarehouse($this->warehouse)
            ->setSerial('SN-1');

        self::assertFalse($row->carriesPlaceholderSerial());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/one unit/');

        $row->setQuantity(2);
    }

    /** The movement pre-flight agrees with the entity: five units under the label go through. */
    public function testAMovementMayDeliverFiveUnitsUnderTheSentinel(): void
    {
        $this->product->setTrackingPolicy($this->serialPolicyWithSentinel('[PENDING]'));
        $this->em->flush();

        $request = MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'ser-sentinel-five');
        $request->receive($this->product, new DetailKey($this->warehouse, $this->bin, null, '[PENDING]'), 5);
        $this->movements->apply($request);

        $row = $this->details->findExisting(
            $this->product,
            $this->warehouse,
            $this->bin,
            null,
            '[PENDING]',
            InventoryDetail::STATUS_AVAILABLE,
        );

        self::assertNotNull($row);
        self::assertSame('5.0000', $row->getQuantity(), 'one row of five, not five rows and not a refusal');
    }

    private function serialPolicyWithSentinel(?string $sentinelIn): TrackingPolicy
    {
        $policy = (new TrackingPolicy())
            ->setName('Serial ' . ($sentinelIn ?? 'blank'))
            ->setMode(TrackingPolicy::MODE_SERIAL)
            ->setTrackIn(true)
            ->setSentinelIn($sentinelIn);
        $this->em->persist($policy);

        return $policy;
    }

    /** A lot is a quantity of interchangeable units, and nothing here changes that. */
    public function testALotRowIsUnaffected(): void
    {
        $row = (new InventoryDetail())
            ->setProduct($this->product)
            ->setWarehouse($this->warehouse);

        self::assertSame('400.0000', $row->setQuantity(400)->getQuantity());
    }

    /** The receipt of two units under one serial, refused before the transaction opens. */
    public function testAMovementCannotDeliverTwoUnitsUnderOneSerial(): void
    {
        $request = MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'ser-two-at-once');
        $request->receive($this->product, new DetailKey($this->warehouse, $this->bin, null, 'SN-1'), 2);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SN-1/');

        $this->movements->apply($request);
    }

    /**
     * And the sneaky version: two lines of one each, which pass individually.
     *
     * Simulated in request order like the source check, so the second line sees the first one's
     * delivery. Summing per row would let this through; checking each line in isolation certainly
     * would.
     */
    public function testTwoSeparateLinesUnderOneSerialAreRefusedToo(): void
    {
        $request = MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'ser-two-lines');
        $request->receive($this->product, new DetailKey($this->warehouse, $this->bin, null, 'SN-1'), 1);
        $request->receive($this->product, new DetailKey($this->warehouse, $this->bin, null, 'SN-1'), 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SN-1/');

        $this->movements->apply($request);
    }

    /** And a second unit arriving later, against a row that already holds its one. */
    public function testASecondUnitArrivingLaterIsRefused(): void
    {
        $first = MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'ser-first');
        $first->receive($this->product, new DetailKey($this->warehouse, $this->bin, null, 'SN-1'), 1);
        $this->movements->apply($first);

        $second = MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'ser-second');
        $second->receive($this->product, new DetailKey($this->warehouse, $this->bin, null, 'SN-1'), 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SN-1/');

        $this->movements->apply($second);
    }

    /** Nothing above may have made an ordinary serialised receipt harder. */
    public function testOneUnitUnderOneSerialStillGoesThrough(): void
    {
        $request = MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'ser-ok');
        $request->receive($this->product, new DetailKey($this->warehouse, $this->bin, null, 'SN-1'), 1);
        $this->movements->apply($request);

        $row = $this->details->findExisting(
            $this->product,
            $this->warehouse,
            $this->bin,
            null,
            'SN-1',
            InventoryDetail::STATUS_AVAILABLE,
        );

        self::assertNotNull($row);
        self::assertSame('1.0000', $row->getQuantity());
    }

    /** Nor an ordinary non-serialised one, which is every product until somebody opts one in. */
    public function testAnUnserialisedReceiptOfFortyStillGoesThrough(): void
    {
        $request = MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'plain-ok');
        $request->receive($this->product, new DetailKey($this->warehouse, $this->bin), 40);
        $this->movements->apply($request);

        $row = $this->details->findExisting(
            $this->product,
            $this->warehouse,
            $this->bin,
            null,
            null,
            InventoryDetail::STATUS_AVAILABLE,
        );

        self::assertNotNull($row);
        self::assertSame('40.0000', $row->getQuantity());
    }
}
