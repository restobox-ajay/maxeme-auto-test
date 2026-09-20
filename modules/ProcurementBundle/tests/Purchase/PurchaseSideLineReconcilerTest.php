<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Purchase;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Tests\DoctrineIntegrationTestCase;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorPrice;
use ProcurementBundle\Purchase\PurchaseSideLineReconciler;

/**
 * Direct coverage of PurchaseSideLineReconciler, extracted from PurchaseOrderController's own
 * requestedLines()/save() — the class VendorBillController moves onto next, so it has to prove the
 * exact behaviors PurchaseOrderController's own Cests only prove indirectly through a full HTTP
 * save (of which there are none in this bundle — see the class's own docblock).
 */
final class PurchaseSideLineReconcilerTest extends DoctrineIntegrationTestCase
{
    private function reconciler(): PurchaseSideLineReconciler
    {
        return self::getContainer()->get(PurchaseSideLineReconciler::class);
    }

    private function makeProduct(string $sku): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Reconciler Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function makeVendor(): Vendor
    {
        $vendor = (new Vendor())->setName('Reconciler Test Vendor')->setCurrency('USD');
        $this->em->persist($vendor);
        $this->em->flush();

        return $vendor;
    }

    /** PurchaseOrderLine::$purchaseOrder must be initialized before flush. */
    private function attachToAMinimalOrder(PurchaseOrderLine $line, ?Vendor $vendor = null): PurchaseOrder
    {
        $vendor ??= $this->makeVendor();
        $warehouse = new Warehouse();
        $warehouse->setName('Reconciler Test Warehouse');
        $this->em->persist($warehouse);

        $order = (new PurchaseOrder())->setPoNumber('RECON-PO-' . uniqid())->setVendor($vendor)->setWarehouse($warehouse);
        $order->addLine($line);
        $this->em->persist($order);
        $this->em->persist($line);

        return $order;
    }

    public function testANewRowWithNoIdBecomesANewLine(): void
    {
        $product = $this->makeProduct('RECON-PO-NEW-1');

        $result = $this->reconciler()->reconcile([], [['product_id' => (string) $product->getId(), 'qty' => '5', 'unit_cost' => '12.50']], null);

        self::assertCount(1, $result['lines']);
        $line = $result['lines'][0];
        self::assertNull($line->existingId, 'no id in the row means a brand new line');
        self::assertSame($product->getId(), $line->product?->getId());
        self::assertEqualsWithDelta(5.0, $line->baseQuantity, 0.0001);
        self::assertTrue($line->writeQuantity && $line->writeUnitCost, 'a new line always writes both columns');
    }

