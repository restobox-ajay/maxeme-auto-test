<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Shipment;

use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\Shipment;
use InventoryDepthBundle\Entity\ShipmentLine;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Shipment\ShipmentException;
use InventoryDepthBundle\Shipment\ShipmentRequest;
use InventoryDepthBundle\Shipment\ShipmentService;

/**
 * `ShipmentService::ship()` against the two questions
 * `docs/plans/2026-09-14-shipment-dispatch.md` reduces Shipment to, and the whole-request guards
 * ("Combined shipments") the plan adds on top.
 */
final class ShipmentServiceTest extends DoctrineIntegrationTestCase
{
    private ShipmentService $shipments;
    private StockMovementService $movements;
    private Warehouse $warehouse;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shipments = self::getContainer()->get(ShipmentService::class);
        $this->movements = self::getContainer()->get(StockMovementService::class);

        $region = (new FulfillmentRegion())->setName('Ship Region');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->company = (new Company())->setName('Buyer Ltd');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testSimpleProductShipsAPaperworkOnlyLine(): void
    {
        $product = (new ProductCore())
            ->setSku('SHIP-SIMPLE-1')
            ->setName('Simple Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        $invoiceLine = $this->invoiceLine($product, '5.00');

        $shipment = $this->shipments->ship(
            ShipmentRequest::for($this->company)->add($invoiceLine, '5.00'),
            'tester',
        );

        self::assertCount(1, $shipment->getLines());
        $line = $shipment->getLines()->first();
        self::assertInstanceOf(ShipmentLine::class, $line);
        // The store's configured scale, not the request's string: `ship()` rounds a picker's typed
        // figure once, on the way in, so `shipment_line.quantity` is spelt the way every other
        // quantity column is. It used to store the request byte for byte, which was the same thing
        // only while four places was the only rule there was.
        self::assertSame('5.0000', $line->getQuantity());
        self::assertFalse($line->isMovementApplied(), 'a simple product records paperwork only');
        self::assertNull($line->getLotId());
        self::assertNull($line->getSerial());
        self::assertSame($invoiceLine, $line->getInvoiceLine());
        self::assertStringStartsWith('SHP-', $shipment->getShipmentNumber());
    }

    public function testDimensionalLotTrackedProductMovesAvailableToSoldAtTheInvoiceLinesOwnLot(): void
    {
        $product = $this->lotTrackedProduct('SHIP-LOT-1');
        $lot = $this->lotWithStock($product, 30);

        self::assertSame('30.0000', $this->inventory($product)->getAvailableQuantity(), 'guard: 30 on the shelf to begin with');

        $invoiceLine = $this->invoiceLine($product, '5.00', $lot->getId(), issued: false);
        $this->heldByInvoice($product, '5.0000');

        $beforeShip = $this->inventory($product);
        // The `approved` hold already removed these 5 units from availability the moment the
        // invoice reached Processing — SellingADimensionalProductTest settles this. ship() has not
        // run yet, so this is the hold's own effect, not the movement's.
        self::assertSame('25.0000', $beforeShip->getAvailableQuantity());
        self::assertSame('5.0000', $beforeShip->getApprovedQuantity(), 'guard: the invoice already holds the approved bucket');

        $shipment = $this->shipments->ship(
            ShipmentRequest::for($this->company)->add($invoiceLine, '5.00'),
            'tester',
        );

        $after = $this->inventory($product);
        self::assertSame('25.0000', $after->getAvailableQuantity(), 'ship() reclassifies detail rows; it does not change availability a second time');
        self::assertSame('5.0000', $this->soldUnits($product), 'a sold detail row now exists for the shipped quantity');

        // The hold has moved from `approved` to `shipped`, and ship() still did not touch either
        // column — the partial test below is what pins that. This line shipped the WHOLE invoice, so
        // InvoiceCompletedWhenFullyShippedSubscriber completed the invoice, and core's own
        // InvoiceInventoryBucketResolver moves the balance on Completed exactly as it always has for
        // an invoice completed by hand. Availability is unchanged by the move because both buckets
        // subtract from it; what changed is which one says why.
        self::assertSame('0.0000', $after->getApprovedQuantity());
        self::assertSame('5.0000', $after->getShippedQuantity());

        $line = $shipment->getLines()->first();
        self::assertInstanceOf(ShipmentLine::class, $line);
        self::assertTrue($line->isMovementApplied());
        self::assertSame($lot->getId(), $line->getLotId());
    }

    /**
     * The same movement, stopping short of the whole line, which is what keeps "ship() never touches
     * the reservation hold" a testable claim now that shipping everything completes the invoice.
     *
     * Nothing about the invoice's lifecycle moves here — 3 of 5 leaves 2 owed — so any change to
     * `approved` or `shipped` could only have come from this service, and there is none.
     */
    public function testAPartialLotTrackedShipmentLeavesTheReservationHoldAloneEntirely(): void
    {
        $product = $this->lotTrackedProduct('SHIP-LOT-PARTIAL');
        $lot = $this->lotWithStock($product, 30);

        $invoiceLine = $this->invoiceLine($product, '5.00', $lot->getId(), issued: false);
        $this->heldByInvoice($product, '5.0000');

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '3.00'), 'tester');

