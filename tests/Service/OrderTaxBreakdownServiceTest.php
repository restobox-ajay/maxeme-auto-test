<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Contract\Tax\TaxCalculatorInterface;
use App\Contract\Tax\TaxContext;
use App\Contract\Tax\TaxLine;
use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Entity\AbstractDocumentAddress;
use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Repository\BundleStatusRepository;
use App\Service\OrderTaxBreakdownService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TaxBundle\Tax\TaxCalculatorResolver;

final class OrderTaxBreakdownServiceTest extends TestCase
{
    private function resolver(TaxCalculatorInterface ...$calculators): TaxCalculatorResolver
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActiveForInstance')->willReturn(true);

        return new TaxCalculatorResolver($calculators, [], $repo);
    }

    private function service(TaxCalculatorResolver $resolver, ?LoggerInterface $logger = null): OrderTaxBreakdownService
    {
        return new OrderTaxBreakdownService($resolver, $logger ?? $this->createStub(LoggerInterface::class));
    }

    public function testComputeBreakdownAppliesFlatRateToSingleLine(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $result = $service->computeBreakdown('ON', null, [0 => ['subtotal' => 100.0, 'taxCode' => 'G']], []);

        self::assertSame(5.0, $result['perLineTax'][0]);
        self::assertSame('GST', $result['perLineTaxLabel'][0]);
        self::assertCount(1, $result['lines']);
        self::assertSame(5.0, $result['lines'][0]->amount);
        self::assertSame(5.0, $result['total']);
    }

    public function testComputeBreakdownMergesSameLabelAcrossLinesAndShipping(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $result = $service->computeBreakdown(
            'ON',
            null,
            [
                0 => ['subtotal' => 100.0, 'taxCode' => 'G'],
                1 => ['subtotal' => 50.0, 'taxCode' => 'G'],
            ],
            [new FeeLine(null, 'shipping', 'Shipping (Ground)', 'G', 20.0, 'main_line', FeeLine::TYPE_SHIPPING)],
        );

        // 100*0.05 + 50*0.05 + 20*0.05 = 8.5, all merged under one 'GST' label.
        self::assertCount(1, $result['lines']);
        self::assertSame(8.5, $result['lines'][0]->amount);
        self::assertSame(8.5, $result['total']);
        self::assertSame(5.0, $result['perLineTax'][0]);
        self::assertSame(2.5, $result['perLineTax'][1]);
    }

    public function testComputeBreakdownSkipsAZeroShippingRow(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $result = $service->computeBreakdown(
            'ON',
            null,
            [0 => ['subtotal' => 100.0, 'taxCode' => 'G']],
            [new FeeLine(null, 'shipping', 'Shipping (Pickup)', 'G', 0.0, 'main_line', FeeLine::TYPE_SHIPPING)],
        );

        self::assertSame(5.0, $result['total']);
    }

    public function testComputeBreakdownUsesHighestTaxClassAcrossLinesForShipping(): void
    {
        // Calculator only taxes when the context's tax class is 'S' (the highest priority
        // class), so this only produces a tax line for shipping if resolveHighestTaxClass()
        // correctly picked 'S' out of the mixed line tax codes ('E' and 'S').
        $service = $this->service($this->resolver(new OnlyForClassTaxCalculator('S', 'PST', 0.07)));

        $result = $service->computeBreakdown(
            'BC',
            null,
            [
                0 => ['subtotal' => 100.0, 'taxCode' => 'E'],
                1 => ['subtotal' => 100.0, 'taxCode' => 'S'],
            ],
            // The row carries the class the document resolved for it — see
            // SalesDocumentChargeLines::toShippingLines(), which is where 'S' comes from.
            [new FeeLine(null, 'shipping', 'Shipping (Ground)', 'S', 50.0, 'main_line', FeeLine::TYPE_SHIPPING)],
        );

        // Line 1 (S) taxed at 7 + shipping (highest class S) taxed at 3.5 = 10.5.
        self::assertSame(10.5, $result['total']);
        self::assertSame(0.0, $result['perLineTax'][0]);
        self::assertSame('No tax', $result['perLineTaxLabel'][0]);
        self::assertSame(7.0, $result['perLineTax'][1]);
    }

    public function testComputeBreakdownSkipsExemptAndNonPositiveFeeLines(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $feeLines = [
            new FeeLine(null, 'exempt-fee', 'Exempt Fee', 'E', 10.0),
            new FeeLine(null, 'zero-fee', 'Zero Fee', 'G', 0.0),
            new FeeLine(null, 'taxable-fee', 'Taxable Fee', 'G', 20.0),
        ];

        $result = $service->computeBreakdown('ON', null, [], $feeLines);

        self::assertSame(1.0, $result['total']);
    }

    public function testFeeLineDefaultsToAGenericCalculatedFee(): void
    {
        $line = new FeeLine(null, 'eco', 'Eco Fee', 'G', 2.5);

        self::assertSame(FeeLine::TYPE_FEE, $line->type);
        self::assertSame(FeeLine::SOURCE_AUTO_CALC, $line->source);
        self::assertSame('main_line', $line->placement);
    }

    public function testStoredFeeLinesWithoutTypeOrSourceReadBackAsGenericCalculatedFees(): void
    {
        // Every line written before those fields existed was a calculator-produced fee, so the
        // absent keys must decode to exactly that rather than to an empty string.
        $lines = FeeLineSnapshot::decode(json_encode([
            ['slug' => 'legacy', 'label' => 'Legacy Fee', 'taxClass' => 'G', 'amount' => 4.0, 'placement' => 'main_line'],
        ]));

        self::assertCount(1, $lines);
        self::assertSame(FeeLine::TYPE_FEE, $lines[0]->type);
        self::assertSame(FeeLine::SOURCE_AUTO_CALC, $lines[0]->source);
    }

    public function testStoredFeeLinesRoundTripTypeAndSource(): void
    {
        $lines = FeeLineSnapshot::decode(json_encode([
            [
                'slug' => 'coupon-SAVE10', 'label' => 'Coupon SAVE10', 'taxClass' => 'S',
                'amount' => -10.0, 'placement' => 'main_line',
                'type' => FeeLine::TYPE_DISCOUNT, 'source' => FeeLine::SOURCE_MANUAL,
            ],
        ]));

        self::assertSame(FeeLine::TYPE_DISCOUNT, $lines[0]->type);
        self::assertSame(FeeLine::SOURCE_MANUAL, $lines[0]->source);
    }

    /**
     * An uncovered jurisdiction is still $0, and now SAYS so (queue item 66).
     *
     * This case used to assert the opposite — no lines and a label reading "No tax" — which is the
     * defect stated as a test: "No tax" is the same words an exempt line gets and a covered
     * province charging nothing gets, so the document could not be read. The figure was never the
     * problem and has not changed; what changed is that the zero now carries its reason.
     */
    public function testAnUncoveredJurisdictionIsZeroTaxAndSaysSoOnTheDocument(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = $this->service($this->resolver(), $logger);

        $result = $service->computeBreakdown('CA', null, [0 => ['subtotal' => 100.0, 'taxCode' => 'G']], []);

        self::assertCount(1, $result['lines']);
        self::assertSame('No tax rule for California (United States)', $result['lines'][0]->label);
        self::assertSame('tax-jurisdiction-not-covered', $result['lines'][0]->slug);
        self::assertNull($result['lines'][0]->rate, 'there is no rate, so none is claimed');
        self::assertSame(0.0, $result['lines'][0]->amount, 'and it adds nothing to the total');
        self::assertSame(0.0, $result['perLineTax'][0]);
        self::assertSame('No tax rule for California (United States)', $result['perLineTaxLabel'][0]);
        self::assertSame(0.0, $result['total']);
    }

    /**
     * One notice per DOCUMENT, not one per line. The notice is SOURCE_AUTO_CALC precisely so the
     * merge keys it by slug; a manual line is never merged and would produce one per row.
     */
    public function testTheUncoveredJurisdictionNoticeIsStatedOncePerDocument(): void
    {
        $service = $this->service($this->resolver());

        $result = $service->computeBreakdown('CA', null, [
            0 => ['subtotal' => 100.0, 'taxCode' => 'G'],
            1 => ['subtotal' => 50.0, 'taxCode' => 'S'],
            2 => ['subtotal' => 25.0, 'taxCode' => 'G'],
        ], []);

        self::assertCount(1, $result['lines']);
        self::assertSame(0.0, $result['total']);
    }

    /**
     * A document with NO province gets no notice at all, and that is the narrowing rather than a
     * gap.
     *
     * `TaxJurisdictionNotCovered::describe('')` has nothing to name and hands back the empty string,
     * so the notice read "No tax rule for " — with nothing after "for" — and was FROZEN into
     * `tax_lines`, reaching the detail screens, the print output and the customer's copy. "No tax
     * rule for BC" is a statement; "No tax rule for ''" is not a statement about anything. A
     * document with no province has not had its tax worked out at all, which the totals boxes
     * already say in their own words.
     *
     * The two assertions that matter are paired here deliberately: no line for the blank province,
     * and the full sentence still produced for a real one. The case directly above is the same
     * pairing seen from the other side.
     */
    public function testADocumentWithNoProvinceGetsNoNoticeWhileARealOneStillDoes(): void
    {
        // No log line either: a draft nobody has given a province is an ordinary state of a draft,
        // not an incident for somebody to look at.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $service = $this->service($this->resolver(), $logger);

        $result = $service->computeBreakdown('', null, [0 => ['subtotal' => 100.0, 'taxCode' => 'G']], []);

        self::assertSame([], $result['lines'], 'a province-less document states nothing about a jurisdiction it has not got');
        self::assertSame('No tax', $result['perLineTaxLabel'][0]);
        self::assertSame(0.0, $result['perLineTax'][0]);
        self::assertSame(0.0, $result['total']);
        self::assertNull(OrderTaxBreakdownService::notCoveredLine(''), 'the decision lives in the one place that words the notice');
        self::assertNull(OrderTaxBreakdownService::notCoveredLine('   '), 'a province of whitespace is no province');

        // The positive control, on the same method: a real jurisdiction nobody covers is still named
        // in full. Narrowed, not deleted.
        self::assertSame(
            'No tax rule for California (United States)',
            OrderTaxBreakdownService::notCoveredLine('CA')?->label,
        );
    }

    /**
     * An EXEMPT line gets no notice: there is no rate to get wrong, so there is nothing missing.
     * The same line PurchaseOrder::assertTaxProvinceKnownIfTaxable() draws for the same reason.
     */
    public function testAnExemptLineInAnUncoveredJurisdictionIsNotAnnounced(): void
    {
        $service = $this->service($this->resolver());

        $result = $service->computeBreakdown('CA', null, [0 => ['subtotal' => 100.0, 'taxCode' => 'E']], []);

        self::assertSame([], $result['lines']);
        self::assertSame('No tax', $result['perLineTaxLabel'][0], 'which is true of an exempt line in any jurisdiction');
        self::assertSame(0.0, $result['total']);
    }

    /**
     * A calculator that IS installed and fell over is a different thing from a jurisdiction nobody
     * covers, and still behaves exactly as it did: logged, $0, no statement invented about the
     * jurisdiction — because there is nothing true to say about it.
     */
    public function testACalculatorThatThrowsIsStillJustLoggedAndTreatedAsZero(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = $this->service($this->resolver(new ThrowingTaxCalculator()), $logger);

        $result = $service->computeBreakdown('ON', null, [0 => ['subtotal' => 100.0, 'taxCode' => 'G']], []);

        self::assertSame([], $result['lines']);
        self::assertSame('No tax', $result['perLineTaxLabel'][0]);
        self::assertSame(0.0, $result['total']);
    }

    /**
     * An unpriced quote line used to reach the customer labelled "No tax" with a Total Tax of
     * $0.00, sitting next to a Price column that correctly read TBD — the row asserting the item
     * was non-taxable when all that was true is that nobody had priced it (#255). The null
     * subtotal was being flattened to 0.0 before the label was chosen, and a 0.0 subtotal makes no
     * tax lines, so the two states were indistinguishable by the time the label was picked.
     */
    public function testAnUnpricedLineIsLabelledTbdRatherThanNoTax(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $result = $service->computeBreakdown(
            'ON',
            null,
            [
                0 => ['subtotal' => null, 'taxCode' => 'G'],
                1 => ['subtotal' => 100.0, 'taxCode' => 'G'],
            ],
            [],
        );

        self::assertSame('TBD', $result['perLineTaxLabel'][0]);
        self::assertNull($result['perLineTax'][0], 'an unpriced line has no tax figure, not a zero one');

        // The priced sibling is unaffected — a half-priced quote still shows real tax on the rows
        // that have prices, and the document total counts only those.
        self::assertSame('GST', $result['perLineTaxLabel'][1]);
        self::assertSame(5.0, $result['perLineTax'][1]);
        self::assertSame(5.0, $result['total']);
    }

    /**
     * The distinction the fix turns on: a line that really is free and really is non-taxable still
     * says "No tax". Only the null becomes TBD.
     */
    public function testAGenuinelyZeroLineStillReadsNoTax(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $result = $service->computeBreakdown('ON', null, [0 => ['subtotal' => 0.0, 'taxCode' => 'G']], []);

        self::assertSame('No tax', $result['perLineTaxLabel'][0]);
        self::assertSame(0.0, $result['perLineTax'][0]);
    }

    /** The null has to survive the document unpacking too, not just computeBreakdown() itself. */
    public function testBuildForOrderKeepsAnUnpricedEstimateLineDistinctFromAFreeOne(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $estimate = (new Estimate())->setCompany(new Company())
            ->setShippingAddressFrom((new CompanyAddress())->setProvince('ON'));
        $estimate->addLine((new EstimateLine())->setName('Not priced yet')->setTaxCode('G'));
        $estimate->addLine((new EstimateLine())->setName('On the house')->setSubtotal('0.00')->setTaxCode('G'));

        $result = $service->buildForOrder($estimate);

        self::assertSame('TBD', $result['perLineTaxLabel'][0]);
        self::assertNull($result['perLineTax'][0]);
        self::assertSame('No tax', $result['perLineTaxLabel'][1]);
        self::assertSame(0.0, $result['perLineTax'][1]);
    }

    /**
     * The labels are frozen into tax_lines JSON and carried into the converted order, so a TBD
     * label must not outlive the pricing state that produced it. It doesn't: conversion refuses a
     * quote that isn't fully priced, and the save that prices the last line recomputes the whole
     * breakdown — which is what this asserts, by re-running the breakdown with the price filled in.
     */
    public function testTheTbdLabelIsReplacedOnceTheLineIsPriced(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $estimate = (new Estimate())->setCompany(new Company())
            ->setShippingAddressFrom((new CompanyAddress())->setProvince('ON'));
        $line = (new EstimateLine())->setName('Widget')->setTaxCode('G');
        $estimate->addLine($line);

        self::assertSame('TBD', $service->buildForOrder($estimate)['perLineTaxLabel'][0]);

        $line->setPrice('100.00')->setSubtotal('100.00');

        $priced = $service->buildForOrder($estimate);
        self::assertSame('GST', $priced['perLineTaxLabel'][0]);
        self::assertSame(5.0, $priced['perLineTax'][0]);

        // And that is what gets frozen — the snapshot the converted order inherits carries the real
        // label, never the TBD-era placeholder.
        $frozen = $service->tryDecodeTaxLinesJson($service->toJson($priced));
        self::assertSame(['GST'], array_values($frozen['perLineTaxLabel']));
    }

    public function testToJsonThenTryDecodeTaxLinesJsonRoundTrips(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $breakdown = $service->computeBreakdown('ON', null, [0 => ['subtotal' => 100.0, 'taxCode' => 'G']], []);
        $decoded = $service->tryDecodeTaxLinesJson($service->toJson($breakdown));

        self::assertSame($breakdown['total'], $decoded['total']);
        self::assertSame($breakdown['perLineTax'], $decoded['perLineTax']);
        self::assertSame($breakdown['perLineTaxLabel'], $decoded['perLineTaxLabel']);
        self::assertCount(1, $decoded['lines']);
        self::assertSame('GST', $decoded['lines'][0]->label);
        self::assertSame(5.0, $decoded['lines'][0]->amount);
    }

    public function testManualTaxLineRoundTripsWithNoRateAndItsOwnSlug(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $breakdown = $service->withManualTaxLines(
            $service->computeBreakdown('ON', null, [0 => ['subtotal' => 100.0, 'taxCode' => 'G']], []),
            $service->manualTaxLinesFromCharges([['label' => 'PST adjustment', 'amount' => 3.5, 'type' => 'tax']]),
        );

        // The adjustment moves the total, which is what keeps the document's tax figure equal to
        // the sum of its tax lines instead of a total with an unexplained extra on top.
        self::assertSame(8.5, $breakdown['total']);

        $decoded = $service->tryDecodeTaxLinesJson($service->toJson($breakdown));

        self::assertCount(2, $decoded['lines']);
        self::assertSame('PST adjustment', $decoded['lines'][1]->label);
        self::assertNull($decoded['lines'][1]->rate);
        self::assertSame('pst-adjustment', $decoded['lines'][1]->slug);
        self::assertSame(TaxLine::SOURCE_MANUAL, $decoded['lines'][1]->source);
        self::assertSame(8.5, $decoded['total']);
    }

    public function testTaxLinesStoredBeforeSourceExistedDecodeAsCalculated(): void
    {
        $service = $this->service($this->resolver());

        $decoded = $service->tryDecodeTaxLinesJson(
            '{"lines":[{"label":"GST","rate":0.05,"amount":5}],"total":5,"perLineTax":[],"perLineTaxLabel":[]}'
        );

        self::assertSame(TaxLine::SOURCE_AUTO_CALC, $decoded['lines'][0]->source);
        self::assertSame(0.05, $decoded['lines'][0]->rate);
        // Slugs only reached the calculators with this change; older lines merged on label.
        self::assertNull($decoded['lines'][0]->slug);
    }

    public function testManualLineIsNotMergedIntoACalculatedLineSharingItsLabel(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05, 'gst')));

        $breakdown = $service->withManualTaxLines(
            $service->computeBreakdown('ON', null, [0 => ['subtotal' => 100.0, 'taxCode' => 'G']], []),
            // Same label and the same derived slug as the real GST line — still its own row,
            // because an admin typed it and expects to see exactly what they typed.
            $service->manualTaxLinesFromCharges([['label' => 'GST', 'amount' => 2.0, 'type' => 'tax']]),
        );

        self::assertCount(2, $breakdown['lines']);
        self::assertSame(5.0, $breakdown['lines'][0]->amount);
        self::assertSame(2.0, $breakdown['lines'][1]->amount);
        self::assertSame(7.0, $breakdown['total']);
    }

    public function testTwoManualLinesSharingALabelStayTwoRows(): void
    {
        $service = $this->service($this->resolver());

        $lines = $service->manualTaxLinesFromCharges([
            ['label' => 'Adjustment', 'amount' => 1.0, 'type' => 'tax'],
            ['label' => 'Adjustment', 'amount' => 2.0, 'type' => 'tax'],
        ]);

        $breakdown = $service->withManualTaxLines(
            ['lines' => [], 'total' => 0.0, 'perLineTax' => [], 'perLineTaxLabel' => [], 'perFeeLineTax' => []],
            $lines,
        );

        self::assertCount(2, $breakdown['lines']);
        self::assertSame(3.0, $breakdown['total']);
    }

    public function testManualSlugComesFromTheAdminWhenGivenAndTheLabelWhenNot(): void
    {
        $service = $this->service($this->resolver());

        $lines = $service->manualTaxLinesFromCharges([
            ['label' => 'Custom GST', 'amount' => 1.0, 'type' => 'tax', 'slug' => 'Border Levy'],
            ['label' => 'Custom  PST!', 'amount' => 1.0, 'type' => 'tax', 'slug' => ''],
            // Nothing to derive from at all still gets a key rather than an empty one.
            ['label' => '///', 'amount' => 1.0, 'type' => 'tax'],
            ['label' => 'Shipping (Ground)', 'amount' => 9.0, 'type' => 'shipping'],
        ]);

        self::assertSame(['border-levy', 'custom-pst', 'tax-adjustment'], array_map(fn (TaxLine $l) => $l->slug, $lines));
    }

    /**
     * The filter names the type it wants instead of skipping the one it doesn't. While shipping and
     * tax were the whole vocabulary "anything that is not shipping" happened to be right; the moment
     * a fee row existed it silently became a tax line — charged as tax, reported as tax, and no
     * error anywhere to notice it by. A row of a kind this method does not own is now left alone,
     * whatever that kind turns out to be.
     */
    public function testOnlyTaxRowsBecomeTaxLines(): void
    {
        $service = $this->service($this->resolver());

        $lines = $service->manualTaxLinesFromCharges([
            ['label' => 'Shipping (Ground)', 'amount' => 9.0, 'type' => 'shipping'],
            ['label' => 'Crating', 'amount' => 40.0, 'type' => 'fee', 'slug' => '', 'taxClass' => 'E', 'placement' => 'main_line'],
            ['label' => 'Border Levy', 'amount' => 4.0, 'type' => 'tax'],
            // A kind nobody has invented yet must not land here either.
            ['label' => 'Something New', 'amount' => 5.0, 'type' => 'deposit'],
        ]);

        self::assertSame(['Border Levy'], array_map(fn (TaxLine $l) => $l->label, $lines));
        self::assertSame([4.0], array_map(fn (TaxLine $l) => $l->amount, $lines));
    }

    /** The snapshot is rewritten on every save, so an unchanged document must not churn its slugs. */
    public function testManualSlugsAreStableAcrossASaveCycle(): void
    {
        $service = $this->service($this->resolver());

        $charges = [
            ['label' => 'Adjustment', 'amount' => 1.0, 'type' => 'tax'],
            ['label' => 'Border Levy', 'amount' => 2.0, 'type' => 'tax'],
        ];

        $first = $service->withManualTaxLines(
            ['lines' => [], 'total' => 0.0, 'perLineTax' => [], 'perLineTaxLabel' => [], 'perFeeLineTax' => []],
            $service->manualTaxLinesFromCharges($charges),
        );

        // Re-saving replays the rows the form hands back, not the ones originally posted.
        $rows = $service->manualTaxChargeRows($service->toJson($first));
        $second = $service->withManualTaxLines(
            ['lines' => [], 'total' => 0.0, 'perLineTax' => [], 'perLineTaxLabel' => [], 'perFeeLineTax' => []],
            $service->manualTaxLinesFromCharges($rows),
        );

        self::assertSame(['adjustment', 'border-levy'], array_map(fn (TaxLine $l) => $l->slug, $second['lines']));
        self::assertSame($service->toJson($first), $service->toJson($second));
    }

    public function testCalculatedLinesMergeOnSlugRatherThanLabel(): void
    {
        // Two calculators, one tax: the same 'gst' row spelled differently for display. Keying on
        // the slug rolls them into one line the way a single calculator's lines already are.
        $service = $this->service($this->resolver(
            new OnlyForClassTaxCalculator('G', 'GST', 0.05, 'gst'),
            new OnlyForClassTaxCalculator('S', 'GST 5%', 0.05, 'gst'),
        ));

        $breakdown = $service->computeBreakdown(
            'ON',
            null,
            [
                0 => ['subtotal' => 100.0, 'taxCode' => 'G'],
                1 => ['subtotal' => 100.0, 'taxCode' => 'S'],
            ],
            [],
        );

        self::assertCount(1, $breakdown['lines']);
        self::assertSame('GST', $breakdown['lines'][0]->label);
        self::assertSame(10.0, $breakdown['lines'][0]->amount);
    }

    public function testTryDecodeTaxLinesJsonReturnsNullForEmptyOrInvalidInput(): void
    {
        $service = $this->service($this->resolver());

        self::assertNull($service->tryDecodeTaxLinesJson(null));
        self::assertNull($service->tryDecodeTaxLinesJson(''));
        self::assertNull($service->tryDecodeTaxLinesJson('   '));
        self::assertNull($service->tryDecodeTaxLinesJson('not json'));
    }

    public function testBreakdownForOrderUsesFrozenSnapshotWithoutRecomputing(): void
    {
        // The resolver has no calculators, so if breakdownForOrder() ever fell through to a
        // live recompute it would produce $0 tax (via the RuntimeException-catch path) instead
        // of the frozen $12.34 snapshot below — proving the frozen snapshot took priority.
        $service = $this->service($this->resolver());

        $order = $this->makeOrder();
        $order->setTaxLines(json_encode([
            'lines' => [['label' => 'Frozen GST', 'rate' => 0.05, 'amount' => 12.34]],
            'total' => 12.34,
            'perLineTax' => [0 => 12.34],
            'perLineTaxLabel' => [0 => 'Frozen GST'],
        ]));

        $result = $service->breakdownForOrder($order);

        self::assertSame(12.34, $result['total']);
        self::assertSame('Frozen GST', $result['lines'][0]->label);
    }

    public function testBreakdownForOrderFallsBackToBuildForOrderWhenNoSnapshot(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $order = $this->makeOrder();

        $result = $service->breakdownForOrder($order);

        self::assertSame(5.0, $result['total']);
        self::assertSame('GST', $result['lines'][0]->label);
    }

    public function testBuildForOrderDerivesProvinceFromEffectiveShippingAddress(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $order = $this->makeOrder(province: 'BC');

        $result = $service->buildForOrder($order);

        self::assertSame('GST', $result['lines'][0]->label);
    }

    /**
     * buildForOrder() was never about orders — it unpacks a document into computeBreakdown()'s
     * arguments, which an estimate and a cart need doing just as much and could not reach.
     */
    public function testBuildForOrderAnswersForAnEstimateAndACartToo(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $address = (new CompanyAddress())->setProvince('ON');

        $estimate = (new Estimate())->setCompany(new Company())->setShippingAddressFrom($address);
        $estimate->addLine((new EstimateLine())->setName('Widget')->setSubtotal('100.00')->setTaxCode('G'));

        $cart = (new Cart())->setSessionId('sess-1');
        $cart->setCompany(new Company());
        $cart->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING)->setProvince('ON');
        $cartItem = (new CartItem())->setProduct((new ProductCore())->setSku('W')->setSalesTaxCode('G'))->setQuantity(1);
        $cart->addItem($cartItem);

        self::assertSame(5.0, $service->buildForOrder($estimate)['total']);
        // A cart's money is live, so priceItems() is what puts a figure on the row; the breakdown
        // reads it off the row like any other document's.
        self::assertSame(0.0, $service->buildForOrder($cart)['total'], 'an unpriced cart is taxed on nothing');
    }

    /**
     * perLineTax is keyed by POSITION, and the form and detail pages render the document's rows in
     * its own sort order — so the breakdown has to read them in that order too. Mid-save it cannot
     * rely on the collection: a line the admin inserted in the middle is appended to the collection
     * and only falls into place once it has been flushed and reloaded, which is after this snapshot
     * has already been frozen into tax_lines (#263).
     */
    public function testComputeBreakdownForReadsLinesInTheDocumentsOwnRowOrder(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $estimate = (new Estimate())->setCompany(new Company())->setShippingAddressFrom((new CompanyAddress())->setProvince('ON'));
        // Appended last, but the admin put it first — exactly the shape a mid-list insert leaves
        // the collection in before the save is flushed.
        $estimate->addLine((new EstimateLine())->setName('Second')->setSubtotal('200.00')->setTaxCode('G')->setSortOrder(1));
        $estimate->addLine((new EstimateLine())->setName('First')->setSubtotal('100.00')->setTaxCode('G')->setSortOrder(0));

        $result = $service->computeBreakdownFor($estimate, []);

        self::assertSame(5.0, $result['perLineTax'][0], 'row 0 is the line the document renders first');
        self::assertSame(10.0, $result['perLineTax'][1]);
    }

    /** A cart has no row order of its own, so every item answers 0 and a stable sort leaves them be. */
    public function testComputeBreakdownForLeavesACartsItemsInCollectionOrder(): void
    {
        $service = $this->service($this->resolver(new FlatRateTaxCalculator('GST', 0.05)));

        $cart = (new Cart())->setSessionId('sess-order-1');
        $cart->setCompany(new Company());
        $cart->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING)->setProvince('ON');
        foreach (['A', 'B'] as $sku) {
            $cart->addItem((new CartItem())->setProduct((new ProductCore())->setSku($sku)->setSalesTaxCode('G'))->setQuantity(1));
        }

        $result = $service->computeBreakdownFor($cart, []);

        self::assertCount(2, $result['perLineTax']);
        self::assertSame([0, 1], array_keys($result['perLineTax']));
    }

    /**
     * The province a calculator sees is normalised, wherever it came from: a legacy display name
     * left raw matches no rule and silently produces a $0-tax document.
     */
    public function testALegacyProvinceDisplayNameOnTheDocumentStillMatchesACalculator(): void
    {
        $service = $this->service($this->resolver(new OnlyForProvinceTaxCalculator('BC', 'PST', 0.07)));

        $order = $this->makeOrder(province: 'British Columbia');

        $result = $service->buildForOrder($order);

        self::assertSame('PST', $result['lines'][0]->label ?? null);
        self::assertSame(7.0, $result['total']);
    }

    private function makeOrder(string $province = 'ON'): SalesOrder
    {
        $address = new CompanyAddress();
        $address->setProvince($province);

        $company = new Company();

        $order = new SalesOrder();
        $order->setCompany($company);
        $order->setShippingAddressFrom($address);

        $line = new SalesOrderLine();
        $line->setName('Widget');
        $line->setSubtotal('100.00');
        $line->setTaxCode('G');
        $order->addLine($line);

        return $order;
    }
}

