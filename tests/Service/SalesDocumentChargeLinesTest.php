<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Contract\Fee\FeeLine;
use App\Service\SalesDocumentChargeLines;
use PHPUnit\Framework\TestCase;

final class SalesDocumentChargeLinesTest extends TestCase
{
    /**
     * One vocabulary, not two. `shipping` on a charge row is FeeLine::TYPE_SHIPPING itself, so a
     * second constant spelling the same word cannot drift from it — and there is no constant at all
     * for the retired extra_charges column this class used to be named after.
     */
    public function testTheShippingTypeIsTheLineModelsAndNotACopyOfIt(): void
    {
        $reflection = new \ReflectionClass(SalesDocumentChargeLines::class);

        self::assertSame(['TYPE_TAX' => 'tax'], $reflection->getConstants());
        self::assertSame(
            [['label' => 'Ground', 'amount' => 8.0, 'type' => FeeLine::TYPE_SHIPPING, 'slug' => '']],
            SalesDocumentChargeLines::normalize([['label' => 'Ground', 'amount' => '8', 'type' => FeeLine::TYPE_SHIPPING]]),
        );
    }

    public function testNormalizeKeepsOnlyMeaningfulRowsAndDefaultsTheirLabelPerType(): void
    {
        $rows = SalesDocumentChargeLines::normalize([
            ['label' => ' Shipping (Canada Post) ', 'amount' => '$25.50', 'type' => 'SHIPPING'],
            ['label' => '', 'amount' => '4', 'type' => 'tax'],
            ['label' => '', 'amount' => '0', 'type' => 'shipping'],
            ['label' => '', 'amount' => '12', 'type' => 'fee'],
            ['label' => 'Rush', 'amount' => '-3', 'type' => 'fee'],
            'not an array',
        ]);

        self::assertSame([
            ['label' => 'Shipping (Canada Post)', 'amount' => 25.5, 'type' => 'shipping', 'slug' => ''],
            ['label' => 'Tax', 'amount' => 4.0, 'type' => 'tax', 'slug' => ''],
            ['label' => 'Fee', 'amount' => 12.0, 'type' => 'fee', 'slug' => '', 'taxClass' => 'E', 'placement' => 'main_line'],
            ['label' => 'Rush', 'amount' => 0.0, 'type' => 'fee', 'slug' => '', 'taxClass' => 'E', 'placement' => 'main_line'],
        ], $rows);
    }

    /**
     * The bug this vocabulary's third word was added to close: while shipping and tax were the whole
     * list, "anything that is not shipping" was read as tax, so a charge that was neither silently
     * became a tax line. Nothing is guessed any more — a type the vocabulary does not have is
     * refused, and the admin is told which row and which word.
     */
    public function testAnUnknownTypeIsRefusedRatherThanFiledSomewhere(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Charge row "Rush" has an unknown line type "nonsense". Expected shipping, fee or tax.');

        SalesDocumentChargeLines::normalize([['label' => 'Rush', 'amount' => '3', 'type' => 'nonsense']]);
    }

    /**
     * A row that says nothing about its kind is refused too. "No type" used to mean tax, which was
     * only ever the same guess wearing a different hat: both forms state a type on every row they
     * render, so a row without one is a post neither of them can produce.
     */
    public function testARowWithNoTypeIsRefusedAsWell(): void
    {
        self::assertSame(
            'Charge row "Adjustment" has no line type. Expected shipping, fee or tax.',
            SalesDocumentChargeLines::errorFor([['label' => 'Adjustment', 'amount' => '3']]),
        );
    }

    /**
     * An empty row is not a badly typed row: both forms render spare rows for the no-JS path, and
     * nobody typing nothing into one should be told their charge lines are wrong.
     */
    public function testABlankRowIsStillJustDroppedWhateverItsTypeSays(): void
    {
        self::assertSame([], SalesDocumentChargeLines::normalize([
            ['label' => '', 'amount' => '', 'type' => ''],
            ['label' => '', 'amount' => '0', 'type' => 'nonsense'],
        ]));
        self::assertNull(SalesDocumentChargeLines::errorFor([['label' => '', 'amount' => '', 'type' => '']]));
    }

    public function testErrorForIsNullWhenEveryRowCanBecomeALine(): void
    {
        self::assertNull(SalesDocumentChargeLines::errorFor([
            ['label' => 'Custom Shipping', 'amount' => '10', 'type' => 'shipping'],
            ['label' => 'Crating', 'amount' => '40', 'type' => 'fee', 'taxClass' => 'G', 'placement' => 'main_line'],
            ['label' => 'Border Levy', 'amount' => '4', 'type' => 'tax'],
        ]));
    }