        $after = $this->inventory($product);
        self::assertSame('5.0000', $after->getApprovedQuantity(), 'ship() never touches the reservation hold');
        self::assertSame('0.0000', $after->getShippedQuantity(), 'and never writes the bucket on the other side of it either');
        self::assertSame('3.0000', $this->soldUnits($product));
        self::assertSame('Processing', $invoiceLine->getInvoice()->getStatus(), 'still owed 2, so nothing completed');
    }

    public function testRefusesToSubstituteADifferentLotEvenWhenOneWouldCover(): void
    {
        $product = $this->lotTrackedProduct('SHIP-LOT-2');
        $lot = $this->lotWithStock($product, 3, 'THIN-LOT');
        // Plenty of stock overall, just not under THIS lot — proves ship() never looks elsewhere.
        $this->lotWithStock($product, 100, 'OTHER-LOT');

        $invoiceLine = $this->invoiceLine($product, '5.00', $lot->getId(), issued: false);
        $this->heldByInvoice($product, '5.0000');

        $this->expectException(ShipmentException::class);
        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '5.00'));
    }

    public function testRefusesOversellBeyondTheInvoiceLine(): void
    {
        $product = (new ProductCore())->setSku('SHIP-OVERSELL')->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        $invoiceLine = $this->invoiceLine($product, '5.00');

        $this->expectException(ShipmentException::class);
        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '6.00'));
    }

    public function testPartialShipmentThenShippingTheRestSucceedsButOversellingAfterThatIsRefused(): void
    {
        $product = (new ProductCore())->setSku('SHIP-PARTIAL')->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        $invoiceLine = $this->invoiceLine($product, '5.00');

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '2.00'));
        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '3.00'));

        $this->expectException(ShipmentException::class);
        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '1.00'));
    }

    public function testRefusesADifferentCompanysInvoiceLineOnTheSameShipment(): void
    {
        $otherCompany = (new Company())->setName('Other Ltd');
        $this->em->persist($otherCompany);
        $this->em->flush();

        $product = (new ProductCore())->setSku('SHIP-COMBINE')->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        $invoiceLine = $this->invoiceLine($product, '5.00', null, $otherCompany);

        $this->expectException(ShipmentException::class);
        // Shipment is for $this->company; the line belongs to $otherCompany's invoice.
        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '5.00'));
    }

    public function testIsIdempotentOnClientOperationId(): void
    {
        $product = (new ProductCore())->setSku('SHIP-IDEMPOTENT')->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        $invoiceLine = $this->invoiceLine($product, '5.00');

        $first = $this->shipments->ship(ShipmentRequest::for($this->company, clientOperationId: 'op-1')->add($invoiceLine, '5.00'));
        $second = $this->shipments->ship(ShipmentRequest::for($this->company, clientOperationId: 'op-1')->add($invoiceLine, '5.00'));

        self::assertSame($first->getId(), $second->getId());
        self::assertCount(1, $this->em->getRepository(Shipment::class)->findAll());
    }

    public function testCombinesTwoInvoicesForTheSameCompanyIntoOneShipment(): void
    {
        $product = (new ProductCore())->setSku('SHIP-COMBINE-OK')->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        $lineA = $this->invoiceLine($product, '2.00');
        $lineB = $this->invoiceLine($product, '3.00');
        self::assertNotSame($lineA->getInvoice(), $lineB->getInvoice(), 'guard: two separate invoices');
        self::assertSame($lineA->getInvoice()->getCompany()->getId(), $lineB->getInvoice()->getCompany()->getId(), 'guard: same company');

        $shipment = $this->shipments->ship(
            ShipmentRequest::for($this->company)->add($lineA, '2.00')->add($lineB, '3.00'),
        );

        self::assertCount(2, $shipment->getLines());
        $invoicesOnShipment = array_map(
            static fn (ShipmentLine $line): int => $line->getInvoiceLine()->getInvoice()->getId(),
            $shipment->getLines()->toArray(),
        );
        self::assertContains($lineA->getInvoice()->getId(), $invoicesOnShipment);
        self::assertContains($lineB->getInvoice()->getId(), $invoicesOnShipment);
    }

    public function testSerialTrackedProductMovesExactlyOneUnitAtTheInvoiceLinesOwnSerial(): void
    {
        $policy = (new TrackingPolicy())->setName('Serial SHIP-SERIAL-1')->setMode(TrackingPolicy::MODE_SERIAL)->setTrackOut(true);
        $this->em->persist($policy);

        $product = (new ProductCore())
            ->setSku('SHIP-SERIAL-1')
            ->setName('Serialised Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
            ->setTrackingPolicy($policy);
        $this->em->persist($product);
        $this->em->flush();

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed-serial')
                ->receive($product, new DetailKey($this->warehouse, null, null, 'SN-001', InventoryDetail::STATUS_AVAILABLE), 1),
        );
        $this->em->flush();

        $invoiceLine = $this->invoiceLine($product, '1.00', null, null, 'SN-001');

        $shipment = $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '1.00'));

        self::assertSame('0.0000', $this->availableUnits($product), 'the serial left available');
        self::assertSame('1.0000', $this->soldUnits($product));

        $line = $shipment->getLines()->first();
        self::assertInstanceOf(ShipmentLine::class, $line);
        self::assertSame('SN-001', $line->getSerial());
        self::assertTrue($line->isMovementApplied());
    }

    public function testRefusesShippingTwoUnitsUnderOneSerial(): void
    {
        $policy = (new TrackingPolicy())->setName('Serial SHIP-SERIAL-2')->setMode(TrackingPolicy::MODE_SERIAL)->setTrackOut(true);
        $this->em->persist($policy);

        $product = (new ProductCore())
            ->setSku('SHIP-SERIAL-2')
            ->setName('Serialised Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
            ->setTrackingPolicy($policy);
        $this->em->persist($product);
        $this->em->flush();

        $invoiceLine = $this->invoiceLine($product, '2.00', null, null, 'SN-002');

        $this->expectException(ShipmentException::class);
        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '2.00'));
    }

    /**
     * Step 2 (`docs/plans/2026-09-14-shipment-dispatch.md`'s 2026-09-20 revision): a real pick at
     * ship time may split one invoice line across more than one lot in a SINGLE shipment — the case
     * the invoice line's own single `lotId` field could never represent.
     */
    public function testShipsOneInvoiceLineAcrossTwoLotsInOneShipmentViaAnExplicitAllocation(): void
    {
        $product = $this->lotTrackedProduct('SHIP-ALLOC-LOT');
        $lotA = $this->lotWithStock($product, 6, 'ALLOC-A');
        $lotB = $this->lotWithStock($product, 4, 'ALLOC-B');

        // The invoice line captures lotA alone to satisfy MandatoryCaptureGuard at issue — the
        // allocation below is still what's authoritative for this shipment, splitting across BOTH
        // lots despite the invoice line naming only one.
        $invoiceLine = $this->invoiceLine($product, '10.00', $lotA->getId());

        $shipment = $this->shipments->ship(
            ShipmentRequest::for($this->company)->add($invoiceLine, '10.00', [
                ['lotId' => $lotA->getId(), 'serial' => null, 'quantity' => '6'],
                ['lotId' => $lotB->getId(), 'serial' => null, 'quantity' => '4'],
            ]),
        );

        self::assertCount(2, $shipment->getLines());
        self::assertSame('10.0000', $this->soldUnits($product));
        self::assertSame('0.0000', $this->availableUnits($product));

        $byLot = [];
        foreach ($shipment->getLines() as $line) {
            self::assertTrue($line->isMovementApplied());
            $byLot[$line->getLotId()] = (int) round((float) $line->getQuantity());
        }
        self::assertSame(6, $byLot[$lotA->getId()]);
        self::assertSame(4, $byLot[$lotB->getId()]);
    }

    public function testRefusesAnAllocationThatDoesNotSumToTheLineQuantity(): void
    {
        $product = $this->lotTrackedProduct('SHIP-ALLOC-MISMATCH');
        $lotA = $this->lotWithStock($product, 6, 'MISMATCH-A');
        $lotB = $this->lotWithStock($product, 4, 'MISMATCH-B');

        $invoiceLine = $this->invoiceLine($product, '10.00', $lotA->getId());

        $this->expectException(ShipmentException::class);
        $this->shipments->ship(
            ShipmentRequest::for($this->company)->add($invoiceLine, '10.00', [
                ['lotId' => $lotA->getId(), 'serial' => null, 'quantity' => '6'],
                ['lotId' => $lotB->getId(), 'serial' => null, 'quantity' => '3'],
            ]),
        );
    }

    /**
     * A serial identifies one unit, so a qty-3 line can only ever ship as three allocation rows —
     * this is the case the invoice line's own single `serial` field could never represent at all,
     * regardless of partial shipments.
     */
    public function testShipsThreeSeparateSerialsForOneInvoiceLineViaAllocations(): void
    {
        $policy = (new TrackingPolicy())->setName('Serial ALLOC')->setMode(TrackingPolicy::MODE_SERIAL)->setTrackOut(true);
        $this->em->persist($policy);

        $product = (new ProductCore())
            ->setSku('SHIP-ALLOC-SERIAL')
            ->setName('Serialised Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
            ->setTrackingPolicy($policy);
        $this->em->persist($product);
        $this->em->flush();

        foreach (['SN-A', 'SN-B', 'SN-C'] as $serial) {
            $this->movements->apply(
                MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed-' . $serial)
                    ->receive($product, new DetailKey($this->warehouse, null, null, $serial, InventoryDetail::STATUS_AVAILABLE), 1),
            );
        }
        $this->em->flush();

        // Captures SN-A alone to satisfy MandatoryCaptureGuard at issue — the allocation below is
        // still what's authoritative, naming all three serials the single capture field never could.
        $invoiceLine = $this->invoiceLine($product, '3.00', null, null, 'SN-A');

        $shipment = $this->shipments->ship(
            ShipmentRequest::for($this->company)->add($invoiceLine, '3.00', [
                ['lotId' => null, 'serial' => 'SN-A', 'quantity' => '1'],
                ['lotId' => null, 'serial' => 'SN-B', 'quantity' => '1'],
                ['lotId' => null, 'serial' => 'SN-C', 'quantity' => '1'],
            ]),
        );

        self::assertCount(3, $shipment->getLines());
        $serials = array_map(static fn (ShipmentLine $line): ?string => $line->getSerial(), $shipment->getLines()->toArray());
        sort($serials);
        self::assertSame(['SN-A', 'SN-B', 'SN-C'], $serials);
        self::assertSame('3.0000', $this->soldUnits($product));
    }

    // ── quantities are decimal (2026-09-17) ─────────────────────────────────

    /**
     * 0.4 of a 1-unit line, which this service used to refuse outright.
     *
     * `(int) round(0.4)` is 0, so the request tripped "shipment quantity must be greater than zero"
     * and a real part shipment of a drum, a roll or a metre could not be recorded at all. The
     * quantity is stored byte for byte as the caller wrote it, and what remains is the column's own
     * four decimal places.
     */
    public function testShipsAFractionOfAUnitWhereItUsedToRefuseItAsZero(): void
    {
        $product = $this->simpleProduct('SHIP-FRACTION');
        $invoiceLine = $this->invoiceLine($product, '1.0000');

        $shipment = $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '0.4000'));

        $line = $shipment->getLines()->first();
        self::assertInstanceOf(ShipmentLine::class, $line);
        self::assertSame('0.4000', $line->getQuantity());
        self::assertSame('0.6000', $this->shipments->remainingToShip($invoiceLine));
    }

    /**
     * 1.6 against a 1.5 line. Rounded, both sides read 2 and this ships MORE than was ever billed.
     *
     * The refusal is the assertion, and so is the absence of a row: an oversell guard that refuses
     * after writing is not a guard.
     */
    public function testRefusesToOvershipALineByRoundingTheQuantityUp(): void
    {
        $product = $this->simpleProduct('SHIP-ROUND-UP');
        $invoiceLine = $this->invoiceLine($product, '1.5000');

        try {
            $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '1.6000'));
            self::fail('1.6 against a 1.5 line must be refused, not rounded to 2');
        } catch (ShipmentException $e) {
            self::assertStringContainsString('remains unshipped', $e->getMessage());
        }

        self::assertCount(0, $this->em->getRepository(ShipmentLine::class)->findBy(['invoiceLine' => $invoiceLine]));
        self::assertSame('1.5000', $this->shipments->remainingToShip($invoiceLine));
    }

    /**
     * Tenths that sum exactly, which they do not as floats: 0.1 + 0.2 is 0.30000000000000004, so a
     * float comparison leaves this line owing a sliver forever and the next 0.0001 is accepted
     * against a line that is already full.
     */
    public function testTwoTenthsFinishAThreeTenthLineExactly(): void
    {
        $product = $this->simpleProduct('SHIP-TENTHS');
        $invoiceLine = $this->invoiceLine($product, '0.3000');

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '0.1000'));
        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '0.2000'));

        self::assertSame('0.0000', $this->shipments->remainingToShip($invoiceLine));

        $this->expectException(ShipmentException::class);
        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '0.0001'));
    }

    /** The breakdown's own sum is scaled too, so fractional allocations reconcile exactly. */
    public function testAFractionalBreakdownMustStillSumToTheLineQuantity(): void
    {
        $product = $this->simpleProduct('SHIP-FRACTION-ALLOC');
        $invoiceLine = $this->invoiceLine($product, '1.0000');

        $shipment = $this->shipments->ship(
            ShipmentRequest::for($this->company)->add($invoiceLine, '1.0000', [
                ['lotId' => null, 'serial' => null, 'quantity' => '0.6000'],
                ['lotId' => null, 'serial' => null, 'quantity' => '0.4000'],
            ]),
        );

        self::assertCount(2, $shipment->getLines());
        self::assertSame('0.0000', $this->shipments->remainingToShip($invoiceLine));
    }

    public function testRefusesAFractionalBreakdownThatIsAHairShortOfTheLineQuantity(): void
    {
        $product = $this->simpleProduct('SHIP-FRACTION-ALLOC-SHORT');
        $invoiceLine = $this->invoiceLine($product, '1.0000');

        $this->expectException(ShipmentException::class);
        $this->shipments->ship(
            ShipmentRequest::for($this->company)->add($invoiceLine, '1.0000', [
                ['lotId' => null, 'serial' => null, 'quantity' => '0.6000'],
                ['lotId' => null, 'serial' => null, 'quantity' => '0.3999'],
            ]),
        );
    }

    /**
     * A LOT-tracked line ships a fraction like any other, and this test used to assert the opposite.
     *
     * It was `testRefusesAFractionalPickForALotTrackedProduct`, and its reason was
     * "`inventory_detail.quantity` is an `int` count of physical rows and `MovementRequest::move()`
     * takes an `int`". Neither was a fact about the ledger: the column has been `NUMERIC(14, 4)`
     * since #645 and the `int` was `App\Doctrine\Type\QuantityType` extending `IntegerType`. Both
     * are fixed, so a lot is what it always was — the batch a quantity came from — and 0.5 kg out
     * of a batch is the same kind of number as 0.5 kg out of anything else.
     *
     * The whole-unit rule that survives is the SERIAL one, and it has its own test below.
     */
    public function testAFractionalPickIsOrdinaryForALotTrackedProduct(): void
    {
        $product = $this->lotTrackedProduct('SHIP-FRACTION-TRACKED');
        $lot = $this->lotWithStock($product, 10);

        $invoiceLine = $this->invoiceLine($product, '2.00', $lot->getId());

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '0.5000'));

        $lines = $this->em->getRepository(ShipmentLine::class)->findBy(['invoiceLine' => $invoiceLine]);
        self::assertCount(1, $lines);
        self::assertSame('0.5000', $lines[0]->getQuantity(), 'the paperwork records the half that was picked');
        self::assertSame('0.5000', $this->soldUnits($product), 'and the half actually left the shelf');
        self::assertSame('9.5000', $this->availableUnits($product), 'leaving the rest of the lot behind');
    }

    private function simpleProduct(string $sku): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function invoiceLine(ProductCore $product, string $quantity, ?int $lotId = null, ?Company $company = null, ?string $serial = null, bool $issued = true): InvoiceLine
    {
        $company ??= $this->company;

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('SHIP-ORD-' . uniqid())
            ->setDocumentDate('2026-09-15')
            ->setFulfillmentRegion($this->warehouse->getName());
        $this->em->persist($order);

        $orderLine = (new SalesOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setQuantity($quantity)
            ->setLocation($this->warehouse->getName())
            ->setLotId($lotId)
            ->setSerial($serial);
        $order->addLine($orderLine);
        $this->em->flush();

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->flush();

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('SHIP-INV-' . uniqid())
            ->setDocumentDate('2026-09-15')
            ->setFulfillmentRegion($this->warehouse->getName());
        $order->addInvoice($invoice);

        $invoiceLine = (new InvoiceLine())
            ->setSalesOrderLine($orderLine)
            ->setProduct($product)
            ->setName($product->getName())
            ->setQuantity($quantity)
            ->setLotId($lotId)
            ->setSerial($serial);
        $invoice->addLine($invoiceLine);
        $this->em->persist($invoice);
        $this->em->flush();

        // Shipping happens against a live invoice, not a draft (#784) — issued by default so every
        // test not specifically about that gate exercises ship() the way it is actually reached.
        // heldByInvoice() below does its own issue() as step 2/3 of pushing the invoice further to
        // Processing, so callers that follow it with heldByInvoice() pass issued: false here.
        if ($issued) {
            $invoice->issue(DocumentActor::system());
            $this->em->flush();
        }

        return $invoiceLine;
    }

    /** Mirrors SellingADimensionalProductTest's step 2/3: pushes the invoice into Processing so it holds `approved`. */
    private function heldByInvoice(ProductCore $product, string $expected): void
    {
        $invoiceLine = $this->em->getRepository(InvoiceLine::class)->findOneBy(['product' => $product]);
        self::assertInstanceOf(InvoiceLine::class, $invoiceLine);

        $invoice = $invoiceLine->getInvoice();
        $invoice->issue(DocumentActor::system());
        $this->em->flush();
        $invoice->startProcessing(DocumentActor::system());
        $this->em->flush();

        self::assertSame($expected, $this->inventory($product)->getApprovedQuantity());
    }

    /** A product opted fully into lot tracking: TrackingPolicy(mode=lot, trackOut) AND dimensional. */
    private function lotTrackedProduct(string $sku): ProductCore
    {
        $policy = (new TrackingPolicy())->setName('Lot ' . $sku)->setMode(TrackingPolicy::MODE_LOT)->setTrackOut(true);
        $this->em->persist($policy);

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Lot Tracked Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
            ->setTrackingPolicy($policy);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function lotWithStock(ProductCore $product, int $quantity, string $code = 'BATCH-A'): InventoryLot
    {
        $lot = (new InventoryLot())->setProduct($product)->setCode($code)->setExpiry(new \DateTimeImmutable('2027-01-31'));
        $this->em->persist($lot);
        $this->em->flush();

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed-' . $code)
                ->receive($product, new DetailKey($this->warehouse, null, $lot, null, InventoryDetail::STATUS_AVAILABLE), $quantity),
        );
        $this->em->flush();

        return $lot;
    }

    private function inventory(ProductCore $product): ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product->getId(),
            'warehouse' => $this->warehouse->getId(),
        ]);
        self::assertInstanceOf(ProductInventory::class, $row);
        $this->em->refresh($row);

        return $row;
    }

    /** A decimal string since the quantity columns started reading as decimals in PHP. */
    private function soldUnits(ProductCore $product): string
    {
        return QuantityScale::canonical((string) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
            [$product->getId(), InventoryDetail::STATUS_SOLD],
        ));
    }

    private function availableUnits(ProductCore $product): string
    {
        return QuantityScale::canonical((string) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
            [$product->getId(), InventoryDetail::STATUS_AVAILABLE],
        ));
    }
}
