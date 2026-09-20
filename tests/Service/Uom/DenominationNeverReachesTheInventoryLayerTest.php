<?php

declare(strict_types=1);

namespace App\Tests\Service\Uom;

use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\DenominatedLine;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\UnitOfMeasure;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\Uom\UnitOfMeasureService;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * **If any entered figure reaches the inventory layer, the unit model is wrong** (#601, #659).
 *
 * That is the one sentence this file exists to falsify. A line entered as **3 BOX-12** of a product
 * whose base unit is `EA` must move `product_inventory` by **36**, and 3 must not appear anywhere
 * below the document:
 *
 * ```
 * sales_order_line.quantity           36.0000   <- the base figure, the column that was always there
 * sales_order_line.quantity_entered    3.0000   <- what the person said
 * sales_order_line.unit_id                ->BOX-12
 * product_inventory.sales_hold_quantity   36    <- NOT 3, and NOT 39
 * order_inventory_reservation.quantity    36
 * ```
 *
 * #659 changed where the twelve comes from — `unit_of_measure.factor_to_family_base` on the term
 * itself, against the product's base unit, rather than a per-product rung — and changed nothing
 * about the rule. Assertions are made against the columns by name through the connection as well as
 * through the entities, because the entity is the thing under test: a getter that resolved boxes
 * into eaches on the way out would satisfy an object-level assertion and still leave 3 in the
 * database, where the reconciler, the recalc command and every report would find it.
 *
 * The reconciler is not called by hand. `InventoryReconciliationSubscriber` fires on Doctrine's
 * postFlush, so persisting the order is what moves the bucket — the same path a screen takes.
 */
final class DenominationNeverReachesTheInventoryLayerTest extends DoctrineIntegrationTestCase
{
    private UnitOfMeasureService $units;
    private FulfillmentRegion $region;
    private Warehouse $warehouse;
    private Company $company;
    private ProductCore $product;
    private ProductCore $control;
    private ProductInventory $inventory;
    private ProductInventory $controlInventory;
    private UnitOfMeasure $each;
    private UnitOfMeasure $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->units = self::getContainer()->get(UnitOfMeasureService::class);

        // Two terms of one family. BOX-12 holds twelve because the GLOBAL table says so — that is
        // the whole of what #659 moved, and every conversion below resolves through these two rows.
        $this->each = $this->units->add('EA-INV', 'Each', UnitOfMeasure::FAMILY_QUANTITY, '1', '1');
        $this->box = $this->units->add('BOX-12-INV', 'Box of 12', UnitOfMeasure::FAMILY_QUANTITY, '12', '1');

        $this->region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($this->region);

        // The order names a REGION and its stock comes out of the WAREHOUSE serving it (#546).
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);

        $this->product = $this->stockedProduct('CASED-1', 1000);

        // The row that must NOT change: same warehouse, same everything, a different product. If an
        // entered figure were leaking through a shared code path this row is where it would show.
        $this->control = $this->stockedProduct('CONTROL-1', 1000);

        $this->inventory = $this->inventoryRow($this->product);
        $this->controlInventory = $this->inventoryRow($this->control);

    }

    public function testAnOrderLineEnteredInBoxesHoldsStockInBaseUnits(): void
    {
        $order = $this->newOrder();
        $line = (new SalesOrderLine())->setProduct($this->product)->setName('Widget');
        $line->setEnteredQuantity('3', $this->box, $this->each);
        $order->addLine($line);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $this->em->persist($order);
        $this->em->flush();

        // The document kept what was said.
        self::assertSame('36.0000', $line->getQuantityBase(), 'sales_order_line.quantity is the BASE figure');
        self::assertSame('3.0000', $line->getQuantityEntered(), 'sales_order_line.quantity_entered is what was typed');
        self::assertSame($this->box->getId(), $line->getUnitOfMeasure()?->getId());

        $row = $this->columnsOf('sales_order_line', (int) $line->getId());
        self::assertSame(36.0, (float) $row['quantity']);
        self::assertSame(3.0, (float) $row['quantity_entered']);
        self::assertSame($this->box->getId(), (int) $row['unit_id']);

        // The inventory layer saw only the base figure.
        $this->em->refresh($this->inventory);
        self::assertSame('36.0000', $this->inventory->getSalesHoldQuantity(), 'product_inventory.sales_hold_quantity must move by 36, not 3');
        self::assertSame('1000.0000', $this->inventory->getQuantity(), 'a hold moves no stock');

        self::assertSame(36.0, $this->reservationTotal('order_inventory_reservation', $this->product));

        // The row that should not have changed.
        $this->em->refresh($this->controlInventory);
        self::assertSame('0.0000', $this->controlInventory->getSalesHoldQuantity());
        self::assertSame('1000.0000', $this->controlInventory->getQuantity());
    }

    /**
     * The same figure one document further along: an invoice line entered in boxes.
     *
     * `InvoiceReservationSubject::stockedQuantityFor()` reads `InvoiceLine::getQuantity()` and
     * clamps it against the order line's — two reads of the base column, on two documents, and both
     * have to be seeing 36 for the pending bucket to land on 36.
     */
    public function testAnInvoiceLineEnteredInBoxesBillsStockInBaseUnits(): void
    {
        $order = $this->newOrder();
        $orderLine = (new SalesOrderLine())->setProduct($this->product)->setName('Widget');
        $orderLine->setEnteredQuantity('3', $this->box, $this->each);
        $order->addLine($orderLine);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        $invoice = new Invoice();
        $order->addInvoice($invoice);
        $invoice->setCompany($this->company)
            ->setDocumentNumber('INV-CASES-1')
            ->setFulfillmentRegion($order->getFulfillmentRegion());

        $invoiceLine = (new InvoiceLine())
            ->setSalesOrderLine($orderLine)
            ->setProduct($this->product)
            ->setName('Widget');
        $invoiceLine->setEnteredQuantity('3', $this->box, $this->each);
        $invoice->addLine($invoiceLine);

        $invoice->issue(DocumentActor::system());
        $this->em->persist($invoice);
        $this->em->flush();

        $row = $this->columnsOf('invoice_line', (int) $invoiceLine->getId());
        self::assertSame(36.0, (float) $row['quantity']);
        self::assertSame(3.0, (float) $row['quantity_entered']);

        $this->em->refresh($this->inventory);
        self::assertSame('36.0000', $this->inventory->getPendingQuantity(), 'invoice_line entered in boxes must bill 36');
        self::assertSame('0.0000', $this->inventory->getSalesHoldQuantity(), 'the order hold gives way to the invoice');

        $this->em->refresh($this->controlInventory);
        self::assertSame('0.0000', $this->controlInventory->getPendingQuantity());
    }

    /**
     * Restating the base figure directly forgets the entered expression rather than keeping a stale
     * one.
     *
     * Without this the line would go on claiming to be three boxes of something while its base
     * column read 40, and the printed document would contradict its own arithmetic — `40 BOX-12`
     * against 40 base units, at a per-unit price.
     */
    public function testWritingTheBaseFigureDirectlyForgetsHowTheLineWasEntered(): void
    {
        $order = $this->newOrder();
        $line = (new SalesOrderLine())->setProduct($this->product)->setName('Widget');
        $line->setEnteredQuantity('3', $this->box, $this->each);
        $order->addLine($line);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        $line->setQuantity('40');
        $this->em->flush();

        self::assertNull($line->getUnitOfMeasure());
        self::assertSame('40', $line->getQuantityEntered(), 'entered falls back to the base figure once the pair is cleared');

        $row = $this->columnsOf('sales_order_line', (int) $line->getId());
        self::assertSame(40.0, (float) $row['quantity']);
        self::assertNull($row['quantity_entered'], 'sales_order_line.quantity_entered goes back to NULL');
        self::assertNull($row['unit_id']);

        $this->em->refresh($this->inventory);
        self::assertSame('40.0000', $this->inventory->getSalesHoldQuantity());
    }

    /**
     * A row written before phase 3 reads as "entered in base units" — with no UPDATE having run.
     *
     * Simulated the only honest way: both columns are set to NULL through the connection, which is
     * exactly what the migration's `ADD COLUMN ... DEFAULT NULL` left on every existing row, and the
     * entity is then read back fresh.
     */
    public function testARowWithNeitherColumnSetReadsAsEnteredInBaseUnits(): void
    {
        $order = $this->newOrder();
        $line = (new SalesOrderLine())->setProduct($this->product)->setName('Widget')->setQuantity('36');
        $order->addLine($line);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        $lineId = (int) $line->getId();
        $this->em->getConnection()->executeStatement(
            'UPDATE sales_order_line SET quantity_entered = NULL, unit_id = NULL WHERE id = ?',
            [$lineId],
        );
        $this->em->clear();

        $reread = $this->em->find(SalesOrderLine::class, $lineId);

        self::assertInstanceOf(SalesOrderLine::class, $reread);
        self::assertTrue($reread->isEnteredInBaseUnits());
        self::assertNull($reread->getUnitOfMeasure());
        self::assertSame(36.0, (float) $reread->getQuantityBase());
        self::assertSame(36.0, (float) $reread->getQuantityEntered(), 'no unit means the entered figure IS the base figure');
    }

    /**
     * Every line-bearing entity resolves the same way — enumerated from the entity map, not listed.
     *
     * Fourteen tables carry these columns and a per-table test would be fourteen chances to forget
     * one. This asks each of them the same question: say 3 BOX-12, and the base column must read 36.
     */
    public function testEveryLineEntityResolvesTheEnteredFigureIntoItsOwnBaseColumn(): void
    {
        $seen = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if (!$meta instanceof ClassMetadata || $meta->isMappedSuperclass) {
                continue;
            }

            $class = $meta->getName();
            if (!is_a($class, DenominatedLine::class, true)) {
                continue;
            }

            /** @var DenominatedLine $line */
            $line = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $line->setEnteredQuantity('3', $this->box, $this->each);

            $where = $meta->getTableName();
            self::assertSame(36.0, (float) $line->getQuantityBase(), $where . ' must resolve 3 BOX-12 into 36 base units');
            self::assertSame(3.0, (float) $line->getQuantityEntered(), $where . ' must keep the figure that was typed');
            self::assertSame($this->box->getId(), $line->getUnitOfMeasure()?->getId(), $where);

            $line->setEnteredQuantity('7', null, $this->each);
            self::assertSame(7.0, (float) $line->getQuantityBase(), $where . ' in base units is itself');
            self::assertTrue($line->isEnteredInBaseUnits(), $where);

            $seen[] = $where;
        }

        sort($seen);

        self::assertSame([
            'cart_item',
            'credit_memo_line',
            'debit_memo_line',
            'estimate_line',
            'goods_receipt_line',
            'invoice_line',
            'pick_task',
            'purchase_order_line',
            'rfq_line',
            'sales_order_line',
            'sales_return_line',
            'transfer_order_line',
            'vendor_bill_line',
            'vendor_return_line',
        ], $seen);
    }

    private function newOrder(): SalesOrder
    {
        return (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('ORD-' . uniqid())
            ->setFulfillmentRegion($this->region->getName());
    }

    private function stockedProduct(string $sku, int $quantity): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Product ' . $sku)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);

        $row = (new ProductInventory())->setProduct($product)->setWarehouse($this->warehouse)->setQuantity($quantity);
        $this->em->persist($row);
        $this->em->flush();

        return $product;
    }

    private function inventoryRow(ProductCore $product): ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)
            ->findOneBy(['product' => $product, 'warehouse' => $this->warehouse]);

        self::assertInstanceOf(ProductInventory::class, $row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function columnsOf(string $table, int $id): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            sprintf('SELECT quantity, quantity_entered, unit_id FROM %s WHERE id = ?', $table),
            [$id],
        );

        self::assertIsArray($row, $table . ' row ' . $id . ' was not written');

        return $row;
    }

    private function reservationTotal(string $table, ProductCore $product): float
    {
        return (float) $this->em->getConnection()->fetchOne(
            sprintf('SELECT COALESCE(SUM(quantity), 0) FROM %s WHERE product_id = ?', $table),
            [(int) $product->getId()],
        );
    }
}
