<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Service\QuantityScale;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InventoryBucketChangeLog;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\Warehouse;
use App\Enum\InvoiceIssueIntent;
use App\Service\DocumentActor;
use App\Service\OrderInvoicingService;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Inventory\IncomingStockReconciler;
use ProcurementBundle\Receiving\ReceivingRequest;
use ProcurementBundle\Receiving\ReceivingService;

/**
 * The whole document chain in one run: a purchase order, a receipt, an estimate, a sales order, and
 * an invoice all the way to Completed — checking, at every step that is actually supposed to change
 * something, both the bucket's live value AND that `inventory_bucket_change_log` recorded it
 * faithfully. And, just as deliberately, checking the one moment this session found that changes
 * NOTHING: pricing an estimate, since `EstimateLine` carries no product and so can never touch
 * inventory.
 *
 * Completing the invoice USED TO be a second such moment — `approved` held the sale permanently and
 * nothing distinguished Processing from Completed at the bucket level. As of
 * `docs/plans/2026-09-15-shipment-approved-to-shipped-bucket.md`, Completed transfers the hold to
 * its own `shipped` bucket instead, so this test now asserts that transfer rather than its absence.
 */
final class PurchaseToInvoiceCompletedBucketLifecycleTest extends DoctrineIntegrationTestCase
{
    private Warehouse $warehouse;
    private Company $customer;
    private Vendor $vendor;
    private ProductCore $product;
    private WarehouseLocation $bin;
    private ReceivingService $receiving;
    private IncomingStockReconciler $incoming;
    private OrderInvoicingService $invoicing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receiving = self::getContainer()->get(ReceivingService::class);
        $this->incoming = self::getContainer()->get(IncomingStockReconciler::class);
        $this->invoicing = self::getContainer()->get(OrderInvoicingService::class);

        $region = (new FulfillmentRegion())->setName('Lifecycle Region');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->customer = (new Company())->setName('Lifecycle Customer')->setCode('LIFE');
        $this->em->persist($this->customer);

        $this->vendor = (new Vendor())->setName('Lifecycle Vendor')->setCurrency('CAD');
        $this->em->persist($this->vendor);

        $this->product = (new ProductCore())
            ->setSku('LIFECYCLE-1')
            ->setName('Lifecycle Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);

        $this->bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('L-01')->setSortKey(10);
        $this->em->persist($this->bin);

        $this->em->flush();
    }

