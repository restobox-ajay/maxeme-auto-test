<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Movement;

use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;

/**
 * What actually happens to the buckets when a DIMENSIONAL product is sold and shipped.
 *
 * Written because the question could not be settled from the development database. Every product
 * there with `approved > 0` is `simple`, and every product with `sold` detail rows is
 * `dimensional` — zero overlap. That looked like proof the two mechanisms never meet, but a
 * database under active development proves nothing: it may only mean nobody has yet invoiced a
 * dimensional product.
 *
 * So this conducts the transaction rather than reading the leftovers of other people's. It sells a
 * dimensional product through a real invoice and ships it through the movement layer, and records
 * what each side does.
 *
 * The question it answers: when a dimensional product ships, is the stock accounted for TWICE —
 * once by the invoice's `approved` hold and once by the depth layer — or NOT AT ALL?
 */
final class SellingADimensionalProductTest extends DoctrineIntegrationTestCase
{
    private StockMovementService $movements;
    private Warehouse $warehouse;
    private ProductCore $product;
    private WarehouseLocation $bin;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->movements = self::getContainer()->get(StockMovementService::class);

        $region = (new FulfillmentRegion())->setName('Sell Region');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->company = (new Company())->setName('Buyer Ltd');
        $this->em->persist($this->company);

        $this->product = (new ProductCore())
            ->setSku('SELL-DIM-1')
            ->setName('Dimensional Thing')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);

        $this->bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('A-01')->setSortKey(10);
        $this->em->persist($this->bin);
        $this->em->flush();

        // 30 on the shelf, booked the way stock actually arrives.
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed')
                ->receive($this->product, new DetailKey($this->warehouse, $this->bin, null, null, InventoryDetail::STATUS_AVAILABLE), 30)
        );
        $this->em->flush();
    }

    public function testWhatHappensWhenADimensionalProductIsSoldAndShipped(): void
    {
        $before = $this->inventory();
        self::assertSame('30.0000', $before->getAvailableQuantity(), 'guard: 30 on the shelf to begin with');

        // 1. An order for 5, approved.
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('SELL-1')
            ->setDocumentDate('2026-08-22')
            ->setFulfillmentRegion($this->warehouse->getName());
        $this->em->persist($order);

        $line = (new SalesOrderLine())
            ->setProduct($this->product)
            ->setName($this->product->getName())
            ->setQuantity('5.00')
            ->setLocation($this->warehouse->getName());
        $order->addLine($line);
        $this->em->flush();

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->flush();

        // 2. An invoice billing it, moved to Processing so it holds `approved`.
        $invoice = (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber('SELL-INV-1')
            ->setDocumentDate('2026-08-22')
            ->setFulfillmentRegion($this->warehouse->getName());
        $order->addInvoice($invoice);
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($line)
                ->setProduct($this->product)
                ->setName($this->product->getName())
                ->setQuantity('5.00')
        );
        $this->em->persist($invoice);
        $this->em->flush();

        $invoice->issue(DocumentActor::system());
        $this->em->flush();
        $invoice->startProcessing(DocumentActor::system());
        $this->em->flush();

        $afterInvoice = $this->inventory();
        $heldByInvoice = $afterInvoice->getApprovedQuantity();

        // 3. The warehouse ships it: the depth layer marks the units sold.
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_SHIP, 'ship-1', null, null, 'SELL-1')
                ->move(
                    $this->product,
                    new DetailKey($this->warehouse, $this->bin, null, null, InventoryDetail::STATUS_AVAILABLE),
                    new DetailKey($this->warehouse, $this->bin, null, null, InventoryDetail::STATUS_SOLD),
                    5
                )
        );
        $this->em->flush();

        $after = $this->inventory();

        // Everything the run observed, so a failure reports the whole picture rather than one number.
        $report = sprintf(
            "\n  invoice `approved` hold : %d"
            . "\n  quantity               : %d"
            . "\n  received               : %d"
            . "\n  approved (after ship)  : %d"
            . "\n  pending  (after ship)  : %d"
            . "\n  available              : %d"
            . "\n  sold detail rows       : %d\n",
            $heldByInvoice,
            $after->getQuantity(),
            $after->getReceivedQuantity(),
            $after->getApprovedQuantity(),
            $after->getPendingQuantity(),
            $after->getAvailableQuantity(),
            $this->soldUnits(),
        );

        self::assertSame(5, $this->soldUnits(), 'guard: the shipment produced sold detail rows' . $report);

        // THE QUESTION. 30 on the shelf, 5 sold and shipped, so 25 should be sellable.
        //
        //   25 -> the units are accounted for exactly once
        //   20 -> counted twice: the invoice hold AND the depth layer
        //   30 -> counted by neither, and 5 sold units are still on offer
        self::assertSame('25.0000', $after->getAvailableQuantity(), 'a dimensional product that is sold and shipped' . $report);

        // And the answer to WHICH side did it, recorded rather than inferred, because this is the
        // fact the rest of the design rests on: the invoice holds the 5, the depth layer does not
        // take them off again.
        self::assertSame('5.0000', $heldByInvoice, 'the invoice took the hold when it began processing' . $report);
        self::assertSame('5.0000', $after->getApprovedQuantity(), 'and still holds it after the goods leave' . $report);
        self::assertSame('30.0000', $after->getReceivedQuantity(), 'shipping is not an arrival and not a write-off' . $report);
        self::assertSame('0.0000', $after->getWriteOffQuantity(), 'sold stock is not written off' . $report);
        self::assertSame('0.0000', $after->getTransferOutQuantity(), 'nor in transit' . $report);
        self::assertSame('0.0000', $after->getQuantity(), 'the external system has never counted this product' . $report);
    }

    private function inventory(): ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product->getId(),
            'warehouse' => $this->warehouse->getId(),
        ]);
        self::assertInstanceOf(ProductInventory::class, $row);

        // Refreshed rather than the identity map's copy: the buckets are written by listeners and
        // by the reservation reconciler, so an in-memory instance can be behind the row. Not
        // clear()ed, which would detach the fixtures this test still holds.
        $this->em->refresh($row);

        return $row;
    }

    private function soldUnits(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
            [$this->product->getId(), InventoryDetail::STATUS_SOLD],
        );
    }
}
