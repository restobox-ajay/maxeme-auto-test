<?php

declare(strict_types=1);

namespace App\Tests\Service\Uom;

use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\UnitOfMeasure;
use App\Service\Uom\ProductBaseUnitService;
use App\Service\Uom\UnitOfMeasureRefusal;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * Refusal 2: **a product's base unit cannot change once stock or documents exist** (#601, phase 1
 * #643).
 *
 * `product_inventory.quantity` reading 5000 means five thousand grams while the product says `G`.
 * Point the same product at `KG` and the row means five thousand kilograms — a thousandfold
 * restatement with no movement written, no audit entry, and every historical document changing
 * meaning at the same instant. Business Central forbids it once ledger entries exist; so does this.
 *
 * Each test asserts the refusal AND re-reads `product_core.unit_id` afterwards, because a refusal
 * that throws and writes anyway is worse than no refusal at all.
 */
final class ProductBaseUnitServiceTest extends DoctrineIntegrationTestCase
{
    private ProductBaseUnitService $baseUnits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseUnits = self::getContainer()->get(ProductBaseUnitService::class);
    }

    /** Filling the column in for the first time is not a change, whatever stock the product has. */
    public function testTheFirstAssignmentIsAllowedEvenWithStockOnHand(): void
    {
        $product = $this->product('BASE-FIRST');
        $grams = $this->unit('G', 'Gram');
        $this->stock($product, quantity: 5000);

        $this->baseUnits->assign($product, $grams);
        $this->em->flush();
        $this->em->clear();

        $reread = $this->em->find(ProductCore::class, (int) $product->getId());

        self::assertInstanceOf(ProductCore::class, $reread);
        self::assertSame('G', $reread->getBaseUnit()?->getCode());
    }

    public function testChangingTheBaseUnitIsRefusedOnceStockExists(): void
    {
        $product = $this->product('BASE-STOCK');
        $grams = $this->unit('G', 'Gram');
        $kilos = $this->unit('KG', 'Kilogram');

        $this->baseUnits->assign($product, $grams);
        $this->em->flush();

        $this->stock($product, quantity: 5000);

        $gramsId = (int) $grams->getId();

        try {
            $this->baseUnits->assign($product, $kilos);
            self::fail('5000 g must not be allowed to become 5000 kg by editing a dropdown');
        } catch (UnitOfMeasureRefusal $refusal) {
            self::assertStringContainsString('product_inventory.quantity', $refusal->getMessage());
        }

        $this->em->flush();
        $this->em->clear();
        $reread = $this->em->find(ProductCore::class, (int) $product->getId());

        self::assertInstanceOf(ProductCore::class, $reread);
        self::assertSame($gramsId, $reread->getBaseUnit()?->getId(), 'product_core.unit_id must be untouched');
    }

    /**
     * A bucket other than `quantity` locks it too.
     *
     * `product_inventory.quantity` reading 0 does not mean the product never moved: this row has
     * sold everything it received, and `received_quantity` still says 500 in the old unit. The
     * invariant `quantity + received + … - SUM(sold)` is denominated in one unit, so a bucket is
     * every bit as denominated as the on-hand figure.
     */
    public function testABucketOtherThanQuantityLocksTheBaseUnitToo(): void
    {
        $product = $this->product('BASE-BUCKET');
        $grams = $this->unit('G', 'Gram');
        $kilos = $this->unit('KG', 'Kilogram');

        $this->baseUnits->assign($product, $grams);
        $this->em->flush();

        $this->stock($product, quantity: 0, received: 500);

        $this->expectException(UnitOfMeasureRefusal::class);
        $this->expectExceptionMessageMatches('/product_inventory\.received_quantity/');

        $this->baseUnits->assign($product, $kilos);
    }

    /** A document line is denominated in the unit exactly as stock is. */
    public function testADocumentLineLocksTheBaseUnit(): void
    {
        $product = $this->product('BASE-DOC');
        $each = $this->unit('EA', 'Each');
        $pairs = $this->unit('PR', 'Pair');

        $this->baseUnits->assign($product, $each);
        $this->em->flush();

        $company = (new Company())->setName('Acme Supplies')->setCode('ACME-UOM');
        $this->em->persist($company);

        $order = (new SalesOrder())->setCompany($company)->setOrderNumber('ORD-UOM-1');
        $order->addLine(
            (new SalesOrderLine())->setProduct($product)->setName('Widget')->setQuantity('40.00')
        );
        $this->em->persist($order);
        $this->em->flush();

        $eachId = (int) $each->getId();

        try {
            $this->baseUnits->assign($product, $pairs);
            self::fail('40 EA on an order must not silently become 40 PR');
        } catch (UnitOfMeasureRefusal $refusal) {
            self::assertStringContainsString('sales_order_line.quantity', $refusal->getMessage());
        }

        $this->em->flush();
        $this->em->clear();
        $reread = $this->em->find(ProductCore::class, (int) $product->getId());

        self::assertInstanceOf(ProductCore::class, $reread);
        self::assertSame($eachId, $reread->getBaseUnit()?->getId());
    }

    /**
     * Zero does not lock.
     *
     * Every product gets a `product_inventory` row whether or not it has ever held anything, so a
     * guard that fired on the row's existence would lock the column against ever being set — and a
     * row recording none of a product says nothing about what unit it would have been in.
     */
    public function testARowRecordingNothingDoesNotLockTheBaseUnit(): void
    {
        $product = $this->product('BASE-ZERO');
        $grams = $this->unit('G', 'Gram');
        $kilos = $this->unit('KG', 'Kilogram');

        $this->baseUnits->assign($product, $grams);
        $this->em->flush();

        $this->stock($product, quantity: 0);

        $this->baseUnits->assign($product, $kilos);
        $this->em->flush();
        $this->em->clear();

        $reread = $this->em->find(ProductCore::class, (int) $product->getId());

        self::assertInstanceOf(ProductCore::class, $reread);
        self::assertSame('KG', $reread->getBaseUnit()?->getCode());
    }

    /** One product's history is one product's business. */
    public function testAnotherProductsBaseUnitIsUnaffectedByTheRefusal(): void
    {
        $control = $this->product('BASE-CONTROL');
        $grams = $this->unit('G', 'Gram');
        $kilos = $this->unit('KG', 'Kilogram');

        $this->baseUnits->assign($control, $grams);
        $this->em->flush();
        $controlId = (int) $control->getId();

        $locked = $this->product('BASE-LOCKED');
        $this->baseUnits->assign($locked, $grams);
        $this->em->flush();
        $this->stock($locked, quantity: 5000);

        try {
            $this->baseUnits->assign($locked, $kilos);
        } catch (UnitOfMeasureRefusal) {
            // expected — the control row is the subject here
        }

        // The control product has no stock, so its own unit is still free to change.
        $this->baseUnits->assign($control, $kilos);
        $this->em->flush();
        $this->em->clear();

        $reread = $this->em->find(ProductCore::class, $controlId);

        self::assertInstanceOf(ProductCore::class, $reread);
        self::assertSame('KG', $reread->getBaseUnit()?->getCode(), 'the unlocked product must still be free to change');
    }

    /**
     * The guard enumerates its subjects from the entity map (#601's "enumerate, do not list").
     *
     * Asserted as a subset rather than an exact list: the installed bundle set decides how many
     * tables there are, and a conformance test that pinned the total would fail for a reason that
     * has nothing to do with units. What must hold is that every core table holding a quantity of a
     * product is in, and that things which merely mention a product are out.
     */
    public function testTheSweepFindsEveryCoreTableThatHoldsAQuantityOfAProduct(): void
    {
        $columns = $this->baseUnits->quantityColumns();

        foreach ([
            'product_inventory.quantity',
            'product_inventory.received_quantity',
            'product_inventory.quarantine_quantity',
            'product_inventory.write_off_quantity',
            'product_inventory.transfer_in_quantity',
            'product_inventory.transfer_out_quantity',
            'sales_order_line.quantity',
            'invoice_line.quantity',
            'estimate_line.quantity',
            'credit_memo_line.quantity',
            'sales_return_line.quantity',
            'cart_item.quantity',
        ] as $column) {
            self::assertContains($column, $columns, $column . ' holds a quantity of a product and must lock its base unit');
        }

        // A configured cap is not a quantity held: it is settable on a product that has never moved,
        // and treating it as history would lock a brand-new product's base unit.
        self::assertNotContains('product_inventory.max_backorder_quantity', $columns);

        // Phase 3 (#646) put a SECOND quantity-named column on fourteen of these tables, and it is
        // not denominated in the base unit: `quantity_entered` counts PACKAGES. The base column
        // beside it is always present and always in this list, so every one of those rows still
        // locks — what the exclusion prevents is the admin being told a figure measured in cases is
        // why the base unit cannot change.
        foreach ($columns as $column) {
            self::assertStringEndsNotWith('.quantity_entered', $column);
        }
    }

    /**
     * A line entered in a larger unit locks the base unit through its BASE column, not its entered
     * one.
     *
     * The refusal must not have weakened: 3 BOX-12 is still 36 EA on the shelf, and pointing the
     * product at PR would restate all thirty-six of them.
     */
    public function testALineEnteredInAnotherUnitStillLocksTheBaseUnitThroughItsBaseColumn(): void
    {
        $product = $this->product('BASE-CASED');
        $each = $this->unit('EA', 'Each');
        $pairs = $this->unit('PR', 'Pair');

        $this->baseUnits->assign($product, $each);
        $this->em->flush();

        $box = $this->unit('BOX-12', 'Box of 12', '12');

        $company = (new Company())->setName('Acme Supplies')->setCode('ACME-CASED');
        $this->em->persist($company);

        $line = (new SalesOrderLine())->setProduct($product)->setName('Widget');
        $line->setEnteredQuantity('3', $box, $each);

        $order = (new SalesOrder())->setCompany($company)->setOrderNumber('ORD-UOM-CASED');
        $order->addLine($line);
        $this->em->persist($order);
        $this->em->flush();

        self::assertSame(
            ['sales_order_line.quantity' => 1],
            $this->baseUnits->blockers($product),
            'the base column is the blocker; quantity_entered counts boxes and must not be named',
        );

        $this->expectException(UnitOfMeasureRefusal::class);
        $this->baseUnits->assign($product, $pairs);
    }

    private function product(string $sku): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Product ' . $sku);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function unit(string $code, string $name, string $factor = '1'): UnitOfMeasure
    {
        $unit = (new UnitOfMeasure())->setCode($code)->setName($name)->setFactorToFamilyBase($factor);
        $this->em->persist($unit);
        $this->em->flush();

        return $unit;
    }

    private function stock(ProductCore $product, int $quantity, int $received = 0): ProductInventory
    {
        $row = (new ProductInventory())->setProduct($product);
        $row->setQuantity($quantity);
        $row->setReceivedQuantity($received);
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }
}
