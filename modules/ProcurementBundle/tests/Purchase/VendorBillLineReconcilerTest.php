<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Purchase;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Tests\DoctrineIntegrationTestCase;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Purchase\VendorBillLineReconciler;

/**
 * Direct coverage of the seams {@see VendorBillLineReconciler} overrides — the rules
 * `VendorBillController::requestedLines()` had that the shared {@see \ProcurementBundle\Purchase\PurchaseSideLineReconciler}
 * base class (lifted from PurchaseOrder) does not share, because VendorBill's own
 * delete-and-rebuild code never had an "existing line" case in mind at all until this conversion.
 */
final class VendorBillLineReconcilerTest extends DoctrineIntegrationTestCase
{
    private function reconciler(): VendorBillLineReconciler
    {
        return self::getContainer()->get(VendorBillLineReconciler::class);
    }

    private function makeProduct(string $sku): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Reconciler Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function makeOrderWithLine(ProductCore $product): PurchaseOrderLine
    {
        $vendor = (new Vendor())->setName('Reconciler Test Vendor')->setCurrency('USD');
        $this->em->persist($vendor);
        $warehouse = new Warehouse();
        $warehouse->setName('Reconciler Test Warehouse');
        $this->em->persist($warehouse);

        $order = (new PurchaseOrder())->setPoNumber('RECON-BILL-PO-' . uniqid())->setVendor($vendor)->setWarehouse($warehouse);
        $line = (new PurchaseOrderLine())->setProduct($product)->setName('PO Line Name')->setSku('PO-LINE-SKU')->setVendorSku('PO-VENDOR-SKU')->setQuantityOrdered('10.0000')->setUnitCost('5.000000');
        $order->addLine($line);
        $this->em->persist($order);
        $this->em->persist($line);
        $this->em->flush();

        return $line;
    }

    /** VendorBillLine::$bill must be initialized before flush. */
    private function attachToAMinimalBill(VendorBillLine $line, ?PurchaseOrder $order = null): VendorBill
    {
        $vendor = $order?->getVendor() ?? (new Vendor())->setName('Reconciler Test Vendor')->setCurrency('USD');
        if ($order === null) {
            $this->em->persist($vendor);
        }

        $bill = (new VendorBill())->setBillNumber('RECON-BILL-' . uniqid())->setVendor($vendor)->setPurchaseOrder($order);
        $bill->addLine($line);
        $this->em->persist($bill);
        $this->em->persist($line);

        return $bill;
    }

    public function testAnAttributedOrderLinesProductWinsOverATypedOne(): void
    {
        $product = $this->makeProduct('RECON-BILL-PRODUCT');
        $otherProduct = $this->makeProduct('RECON-BILL-OTHER-PRODUCT');
        $orderLine = $this->makeOrderWithLine($product);
        $order = $orderLine->getPurchaseOrder();

        $result = $this->reconciler()->reconcile(
            [],
            [['purchase_order_line_id' => (string) $orderLine->getId(), 'product_id' => (string) $otherProduct->getId(), 'qty' => '1']],
            $order->getVendor(),
            $order,
        );

        self::assertSame($product->getId(), $result['lines'][0]->product?->getId(), 'a bill line attributed to a PO line is for that lines product, whatever the form carried');
        self::assertSame($orderLine->getId(), $result['lines'][0]->attributedLine?->getId());
    }

    public function testAnUnrelatedOrdersLineIdIsNotAccepted(): void
    {
        $product = $this->makeProduct('RECON-BILL-WRONGORDER');
        $orderLine = $this->makeOrderWithLine($product);
        // A different order — the bill being saved is NOT against $orderLine's own order.
        $unrelatedOrder = new PurchaseOrder();
        $unrelatedOrder->setPoNumber('RECON-UNRELATED-' . uniqid())->setVendor($orderLine->getPurchaseOrder()->getVendor())->setWarehouse($orderLine->getPurchaseOrder()->getWarehouse());
        $this->em->persist($unrelatedOrder);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [],
            [['purchase_order_line_id' => (string) $orderLine->getId(), 'product_id' => (string) $product->getId(), 'qty' => '1']],
            $unrelatedOrder->getVendor(),
            $unrelatedOrder,
        );

        self::assertNull($result['lines'][0]->attributedLine, 'an id naming a line on a DIFFERENT order must not be accepted');
    }

    public function testABlankCostIsZeroNotARateCardGuess(): void
    {
        $product = $this->makeProduct('RECON-BILL-BLANKCOST');

        $result = $this->reconciler()->reconcile([], [['product_id' => (string) $product->getId(), 'qty' => '1', 'unit_cost' => '']], null);

        self::assertEqualsWithDelta(0.0, (float) $result['lines'][0]->unitCost, 0.0001, 'a bill states what the vendor actually charged, never a rate-card guess');
    }

    public function testAnyNonblankTaxCodeIsAcceptedUnlikePurchaseOrder(): void
    {
        $product = $this->makeProduct('RECON-BILL-TAXCODE');
        $product->setSalesTaxCode('S');
        $this->em->flush();

        $result = $this->reconciler()->reconcile([], [['product_id' => (string) $product->getId(), 'qty' => '1', 'tax_code' => 'not-a-real-code']], null);

        self::assertSame('not-a-real-code', $result['lines'][0]->taxCode, 'no E/G/S validation on this side — any nonblank typed value is accepted verbatim through TaxContext::mapTaxCode()');
    }

    public function testAnExistingLineIsUpsertedNotDuplicated(): void
    {
        $product = $this->makeProduct('RECON-BILL-UPSERT');
        $existing = (new VendorBillLine())->setProduct($product)->setName('Existing charge')->setQuantity('1.0000')->setUnitCost('2.000000');
        $this->attachToAMinimalBill($existing);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $product->getId(), 'qty' => '1', 'unit_cost' => '2.00']],
            null,
        );

        self::assertCount(1, $result['lines']);
        self::assertSame($existing->getId(), $result['lines'][0]->existingId);
        self::assertArrayHasKey((int) $existing->getId(), $result['keptIds']);
    }

    public function testAnExistingLinesNameSurvivesWhenNothingIsTypedOrAttributed(): void
    {
        $product = $this->makeProduct('RECON-BILL-KEEPNAME');
        $existing = (new VendorBillLine())->setProduct($product)->setName('My Own Charge Name')->setQuantity('1.0000')->setUnitCost('2.000000');
        $this->attachToAMinimalBill($existing);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $product->getId(), 'qty' => '1']],
            null,
        );

        self::assertSame('My Own Charge Name', $result['lines'][0]->name);
    }
}
