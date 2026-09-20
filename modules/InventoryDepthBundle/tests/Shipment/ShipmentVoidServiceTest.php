<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Shipment;

use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
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
use InventoryDepthBundle\Movement\InsufficientStockException;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use InventoryDepthBundle\Shipment\ShipmentException;
use InventoryDepthBundle\Shipment\ShipmentRequest;
use InventoryDepthBundle\Shipment\ShipmentService;
use InventoryDepthBundle\Shipment\ShipmentVoidService;

/**
 * `ShipmentVoidService::void()` — the reversal half of
 * `docs/plans/2026-09-14-shipment-dispatch.md`'s "Void" section.
 */
final class ShipmentVoidServiceTest extends DoctrineIntegrationTestCase
{
    private ShipmentService $shipments;
    private ShipmentVoidService $voids;
    private StockMovementService $movements;
    private InventoryDetailRepository $details;
    private Warehouse $warehouse;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shipments = self::getContainer()->get(ShipmentService::class);
        $this->voids = self::getContainer()->get(ShipmentVoidService::class);
        $this->movements = self::getContainer()->get(StockMovementService::class);
        $this->details = self::getContainer()->get(InventoryDetailRepository::class);

        $region = (new FulfillmentRegion())->setName('Void Region');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->company = (new Company())->setName('Buyer Ltd');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testVoidingASimpleProductShipmentHasNoStockEffect(): void
    {
        $product = (new ProductCore())->setSku('VOID-SIMPLE')->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        $invoiceLine = $this->invoiceLine($product, '5.00');
        $shipment = $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '5.00'));

        $voided = $this->voids->void($shipment, 'Wrong customer', 'tester');

        self::assertTrue($voided->isVoided());
        self::assertSame('Wrong customer', $voided->getVoidReason());
        self::assertSame('tester', $voided->getVoidedBy());
    }

    public function testVoidingALotTrackedShipmentMovesStockBackFromSoldToAvailable(): void
    {
        $product = $this->lotTrackedProduct('VOID-LOT-1');
        $lot = $this->lotWithStock($product, 30);

        $invoiceLine = $this->invoiceLine($product, '5.00', $lot->getId());
        $this->issueAndProcess($invoiceLine);

        $shipment = $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '5.00'));

        self::assertSame('25.0000', $this->details->availableForLot($lot));
        self::assertSame('5.0000', $this->soldUnits($product));

        $this->voids->void($shipment, 'Damaged in transit, never actually left', 'tester');

        self::assertSame('30.0000', $this->details->availableForLot($lot), 'the units are back on the shelf');
        self::assertSame('0.0000', $this->soldUnits($product), 'nothing sold remains');
    }

    public function testVoidingTwiceIsRefused(): void
    {
        $product = (new ProductCore())->setSku('VOID-TWICE')->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        $invoiceLine = $this->invoiceLine($product, '5.00');
        $shipment = $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '5.00'));

        $this->voids->void($shipment, 'First void', 'tester');

        $this->expectException(ShipmentException::class);
        $this->voids->void($shipment, 'Second void', 'tester');
    }

    public function testVoidingRequiresAReason(): void
    {
        $product = (new ProductCore())->setSku('VOID-NOREASON')->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        $invoiceLine = $this->invoiceLine($product, '5.00');
        $shipment = $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '5.00'));

        $this->expectException(ShipmentException::class);
        $this->voids->void($shipment, '   ', 'tester');
    }

    /**
     * A fraction on a line that moved stock is refused rather than rounded.
     *
     * This used to be `(int) round((float) $line->getQuantity())`, so a `1.6000` line put 2 units
     * back on the shelf — one more than could ever have left it. `ShipmentService` now refuses to
     * record a fractional pick for a tracked product at all, which is why the row has to be written
     * by hand here: the state is unreachable through the application and the guard exists precisely
     * for a row that predates that refusal.
     */
    /**
     * A fractional line is VOIDED, and this test used to assert that it was refused.
     *
     * The refusal was real and its reason was honest at the time — the movement layer took an `int`,
     * so there was no way to put 1.6 back and rounding it would have returned a different number of
     * units than left. It was also the worst place in the stack for a limitation to sit: a shipment
     * that had gone out could not be reversed at all.
     *
     * `App\Doctrine\Type\QuantityType` is a decimal type now and `MovementRequest::move()` takes a
     * decimal quantity, so a void returns exactly what the line took, whatever its shape. The only
     * whole-number rule left anywhere is the serial one, enforced on the way OUT by
     * `ShipmentService::assertSerialPicksAreWholeUnits()`.
     */
    public function testVoidingAFractionalQuantityPutsExactlyThatFractionBack(): void
    {
        $product = $this->lotTrackedProduct('VOID-FRACTION');
        $lot = $this->lotWithStock($product, 10);

        $invoiceLine = $this->invoiceLine($product, '2.00', $lot->getId());
        $this->issueAndProcess($invoiceLine);

        // Shipped through the real service, so the `sold` rows this void has to draw back actually
        // exist — the old version of this test hand-built a shipment that had never moved anything,
        // which only worked because it asserted a refusal that came before the movement.
        $shipment = $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '1.6000'));

        self::assertSame('8.4000', $this->details->availableForLot($lot), 'guard: 1.6 left the shelf');
        self::assertSame('1.6000', $this->soldUnits($product), 'guard: and landed in the sold rows');

        $this->voids->void($shipment, 'Never actually left', 'tester');

        self::assertSame('10.0000', $this->details->availableForLot($lot), 'the whole 1.6 came back');
        self::assertSame('0.0000', $this->soldUnits($product), 'and nothing is still sold');
        self::assertTrue($shipment->isVoided());
    }

    public function testVoidingRefusesWhenTheStockHasMovedSince(): void
    {
        $product = $this->lotTrackedProduct('VOID-MOVED');
        $lot = $this->lotWithStock($product, 5);

        $invoiceLine = $this->invoiceLine($product, '5.00', $lot->getId());
        $this->issueAndProcess($invoiceLine);

        $shipment = $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '5.00'));
        self::assertSame('5.0000', $this->soldUnits($product));

        // Simulate the sold stock moving again since (e.g. reconciled away by another process),
        // the same way the plan describes: "refusing if that stock has moved again since".
        $soldRow = $this->details->findExisting($product, $this->warehouse, null, $lot, null, InventoryDetail::STATUS_SOLD);
        self::assertNotNull($soldRow);
        self::assertTrue($this->details->decrement($soldRow, 3));
        $this->em->flush();

        $this->expectException(InsufficientStockException::class);
        $this->voids->void($shipment, 'Attempting to reverse stale stock', 'tester');
    }

    private function invoiceLine(ProductCore $product, string $quantity, ?int $lotId = null): InvoiceLine
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('VOID-ORD-' . uniqid())
            ->setDocumentDate('2026-09-15')
            ->setFulfillmentRegion($this->warehouse->getName());
        $this->em->persist($order);

        $orderLine = (new SalesOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setQuantity($quantity)
            ->setLocation($this->warehouse->getName())
            ->setLotId($lotId);
        $order->addLine($orderLine);
        $this->em->flush();

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->flush();

        $invoice = (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber('VOID-INV-' . uniqid())
            ->setDocumentDate('2026-09-15')
            ->setFulfillmentRegion($this->warehouse->getName());
        $order->addInvoice($invoice);

        $invoiceLine = (new InvoiceLine())
            ->setSalesOrderLine($orderLine)
            ->setProduct($product)
            ->setName($product->getName())
            ->setQuantity($quantity)
            ->setLotId($lotId);
        $invoice->addLine($invoiceLine);
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoiceLine;
    }

    private function issueAndProcess(InvoiceLine $invoiceLine): void
    {
        $invoice = $invoiceLine->getInvoice();
        $invoice->issue(DocumentActor::system());
        $this->em->flush();
        $invoice->startProcessing(DocumentActor::system());
        $this->em->flush();
    }

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

    /** A decimal string since the quantity columns started reading as decimals in PHP. */
    private function soldUnits(ProductCore $product): string
    {
        return QuantityScale::canonical((string) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
            [$product->getId(), InventoryDetail::STATUS_SOLD],
        ));
    }
}
