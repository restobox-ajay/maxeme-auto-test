<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Purchase;

use App\Repository\BundleStatusRepository;
use PHPUnit\Framework\TestCase;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLine;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLineSnapshot;
use ProcurementBundle\Contract\Purchase\PurchaseFeeCalculatorInterface;
use ProcurementBundle\Contract\Purchase\PurchaseFeeContext;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Purchase\PurchaseFeeCalculatorResolver;

/**
 * The purchase-fee subscription seam actually works (#655).
 *
 * ## Why this test exists at all
 *
 * Nothing in the application implements `PurchaseFeeCalculatorInterface` yet — that is deliberate,
 * the owner's ruling is that bundles subscribe later and a speculative freight calculator would be
 * guessing at rules nobody has stated. But an extension point with no subscriber and no test is
 * exactly the shape this repository has already been bitten by:
 * `VendorPrice::roundUpToOrderMultiple()` is fully implemented and has never been called, and
 * `inventory_reconciliation_discrepancy` had four writers and zero readers for months.
 *
 * So the seam is exercised here by a calculator that exists only inside this file. That proves the
 * wiring a future bundle will rely on — tagged service is found, the bundle gate is honoured, lines
 * come back, a thrower does not take the save down — without inventing a charge nobody asked for.
 *
 * A plain `TestCase`, not the Doctrine one: every collaborator is either a value object or the one
 * repository, which is stubbed. There is nothing here that needs a database.
 */
final class PurchaseFeeSeamTest extends TestCase
{
    private function context(): PurchaseFeeContext
    {
        return new PurchaseFeeContext(
            (new Vendor())->setName('Seam Vendor')->setCurrency('CAD'),
            'AB',
            'CAD',
            100.0,
        );
    }

    /**
     * A BundleStatusRepository that says yes (or no) to everything.
     *
     * A hand-written subclass rather than `createMock()`: PHPUnit 12 emits a notice for a test
     * double over a concrete CLASS, and every existing double in this repository is over an
     * interface. `BundleStatusRepository` has no interface to stand behind, so the cheapest honest
     * substitute is a subclass that skips the Doctrine constructor it would otherwise need a
     * ManagerRegistry for — it answers one question and touches no database.
     */
    private function gate(bool $active): BundleStatusRepository
    {
        return new class ($active) extends BundleStatusRepository {
            public function __construct(private readonly bool $active) {}

            public function isActiveForInstance(object $instance): bool
            {
                return $this->active;
            }
        };
    }

    private function calculator(callable $calculate, bool $supports = true): PurchaseFeeCalculatorInterface
    {
        return new class ($calculate, $supports) implements PurchaseFeeCalculatorInterface {
            public function __construct(private $calculate, private bool $supports) {}

            public function supports(PurchaseFeeContext $context): bool
            {
                return $this->supports;
            }

            public function calculate(PurchaseFeeContext $context): array
            {
                return ($this->calculate)($context);
            }
        };
    }

    public function testATaggedCalculatorsLinesReachTheDocument(): void
    {
        $resolver = new PurchaseFeeCalculatorResolver(
            [$this->calculator(static fn (PurchaseFeeContext $c): array => [
                new PurchaseChargeLine('brokerage', 'Brokerage', 'G', round($c->subtotal * 0.02, 2)),
            ])],
            $this->gate(true),
        );

        $lines = $resolver->calculate($this->context());

        self::assertCount(1, $lines);
        self::assertSame('Brokerage', $lines[0]->label);
        self::assertSame(2.0, $lines[0]->amount, 'the calculator was handed the document subtotal');
        self::assertTrue($resolver->hasCalculators());
    }

    /**
     * Charges STACK — every supporting calculator contributes, unlike tax where the first match
     * wins because a province has one regime and two answering would double the tax.
     */
    public function testEverySupportingCalculatorContributesRatherThanTheFirstWinning(): void
    {
        $resolver = new PurchaseFeeCalculatorResolver(
            [
                $this->calculator(static fn (): array => [new PurchaseChargeLine('freight', 'Freight', 'G', 30.0, PurchaseChargeLine::PLACEMENT_MAIN_LINE, PurchaseChargeLine::TYPE_FREIGHT)]),
                $this->calculator(static fn (): array => [new PurchaseChargeLine('duty', 'Duty', 'E', 12.0)]),
            ],
            $this->gate(true),
        );

        self::assertSame(
            ['Freight', 'Duty'],
            array_map(static fn (PurchaseChargeLine $l): string => $l->label, $resolver->calculate($this->context())),
        );
    }

    /** A calculator whose owning bundle is Inactive is skipped, exactly as a tax calculator is. */
    public function testAnInactiveBundlesCalculatorIsSkipped(): void
    {
        $resolver = new PurchaseFeeCalculatorResolver(
            [$this->calculator(static fn (): array => [new PurchaseChargeLine('freight', 'Freight', 'G', 30.0)])],
            $this->gate(false),
        );

        self::assertSame([], $resolver->calculate($this->context()));
        self::assertFalse($resolver->hasCalculators());
    }

