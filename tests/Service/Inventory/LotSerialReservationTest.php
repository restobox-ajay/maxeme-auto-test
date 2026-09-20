<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceInventoryReservation;
use App\Entity\InvoiceLine;
use App\Entity\OrderInventoryReservation;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\Inventory\BackorderSplitResolver;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * Lot/serial as a reservation-scoped dimension, not a new mechanism (2026-09-14 lot/serial/expiry
 * plan). Exercises InventoryReservationReconciler's widened diff key through the real path —
 * persisting/flushing a SalesOrder or Invoice — exactly as InventoryReservationReconcilerTest
 * already does for the product+warehouse-only case, one dimension narrower.
 */
final class LotSerialReservationTest extends DoctrineIntegrationTestCase
{
    private FulfillmentRegion $region;
    private Warehouse $warehouse;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($this->region);

        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);

        $this->em->flush();
    }

    /** A product opted fully into lot tracking: TrackingPolicy(mode=lot, trackOut) AND dimensional. */
    private function lotTrackedProduct(string $sku = 'LOT-1'): ProductCore
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

        $this->em->persist((new ProductInventory())->setProduct($product)->setWarehouse($this->warehouse)->setQuantity(100));
        $this->em->flush();

        return $product;
    }

    private function lotWithStock(ProductCore $product, int $quantity, string $code = 'BATCH-A'): InventoryLot
    {
        $lot = (new InventoryLot())->setProduct($product)->setCode($code)->setExpiry(new \DateTimeImmutable('2027-01-31'));
        $this->em->persist($lot);
        $this->em->flush();

        /** @var InventoryDetailRepository $details */
        $details = self::getContainer()->get(InventoryDetailRepository::class);
        $detail = $details->findOrCreate($product, $this->warehouse, null, $lot, null, InventoryDetail::STATUS_AVAILABLE);
        $detail->setQuantity($quantity);
        $this->em->flush();

        return $lot;
    }

    private function newOrder(): SalesOrder
    {
        return (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('ORD-' . uniqid())
            ->setFulfillmentRegion($this->region->getName());
    }

    private function addLine(SalesOrder $order, ProductCore $product, string $quantity, ?InventoryLot $lot = null): SalesOrderLine
    {
        $line = (new SalesOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setQuantity($quantity)
            ->setLotId($lot?->getId());
        $order->addLine($line);

        return $line;
    }

    private function approvedOrder(ProductCore $product, string $quantity, ?InventoryLot $lot = null): SalesOrder
    {
        $order = $this->newOrder();
        $this->addLine($order, $product, $quantity, $lot);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);

        return $order;
    }

    public function testLotTrackedLineCreatesALotScopedOrderReservation(): void
    {
        $product = $this->lotTrackedProduct();
        $lot = $this->lotWithStock($product, 10);

        $order = $this->approvedOrder($product, '5', $lot);
        $this->em->flush();

        $reservations = $this->em->getRepository(OrderInventoryReservation::class)->findBy(['order' => $order]);
        self::assertCount(1, $reservations);
        self::assertSame($lot->getId(), $reservations[0]->getLotId());
        self::assertSame('5.0000', $reservations[0]->getQuantity());
        self::assertSame(OrderInventoryReservation::BUCKET_SALES_HOLD, $reservations[0]->getBucket());

        // The aggregate bucket is untouched by the lot dimension — the one rule this whole plan
        // answers to: lot/serial is a view onto the same subtraction, not a second one.
        $this->em->refresh($this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $this->warehouse]));
        $inventory = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $this->warehouse]);
        self::assertSame('5.0000', $inventory->getSalesHoldQuantity());
        self::assertSame('95.0000', $inventory->getAvailableQuantity());
    }

    /**
     * The composite gate (section 2): a lot on a line is only honoured when the product BOTH has a
     * TrackingPolicy tracking lots outbound AND is actually dimensional. A product failing either
     * half of that must reconcile exactly as it did before this widening — the stray lot id is
     * blanked back to "no identity", not written onto the ledger.
     */
    public function testALotIsIgnoredWhenTheProductIsNotDimensional(): void
    {
        $policy = (new TrackingPolicy())->setName('Lot but simple')->setMode(TrackingPolicy::MODE_LOT)->setTrackOut(true);
        $this->em->persist($policy);

        // Deliberately NOT dimensional — the default INVENTORY_MODE_SIMPLE.
        $product = (new ProductCore())->setSku('LOT-SIMPLE')->setName('Simple Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)->setTrackingPolicy($policy);
        $this->em->persist($product);
        $this->em->persist((new ProductInventory())->setProduct($product)->setWarehouse($this->warehouse)->setQuantity(100));
        $this->em->flush();

        $order = $this->newOrder();
        // A stray lot id — nothing this product's ledger has any business honouring.
        $this->addLine($order, $product, '5', null)->setLotId(999999);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        $reservations = $this->em->getRepository(OrderInventoryReservation::class)->findBy(['order' => $order]);
        self::assertCount(1, $reservations);
        self::assertNull($reservations[0]->getLotId(), 'a non-dimensional product must not carry a lot onto its reservation');
    }

    public function testALotIsIgnoredWhenTheProductHasNoTrackingPolicyForIt(): void
    {
        // Dimensional, but the default 'None' TrackingPolicy — tracksLotsOutbound() is false.
        $product = (new ProductCore())->setSku('LOT-NOPOLICY')->setName('Untracked Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($product);
        $this->em->persist((new ProductInventory())->setProduct($product)->setWarehouse($this->warehouse)->setQuantity(100));
        $this->em->flush();

        $order = $this->newOrder();
        $this->addLine($order, $product, '5', null)->setLotId(999999);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        $reservations = $this->em->getRepository(OrderInventoryReservation::class)->findBy(['order' => $order]);
        self::assertCount(1, $reservations);
        self::assertNull($reservations[0]->getLotId());
    }

    /**
     * A formerly-backordered line that later has a lot picked on it (at invoice time, same as any
     * other line) reconciles correctly with no special-casing anywhere in the path — section 4/5's
     * central claim, proven at the reconciler.
     */
    public function testFormerlyBackorderedLineLaterGivenALotReconcilesWithNoSpecialCasing(): void
    {
        $policy = (new TrackingPolicy())->setName('Lot backorder')->setMode(TrackingPolicy::MODE_LOT)->setTrackOut(true);
        $this->em->persist($policy);

        $product = (new ProductCore())
            ->setSku('LOT-BACKORDER')
            ->setName('Lot Tracked Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
            ->setTrackingPolicy($policy);
        $this->em->persist($product);
        $this->em->flush();

        // No stock at all yet, and backorder allowed without limit: every unit of this order
        // backorders rather than being refused (refusal is AdminOrderStockValidator's question, not
        // the reconciler's — see BackorderSplitResolver).
        $this->em->persist((new ProductInventory())->setProduct($product)->setWarehouse($this->warehouse)->setQuantity(0)->setAllowBackorder(true));
        $this->em->flush();

        $order = $this->newOrder();
        $line = $this->addLine($order, $product, '5', null);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        self::getContainer()->get(BackorderSplitResolver::class)->applyToOrderLines($order, $order->getStatus(), $this->em);
        $this->em->flush();

        self::assertSame('5.0000', $line->getBackorderedUnits());
        $backordered = $this->em->getRepository(OrderInventoryReservation::class)->findOneBy([
            'order' => $order,
            'bucket' => OrderInventoryReservation::BUCKET_BACKORDERED,
        ]);
        self::assertNotNull($backordered);
        self::assertNull($backordered->getLotId(), 'nothing to pick a lot from while there is no stock behind the line');

        // Stock arrives and BackorderReleaseService's own bucket flip happens (shrinking the line's
        // backordered quantity is the whole of what that service does — see its docblock).
        $lot = $this->lotWithStock($product, 10);
        $line->setBackorderedQuantity('0.00');
        // The human picks the lot the same way they would on any other line, at invoice time or on
        // an order edit — nothing here is a special path for a formerly-backordered line.
        $line->setLotId($lot->getId());
        $this->em->flush();

        self::assertSame('0.0000', $line->getBackorderedUnits());

        $salesHold = $this->em->getRepository(OrderInventoryReservation::class)->findOneBy([
            'order' => $order,
            'bucket' => OrderInventoryReservation::BUCKET_SALES_HOLD,
        ]);
        self::assertNotNull($salesHold);
        self::assertSame($lot->getId(), $salesHold->getLotId());
        self::assertSame('5.0000', $salesHold->getQuantity());

        self::assertNull(
            $this->em->getRepository(OrderInventoryReservation::class)->findOneBy(['order' => $order, 'bucket' => OrderInventoryReservation::BUCKET_BACKORDERED]),
            'the backordered row is gone, not left behind at zero',
        );
    }

    /**
     * The one-bucket-at-a-time discipline, for lot/serial exactly as it already holds for the
     * aggregate bucket (InventoryReservationReconcilerTest::testIssuingAnInvoiceMovesTheHoldFrom
     * SalesHoldToPending): issuing an invoice on the same lot RELEASES the order's lot-scoped hold
     * the moment the invoice's own lot-scoped hold is created, so the two never both subtract
     * against the same units.
     */
    public function testIssuingAnInvoiceOnTheSameLotMovesTheHoldNotAddsToIt(): void
    {
        $product = $this->lotTrackedProduct();
        $lot = $this->lotWithStock($product, 10);

        $order = $this->approvedOrder($product, '5', $lot);
        $this->em->flush();

        $orderLine = $order->getLines()->first();

        $invoice = new Invoice();
        $order->addInvoice($invoice);
        $invoice->setCompany($this->company)->setDocumentNumber('INV-' . uniqid())
            ->setFulfillmentRegion($order->getFulfillmentRegion())->setTotal('100.00');
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($orderLine)
                ->setProduct($product)
                ->setName($orderLine->getName())
                ->setQuantity($orderLine->getQuantity())
                ->setLotId($lot->getId()),
        );
        $this->em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        self::assertCount(
            0,
            $this->em->getRepository(OrderInventoryReservation::class)->findBy(['order' => $order]),
            'the order\'s lot-scoped hold is released, not left standing beside the invoice\'s',
        );

        $invoiceReservations = $this->em->getRepository(InvoiceInventoryReservation::class)->findBy(['invoice' => $invoice]);
        self::assertCount(1, $invoiceReservations);
        self::assertSame($lot->getId(), $invoiceReservations[0]->getLotId());
        self::assertSame('5.0000', $invoiceReservations[0]->getQuantity());

        $inventory = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $this->warehouse]);
        $this->em->refresh($inventory);
        self::assertSame('95.0000', $inventory->getAvailableQuantity(), 'the same five units, held by a different document — never both at once');
    }
}