    public function testEveryStepOfTheChainMovesTheBucketItShouldAndLogsIt(): void
    {
        // ------------------------------------------------------------------------------------
        // 1. Purchase order issued: `incoming` rises.
        // ------------------------------------------------------------------------------------
        $order = (new PurchaseOrder())
            ->setPoNumber('PO-LIFE-1')
            ->setVendor($this->vendor)
            ->deriveTaxProvinceFrom($this->warehouse)
            ->setCurrency('CAD');
        $this->em->persist($order);

        $poLine = (new PurchaseOrderLine())
            ->setProduct($this->product)
            ->setName('Lifecycle Widget')
            ->setSku('LIFECYCLE-1')
            ->setQuantityOrdered('20.00')
            ->setUnitCost('3.0000')
            ->setSubtotal('0.00')
            ->setSortOrder(0);
        $order->addLine($poLine);
        $this->em->persist($poLine);
        $this->em->flush();

        $order->setStatus('Issued', DocumentActor::system());
        $this->em->flush();
        $this->incoming->reconcileForOrder($order);
        $this->em->flush();

        self::assertSame('20.0000', $this->inventory()->getIncomingQuantity());
        $this->assertLogged('incoming', 0, 20);

        // ------------------------------------------------------------------------------------
        // 2. Received: `received` rises, `incoming` falls back to 0.
        // ------------------------------------------------------------------------------------
        $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '20.00', $poLine, null, null, null, $this->bin, '3.0000'),
        );

        self::assertSame('20.0000', $this->inventory()->getReceivedQuantity());
        self::assertSame('0.0000', $this->inventory()->getIncomingQuantity());
        $this->assertLogged('received', 0, 20);
        $this->assertLogged('incoming', 20, 0);

        // ------------------------------------------------------------------------------------
        // 3. An estimate, priced. It carries no product (EstimateLine has no such field in this
        //    app), so it is structurally incapable of touching inventory. Confirmed, not assumed:
        //    the log's row count for this product/warehouse must not move at all.
        // ------------------------------------------------------------------------------------
        $beforeEstimate = $this->logRowCount();

        $estimate = (new Estimate())
            ->setCompany($this->customer)
            ->setDocumentNumber('EST-LIFE-1')
            ->setSource('Admin')
            ->setUserName('Test Admin')
            ->setBillingName('Test Admin')
            ->setShippingName('Test Admin')
            ->setFulfillmentRegion($this->warehouse->getName());
        $estimate->addLine(
            (new EstimateLine())->setName('Lifecycle Widget')->setSku('LIFECYCLE-1')->setQuantity('5.00')->setPrice('9.00')->setSubtotal('45.00'),
        );
        $this->em->persist($estimate);
        $estimate->setStatus('Priced', DocumentActor::system());
        $this->em->flush();

        self::assertSame($beforeEstimate, $this->logRowCount(), 'an estimate carries no product and must never move a bucket');

        // ------------------------------------------------------------------------------------
        // 4. A real sales order for the same product, approved: `sales_hold` rises.
        // ------------------------------------------------------------------------------------
        $salesOrder = (new SalesOrder())
            ->setCompany($this->customer)
            ->setOrderNumber('SO-LIFE-1')
            ->setDocumentDate('2026-09-15')
            ->setFulfillmentRegion($this->warehouse->getName());
        $this->em->persist($salesOrder);

        $orderLine = (new SalesOrderLine())
            ->setProduct($this->product)
            ->setName($this->product->getName())
            ->setQuantity('5.00')
            ->setLocation($this->warehouse->getName());
        $salesOrder->addLine($orderLine);
        $this->em->flush();

        $salesOrder->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->flush();

        self::assertSame('5.0000', $this->inventory()->getSalesHoldQuantity());
        $this->assertLogged('sales_hold', 0, 5);

        // ------------------------------------------------------------------------------------
        // 5. Invoiced in full and issued: `pending` rises, `sales_hold` falls (the same units,
        //    handed from the order's ledger to the invoice's — never held by both at once).
        // ------------------------------------------------------------------------------------
        $invoice = $this->invoicing->invoiceInFull($salesOrder, $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        self::assertSame('Pending', $invoice->getStatus());
        self::assertSame('5.0000', $this->inventory()->getPendingQuantity());
        self::assertSame('0.0000', $this->inventory()->getSalesHoldQuantity(), 'the hold moved to the invoice, not a second claim on top of it');
        $this->assertLogged('pending', 0, 5);
        $this->assertLogged('sales_hold', 5, 0);

        // ------------------------------------------------------------------------------------
        // 6. Processing: `approved` rises, `pending` falls.
        // ------------------------------------------------------------------------------------
        $invoice->startProcessing(DocumentActor::system());
        $this->em->flush();

        self::assertSame('5.0000', $this->inventory()->getApprovedQuantity());
        self::assertSame('0.0000', $this->inventory()->getPendingQuantity());
        $this->assertLogged('approved', 0, 5);
        $this->assertLogged('pending', 5, 0);

        // ------------------------------------------------------------------------------------
        // 7. Completed: `shipped` rises, `approved` falls -- a transfer, not a release. This used
        //    to move nothing at all (the exact gap this session traced); as of
        //    docs/plans/2026-09-15-shipment-approved-to-shipped-bucket.md, InvoiceInventoryBucketResolver
        //    maps Completed to `shipped` instead of `approved`, and the existing reconciler
        //    machinery -- the same one that already moves pending -> approved -- does the rest.
        // ------------------------------------------------------------------------------------
        $invoice->setStatus('Completed', DocumentActor::system(), 'Fulfilment completed.');
        $this->em->flush();

        self::assertSame('Completed', $invoice->getStatus());
        self::assertSame('0.0000', $this->inventory()->getApprovedQuantity(), 'the hold moved to shipped, not a second claim on top of it');
        self::assertSame('5.0000', $this->inventory()->getShippedQuantity());
        $this->assertLogged('approved', 5, 0);
        $this->assertLogged('shipped', 0, 5);
    }

    private function inventory(): ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product->getId(),
            'warehouse' => $this->warehouse->getId(),
        ]);
        self::assertInstanceOf(ProductInventory::class, $row);

        // Refreshed rather than the identity map's copy: the buckets are written by listeners and
        // reconcilers on their own flushes, so an in-memory instance can be behind the row.
        $this->em->refresh($row);

        return $row;
    }

    /** Every bucket-log row ever written for this product/warehouse, regardless of bucket. */
    private function logRowCount(): int
    {
        return (int) $this->em->getRepository(InventoryBucketChangeLog::class)->count([
            'product' => $this->product->getId(),
            'warehouse' => $this->warehouse->getId(),
        ]);
    }

    /**
     * The most recent log row for this product/warehouse/bucket must exist and must record exactly
     * this previous -> new transition -- not just "a row exists somewhere", which a stale row from
     * an earlier step could satisfy by accident.
     */
    private function assertLogged(string $bucket, string|int|float $previous, string|int|float $new): void
    {
        $rows = $this->em->getRepository(InventoryBucketChangeLog::class)->findBy(
            ['product' => $this->product->getId(), 'warehouse' => $this->warehouse->getId(), 'bucket' => $bucket],
            ['id' => 'DESC'],
            1,
        );

        self::assertNotEmpty($rows, sprintf('no inventory_bucket_change_log row exists at all for bucket "%s"', $bucket));
        $row = $rows[0];
        // Compared in whole units: the log columns are decimal, the expectations here are written
        // as plain unit counts because that is what the scenario reads as.
        self::assertSame(
            QuantityScale::unitsAtColumnScale($previous),
            QuantityScale::unitsAtColumnScale($row->getPreviousQuantity()),
            sprintf('bucket "%s" latest row: wrong previous value', $bucket),
        );
        self::assertSame(
            QuantityScale::unitsAtColumnScale($new),
            QuantityScale::unitsAtColumnScale($row->getNewQuantity()),
            sprintf('bucket "%s" latest row: wrong new value', $bucket),
        );
    }
}