    public function testShippingRowsIgnoreTaxRows(): void
    {
        self::assertSame([
            ['label' => 'Shipping (Ground)', 'amount' => 10.0, 'type' => 'shipping'],
            ['label' => 'Fuel surcharge', 'amount' => 5.0, 'type' => 'shipping'],
        ], SalesDocumentChargeLines::shippingRows([
            ['label' => 'Shipping (Ground)', 'amount' => 10.0, 'type' => 'shipping'],
            ['label' => 'Fuel surcharge', 'amount' => 5.0, 'type' => 'shipping'],
            ['label' => 'Custom GST', 'amount' => 2.5, 'type' => 'tax'],
        ]));
    }

    public function testEveryShippingRowBecomesItsOwnLineCarryingTheDocumentsTaxClass(): void
    {
        $lines = SalesDocumentChargeLines::toShippingLines([
            ['label' => 'Custom GST', 'amount' => 2.5, 'type' => 'tax'],
            ['label' => 'Shipping (Ground)', 'amount' => 10.0, 'type' => 'shipping', 'slug' => ''],
            ['label' => 'Fuel surcharge', 'amount' => 5.0, 'type' => 'shipping', 'slug' => 'fuel'],
        ], 'S');

        self::assertCount(2, $lines);
        self::assertSame(['shipping-ground', 'fuel'], array_map(static fn (FeeLine $l) => $l->slug, $lines));
        self::assertSame(['Shipping (Ground)', 'Fuel surcharge'], array_map(static fn (FeeLine $l) => $l->label, $lines));
        self::assertSame([10.0, 5.0], array_map(static fn (FeeLine $l) => $l->amount, $lines));

        foreach ($lines as $line) {
            self::assertSame('S', $line->taxClass);
            self::assertSame(FeeLine::TYPE_SHIPPING, $line->type);
            self::assertSame(FeeLine::SOURCE_MANUAL, $line->source);
            // main_line keeps shipping inside "subtotal excluding taxes", where it has always sat,
            // and is why the after_tax_line/Exempt rule cannot be reached from a shipping row.
            self::assertSame('main_line', $line->placement);
        }
    }

    /** The form rebuilds the document from what it posts, so stored rows have to come back as rows. */
    public function testShippingLinesRoundTripBackIntoChargeRows(): void
    {
        $charges = [
            ['label' => 'Shipping (Ground)', 'amount' => 10.0, 'type' => 'shipping', 'slug' => ''],
            ['label' => 'Custom GST', 'amount' => 2.5, 'type' => 'tax', 'slug' => ''],
        ];

        self::assertSame(
            [['label' => 'Shipping (Ground)', 'amount' => 10.0, 'type' => 'shipping', 'slug' => 'shipping-ground']],
            SalesDocumentChargeLines::fromShippingLines(
                SalesDocumentChargeLines::toShippingLines($charges, 'G'),
            ),
        );
    }

    /**
     * A fee row is the one-off charge that is neither tax nor shipping. It becomes a fee line with
     * no Fee behind it — feeId null, SOURCE_MANUAL — carrying the tax class and placement the admin
     * picked, which is the whole reason it has fields a shipping row does not.
     */
    public function testEveryFeeRowBecomesAManualFeeLineCarryingItsOwnTaxClassAndPlacement(): void
    {
        $lines = SalesDocumentChargeLines::toFeeLines(SalesDocumentChargeLines::normalize([
            ['label' => 'Custom Shipping', 'amount' => '10', 'type' => 'shipping'],
            ['label' => 'Border Levy', 'amount' => '4', 'type' => 'tax'],
            ['label' => 'Crating', 'amount' => '40', 'type' => 'fee', 'slug' => '', 'taxClass' => 'g', 'placement' => 'before_tax_line'],
            ['label' => 'COD Fee', 'amount' => '12', 'type' => 'fee', 'slug' => 'cod', 'taxClass' => 'E', 'placement' => 'after_tax_line'],
        ]));

        self::assertCount(2, $lines);
        self::assertSame(['crating', 'cod'], array_map(static fn (FeeLine $l) => $l->slug, $lines));
        self::assertSame(['Crating', 'COD Fee'], array_map(static fn (FeeLine $l) => $l->label, $lines));
        self::assertSame([40.0, 12.0], array_map(static fn (FeeLine $l) => $l->amount, $lines));
        self::assertSame(['G', 'E'], array_map(static fn (FeeLine $l) => $l->taxClass, $lines));
        self::assertSame(['before_tax_line', 'after_tax_line'], array_map(static fn (FeeLine $l) => $l->placement, $lines));

        foreach ($lines as $line) {
            self::assertNull($line->feeId);
            self::assertSame(FeeLine::TYPE_FEE, $line->type);
            self::assertSame(FeeLine::SOURCE_MANUAL, $line->source);
        }
    }

    /** A blank tax class is Exempt and a blank placement is main_line — the safe answers. */
    public function testAFeeRowThatStatesNeitherTaxClassNorPlacementIsExemptAndMainLine(): void
    {
        $lines = SalesDocumentChargeLines::toFeeLines([['label' => 'Crating', 'amount' => 40.0, 'type' => 'fee']]);

        self::assertSame('E', $lines[0]->taxClass);
        self::assertSame('main_line', $lines[0]->placement);
    }

