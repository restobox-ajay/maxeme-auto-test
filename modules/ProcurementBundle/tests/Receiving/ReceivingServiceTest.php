<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Receiving;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\ProductReceivingRule;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorAddress;
use ProcurementBundle\Enum\PurchaseOrderStatus;
use ProcurementBundle\Receiving\ReceivingException;
use ProcurementBundle\Receiving\ReceivingRequest;
use ProcurementBundle\Receiving\ReceivingService;

/**
 * Receiving reaches stock through #550's movement service and nothing else (#555).
 *
 * The load-bearing assertion in this file is
 * testAReceiptProducesExactlyWhatTheEquivalentManualAdjustmentWould: it books goods in through
 * receiving, books identical goods in through StockMovementService directly, and compares the
 * detail rows. If receiving ever grew its own path into `inventory_detail` that is the test that
 * would notice — which is the plan's own acceptance criterion, stated as "same service, provably".
 */
final class ReceivingServiceTest extends DoctrineIntegrationTestCase
{
    private ReceivingService $receiving;
    private StockMovementService $movements;
    private InventoryDetailRepository $details;
    private Warehouse $warehouse;
    private Vendor $vendor;
    private ProductCore $product;
    private WarehouseLocation $bin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receiving = self::getContainer()->get(ReceivingService::class);
        $this->movements = self::getContainer()->get(StockMovementService::class);
        $this->details = self::getContainer()->get(InventoryDetailRepository::class);

        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->vendor = (new Vendor())->setName('Acme Supply')->setCurrency('CAD');
        $this->em->persist($this->vendor);

