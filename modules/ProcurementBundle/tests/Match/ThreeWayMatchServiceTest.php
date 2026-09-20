<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Match;

use App\Entity\AppSetting;
use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Service\AppSettings;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Match\MatchLine;
use ProcurementBundle\Match\ThreeWayMatchService;

/**
 * Ordered ↔ received ↔ charged (#555).
 *
 * The plan's acceptance criterion in this area is "a three-way match with a price variance inside
 * tolerance approves; outside it, doesn't", which is the pair of tests at the bottom. The rest
 * pins down the four exception kinds and the one asymmetry worth being explicit about: quantity is
 * matched against what was RECEIVED and price against what was ORDERED.
 */
final class ThreeWayMatchServiceTest extends DoctrineIntegrationTestCase
{
    private ThreeWayMatchService $matcher;
    private Warehouse $warehouse;
    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = self::getContainer()->get(ThreeWayMatchService::class);

        // AppSettings caches its rows in a pool that lives OUTSIDE the per-test database, so a
        // tolerance set by one test is still cached for the next one — which silently turns the
        // "zero means exact" test into a re-run of the "inside tolerance" one. Same reason
        // AdminMenuDefaultSidebarCest clears it in its own _before().
        self::getContainer()->get(AppSettings::class)->clearCache();

        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->vendor = (new Vendor())->setName('Acme Supply');
        $this->em->persist($this->vendor);
        $this->em->flush();
    }

    public function testEverythingAgreeingIsApprovableWithoutAHuman(): void
    {
        $order = $this->order(ordered: '100.00', received: '100.00', unitCost: '4.5000');
        $bill = $this->bill($order, quantity: '100.00', unitCost: '4.5000');

        $report = $this->matcher->match($bill);

        self::assertTrue($report->isAutoApprovable());
        self::assertSame(0, $report->exceptionCount());
        self::assertStringContainsString('clean', $report->summary());
    }

    /** The most expensive exception on the list: paying for goods we do not have. */
    public function testBilledForMoreThanArrivedIsBilledButNotReceived(): void
    {
        $order = $this->order(ordered: '100.00', received: '80.00', unitCost: '4.5000');
        $bill = $this->bill($order, quantity: '100.00', unitCost: '4.5000');

        $report = $this->matcher->match($bill);
        $line = $report->lines[0];

        self::assertTrue($line->has(MatchLine::EXCEPTION_BILLED_NOT_RECEIVED));
        self::assertSame('20.0000', $line->quantityVariance);
        self::assertFalse($report->isAutoApprovable());
    }

    /**
     * Charged for exactly what arrived, which was less than was ordered.
     *
     * A quantity variance and NOT billed-but-not-received: we have the goods we are being charged
     * for. Conflating the two would either wave through a short-shipped invoice or flag every
     * partial delivery as a payment risk.
     */
    public function testAShortShipmentBilledCorrectlyIsAQuantityVarianceOnly(): void
    {
        $order = $this->order(ordered: '100.00', received: '80.00', unitCost: '4.5000');
        $bill = $this->bill($order, quantity: '80.00', unitCost: '4.5000');

        $line = $this->matcher->match($bill)->lines[0];

        self::assertFalse($line->has(MatchLine::EXCEPTION_BILLED_NOT_RECEIVED));
        self::assertTrue($line->has(MatchLine::EXCEPTION_QUANTITY_VARIANCE));
    }

    public function testAnOverShipmentBilledCorrectlyIsAQuantityVarianceAndNotAPaymentRisk(): void
    {
        $order = $this->order(ordered: '240.00', received: '250.00', unitCost: '4.5000');
        $bill = $this->bill($order, quantity: '250.00', unitCost: '4.5000');

        $line = $this->matcher->match($bill)->lines[0];

        self::assertFalse($line->has(MatchLine::EXCEPTION_BILLED_NOT_RECEIVED));
        self::assertTrue($line->has(MatchLine::EXCEPTION_QUANTITY_VARIANCE));
    }

    public function testChargedMoreThanQuotedIsAPriceVariance(): void
    {
        $order = $this->order(ordered: '100.00', received: '100.00', unitCost: '4.5000');
        $bill = $this->bill($order, quantity: '100.00', unitCost: '4.9500');

        $line = $this->matcher->match($bill)->lines[0];

        self::assertTrue($line->has(MatchLine::EXCEPTION_PRICE_VARIANCE));
        self::assertSame('0.4500', $line->priceVariance);
    }

    /** One-sided on purpose: a discount is not an exception anybody needs to chase. */
    public function testChargedLessThanQuotedIsNotAnException(): void
    {
        $order = $this->order(ordered: '100.00', received: '100.00', unitCost: '4.5000');
        $bill = $this->bill($order, quantity: '100.00', unitCost: '4.0000');

        $line = $this->matcher->match($bill)->lines[0];

        self::assertFalse($line->has(MatchLine::EXCEPTION_PRICE_VARIANCE));
        self::assertTrue($line->isClean());
    }

    public function testGoodsReceivedThatTheBillSaysNothingAboutAppearAsAnAccrual(): void
    {
        $order = $this->order(ordered: '100.00', received: '100.00', unitCost: '4.5000');
        // A bill against the order that charges for nothing on it at all.
        $bill = $this->emptyBill($order);

        $report = $this->matcher->match($bill);

        self::assertCount(1, $report->lines);
        self::assertTrue($report->lines[0]->has(MatchLine::EXCEPTION_RECEIVED_NOT_BILLED));
        self::assertFalse($report->isAutoApprovable());
    }

    /** A bill with no purchase order behind it is always a human's call, whatever its lines say. */
    public function testABillWithNoPurchaseOrderIsNeverAutoApprovable(): void
    {
        $bill = (new VendorBill())
            ->setBillNumber('BILL-STANDALONE')
            ->setVendor($this->vendor)
            ->setVendorName($this->vendor->getName());
        $this->em->persist($bill);
        $this->em->flush();

        $report = $this->matcher->match($bill);

        self::assertTrue($report->withoutPurchaseOrder);
        self::assertFalse($report->isAutoApprovable());
        self::assertStringContainsString('no purchase order', $report->summary());
    }

    /* ------------------------------------------------------------------------------------------
     * The duplicate-payment guard
     * ---------------------------------------------------------------------------------------- */

    public function testASecondBillCarryingAVendorInvoiceNumberAlreadyOnFileWarns(): void
    {
        $first = (new VendorBill())
            ->setBillNumber('BILL-1')
            ->setVendor($this->vendor)
            ->setVendorName($this->vendor->getName())
            ->setVendorInvoiceNo('INV-9001');
        $this->em->persist($first);

        $second = (new VendorBill())
            ->setBillNumber('BILL-2')
            ->setVendor($this->vendor)
            ->setVendorName($this->vendor->getName())
            ->setVendorInvoiceNo('INV-9001');
        $this->em->persist($second);
        $this->em->flush();

        self::assertTrue($this->matcher->hasDuplicateVendorInvoiceNumber($second));
        // It does not report itself against itself.
        self::assertTrue($this->matcher->hasDuplicateVendorInvoiceNumber($first), 'each of a duplicate pair sees the other');
        self::assertFalse($this->matcher->match($second)->isAutoApprovable());
    }

    /** Two copies of one PDF differ in case more often than they differ in substance. */
    public function testTheDuplicateGuardIgnoresCase(): void
    {
        $first = (new VendorBill())->setBillNumber('BILL-3')->setVendor($this->vendor)->setVendorName('Acme Supply')->setVendorInvoiceNo('INV-9002');
        $second = (new VendorBill())->setBillNumber('BILL-4')->setVendor($this->vendor)->setVendorName('Acme Supply')->setVendorInvoiceNo('inv-9002');
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();

        self::assertTrue($this->matcher->hasDuplicateVendorInvoiceNumber($second));
    }

    public function testTheSameNumberFromADifferentVendorIsNotADuplicate(): void
    {
        $other = (new Vendor())->setName('Beta Distribution');
        $this->em->persist($other);

        $first = (new VendorBill())->setBillNumber('BILL-5')->setVendor($this->vendor)->setVendorName('Acme Supply')->setVendorInvoiceNo('1001');
        $second = (new VendorBill())->setBillNumber('BILL-6')->setVendor($other)->setVendorName('Beta Distribution')->setVendorInvoiceNo('1001');
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();

        self::assertFalse($this->matcher->hasDuplicateVendorInvoiceNumber($second));
    }

    public function testABillWithNoVendorInvoiceNumberIsNeverADuplicate(): void
    {
        $bill = (new VendorBill())->setBillNumber('BILL-7')->setVendor($this->vendor)->setVendorName('Acme Supply');
        $this->em->persist($bill);
        $this->em->flush();

        self::assertFalse($this->matcher->hasDuplicateVendorInvoiceNumber($bill));
    }

    /* ------------------------------------------------------------------------------------------
     * Tolerances — the plan's acceptance criterion
     * ---------------------------------------------------------------------------------------- */

    public function testAPriceVarianceInsideToleranceApproves(): void
    {
        $this->setTolerance(ThreeWayMatchService::SETTING_PRICE_TOLERANCE, '10');

        $order = $this->order(ordered: '100.00', received: '100.00', unitCost: '4.5000');
        $bill = $this->bill($order, quantity: '100.00', unitCost: '4.9000'); // +8.9%

        self::assertTrue($this->matcher->match($bill)->isAutoApprovable());
    }

    public function testAPriceVarianceOutsideToleranceDoesNot(): void
    {
        $this->setTolerance(ThreeWayMatchService::SETTING_PRICE_TOLERANCE, '10');

        $order = $this->order(ordered: '100.00', received: '100.00', unitCost: '4.5000');
        $bill = $this->bill($order, quantity: '100.00', unitCost: '5.5000'); // +22%

        $report = $this->matcher->match($bill);

        self::assertFalse($report->isAutoApprovable());
        self::assertTrue($report->lines[0]->has(MatchLine::EXCEPTION_PRICE_VARIANCE));
    }

    /** Zero is the shipped default, and zero must mean exact rather than "roughly". */
    public function testTheDefaultToleranceIsExact(): void
    {
        $order = $this->order(ordered: '100.00', received: '100.00', unitCost: '4.5000');
        $bill = $this->bill($order, quantity: '100.00', unitCost: '4.5001');

        self::assertFalse($this->matcher->match($bill)->isAutoApprovable());
        self::assertSame('0', $this->matcher->match($bill)->priceTolerancePercent);
    }

    public function testAQuantityVarianceInsideToleranceApproves(): void
    {
        $this->setTolerance(ThreeWayMatchService::SETTING_QUANTITY_TOLERANCE, '5');

        $order = $this->order(ordered: '100.00', received: '103.00', unitCost: '4.5000');
        $bill = $this->bill($order, quantity: '103.00', unitCost: '4.5000');

        self::assertTrue($this->matcher->match($bill)->isAutoApprovable());
    }

    /* ------------------------------------------------------------------------------------------
     * Fixtures
     * ---------------------------------------------------------------------------------------- */

    private function setTolerance(string $key, string $percent): void
    {
        $setting = (new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($percent);
        $this->em->persist($setting);
        $this->em->flush();

        self::getContainer()->get(AppSettings::class)->clearCache();
    }

    private function order(string $ordered, string $received, string $unitCost): PurchaseOrder
    {
        $order = (new PurchaseOrder())
            ->setPoNumber('PO-' . random_int(100000, 999999))
            ->setVendor($this->vendor)
            ->deriveTaxProvinceFrom($this->warehouse);
        $this->em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setName('Widget')
            ->setSku('W-1')
            ->setQuantityOrdered($ordered)
            ->setUnitCost($unitCost)
            ->setSubtotal('0.00');
        $line->setQuantityReceived($received);

        $order->addLine($line);
        $this->em->persist($line);
        $this->em->flush();

        return $order;
    }

    private function bill(PurchaseOrder $order, string $quantity, string $unitCost): VendorBill
    {
        $bill = $this->emptyBill($order);

        $line = (new VendorBillLine())
            ->setPurchaseOrderLine($order->getLines()->first())
            ->setName('Widget')
            ->setSku('W-1')
            ->setQuantity($quantity)
            ->setUnitCost($unitCost)
            ->setSubtotal('0.00');

        $bill->addLine($line);
        $this->em->persist($line);
        $bill->recalculateTotals();
        $this->em->flush();

        return $bill;
    }

    private function emptyBill(PurchaseOrder $order): VendorBill
    {
        $bill = (new VendorBill())
            ->setBillNumber('BILL-' . random_int(100000, 999999))
            ->setVendor($this->vendor)
            ->setVendorName($this->vendor->getName())
            ->setPurchaseOrder($order);
        $this->em->persist($bill);
        $this->em->flush();

        return $bill;
    }
}