    /**
     * The slug rule tax rows already follow: whatever the admin typed, else the label sluggified.
     * No disambiguation — two rows labelled "Adjustment" stay two rows both slugged `adjustment`,
     * because nothing keys on slug uniqueness.
     */
    public function testTwoFeeRowsAreFreeToShareASlug(): void
    {
        $lines = SalesDocumentChargeLines::toFeeLines(SalesDocumentChargeLines::normalize([
            ['label' => 'Adjustment', 'amount' => '5', 'type' => 'fee'],
            ['label' => 'Adjustment', 'amount' => '7', 'type' => 'fee'],
        ]));

        self::assertSame(['adjustment', 'adjustment'], array_map(static fn (FeeLine $l) => $l->slug, $lines));
        self::assertSame([5.0, 7.0], array_map(static fn (FeeLine $l) => $l->amount, $lines));
    }

    /**
     * Fee::assertValid() forbids an after_tax_line fee from being taxable, but a line built outside
     * a Fee never runs it. The rule has to hold on the row, and it has to be askable before the save
     * starts: a fee calculator flushes partway through one, so a bad row must be refused at the door
     * rather than discovered with half a document written.
     */
    public function testATaxableAfterTaxFeeRowIsRefused(): void
    {
        $charges = [['label' => 'COD Fee', 'amount' => 12.0, 'type' => 'fee', 'taxClass' => 'G', 'placement' => 'after_tax_line']];

        self::assertSame(
            'After Tax fees cannot be taxable — set the fee row\'s tax class to Exempt first.',
            SalesDocumentChargeLines::errorFor($charges),
        );

        // And the last line of defence, for a save path that somehow skipped the door check.
        $this->expectException(\InvalidArgumentException::class);
        SalesDocumentChargeLines::toFeeLines($charges);
    }

    /** Exempt is what makes an after-tax row legal — placement alone is not the problem. */
    public function testAnExemptAfterTaxFeeRowIsFine(): void
    {
        self::assertNull(SalesDocumentChargeLines::errorFor([
            ['label' => 'COD Fee', 'amount' => '12', 'type' => 'fee', 'taxClass' => 'E', 'placement' => 'after_tax_line'],
        ]));
    }

    /**
     * The round trip the manual fee line exists by: a save rebuilds fee_lines from the calculators,
     * which have never heard of this row, so it survives only because the form is handed it back as
     * a row and posts it again. Everything the line needs has to make it both ways.
     */
    public function testManualFeeLinesRoundTripBackIntoChargeRows(): void
    {
        $charges = SalesDocumentChargeLines::normalize([
            ['label' => 'Custom Shipping', 'amount' => '10', 'type' => 'shipping'],
            ['label' => 'Crating', 'amount' => '40', 'type' => 'fee', 'slug' => '', 'taxClass' => 'G', 'placement' => 'before_tax_line'],
        ]);

        $rows = SalesDocumentChargeLines::fromFeeLines(array_merge(
            // A calculated line sits in the same snapshot and must not come back as an editable row:
            // it is rebuilt from its own definition on every save.
            [new FeeLine(7, 'eco-fee', 'Eco Fee', 'E', 1.5)],
            SalesDocumentChargeLines::toShippingLines($charges, 'S'),
            SalesDocumentChargeLines::toFeeLines($charges),
        ));

        self::assertSame([[
            'label' => 'Crating',
            'amount' => 40.0,
            'type' => 'fee',
            'slug' => 'crating',
            'taxClass' => 'G',
            'placement' => 'before_tax_line',
        ]], $rows);

        // Posting those rows back unchanged rebuilds the identical line.
        self::assertEquals(
            SalesDocumentChargeLines::toFeeLines($charges),
            SalesDocumentChargeLines::toFeeLines(SalesDocumentChargeLines::normalize($rows)),
        );
    }

    public function testDeriveShippingMethodStripsTheChargeRowsLabelWrapper(): void
    {
        self::assertSame('Canada Post - Ground', SalesDocumentChargeLines::deriveShippingMethod([
            ['label' => 'Custom GST', 'amount' => 1.0, 'type' => 'tax'],
            ['label' => 'Shipping (Canada Post - Ground)', 'amount' => 10.0, 'type' => 'shipping'],
        ]));

        // A manual row's label is the method as typed; no shipping row at all means no method.
        self::assertSame('Custom Shipping', SalesDocumentChargeLines::deriveShippingMethod([
            ['label' => 'Custom Shipping', 'amount' => 10.0, 'type' => 'shipping'],
        ]));
        self::assertNull(SalesDocumentChargeLines::deriveShippingMethod([
            ['label' => 'Custom GST', 'amount' => 1.0, 'type' => 'tax'],
        ]));
    }
}