        $this->product = (new ProductCore())
            ->setSku('BUY-1')
            ->setName('Buyable Thing')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);

        $this->bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('A-01')->setSortKey(10);
        $this->em->persist($this->bin);

        $this->em->flush();
    }

    /* ------------------------------------------------------------------------------------------
     * The hinge: receiving goes through #550 and produces what #550 would have produced anyway.
     * ---------------------------------------------------------------------------------------- */

    public function testAReceiptProducesExactlyWhatTheEquivalentManualAdjustmentWould(): void
    {
        // Two products, identical in every respect, so the two paths cannot interfere.
        $viaReceiving = $this->product;
        $viaAdjustment = (new ProductCore())
            ->setSku('BUY-2')
            ->setName('Buyable Thing')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($viaAdjustment);
        $this->em->flush();

        $order = $this->issuedOrder(['BUY-1' => '10.00']);

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)
                ->add($viaReceiving, '10.00', $order->getLines()->first(), 'LOT-A', new \DateTimeImmutable('2027-01-31'), null, $this->bin, '4.5000'),
        );

        // The same goods, booked in the way #550 says stock enters before receiving exists.
        $lot = (new InventoryLot())
            ->setProduct($viaAdjustment)
            ->setCode('LOT-A')
            ->setExpiry(new \DateTimeImmutable('2027-01-31'));
        $this->em->persist($lot);
        $this->em->flush();

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'manual-equivalent')
                ->receive($viaAdjustment, new DetailKey($this->warehouse, $this->bin, $lot), 10),
        );

        $received = $this->details->findExisting($viaReceiving, $this->warehouse, $this->bin, $receipt->getLines()->first()->getLot(), null, InventoryDetail::STATUS_AVAILABLE);
        $adjusted = $this->details->findExisting($viaAdjustment, $this->warehouse, $this->bin, $lot, null, InventoryDetail::STATUS_AVAILABLE);

        self::assertNotNull($received, 'receiving must have created an available detail row');
        self::assertNotNull($adjusted);
        self::assertSame($adjusted->getQuantity(), $received->getQuantity(), 'the two paths must put the same quantity in the same place');
        self::assertSame($adjusted->getStatus(), $received->getStatus());

        // And the core total each side maintains agrees, which is the invariant #550 exists to hold.
        self::assertSame('10.0000', $this->coreQuantity($viaReceiving));
        self::assertSame('10.0000', $this->coreQuantity($viaAdjustment));
        self::assertSame(
            $this->details->availableTotal($viaReceiving, $this->warehouse),
            $this->coreQuantity($viaReceiving),
            'product_inventory.quantity must equal SUM(available detail) after a receipt',
        );
    }

    public function testTheReceiptStoresTheMovementGroupItGotBackRatherThanWritingStockItself(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '4.00']);

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '4.00', $order->getLines()->first()),
        );

        $group = $receipt->getMovementGroup();
        self::assertInstanceOf(InventoryMovementGroup::class, $group);
        self::assertSame(InventoryMovementGroup::TYPE_RECEIPT, $group->getType());
        // The reference is the receipt number, so #550's movement history answers "where did these
        // units come from" without this bundle being installed to read it.
        self::assertSame($receipt->getReceiptNumber(), $group->getReference());
        self::assertCount(1, $group->getMovements());
    }

    /* ------------------------------------------------------------------------------------------
     * Partial receipts and the derived status
     * ---------------------------------------------------------------------------------------- */

    public function testAPartialReceiptLeavesTheOrderPartiallyReceivedWithTheRightRemainder(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '240.00']);
        $line = $order->getLines()->first();

        $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '210.00', $line),
        );

        self::assertSame('210.0000', $line->getQuantityReceived());
        self::assertSame('30.0000', $line->getQuantityOutstanding());
        self::assertSame(PurchaseOrderStatus::PartiallyReceived, $order->getStatusEnum());
        self::assertFalse($line->isComplete());
    }

    public function testTheRestArrivingCompletesTheOrder(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '240.00']);
        $line = $order->getLines()->first();

        $this->receiving->receive(ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '210.00', $line));
        $this->receiving->receive(ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '30.00', $line));

        self::assertSame('240.0000', $line->getQuantityReceived());
        self::assertSame('0.0000', $line->getQuantityOutstanding());
        self::assertSame(PurchaseOrderStatus::Received, $order->getStatusEnum());
        self::assertSame('240.0000', $this->coreQuantity($this->product));
    }

    /**
     * Goods arriving are goods no longer expected (#583).
     *
     * `product_inventory.incoming_quantity` is what the open orders still owe this warehouse, so a
     * receipt has to move it in step with the line it credits — and it is recomputed from the order
     * rather than decremented by what arrived, so the two can never disagree.
     */
    public function testAReceiptTakesWhatArrivedOutOfTheForecast(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '240.00']);
        $line = $order->getLines()->first();

        $this->receiving->receive(ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '210.00', $line));

        self::assertSame('30.0000', $this->incomingQuantity($this->product), 'still owed the shortfall');
        self::assertSame('210.0000', $this->coreQuantity($this->product), 'and what did arrive is on the shelf');

        $this->receiving->receive(ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '30.00', $line));

        self::assertSame('0.0000', $this->incomingQuantity($this->product), 'nothing outstanding, nothing expected');
        self::assertSame('240.0000', $this->coreQuantity($this->product));
    }

    /** A delivery nobody ordered has nothing to take out of the forecast — nobody said it was coming. */
    public function testAReceiptWithNoPurchaseOrderLeavesTheForecastAlone(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '240.00']);
        $this->receiving->receive(ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '40.00', $order->getLines()->first()));
        self::assertSame('200.0000', $this->incomingQuantity($this->product));

        $this->receiving->receive(ReceivingRequest::unordered($this->vendor, $this->warehouse)->add($this->product, '5.00'));

        self::assertSame('200.0000', $this->incomingQuantity($this->product), 'unchanged: the order still owes 200');
        self::assertSame('45.0000', $this->coreQuantity($this->product), '40 against the order plus 5 nobody ordered');
    }

    /** Over-receipt is recorded and flagged, never refused: the warehouse must be able to say what is on the dock. */
    public function testOverReceiptIsAllowedAndFlagged(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '240.00']);
        $line = $order->getLines()->first();

        $this->receiving->receive(ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '250.00', $line));

        self::assertSame('250.0000', $line->getQuantityReceived());
        self::assertTrue($line->isOverReceived());
        // Floored, not negative: "we still owe them ten" is the opposite of what happened.
        self::assertSame('0.0000', $line->getQuantityOutstanding());
        self::assertSame(PurchaseOrderStatus::Received, $order->getStatusEnum());

        // And the shelf, which every neighbouring case asserts and this one did not (#594). Every
        // assertion above is read off the PurchaseOrderLine the service just stamped, so a
        // ReceivingService that clamped the quantity it handed to the movement layer at the ORDERED
        // figure — 240 on the shelf, 250 on the paperwork, ten units billed and never received —
        // passed this test while breaking the one property the file exists to protect.
        self::assertSame('250.0000', $this->coreQuantity($this->product), 'what is on the dock is what reaches stock');
    }

    /**
     * Closing short is a decision, and the deriver must never undo it.
     *
     * A late delivery against a closed order is recorded without silently reopening it — which is
     * what applyDerivedStatus() refusing non-derivable states buys.
     */
    public function testClosingShortSurvivesALaterDelivery(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '240.00']);
        $line = $order->getLines()->first();

        $this->receiving->receive(ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '210.00', $line));
        $order->closeShort(DocumentActor::system(), 'Vendor discontinued the line.');
        $this->em->flush();

        self::assertSame(PurchaseOrderStatus::Closed, $order->getStatusEnum());

        $this->receiving->receive(ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '30.00', $line));

        self::assertSame(PurchaseOrderStatus::Closed, $order->getStatusEnum(), 'a closed order must not spring back to Received');
        self::assertSame('240.0000', $line->getQuantityReceived(), 'the goods are still recorded as having arrived');
    }

    /* ------------------------------------------------------------------------------------------
     * Lots
     * ---------------------------------------------------------------------------------------- */

    /**
     * The case InventoryLot was designed around, and the plan calls out by name: the same code with
     * a different expiry is a different batch, and collapsing them would lose the earlier date.
     */
    public function testTheSameLotCodeWithADifferentExpiryBecomesASecondLot(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '20.00']);
        $line = $order->getLines()->first();

        $first = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)
                ->add($this->product, '10.00', $line, 'RUN-7', new \DateTimeImmutable('2027-01-31')),
        );
        $second = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)
                ->add($this->product, '10.00', $line, 'RUN-7', new \DateTimeImmutable('2028-06-30')),
        );

        $lotA = $first->getLines()->first()->getLot();
        $lotB = $second->getLines()->first()->getLot();

        self::assertNotNull($lotA);
        self::assertNotNull($lotB);
        self::assertNotSame($lotA->getId(), $lotB->getId(), 'two production runs must not share one lot row');
        self::assertSame('RUN-7', $lotA->getCode());
        self::assertSame('RUN-7', $lotB->getCode());
        self::assertSame('2027-01-31', $lotA->getExpiry()?->format('Y-m-d'));
        self::assertSame('2028-06-30', $lotB->getExpiry()?->format('Y-m-d'));
    }

    public function testTheSameLotCodeWithTheSameExpiryReusesTheLot(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '20.00']);
        $line = $order->getLines()->first();

        $first = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '10.00', $line, 'RUN-8', new \DateTimeImmutable('2027-01-31')),
        );
        $second = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '10.00', $line, 'RUN-8', new \DateTimeImmutable('2027-01-31')),
        );

        self::assertSame(
            $first->getLines()->first()->getLot()?->getId(),
            $second->getLines()->first()->getLot()?->getId(),
        );
        self::assertSame('20.0000', $this->coreQuantity($this->product), 'both deliveries land on one detail row');
    }

    /**
     * One batch split across two bins on one delivery is still one batch.
     *
     * The case that needs a flush per new lot rather than one at the end: the lookup reads the
     * database, and a lookup cannot see an entity that is only persisted. Without it, the second
     * line builds a second lot row and the batch is split in two on the way in — which would then
     * make a recall miss half of it.
     */
    public function testOneNewBatchAcrossTwoLinesOfOneDeliveryStaysOneLot(): void
    {
        $binB = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('B-02')->setSortKey(20);
        $this->em->persist($binB);
        $this->em->flush();

        $order = $this->issuedOrder(['BUY-1' => '20.00']);
        $line = $order->getLines()->first();

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)
                ->add($this->product, '12.00', $line, 'RUN-SPLIT', new \DateTimeImmutable('2027-03-31'), null, $this->bin)
                ->add($this->product, '8.00', $line, 'RUN-SPLIT', new \DateTimeImmutable('2027-03-31'), null, $binB),
        );

        $lots = $receipt->getLines()->map(static fn ($l): ?int => $l->getLot()?->getId())->toArray();

        self::assertCount(2, $receipt->getLines());
        self::assertNotNull($lots[0]);
        self::assertSame($lots[0], $lots[1], 'one batch across two bins must be one lot row');
        self::assertSame(
            1,
            $this->em->getRepository(InventoryLot::class)->count(['product' => $this->product, 'code' => 'RUN-SPLIT']),
        );
        self::assertSame('20.0000', $this->coreQuantity($this->product));
    }

    /** The vendor's name at the moment the batch arrived — free text, so renaming the vendor cannot rewrite it. */
    public function testANewLotRecordsTheVendorItArrivedFrom(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '5.00']);

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '5.00', $order->getLines()->first(), 'RUN-9'),
        );

        self::assertSame('Acme Supply', $receipt->getLines()->first()->getLot()?->getSource());
    }

    /* ------------------------------------------------------------------------------------------
     * The capture rules
     * ---------------------------------------------------------------------------------------- */

    public function testALotTrackedProductWithoutALotIsRefused(): void
    {
        $this->requireOfProduct(lot: true);
        $order = $this->issuedOrder(['BUY-1' => '5.00']);

        try {
            $this->receiving->receive(
                ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '5.00', $order->getLines()->first()),
            );
            self::fail('this delivery should have been refused');
        } catch (ReceivingException $e) {
            self::assertMatchesRegularExpression('/batch code/', $e->getMessage());
        }

        // A refusal that has already written rows is a half-booked delivery, which the comment on
        // testARefusedLineTakesTheWholeDeliveryWithIt calls worse than a refusal (#594).
        // expectException on its own proves only that something threw; it says nothing about what
        // was left behind, and six of the seven refusal reasons in this file asserted nothing at
        // all after the throw.
        self::assertSame('0.0000', $this->coreQuantity($this->product), 'a refused delivery books nothing in');
        self::assertSame(0, $this->em->getRepository(GoodsReceipt::class)->count([]), 'and writes no receipt row');
    }

    public function testALotTrackedProductWithALotIsAccepted(): void
    {
        $this->requireOfProduct(lot: true);
        $order = $this->issuedOrder(['BUY-1' => '5.00']);

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '5.00', $order->getLines()->first(), 'RUN-10'),
        );

        self::assertSame('RUN-10', $receipt->getLines()->first()->getLot()?->getCode());
        self::assertSame('5.0000', $this->coreQuantity($this->product));
    }

    public function testAnExpiryTrackedProductWithoutAnExpiryIsRefused(): void
    {
        $this->requireOfProduct(lot: true, expiry: true);
        $order = $this->issuedOrder(['BUY-1' => '5.00']);

        try {
            $this->receiving->receive(
                ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '5.00', $order->getLines()->first(), 'RUN-11'),
            );
            self::fail('this delivery should have been refused');
        } catch (ReceivingException $e) {
            self::assertMatchesRegularExpression('/expiry/', $e->getMessage());
        }

        // A refusal that has already written rows is a half-booked delivery, which the comment on
        // testARefusedLineTakesTheWholeDeliveryWithIt calls worse than a refusal (#594).
        // expectException on its own proves only that something threw; it says nothing about what
        // was left behind, and six of the seven refusal reasons in this file asserted nothing at
        // all after the throw.
        self::assertSame('0.0000', $this->coreQuantity($this->product), 'a refused delivery books nothing in');
        self::assertSame(0, $this->em->getRepository(GoodsReceipt::class)->count([]), 'and writes no receipt row');
    }

    /**
     * Expiry is its own Yes/No, independent of lot, serial and direction (#795): a `none`-mode
     * policy can require a date with no batch to carry it, and the date lands on the detail row
     * itself rather than being silently dropped the way `resolveLot()` used to drop it for any line
     * naming no lot code.
     */
    public function testAnUntrackedProductRequiringExpiryStoresItOnTheDetailRowWithNoLot(): void
    {
        $policy = (new TrackingPolicy())
            ->setName('None + expiry ' . uniqid())
            ->setMode(TrackingPolicy::MODE_NONE)
            ->setRequiresExpiry(true);
        $this->em->persist($policy);
        $this->product->setTrackingPolicy($policy);
        $this->em->flush();

        $order = $this->issuedOrder(['BUY-1' => '6.00']);
        $expiry = new \DateTimeImmutable('2027-03-01');

        $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '6.00', $order->getLines()->first(), null, $expiry),
        );

        $row = $this->em->getRepository(InventoryDetail::class)->findOneBy(['product' => $this->product]);
        self::assertInstanceOf(InventoryDetail::class, $row);
        self::assertNull($row->getLot(), 'no batch to carry it — none was captured');
        self::assertSame('2027-03-01', $row->getExpiry()?->format('Y-m-d'));
        self::assertSame('6.0000', $this->coreQuantity($this->product));
    }

    /** The mode-independent half of #795's refusal: `none`-mode blocks exactly as `lot`-mode always did. */
    public function testAnUntrackedProductRequiringExpiryIsRefusedWithoutOne(): void
    {
        $policy = (new TrackingPolicy())
            ->setName('None + expiry ' . uniqid())
            ->setMode(TrackingPolicy::MODE_NONE)
            ->setRequiresExpiry(true);
        $this->em->persist($policy);
        $this->product->setTrackingPolicy($policy);
        $this->em->flush();

        $order = $this->issuedOrder(['BUY-1' => '6.00']);

        try {
            $this->receiving->receive(
                ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '6.00', $order->getLines()->first()),
            );
            self::fail('this delivery should have been refused');
        } catch (ReceivingException $e) {
            self::assertMatchesRegularExpression('/needs an expiry date/', $e->getMessage());
        }

        self::assertSame('0.0000', $this->coreQuantity($this->product), 'a refused delivery books nothing in');
        self::assertSame(0, $this->em->getRepository(GoodsReceipt::class)->count([]), 'and writes no receipt row');
    }

    /**
     * #550 allows a serial exactly one row holding stock, and #573 caps the quantity ON that row at
     * 1, so two units under one serial is a contradiction rather than a quantity.
     */
    public function testASerialisedProductMustBeReceivedOneUnitPerLine(): void
    {
        $this->requireOfProduct(serial: true);
        $order = $this->issuedOrder(['BUY-1' => '2.00']);

        try {
            $this->receiving->receive(
                ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '2.00', $order->getLines()->first(), null, null, 'SN-1'),
            );
            self::fail('this delivery should have been refused');
        } catch (ReceivingException $e) {
            self::assertMatchesRegularExpression('/separate lines of 1/', $e->getMessage());
        }

        // A refusal that has already written rows is a half-booked delivery, which the comment on
        // testARefusedLineTakesTheWholeDeliveryWithIt calls worse than a refusal (#594).
        // expectException on its own proves only that something threw; it says nothing about what
        // was left behind, and six of the seven refusal reasons in this file asserted nothing at
        // all after the throw.
        self::assertSame('0.0000', $this->coreQuantity($this->product), 'a refused delivery books nothing in');
        self::assertSame(0, $this->em->getRepository(GoodsReceipt::class)->count([]), 'and writes no receipt row');
    }

    /** No rule row means nothing is required — exactly how receiving behaved before this table existed. */
    public function testAProductWithNoRuleNeedsNothing(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '3.00']);

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '3.00', $order->getLines()->first()),
        );

        self::assertNull($receipt->getLines()->first()->getLot());
        self::assertSame('3.0000', $this->coreQuantity($this->product));
    }

    /* ------------------------------------------------------------------------------------------
     * Refusals
     * ---------------------------------------------------------------------------------------- */

    /**
     * A fractional receipt is booked in full, and this test used to assert that it was refused.
     *
     * The refusal read "#550's ledger counts whole units", which was true of the PHP layer and never
     * of the columns: `inventory_detail.quantity` and every bucket on `product_inventory` have been
     * `NUMERIC(14, 4)` since #645, and what could not hold a fraction was
     * `App\Doctrine\Type\QuantityType` extending `IntegerType`. That is fixed, so 2.50 arriving is
     * 2.50 on the shelf — and inbound stops being the one direction in which a fraction cannot be
     * expressed at all.
     *
     * The whole-unit rule that survives is the SERIAL one, which has its own test in this file.
     */
    public function testAFractionalQuantityIntoStockIsBookedInFull(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '5.00']);

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '2.50', $order->getLines()->first()),
        );

        self::assertSame('2.5000', $receipt->getLines()->first()->getQuantity(), 'the receipt records the half');
        self::assertSame('2.5000', $this->coreQuantity($this->product), 'and the half is on the shelf');
        self::assertSame(
            '2.5000',
            $order->getLines()->first()->getQuantityReceived(),
            'and the purchase order line is credited the same figure — the two cannot disagree any more',
        );
    }

    /* ------------------------------------------------------------------------------------------
     * Where the goods came from (#606)
     * ---------------------------------------------------------------------------------------- */

    /**
     * A receipt freezes the vendor's SHIP-FROM address, which is the fact nobody could record before
     * — a supplier shipping from a depot that is not their head office produced a receipt that could
     * not say which depot, exactly when a delivery is late or short and somebody needs to know.
     */
    public function testAReceiptFreezesTheShipFromAddress(): void
    {
        // The default is the head office and the depot is the ship-from, deliberately: before
        // purposes existed there would have been nothing but the default to record.
        $this->vendor->addAddress(
            (new VendorAddress())->setLabel('Head office')->setAddressLine1('1 Main St')->setCity('Vancouver')->setCountry('CA')->setIsDefault(true)
        );
        $this->vendor->addAddress(
            (new VendorAddress())->setLabel('Depot')->setAddressLine1('900 Dock Rd')->setCity('Delta')->setCountry('CA')->setIsShipFrom(true)
        );
        $this->em->flush();

        $order = $this->issuedOrder(['BUY-1' => '5.00']);

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '5.00', $order->getLines()->first(), null, null, null, $this->bin),
        );

        self::assertStringContainsString('900 Dock Rd', (string) $receipt->getShipFromAddress());
        self::assertStringNotContainsString('1 Main St', (string) $receipt->getShipFromAddress());
    }

    /**
     * The fallback, which is what every existing database does: no purpose assigned, so the receipt
     * records the default address rather than nothing.
     */
    public function testAReceiptFallsBackToTheDefaultAddressWhenNoPurposeIsAssigned(): void
    {
        $this->vendor->addAddress(
            (new VendorAddress())->setLabel('Head office')->setAddressLine1('1 Main St')->setCity('Vancouver')->setCountry('CA')->setIsDefault(true)
        );
        $this->em->flush();

        $order = $this->issuedOrder(['BUY-1' => '5.00']);

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '5.00', $order->getLines()->first(), null, null, null, $this->bin),
        );

        self::assertStringContainsString('1 Main St', (string) $receipt->getShipFromAddress());
    }

    /** A vendor with no address at all records NULL — "not recorded" stays distinct from a blank. */
    public function testAReceiptFromAVendorWithNoAddressRecordsNothing(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '5.00']);

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '5.00', $order->getLines()->first(), null, null, null, $this->bin),
        );

        self::assertNull($receipt->getShipFromAddress());
    }

    public function testNothingCanBeBookedInAgainstADraftOrder(): void
    {
        $order = $this->draftOrder(['BUY-1' => '5.00']);

        try {
            $this->receiving->receive(
                ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '5.00', $order->getLines()->first()),
            );
            self::fail('this delivery should have been refused');
        } catch (ReceivingException $e) {
            self::assertMatchesRegularExpression('/nothing for these goods to have arrived against/', $e->getMessage());
        }

        // A refusal that has already written rows is a half-booked delivery, which the comment on
        // testARefusedLineTakesTheWholeDeliveryWithIt calls worse than a refusal (#594).
        // expectException on its own proves only that something threw; it says nothing about what
        // was left behind, and six of the seven refusal reasons in this file asserted nothing at
        // all after the throw.
        self::assertSame('0.0000', $this->coreQuantity($this->product), 'a refused delivery books nothing in');
        self::assertSame(0, $this->em->getRepository(GoodsReceipt::class)->count([]), 'and writes no receipt row');
    }

    public function testABinAtAnotherSiteIsRefused(): void
    {
        $other = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->persistedRegion('East'), 'BC', 'CA');
        $elsewhere = (new WarehouseLocation())->setWarehouse($other)->setCode('Z-99');
        $this->em->persist($elsewhere);
        $this->em->flush();

        $order = $this->issuedOrder(['BUY-1' => '5.00']);

        try {
            $this->receiving->receive(
                ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '5.00', $order->getLines()->first(), null, null, null, $elsewhere),
            );
            self::fail('this delivery should have been refused');
        } catch (ReceivingException $e) {
            self::assertMatchesRegularExpression('/another site/', $e->getMessage());
        }

        // A refusal that has already written rows is a half-booked delivery, which the comment on
        // testARefusedLineTakesTheWholeDeliveryWithIt calls worse than a refusal (#594).
        // expectException on its own proves only that something threw; it says nothing about what
        // was left behind, and six of the seven refusal reasons in this file asserted nothing at
        // all after the throw.
        self::assertSame('0.0000', $this->coreQuantity($this->product), 'a refused delivery books nothing in');
        self::assertSame(0, $this->em->getRepository(GoodsReceipt::class)->count([]), 'and writes no receipt row');
    }

    /**
     * Either the whole truck goes in or none of it does — a half-booked delivery is worse than a
     * refusal.
     *
     * The refused line was a FRACTIONAL one until the quantity type widened, and a fraction is
     * ordinary now, so the refusal has to come from a reason that still exists. A serial line for
     * more than one unit is such a reason and is the only whole-number rule left: a serial
     * identifies one physical unit. What this test is actually about is unchanged — that the good
     * line beside it is not booked in on its own.
     */
    public function testARefusedLineTakesTheWholeDeliveryWithIt(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '10.00']);
        $line = $order->getLines()->first();

        try {
            $this->receiving->receive(
                ReceivingRequest::againstPurchaseOrder($order)
                    ->add($this->product, '4.00', $line)
                    ->add($this->product, '2.00', $line, serial: 'SN-TWO-UNITS'),
            );
            self::fail('a serial line for two units should have been refused');
        } catch (ReceivingException) {
            // expected
        }

        self::assertSame('0.0000', $line->getQuantityReceived(), 'the good line must not have been booked in on its own');
        self::assertSame('0.0000', $this->coreQuantity($this->product));
    }

    /* ------------------------------------------------------------------------------------------
     * Simple inventory, unordered goods, idempotency
     * ---------------------------------------------------------------------------------------- */

    /**
     * A `simple` product's quantity is a number an admin types and #550 refuses to touch it. The
     * paperwork is still a fact, so the line is recorded and flagged rather than refused.
     */
    /**
     * Revised for the simple-inventory bucket parity plan (2026-09-15): a simple product still gets
     * no `InventoryDetail` row, ever — but it is no longer invisible to `product_inventory` beyond
     * its own paperwork. `received` is credited for it exactly the way it is for a dimensional line,
     * through the same `StockMovementService::apply()` call, so a movement group now always exists.
     */
    public function testASimpleProductIsRecordedAndCreditsReceivedIntoTheUnspecifiedRow(): void
    {
        $simple = (new ProductCore())
            ->setSku('SIMPLE-1')
            ->setName('Typed Quantity Thing')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($simple);
        $this->em->flush();

        $order = $this->issuedOrder(['BUY-1' => '5.00']);

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($simple, '5.00'),
        );

        self::assertCount(1, $receipt->getLines());
        self::assertFalse($receipt->getLines()->first()->isMovementApplied(), 'a simple product names no bin/lot/serial');
        self::assertCount(1, $receipt->getLinesNotStocked());
        self::assertNotNull(
            $this->details->findExisting($simple, $this->warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE),
            'a simple product still gets a real InventoryDetail row — the warehouse\'s unspecified one',
        );
        self::assertInstanceOf(
            InventoryMovementGroup::class,
            $receipt->getMovementGroup(),
            'apply() still runs so received gets credited',
        );
        self::assertSame('5.0000', $this->coreQuantity($simple), 'received must be credited for a simple product too');
    }

    /** Goods arrive that nobody raised a PO for. Recorded, flagged, never refused. */
    public function testGoodsWithNoPurchaseOrderAreRecorded(): void
    {
        $receipt = $this->receiving->receive(
            ReceivingRequest::unordered($this->vendor, $this->warehouse, 'PS-4471')
                ->add($this->product, '7.00'),
        );

        self::assertTrue($receipt->isUnordered());
        self::assertSame('PS-4471', $receipt->getPackingSlip());
        self::assertSame('7.0000', $this->coreQuantity($this->product));
    }

    /** A resubmitted form books the goods in once, and writes one receipt rather than two. */
    public function testResubmittingTheSameOperationBooksTheGoodsInOnce(): void
    {
        $order = $this->issuedOrder(['BUY-1' => '10.00']);
        $line = $order->getLines()->first();

        $first = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order, null, null, null, null, 'form-token-abc')->add($this->product, '10.00', $line),
        );
        $second = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order, null, null, null, null, 'form-token-abc')->add($this->product, '10.00', $line),
        );

        self::assertSame($first->getId(), $second->getId(), 'the second submission must find the first receipt, not write another');
        self::assertSame('10.0000', $line->getQuantityReceived());
        self::assertSame('10.0000', $this->coreQuantity($this->product));
    }

    /**
     * Idempotency for a delivery of nothing but simple-inventory products is keyed off the receipt's
     * own `clientOperationId` (`existingReceiptFor()`), not off the movement group's — which matters
     * because before the simple-inventory bucket parity plan (2026-09-15), such a delivery wrote no
     * group at all. It now does (so `received` gets credited), but the outer, receipt-level
     * idempotency check this test protects was never the one relying on the group existing.
     */
    public function testResubmittingADeliveryThatReachedNoStockAlsoWritesOneReceipt(): void
    {
        $simple = (new ProductCore())
            ->setSku('SIMPLE-2')
            ->setName('Typed Quantity Thing')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($simple);
        $this->em->flush();

        $first = $this->receiving->receive(
            ReceivingRequest::unordered($this->vendor, $this->warehouse, null, null, null, null, 'form-token-simple')->add($simple, '5.00'),
        );
        $second = $this->receiving->receive(
            ReceivingRequest::unordered($this->vendor, $this->warehouse, null, null, null, null, 'form-token-simple')->add($simple, '5.00'),
        );

        self::assertInstanceOf(
            InventoryMovementGroup::class,
            $first->getMovementGroup(),
            'apply() still runs so received gets credited, even though nothing reached stock',
        );
        self::assertSame($first->getId(), $second->getId());
        self::assertSame(1, $this->em->getRepository(GoodsReceipt::class)->count([]));
        self::assertSame('5.0000', $this->coreQuantity($simple), 'the resubmission must not double-credit received');
    }

    public function testAReceiptWithNoLinesIsRefused(): void
    {


        try {
            $this->receiving->receive(ReceivingRequest::unordered($this->vendor, $this->warehouse));
            self::fail('this delivery should have been refused');
        } catch (ReceivingException $e) {
            self::assertNotSame('', $e->getMessage(), 'a refusal has to say why');
        }

        // A refusal that has already written rows is a half-booked delivery, which the comment on
        // testARefusedLineTakesTheWholeDeliveryWithIt calls worse than a refusal (#594).
        // expectException on its own proves only that something threw; it says nothing about what
        // was left behind, and six of the seven refusal reasons in this file asserted nothing at
        // all after the throw.
        self::assertSame('0.0000', $this->coreQuantity($this->product), 'a refused delivery books nothing in');
        self::assertSame(0, $this->em->getRepository(GoodsReceipt::class)->count([]), 'and writes no receipt row');
    }

    /* ------------------------------------------------------------------------------------------
     * Fixtures
     * ---------------------------------------------------------------------------------------- */

    private function persistedRegion(string $name): FulfillmentRegion
    {
        $region = (new FulfillmentRegion())->setName($name);
        $this->em->persist($region);
        $this->em->flush();

        return $region;
    }

    /**
     * Makes the product need a batch, an expiry and/or a serial at receiving.
     *
     * Through the TRACKING POLICY, because that is where those three facts live since item 67.
     * `ProductReceivingRule` used to hold its own copies of them and no longer does — which is the
     * whole fix: receiving asked this bundle's table, nothing in the application ever wrote a row
     * to it, so all four guards passed for every product in every install.
     *
     * A `procurement_product_rule` row is still what says a BIN is required; `requireBinOfProduct()`
     * below does that, and it is deliberately the only setter left on the entity.
     */
    private function requireOfProduct(bool $lot = false, bool $expiry = false, bool $serial = false): void
    {
        $policy = (new TrackingPolicy())
            ->setName('Rule policy ' . uniqid())
            ->setMode($serial ? TrackingPolicy::MODE_SERIAL : ($lot || $expiry ? TrackingPolicy::MODE_LOT : TrackingPolicy::MODE_NONE))
            ->setRequiresExpiry($expiry)
            ->setTrackIn($lot || $serial)
            ->setSentinelIn(TrackingPolicy::DEFAULT_SENTINEL);
        $this->em->persist($policy);
        $this->product->setTrackingPolicy($policy);
        $this->em->flush();
    }

    private function requireBinOfProduct(): void
    {
        $rule = (new ProductReceivingRule())->setProduct($this->product)->setLocationRequired(true);
        $this->em->persist($rule);
        $this->em->flush();
    }

    /** @param array<string, string> $lines sku => quantity ordered */
    private function draftOrder(array $lines): PurchaseOrder
    {
        $order = (new PurchaseOrder())
            ->setPoNumber('PO-' . random_int(100000, 999999))
            ->setVendor($this->vendor)
            ->deriveTaxProvinceFrom($this->warehouse)
            ->setCurrency('CAD');
        $this->em->persist($order);

        $sortOrder = 0;
        foreach ($lines as $sku => $quantity) {
            $line = (new PurchaseOrderLine())
                ->setProduct($this->product)
                ->setName('Buyable Thing')
                ->setSku($sku)
                ->setQuantityOrdered($quantity)
                ->setUnitCost('4.5000')
                ->setSubtotal('0.00')
                ->setSortOrder($sortOrder++);
            $order->addLine($line);
            $this->em->persist($line);
        }

        $this->em->flush();

        return $order;
    }

    /** @param array<string, string> $lines sku => quantity ordered */
    private function issuedOrder(array $lines): PurchaseOrder
    {
        $order = $this->draftOrder($lines);
        $order->setStatus('Issued', DocumentActor::system());
        $this->em->flush();

        return $order;
    }

    /** A decimal string since the quantity columns started reading as decimals in PHP. */
    private function incomingQuantity(ProductCore $product): string
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $this->warehouse,
        ]);

        return $row instanceof ProductInventory ? $row->getIncomingQuantity() : '0.0000';
    }

    private function coreQuantity(ProductCore $product): string
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $this->warehouse,
        ]);

        // quantity + received (#564): the movement layer no longer writes `quantity`, which is the
        // client's imported figure. What it maintains is the gap between that snapshot and the
        // shelf, so the core total a movement affects is the pair.
        return $row instanceof ProductInventory
            ? QuantityScale::add($row->getQuantity(), $row->getReceivedQuantity())
            : '0.0000';
    }
}
