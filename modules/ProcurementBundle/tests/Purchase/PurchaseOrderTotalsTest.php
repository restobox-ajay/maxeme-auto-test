<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Purchase;

use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\TestCase;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLine;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLineSnapshot;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Status\ProcurementStatusVocabularyProvider;

/**
 * `PurchaseOrder::recalculateTotals()` — the arithmetic, in isolation (#655).
 *
 * The conducted Cest drives this through the real screens and is where the behaviour is really
 * pinned. These are the cases that are awkward to reach that way and cheap to reach here: the
 * rounding rule, idempotence, and the RFQ-conversion regression below.
 *
 * No database. `recalculateTotals()` reads three things off the entity and writes three; a Doctrine
 * fixture would add setup and prove nothing extra.
 */
final class PurchaseOrderTotalsTest extends TestCase
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

    private function line(string $quantity, string $unitCost, string $subtotal, ?string $taxCode = null): PurchaseOrderLine
    {
        return (new PurchaseOrderLine())
            ->setQuantityOrdered($quantity)
            ->setUnitCost($unitCost)
            ->setSubtotal($subtotal)
            ->setTaxCode($taxCode);
    }

    /** The frozen tax-line snapshot shape, written the way PurchaseTaxBreakdown writes it. */
    private function taxLinesJson(float ...$amounts): string
    {
        $lines = [];
        $total = 0.0;
        foreach ($amounts as $index => $amount) {
            $lines[] = ['label' => 'GST ' . $index, 'rate' => 0.05, 'amount' => $amount, 'slug' => 'gst-' . $index, 'source' => 'auto-calc'];
            $total += $amount;
        }

        return (string) json_encode(['lines' => $lines, 'total' => $total, 'perLineTax' => [], 'perLineTaxLabel' => []]);
    }

    public function testTheTotalIsGoodsPlusChargesPlusTax(): void
    {
        $order = new PurchaseOrder();
        $order->addLine($this->line('4.00', '12.50', '50.00', 'G'));
        $order->addLine($this->line('2.00', '10.00', '20.00', 'E'));
        $order->setChargeLines(PurchaseChargeLineSnapshot::encode([
            new PurchaseChargeLine('freight', 'Freight', 'G', 30.0, PurchaseChargeLine::PLACEMENT_MAIN_LINE, PurchaseChargeLine::TYPE_FREIGHT),
            new PurchaseChargeLine('brokerage', 'Brokerage', 'E', 15.0),
        ]));
        $order->setTaxLines($this->taxLinesJson(2.50, 1.50));

        $order->recalculateTotals();

        self::assertSame('70.00', $order->getSubtotal(), 'subtotal is the goods alone');
        self::assertSame('4.00', $order->getTax(), 'tax is the sum of the frozen tax lines');
        self::assertSame('119.00', $order->getTotal());
    }

    /**
     * Placement decides where a row is DISPLAYED and whether it is taxed — never whether it is
     * owed. All three land in the grand total.
     */
    public function testEveryPlacementReachesTheGrandTotal(): void
    {
        $order = new PurchaseOrder();
        $order->addLine($this->line('1.00', '100.00', '100.00', 'E'));
        $order->setChargeLines(PurchaseChargeLineSnapshot::encode([
            new PurchaseChargeLine('a', 'Main', 'E', 1.0, PurchaseChargeLine::PLACEMENT_MAIN_LINE),
            new PurchaseChargeLine('b', 'Before', 'E', 2.0, PurchaseChargeLine::PLACEMENT_BEFORE_TAX_LINE),
            new PurchaseChargeLine('c', 'After', 'E', 4.0, PurchaseChargeLine::PLACEMENT_AFTER_TAX),
        ]));

        $order->recalculateTotals();

        self::assertSame('107.00', $order->getTotal());
    }

    /**
     * The rounding rule: once at the line, then the rounded lines are summed.
     *
     * Three lines whose stored subtotals each end in a half-cent-free figure, summed in whole
     * cents. The property that matters is that the document's total equals the sum of the figures
     * PRINTED on it — which is why this side sums cents rather than following
     * App\Service\SalesDocumentMoney, whose intermediates carry eight decimal places to the grand
     * total.
     */
    public function testTheTotalEqualsTheSumOfThePrintedLines(): void
    {
        $order = new PurchaseOrder();
        foreach (['33.33', '33.33', '33.34'] as $subtotal) {
            $order->addLine($this->line('1.00', $subtotal, $subtotal, 'E'));
        }

        $order->recalculateTotals();

        self::assertSame('100.00', $order->getSubtotal(), 'the three printed lines add to exactly this');
        self::assertSame('100.00', $order->getTotal());
    }

    /** Calling it twice with nothing changed writes the same three figures. */
    public function testItIsIdempotent(): void
    {
        $order = new PurchaseOrder();
        $order->addLine($this->line('3.00', '7.77', '23.31', 'G'));
        $order->setChargeLines(PurchaseChargeLineSnapshot::encode([new PurchaseChargeLine('f', 'Freight', 'G', 11.11)]));
        $order->setTaxLines($this->taxLinesJson(1.72));

        $order->recalculateTotals();
        $first = [$order->getSubtotal(), $order->getTax(), $order->getTotal()];
        $order->recalculateTotals();

        self::assertSame($first, [$order->getSubtotal(), $order->getTax(), $order->getTotal()]);
    }

    /** A malformed snapshot contributes no tax rather than making the document unreadable. */
    public function testAMalformedTaxSnapshotContributesNoTaxRatherThanThrowing(): void
    {
        $order = new PurchaseOrder();
        $order->addLine($this->line('1.00', '10.00', '10.00', 'E'));
        $order->setTaxLines('{not json');

        $order->recalculateTotals();

        self::assertSame('0.00', $order->getTax());
        self::assertSame('10.00', $order->getTotal());
    }

    /**
     * REGRESSION GUARD, and the reason this file exists rather than only the Cest.
     *
     * `RfqConversionService` used to carry an accepted quote's tax across as the bare `tax` scalar
     * and then call `recalculateTotals()`. That worked only while this method left `tax` alone.
     * Since #655 it derives `tax` from the frozen tax-line snapshot — so a scalar written without a
     * line behind it is silently zeroed, and a purchase order raised from a quote would go out
     * short by exactly the vendor's tax.
     *
     * The fix is that the conversion writes a manual tax LINE. This test states both halves: the
     * scalar alone does NOT survive (which is correct and is the whole design), and the line does.
     */
    public function testAQuotedTaxSurvivesOnlyWhenItIsCarriedAsALine(): void
    {
        $scalarOnly = new PurchaseOrder();
        $scalarOnly->addLine($this->line('10.00', '4.00', '40.00', 'G'));
        $scalarOnly->setTax('6.30');
        $scalarOnly->recalculateTotals();

        self::assertSame('0.00', $scalarOnly->getTax(), 'a scalar with no line behind it is not a tax this document can state');
        self::assertSame('40.00', $scalarOnly->getTotal());

        $asALine = new PurchaseOrder();
        $asALine->addLine($this->line('10.00', '4.00', '40.00', 'G'));
        $asALine->setTaxLines((string) json_encode([
            'lines' => [['label' => 'Tax (as quoted)', 'rate' => null, 'amount' => 6.30, 'slug' => 'quoted-tax', 'source' => 'manual']],
            'total' => 6.30,
            'perLineTax' => [],
            'perLineTaxLabel' => [],
        ]));
        $asALine->recalculateTotals();

        self::assertSame('6.30', $asALine->getTax(), 'the quoted figure has to reach the document as a line');
        self::assertSame('46.30', $asALine->getTotal());
    }

    /** The freight row's class is the highest across the goods, which is what the save resolves. */
    public function testTheHighestTaxClassIsTakenAcrossTheGoods(): void
    {
        $order = new PurchaseOrder();
        $order->addLine($this->line('1.00', '1.00', '1.00', 'E'));
        self::assertSame('E', $order->getHighestTaxClass());

        $order->addLine($this->line('1.00', '1.00', '1.00', 'G'));
        self::assertSame('G', $order->getHighestTaxClass());

        $order->addLine($this->line('1.00', '1.00', '1.00', 'S'));
        self::assertSame('S', $order->getHighestTaxClass(), 'S beats G beats E');
    }

    /**
     * A blank province is one state, and is normalised at the write boundary like every other.
     *
     * Through `deriveTaxProvinceFrom()` since queue item 37, because `setTaxProvince()` is gone: a
     * province is taken from the delivery warehouse's address and there is nowhere to type one. The
     * normalisation this asserts is unchanged — it moved, it did not go away, and a warehouse row
     * written by something that never normalised still lands here as a code.
     */
    public function testTheTaxProvinceIsNormalisedAndABlankBecomesNull(): void
    {
        $order = new PurchaseOrder();

        self::assertNull($order->deriveTaxProvinceFrom(self::warehouseIn(''))->getTaxProvince());
        self::assertNull($order->deriveTaxProvinceFrom(self::warehouseIn('   '))->getTaxProvince());
        self::assertSame('BC', $order->deriveTaxProvinceFrom(self::warehouseIn('bc'))->getTaxProvince());
        self::assertSame(
            'BC',
            $order->deriveTaxProvinceFrom(self::warehouseIn('British Columbia'))->getTaxProvince(),
            'a legacy display name still resolves to its code',
        );

        // And the warehouse goes with it, in the same call: the two cannot be set apart, which is
        // the whole reason the argument is a Warehouse rather than a string. The WAREHOUSE keeps
        // whatever was written to it — normalising is the document's job at its own write boundary,
        // and this asserts the order took the building as well as the code.
        $bc = self::warehouseIn('bc');
        self::assertSame($bc, $order->deriveTaxProvinceFrom($bc)->getWarehouse(), 'the warehouse the province came from');
        self::assertSame('BC', $order->getTaxProvince(), 'the order normalised what the warehouse held raw');
    }

    /**
     * The freeze lives with the DATA, not with the controller that happens to guard the edit screen.
     *
     * An issued purchase order is what the vendor is working from. Re-deriving its province
     * afterwards would restate the tax on paperwork already sent, which is the drift the column
     * exists as a snapshot to prevent.
     */
    public function testDerivingAProvinceIsRefusedOnceTheOrderHasLeftDraft(): void
    {
        $order = (new PurchaseOrder())
            ->setPoNumber('PO-FREEZE-1')
            ->setVendor(self::vendor())
            ->setVendorName('Frozen Supply')
            ->deriveTaxProvinceFrom(self::warehouseIn('BC'));
        $order->addLine($this->line('1.00', '10.00', '10.00', 'S'));
        $order->setStatus('Issued', DocumentActor::system());

        self::assertSame('Issued', $order->getStatus(), 'the order under test was not issued');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('frozen when it left Draft');

        $order->deriveTaxProvinceFrom(self::warehouseIn('AB'));
    }

    /**
     * Issuing a TAXABLE order with no province is refused, and an EXEMPT one is not.
     *
     * The second half is the decision rather than an accident: with a highest tax class of 'E' there
     * is no rate to get wrong and the answer is $0 whatever the province says, so refusing would
     * block a legitimate order to guard a calculation that was never going to run.
     */
    public function testIssuingRefusesTaxableGoodsWithNoProvinceAndLetsExemptGoodsThrough(): void
    {
        $actor = DocumentActor::system();

        $exempt = (new PurchaseOrder())->setPoNumber('PO-EXEMPT-1')->setVendor(self::vendor())->deriveTaxProvinceFrom(self::warehouseIn(''));
        $exempt->addLine($this->line('1.00', '10.00', '10.00', 'E'));
        $exempt->setStatus('Issued', $actor);
        self::assertSame('Issued', $exempt->getStatus(), 'an exempt order with no province should still be issuable');

        $taxable = (new PurchaseOrder())->setPoNumber('PO-TAXABLE-1')->setVendor(self::vendor())->deriveTaxProvinceFrom(self::warehouseIn(''));
        $taxable->addLine($this->line('1.00', '10.00', '10.00', 'S'));

        try {
            $taxable->setStatus('Issued', $actor);
            self::fail('a taxable order with no tax province was issued');
        } catch (\DomainException $e) {
            self::assertStringContainsString('taxable goods but no tax province', $e->getMessage());
            // The remedy is named, because the fix is on a different screen from the refusal.
            self::assertStringContainsString('Config > Warehouses', $e->getMessage());
        }

        self::assertSame('Draft', $taxable->getStatus(), 'the refused order changed status anyway');
    }

    private static function vendor(): Vendor
    {
        return (new Vendor())->setName('Totals Supply');
    }

    private static function warehouseIn(string $province): Warehouse
    {
        $warehouse = (new Warehouse())->setName('Test DC');
        // Set through the raw property rather than setProvince(), which normalises: the point of
        // asserting normalisation in deriveTaxProvinceFrom() is that it is safe whatever wrote the
        // warehouse row, and pre-normalising here would make the assertion vacuous.
        $reflection = new \ReflectionProperty(Warehouse::class, 'province');
        $reflection->setValue($warehouse, $province === '' ? null : $province);

        return $warehouse;
    }
}