    public function testARowNamingAnExistingIdIsMatchedNotDuplicated(): void
    {
        $product = $this->makeProduct('RECON-PO-MATCH-1');
        $existing = (new PurchaseOrderLine())->setProduct($product)->setName('Existing')->setQuantityOrdered('3.0000')->setUnitCost('9.000000');
        $this->attachToAMinimalOrder($existing);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $product->getId(), 'qty' => '3', 'qty_rendered' => '3', 'unit_cost' => '9.000000', 'unit_cost_rendered' => '9.000000']],
            null,
        );

        self::assertCount(1, $result['lines']);
        self::assertSame($existing->getId(), $result['lines'][0]->existingId);
        self::assertArrayHasKey((int) $existing->getId(), $result['keptIds']);
    }

    public function testASecondRowClaimingAnAlreadyMatchedIdBecomesNewRatherThanFightingTheFirst(): void
    {
        $product = $this->makeProduct('RECON-PO-DOUBLECLAIM');
        $existing = (new PurchaseOrderLine())->setProduct($product)->setName('Existing')->setQuantityOrdered('3.0000')->setUnitCost('9.000000');
        $this->attachToAMinimalOrder($existing);
        $this->em->flush();
        $id = (int) $existing->getId();

        $result = $this->reconciler()->reconcile(
            [$id => $existing],
            [
                ['id' => (string) $id, 'product_id' => (string) $product->getId(), 'qty' => '3'],
                ['id' => (string) $id, 'product_id' => (string) $product->getId(), 'qty' => '1'],
            ],
            null,
        );

        self::assertCount(2, $result['lines']);
        self::assertSame($id, $result['lines'][0]->existingId, 'the first row to claim the id keeps it');
        self::assertNull($result['lines'][1]->existingId, 'a second row naming an already-claimed id is treated as new');
    }

    public function testAQuantityOfZeroOrLessSkipsTheRowEntirely(): void
    {
        $product = $this->makeProduct('RECON-PO-ZERO');

        $result = $this->reconciler()->reconcile([], [['product_id' => (string) $product->getId(), 'qty' => '0']], null);

        self::assertCount(0, $result['lines']);
    }

    public function testAnUntouchedQuantityAndCostBoxKeepTheStoredFigureAndSkipTheWrite(): void
    {
        $product = $this->makeProduct('RECON-PO-BOXUNTOUCHED');
        $existing = (new PurchaseOrderLine())->setProduct($product)->setName('Existing')->setQuantityOrdered('7.0000')->setUnitCost('1.500000');
        $this->attachToAMinimalOrder($existing);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $product->getId(), 'qty' => '7', 'qty_rendered' => '7', 'unit_cost' => '1.5', 'unit_cost_rendered' => '1.5']],
            null,
        );

        $line = $result['lines'][0];
        self::assertEqualsWithDelta(7.0, $line->baseQuantity, 0.0001);
        self::assertFalse($line->writeQuantity, 'nothing actually changed, so the precision-preserving write must be skipped');
        self::assertFalse($line->writeUnitCost);
    }

    public function testAChangedQuantityIsFlaggedToWrite(): void
    {
        $product = $this->makeProduct('RECON-PO-CHANGEDQTY');
        $existing = (new PurchaseOrderLine())->setProduct($product)->setName('Existing')->setQuantityOrdered('7.0000')->setUnitCost('1.500000');
        $this->attachToAMinimalOrder($existing);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $product->getId(), 'qty' => '9', 'qty_rendered' => '7']],
            null,
        );

        self::assertTrue($result['lines'][0]->writeQuantity);
    }

    public function testABlankCostOnANewLineFallsBackToTheVendorRateCard(): void
    {
        $vendor = $this->makeVendor();
        $product = $this->makeProduct('RECON-PO-RATECARD');
        $vendorPrice = (new VendorPrice())->setVendor($vendor)->setProduct($product)->setUnitCost('4.250000')->setVendorSku('VEND-SKU-1');
        $this->em->persist($vendorPrice);
        $this->em->flush();

        $result = $this->reconciler()->reconcile([], [['product_id' => (string) $product->getId(), 'qty' => '1', 'unit_cost' => '']], $vendor);

        self::assertEqualsWithDelta(4.25, (float) $result['lines'][0]->unitCost, 0.0001);
        self::assertSame('VEND-SKU-1', $result['lines'][0]->vendorSku);
    }

    public function testAnExistingLinesNonBlankCostIsNeverOverriddenByTheRateCard(): void
    {
        $vendor = $this->makeVendor();
        $product = $this->makeProduct('RECON-PO-RATECARD-EXISTING');
        $vendorPrice = (new VendorPrice())->setVendor($vendor)->setProduct($product)->setUnitCost('4.250000');
        $this->em->persist($vendorPrice);
        $existing = (new PurchaseOrderLine())->setProduct($product)->setName('Existing')->setQuantityOrdered('1.0000')->setUnitCost('9.990000');
        $this->attachToAMinimalOrder($existing, $vendor);
        $this->em->flush();

        // Cost box cleared on an existing, already-priced row: #637's own rule is that this means
        // "look at the line's own stored cost first", never "go re-price from the rate card".
        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $product->getId(), 'qty' => '1', 'unit_cost' => '']],
            $vendor,
        );

        self::assertEqualsWithDelta(9.99, (float) $result['lines'][0]->unitCost, 0.0001);
    }

    public function testARowNamingNoProductKeepsTheExistingLinesProduct(): void
    {
        $product = $this->makeProduct('RECON-PO-KEEPPRODUCT');
        $existing = (new PurchaseOrderLine())->setProduct($product)->setName('Existing')->setQuantityOrdered('1.0000')->setUnitCost('1.000000');
        $this->attachToAMinimalOrder($existing);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => '', 'qty' => '1']],
            null,
        );

        self::assertSame($product->getId(), $result['lines'][0]->product?->getId(), 'a blank product field on an existing line is a gap to fill, never an instruction to clear');
    }

    public function testATaxCodeMustBeExactlyEGOrSOrItFallsToTheProduct(): void
    {
        $product = $this->makeProduct('RECON-PO-TAXCODE');
        $product->setSalesTaxCode('G');
        $this->em->flush();

        $result = $this->reconciler()->reconcile([], [['product_id' => (string) $product->getId(), 'qty' => '1', 'tax_code' => 'not-a-real-code']], null);

        self::assertSame('G', $result['lines'][0]->taxCode, "an invalid typed tax code is not a real choice and falls through to the product's own");
    }
}
