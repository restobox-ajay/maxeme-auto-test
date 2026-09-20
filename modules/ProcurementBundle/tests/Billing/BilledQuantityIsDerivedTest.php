<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Billing;

use App\Entity\UnitOfMeasure;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\TestCase;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Status\ProcurementStatusVocabularyProvider;

/**
 * How much of a purchase order line has been billed — derived from the bill lines, in BASE units.
 *
 * #658 asked for a `quantityBilled` COLUMN. This is the argument for the method instead, pinned:
 * the figure answers from the bill lines themselves, so there is nothing to keep in step and
 * nothing that can drift. `PurchaseOrderLine`'s docblock carries the full reasoning.
 *
 * The three rules worth a test each:
 *
 *  1. base figures count, not entered ones — 40 Cases of a 12-pack is 480 against the line;
 *  2. a DRAFT bill still claims quantity (it is a claim on the line), while it does NOT count as a
 *     charge against the business (the accrual screen's question);
 *  3. a VOID bill claims nothing at all.
 */
final class BilledQuantityIsDerivedTest extends TestCase
{
    /** No kernel here, so the vocabulary registry is primed by hand — see `PurchaseDocumentActionsTest`. */
    protected function setUp(): void
    {
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new ProcurementStatusVocabularyProvider()]));
    }

    protected function tearDown(): void
    {
        StatusVocabularyRegistry::reset();
    }

    private function order(string $ordered = '10.0000'): PurchaseOrderLine
    {
        $vendor = (new Vendor())->setName('Acme Supply');

        $order = (new PurchaseOrder())
            ->setPoNumber('PO-1')
            ->setVendor($vendor)
            ->setVendorName('Acme Supply')
            ->deriveTaxProvinceFrom(new Warehouse());

        $line = (new PurchaseOrderLine())
            ->setName('Widget')
            ->setSku('WID-1')
            ->setQuantityOrdered($ordered)
            ->setUnitCost('5.0000')
            ->setSubtotal('50.0000');
        $order->addLine($line);

        return $line;
    }

    private function bill(PurchaseOrderLine $orderLine, string $quantity): VendorBill
    {
        $bill = (new VendorBill())
            ->setBillNumber('BILL-' . uniqid())
            ->setVendor($orderLine->getPurchaseOrder()->getVendor())
            ->setVendorName('Acme Supply');

        $line = (new VendorBillLine())
            ->setName('Widget')
            ->setQuantity($quantity)
            ->setUnitCost('5.0000')
            ->setSubtotal('0.0000');
        $line->setPurchaseOrderLine($orderLine);
        $bill->addLine($line);

        return $bill;
    }

    public function testNothingBilledLeavesTheWholeLineBillable(): void
    {
        $line = $this->order();

        self::assertSame('0.0000', $line->getQuantityBilled());
        self::assertSame('10.0000', $line->getQuantityUnbilled());
        self::assertFalse($line->isFullyBilled());
    }

    public function testABillDrawsDownTheLineItNames(): void
    {
        $line = $this->order();
        $this->bill($line, '4.0000');

        self::assertSame('4.0000', $line->getQuantityBilled());
        self::assertSame('6.0000', $line->getQuantityUnbilled());
        self::assertFalse($line->isFullyBilled());
    }

    public function testTwoBillsAddUpAndFillTheLine(): void
    {
        $line = $this->order();
        $this->bill($line, '4.0000');
        $this->bill($line, '6.0000');

        self::assertSame('10.0000', $line->getQuantityBilled());
        self::assertSame('0.0000', $line->getQuantityUnbilled());
        self::assertTrue($line->isFullyBilled());
    }

    /**
     * The bill being saved is excluded from its own remainder, or an unchanged draft could not be
     * saved a second time.
     */
    public function testABillIsNotMeasuredAgainstItself(): void
    {
        $line = $this->order();
        $bill = $this->bill($line, '10.0000');

        self::assertSame('10.0000', $line->getQuantityBilled(), 'without the exclusion the line is full');
        self::assertSame('0.0000', $line->getQuantityBilled($bill), 'excluding the bill leaves nothing of its own claim');
        self::assertSame('10.0000', $line->getQuantityUnbilled($bill), 'so the whole line is available to that bill again');
    }

    /**
     * A draft CLAIMS the quantity (so a second bill cannot take it) and is NOT a charge (so the
     * accrual screen keeps reporting the goods as unbilled). Two questions, two answers, one
     * collection.
     */
    public function testADraftClaimsQuantityButIsNotYetACharge(): void
    {
        $line = $this->order();
        $this->bill($line, '10.0000'); // born Draft

        self::assertSame('10.0000', $line->getQuantityBilled(), 'a draft holds the quantity it names');
        self::assertSame('0.0000', $line->getQuantityCharged(), 'a draft is not a charge against the business');
        self::assertSame('0.0000', $line->getQuantityUnbilled(), 'so nothing is left for a second bill to take');
    }

    public function testAnApprovedBillIsBothAClaimAndACharge(): void
    {
        $line = $this->order();
        $bill = $this->bill($line, '10.0000');
        $bill->approve(DocumentActor::system());

        self::assertSame('10.0000', $line->getQuantityBilled());
        self::assertSame('10.0000', $line->getQuantityCharged());
    }

    public function testAVoidBillClaimsNothingAndChargesNothing(): void
    {
        $line = $this->order();
        $bill = $this->bill($line, '10.0000');
        $bill->setStatus('Void', DocumentActor::system(), 'Bill voided: Entered twice.');

        self::assertSame('0.0000', $line->getQuantityBilled(), 'a withdrawn bill holds nothing');
        self::assertSame('0.0000', $line->getQuantityCharged());
        self::assertSame('10.0000', $line->getQuantityUnbilled(), 'the line is billable again');
    }

    /**
     * Quantities are BASE figures under UoM phase 3: bill 40 Cases of a 12-pack and the figure that
     * counts against the purchase order line is 480.
     *
     * `setEnteredQuantity()` resolves the pair into the base column, which is the column this reads
     * — so a bill entered in cases and a purchase order written in eaches cannot talk past each
     * other.
     */
    public function testTheFigureThatCountsIsTheBaseOneNotTheEnteredOne(): void
    {
        $line = $this->order('600.0000');

        // #659 retired `product_packaging_unit`: the twelve is now a factor on a global
        // `unit_of_measure` row, read against the product's base unit rather than off a per-product
        // rung. Same arithmetic, same claim — 40 x (12 / 1) = 480.
        $each = (new UnitOfMeasure())
            ->setCode('EA-BILL')
            ->setName('Each')
            ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
            ->setFactorToFamilyBase('1');
        $case = (new UnitOfMeasure())
            ->setCode('CASE-12-BILL')
            ->setName('Case of 12')
            ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
            ->setFactorToFamilyBase('12');

        $bill = (new VendorBill())
            ->setBillNumber('BILL-UOM')
            ->setVendor($line->getPurchaseOrder()->getVendor())
            ->setVendorName('Acme Supply');

        $billLine = (new VendorBillLine())->setName('Widget')->setUnitCost('5.0000')->setSubtotal('0.0000');
        $billLine->setPurchaseOrderLine($line);
        $billLine->setEnteredQuantity('40', $case, $each);
        $bill->addLine($billLine);

        self::assertSame('40.0000', $billLine->getQuantityEntered(), 'the line remembers what was typed');
        self::assertSame('480.0000', $billLine->getQuantityBase(), 'and resolves it to base units');
        self::assertSame('480.0000', $line->getQuantityBilled(), 'which is the figure the purchase order line counts');
        self::assertSame('120.0000', $line->getQuantityUnbilled());
    }
}
