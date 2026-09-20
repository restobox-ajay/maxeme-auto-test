<?php

declare(strict_types=1);

namespace App\Tests\Service\Uom;

use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\UnitOfMeasure;
use App\Service\Uom\UnitOfMeasureRefusal;
use App\Service\Uom\UnitOfMeasureService;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * **A unit freezes once something is denominated in it** (#659).
 *
 * The refusal ported from `ProductPackagingUnitService` when #659 moved the ratio onto the global
 * table. The hazard moved with it and got worse: a per-product rung could only
 * restate one product's documents, and a shared unit restates every product that names it.
 *
 * Every assertion here re-reads the row from the database after the refusal, because "the service
 * threw" and "the service threw and wrote nothing anyway" are different claims and only the second
 * one is the guarantee.
 */
final class UnitOfMeasureServiceTest extends DoctrineIntegrationTestCase
{
    private UnitOfMeasureService $units;

    protected function setUp(): void
    {
        parent::setUp();

        $this->units = self::getContainer()->get(UnitOfMeasureService::class);
    }

    public function testAUnitIsAddedWithItsRatioAndItsFamily(): void
    {
        $unit = $this->units->add('BOX-12', 'Box of 12', UnitOfMeasure::FAMILY_QUANTITY, '12', '1');

        $this->em->clear();
        $reread = $this->em->find(UnitOfMeasure::class, (int) $unit->getId());

        self::assertInstanceOf(UnitOfMeasure::class, $reread);
        self::assertSame('BOX-12', $reread->getCode());
        self::assertSame('12.000000', $reread->getFactorToFamilyBase());
        self::assertSame(UnitOfMeasure::FAMILY_QUANTITY, $reread->getFamily());
    }

    /**
     * `Box-12` and `Box-24` are two terms, not one term edited twice — the whole shape of the
     * NetSuite model this issue adopts.
     */
    public function testBoxTwelveAndBoxTwentyFourAreTwoDistinctTerms(): void
    {
        $twelve = $this->units->add('BOX-12', 'Box of 12', UnitOfMeasure::FAMILY_QUANTITY, '12', '1');
        $twentyFour = $this->units->add('BOX-24', 'Box of 24', UnitOfMeasure::FAMILY_QUANTITY, '24', '1');

        self::assertNotSame((int) $twelve->getId(), (int) $twentyFour->getId());
        self::assertSame('12.000000', $twelve->getFactorToFamilyBase());
        self::assertSame('24.000000', $twentyFour->getFactorToFamilyBase());
    }

    /**
     * The sweep's subjects are discovered, and this pins what it discovers.
     *
     * A guard that widens silently is a guard nobody notices has stopped covering something, so the
     * list is asserted rather than merely counted — and a new document type gaining a unit column
     * fails HERE, naming the column, instead of passing while the freeze quietly skips it.
     */
    public function testTheReferenceSweepFindsEveryColumnThatCanPointAtAUnit(): void
    {
        self::assertSame([
            // Step 3. Fourteen document and pick tables, discovered because they declare the
            // association and for no other reason — not one line of the service changed to find
            // them, exactly as not one line changed when step 2 added the two below.
            'cart_item.unit_id',
            'credit_memo_line.unit_id',
            'debit_memo_line.unit_id',
            'estimate_line.unit_id',
            'goods_receipt_line.unit_id',
            'invoice_line.unit_id',
            'pick_task.unit_id',
            // Step 2: a product listing a unit as available is promising that lines may be written
            // in it, and the ratio is what those lines will be resolved through. Unlike a document
            // line this reference can be REMOVED — untick the box and the unit is editable again —
            // so the freeze it causes is recoverable, which is why it is swept rather than excused.
            'product_available_unit.unit_id',
            // Step 2: the unit a line entry opens on.
            'product_core.default_unit_id',
            // Step 1: a product's base unit — every quantity of that product is counted in it.
            'product_core.unit_id',
            'purchase_order_line.unit_id',
            'rfq_line.unit_id',
            'sales_order_line.unit_id',
            'sales_return_line.unit_id',
            'transfer_order_line.unit_id',
            'vendor_bill_line.unit_id',
            'vendor_return_line.unit_id',
        ], $this->units->referencingColumns());
    }

    /**
     * A product declaring the unit as its base freezes the ratio.
     *
     * This is a real reference and not a stand-in: `product_inventory.quantity` for that product is
     * a number OF this unit, so restating the unit's factor restates the stock figure without
     * touching the row that holds it.
     */
    public function testAUnitAProductIsCountedInCannotBeRestated(): void
    {
        $unit = $this->units->add('KG-TEST', 'Kilogram', UnitOfMeasure::FAMILY_WEIGHT, '1000', '0.001');
        $this->product('FREEZE-1', $unit);

        self::assertSame(['product_core.unit_id' => 1], $this->units->referenceCounts($unit));

        try {
            $this->units->update($unit, 'KG-TEST', 'Kilogram', UnitOfMeasure::FAMILY_WEIGHT, '2000', '0.001');
            self::fail('a unit a product is counted in must not have its ratio changed');
        } catch (UnitOfMeasureRefusal $refusal) {
            self::assertStringContainsString('product_core.unit_id', $refusal->getMessage());
            self::assertStringContainsString('(1)', $refusal->getMessage());
        }

        $this->em->clear();
        $reread = $this->em->find(UnitOfMeasure::class, (int) $unit->getId());

        self::assertInstanceOf(UnitOfMeasure::class, $reread);
        self::assertSame('1000.000000', $reread->getFactorToFamilyBase(), 'the refusal must not have written anyway');
    }

    /** Changing the family is the same restatement wearing a different face: a different base. */
    public function testAReferencedUnitCannotChangeFamily(): void
    {
        $unit = $this->units->add('FAM-1', 'Litre', UnitOfMeasure::FAMILY_VOLUME, '1', '0.001');
        $this->product('FREEZE-FAMILY', $unit);

        $this->expectException(UnitOfMeasureRefusal::class);
        $this->units->update($unit, 'FAM-1', 'Litre', UnitOfMeasure::FAMILY_WEIGHT, '1', '0.001');
    }

    /** And the code, because it is what an issued document prints. */
    public function testAReferencedUnitCannotChangeItsCode(): void
    {
        $unit = $this->units->add('CODE-1', 'Crate', UnitOfMeasure::FAMILY_QUANTITY, '6', '1');
        $this->product('FREEZE-CODE', $unit);

        try {
            $this->units->update($unit, 'CODE-2', 'Crate', UnitOfMeasure::FAMILY_QUANTITY, '6', '1');
            self::fail('the printed code of a referenced unit must be frozen');
        } catch (UnitOfMeasureRefusal $refusal) {
            self::assertStringContainsString('product_core.unit_id', $refusal->getMessage());
        }

        $this->em->clear();
        $reread = $this->em->find(UnitOfMeasure::class, (int) $unit->getId());
        self::assertInstanceOf(UnitOfMeasure::class, $reread);
        self::assertSame('CODE-1', $reread->getCode());
    }

    /**
     * The positive control for the three refusals above: what is NOT a restatement still saves.
     *
     * A name is a description nobody computes with, and a rounding precision gates what may be typed
     * next rather than restating anything already stored. Refusing these would make a typo permanent
     * on the first unit anybody used.
     */
    public function testAReferencedUnitCanStillHaveItsNameAndPrecisionCorrected(): void
    {
        $unit = $this->units->add('EACH-1', 'Eachh', UnitOfMeasure::FAMILY_QUANTITY, '1', '1');
        $this->product('FREEZE-NAME', $unit);

        $this->units->update($unit, 'EACH-1', 'Each', UnitOfMeasure::FAMILY_QUANTITY, '1', '0.001');

        $this->em->clear();
        $reread = $this->em->find(UnitOfMeasure::class, (int) $unit->getId());

        self::assertInstanceOf(UnitOfMeasure::class, $reread);
        self::assertSame('Each', $reread->getName());
        self::assertSame('0.001000', $reread->getRoundingPrecision());
        self::assertSame('1.000000', $reread->getFactorToFamilyBase(), 'and the ratio is untouched');
    }

    /**
     * Re-saving the edit form without editing anything is not a restatement.
     *
     * `12`, `12.0` and `12.000000` are one number, and an admin who opened the panel to fix a
     * spelling would otherwise be refused for pressing Save.
     */
    public function testResubmittingTheSameValuesIsNotARestatement(): void
    {
        $unit = $this->units->add('SAME-1', 'Box of 12', UnitOfMeasure::FAMILY_QUANTITY, '12.000000', '1');
        $this->product('FREEZE-SAME', $unit);

        self::assertFalse($this->units->restates($unit, 'same-1', UnitOfMeasure::FAMILY_QUANTITY, '12'));

        $this->units->update($unit, 'SAME-1', 'Box of twelve', UnitOfMeasure::FAMILY_QUANTITY, '12', '1');

        $this->em->clear();
        $reread = $this->em->find(UnitOfMeasure::class, (int) $unit->getId());
        self::assertInstanceOf(UnitOfMeasure::class, $reread);
        self::assertSame('Box of twelve', $reread->getName());
    }

    public function testAReferencedUnitCannotBeDeleted(): void
    {
        $unit = $this->units->add('DEL-1', 'Bag', UnitOfMeasure::FAMILY_QUANTITY, '50', '1');
        $this->product('FREEZE-DELETE', $unit);
        $id = (int) $unit->getId();

        try {
            $this->units->delete($unit);
            self::fail('a unit a product is counted in must not be deletable');
        } catch (UnitOfMeasureRefusal $refusal) {
            self::assertStringContainsString('product_core.unit_id', $refusal->getMessage());
        }

        $this->em->clear();
        self::assertInstanceOf(UnitOfMeasure::class, $this->em->find(UnitOfMeasure::class, $id));
    }

    /**
     * And the guard bites on a real document, which is the case the rule was written for.
     *
     * The reference the owner's ruling names: an order says 3 BOX-12, so twelve is now part of what
     * that order means, and editing it would restate the document by a factor of anything without
     * touching a row of it. Ported from `ProductPackagingUnitServiceTest` when #659 moved the
     * fourteen document columns onto the global table — the rule did not change, only which table it
     * protects, and the sweep found the new column without being told about it.
     */
    public function testAnOrderLineDenominatedInAUnitFreezesIt(): void
    {
        $each = $this->units->add('DOC-EA', 'Each', UnitOfMeasure::FAMILY_QUANTITY, '1', '1');
        $box = $this->units->add('DOC-BOX-12', 'Box of 12', UnitOfMeasure::FAMILY_QUANTITY, '12', '1');
        $product = $this->product('DOC-REF-1', $each);

        $company = (new Company())->setName('Acme Supplies')->setCode('ACME-REF');
        $this->em->persist($company);

        $line = (new SalesOrderLine())->setProduct($product)->setName('Widget');
        $line->setEnteredQuantity('3', $box, $each);

        $order = (new SalesOrder())->setCompany($company)->setOrderNumber('ORD-REF-1');
        $order->addLine($line);
        $this->em->persist($order);
        $this->em->flush();

        self::assertSame(['sales_order_line.unit_id' => 1], $this->units->referenceCounts($box));

        try {
            $this->units->update($box, 'DOC-BOX-12', 'Box of 12', UnitOfMeasure::FAMILY_QUANTITY, '24', '1');
            self::fail('a unit an order is denominated in must not be restated');
        } catch (UnitOfMeasureRefusal $refusal) {
            self::assertStringContainsString('sales_order_line.unit_id', $refusal->getMessage());
        }

        $this->em->clear();
        $reread = $this->em->find(UnitOfMeasure::class, (int) $box->getId());

        self::assertInstanceOf(UnitOfMeasure::class, $reread);
        self::assertSame('12.000000', $reread->getFactorToFamilyBase(), 'the refusal must not have written anyway');

        $rereadLine = $this->em->find(SalesOrderLine::class, (int) $line->getId());
        self::assertInstanceOf(SalesOrderLine::class, $rereadLine);
        self::assertSame(36.0, (float) $rereadLine->getQuantityBase(), 'and the order still means 36');
    }

    /** The positive control for the delete refusal. */
    public function testAnUnreferencedUnitIsDeleted(): void
    {
        $unit = $this->units->add('DEL-2', 'Pallet', UnitOfMeasure::FAMILY_QUANTITY, '240', '1');
        $id = (int) $unit->getId();

        $this->units->delete($unit);

        $this->em->clear();
        self::assertNull($this->em->find(UnitOfMeasure::class, $id));
    }

    /**
     * Freezing one unit leaves every other alone — the row that must NOT have changed.
     *
     * A shared table is exactly where a guard that resolved the wrong row would do the most damage,
     * and it is invisible from the unit being edited.
     */
    public function testRestatingOneUnitLeavesTheOthersAlone(): void
    {
        $control = $this->units->add('CTRL-1', 'Control', UnitOfMeasure::FAMILY_QUANTITY, '12', '1');
        $controlId = (int) $control->getId();

        $other = $this->units->add('OTHER-1', 'Other', UnitOfMeasure::FAMILY_QUANTITY, '6', '1');
        $this->units->update($other, 'OTHER-1', 'Other', UnitOfMeasure::FAMILY_QUANTITY, '8', '1');

        $this->em->clear();
        $reread = $this->em->find(UnitOfMeasure::class, $controlId);

        self::assertInstanceOf(UnitOfMeasure::class, $reread);
        self::assertSame('12.000000', $reread->getFactorToFamilyBase());
        self::assertSame('CTRL-1', $reread->getCode());
    }

    public function testTwoUnitsCannotShareACode(): void
    {
        $this->units->add('DUP-1', 'First', UnitOfMeasure::FAMILY_QUANTITY, '1', '1');

        $this->expectException(UnitOfMeasureRefusal::class);
        $this->expectExceptionMessageMatches('/already exists/');

        $this->units->add('dup-1', 'Second', UnitOfMeasure::FAMILY_QUANTITY, '1', '1');
    }

    public function testAFactorOfZeroOrLessIsNotARatio(): void
    {
        $this->expectException(UnitOfMeasureRefusal::class);
        $this->expectExceptionMessageMatches('/greater than zero/');

        $this->units->add('ZERO-1', 'Zero', UnitOfMeasure::FAMILY_QUANTITY, '0', '1');
    }

    /**
     * The batched counts the list screen renders must say exactly what the guard says.
     *
     * A screen printing "referenced by nothing" for a unit the service then refuses to edit would be
     * worse than printing nothing at all.
     */
    public function testTheBatchedCountsAgreeWithThePerRowOnes(): void
    {
        $used = $this->units->add('BATCH-USED', 'Used', UnitOfMeasure::FAMILY_QUANTITY, '4', '1');
        $free = $this->units->add('BATCH-FREE', 'Free', UnitOfMeasure::FAMILY_QUANTITY, '5', '1');
        $this->product('BATCH-PRODUCT', $used);

        $batched = $this->units->referenceCountsForPage([$used, $free]);

        self::assertSame(['product_core.unit_id' => 1], $batched[(int) $used->getId()]);
        self::assertSame([], $batched[(int) $free->getId()]);
        self::assertSame($this->units->referenceCounts($used), $batched[(int) $used->getId()]);
        self::assertSame($this->units->referenceCounts($free), $batched[(int) $free->getId()]);
    }

    private function product(string $sku, ?UnitOfMeasure $unit = null): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Product ' . $sku);
        if ($unit instanceof UnitOfMeasure) {
            $product->setBaseUnit($unit);
        }
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