final class FlatRateTaxCalculator implements TaxCalculatorInterface
{
    public function __construct(
        private readonly string $label,
        private readonly float $rate,
        private readonly ?string $slug = null,
    ) {
    }

    public function supports(TaxContext $context): bool
    {
        return true;
    }

    public function calculate(TaxContext $context): array
    {
        return [new TaxLine($this->label, $this->rate, round($context->subtotal * $this->rate, 2), $this->slug)];
    }
}

/**
 * A calculator that claims every province and then falls over — the OTHER thing that reaches the
 * breakdown's catch, and the one whose treatment item 66 deliberately left alone.
 */
final class ThrowingTaxCalculator implements TaxCalculatorInterface
{
    public function supports(TaxContext $context): bool
    {
        return true;
    }

    public function calculate(TaxContext $context): array
    {
        throw new \RuntimeException('The rate table is unreachable.');
    }
}

final class OnlyForClassTaxCalculator implements TaxCalculatorInterface
{
    public function __construct(
        private readonly string $taxClass,
        private readonly string $label,
        private readonly float $rate,
        private readonly ?string $slug = null,
    ) {
    }

    public function supports(TaxContext $context): bool
    {
        return $context->taxClass === $this->taxClass;
    }

    public function calculate(TaxContext $context): array
    {
        return [new TaxLine($this->label, $this->rate, round($context->subtotal * $this->rate, 2), $this->slug)];
    }
}

/** Matches on province alone, so a document handing over an unnormalised one matches nothing. */
final class OnlyForProvinceTaxCalculator implements TaxCalculatorInterface
{
    public function __construct(
        private readonly string $province,
        private readonly string $label,
        private readonly float $rate,
    ) {
    }

    public function supports(TaxContext $context): bool
    {
        return $context->province === $this->province;
    }

    public function calculate(TaxContext $context): array
    {
        return [new TaxLine($this->label, $this->rate, round($context->subtotal * $this->rate, 2))];
    }
}