    /** A calculator that says it does not support the context contributes nothing. */
    public function testACalculatorThatDoesNotSupportTheContextContributesNothing(): void
    {
        $resolver = new PurchaseFeeCalculatorResolver(
            [$this->calculator(static fn (): array => [new PurchaseChargeLine('freight', 'Freight', 'G', 30.0)], supports: false)],
            $this->gate(true),
        );

        self::assertSame([], $resolver->calculate($this->context()));
    }

    /**
     * A broken third-party calculator must not make purchase orders unsaveable.
     *
     * The document is short that charge — visibly, because the row simply is not on it — and the
     * OTHER calculator still contributes. Same call `OrderTaxBreakdownService::safeCalculateTax()`
     * makes on the sell side.
     */
    public function testAThrowingCalculatorIsSkippedAndTheOthersStillContribute(): void
    {
        $resolver = new PurchaseFeeCalculatorResolver(
            [
                $this->calculator(static fn (): array => throw new \RuntimeException('rate service down')),
                $this->calculator(static fn (): array => [new PurchaseChargeLine('duty', 'Duty', 'E', 12.0)]),
            ],
            $this->gate(true),
        );

        $lines = $resolver->calculate($this->context());

        self::assertCount(1, $lines);
        self::assertSame('Duty', $lines[0]->label);
    }

    // ------------------------------------------------------------------ the snapshot round-trip

    /**
     * What a calculator returns is exactly what comes back out of the column.
     *
     * Every field, because a snapshot that quietly loses `placement` or `taxClass` changes what the
     * document is taxed on without changing anything a reader would notice.
     */
    public function testTheSnapshotRoundTripsEveryField(): void
    {
        $original = [
            new PurchaseChargeLine('freight', 'Freight', 'G', 30.0, PurchaseChargeLine::PLACEMENT_MAIN_LINE, PurchaseChargeLine::TYPE_FREIGHT, PurchaseChargeLine::SOURCE_MANUAL),
            new PurchaseChargeLine('deposit', 'Deposit', 'E', 5.5, PurchaseChargeLine::PLACEMENT_AFTER_TAX, PurchaseChargeLine::TYPE_FEE, PurchaseChargeLine::SOURCE_AUTO_CALC),
        ];

        $decoded = PurchaseChargeLineSnapshot::decode(PurchaseChargeLineSnapshot::encode($original));

        self::assertCount(2, $decoded);
        foreach ($original as $index => $line) {
            self::assertSame($line->slug, $decoded[$index]->slug);
            self::assertSame($line->label, $decoded[$index]->label);
            self::assertSame($line->taxClass, $decoded[$index]->taxClass);
            self::assertSame($line->amount, $decoded[$index]->amount);
            self::assertSame($line->placement, $decoded[$index]->placement);
            self::assertSame($line->type, $decoded[$index]->type);
            self::assertSame($line->source, $decoded[$index]->source);
        }
    }

    /** "No charges" is one state in the column, not two. */
    public function testAnEmptyListEncodesToNullRatherThanAnEmptyJsonArray(): void
    {
        self::assertNull(PurchaseChargeLineSnapshot::encode([]));
        self::assertSame([], PurchaseChargeLineSnapshot::decode(null));
        self::assertSame([], PurchaseChargeLineSnapshot::decode(''));
    }

    /**
     * A malformed column reads as no charges rather than throwing.
     *
     * This runs on every render of a document, and a purchase order that cannot be LOOKED at is
     * worse than one whose charges are missing from the screen that would let somebody retype them.
     */
    public function testAMalformedColumnDecodesToNoChargesRatherThanThrowing(): void
    {
        self::assertSame([], PurchaseChargeLineSnapshot::decode('{not json'));
        self::assertSame([], PurchaseChargeLineSnapshot::decode('"a string"'));
    }

    /** Only an after-tax row is outside the figure tax is taken on; exempt and zero rows are too. */
    public function testIsTaxableNamesExactlyTheRowsThatBelongInTheTaxBase(): void
    {
        self::assertTrue((new PurchaseChargeLine('f', 'Freight', 'G', 30.0))->isTaxable());
        self::assertFalse((new PurchaseChargeLine('f', 'Freight', 'E', 30.0))->isTaxable(), 'an exempt row is not taxed');
        self::assertFalse((new PurchaseChargeLine('f', 'Freight', 'G', 0.0))->isTaxable(), 'a zero row produces no tax either way');
        self::assertFalse(
            (new PurchaseChargeLine('d', 'Deposit', 'G', 10.0, PurchaseChargeLine::PLACEMENT_AFTER_TAX))->isTaxable(),
            'an after-tax row is added once tax is settled, so taxing it would tax money the breakdown never saw',
        );
    }
}
