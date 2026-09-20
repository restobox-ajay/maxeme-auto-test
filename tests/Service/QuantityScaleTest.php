<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AppSetting;
use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use App\Service\AppSettings;
use App\Service\QuantityScale;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * {@see QuantityScale} — a fully static, bcmath-backed helper, so every test binds the setting
 * (or leaves it unbound) rather than building an instance.
 *
 * `AppSettings` is final, so it is built for real over a stubbed repository rather than doubled —
 * the same approach {@see \App\Tests\Service\BusinessDateTest} and tests/Service/AppSettingsTest.php
 * take. Only the one key is ever reached through it here.
 */
final class QuantityScaleTest extends TestCase
{
    protected function tearDown(): void
    {
        // Static state must not leak into the next test file — the global setting is unbound by
        // default, which is also what decimals() falls back to at runtime with no binder wired.
        QuantityScale::bind(null);

        parent::tearDown();
    }

    /** Binds a settings table whose one row is the quantity key, set to $value. */
    private function bindAt(?string $value): void
    {
        $rows = $value === null
            ? []
            : [(new AppSetting())
                ->setSettingKey(QuantityScale::SETTING_KEY)
                ->setName(QuantityScale::SETTING_KEY)
                ->setSettingValue($value)];

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($rows);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repo);

        QuantityScale::bind(new AppSettings($entityManager, new ArrayAdapter()));
    }

    private function productWithStep(?string $step): ProductCore
    {
        $product = (new ProductCore())->setSku('QS-1');
        if ($step !== null) {
            $product->setBaseUnit((new UnitOfMeasure())->setCode('QS-UNIT')->setRoundingPrecision($step));
        }

        return $product;
    }

    // ------------------------------------------------------------------ the setting, and the clamp

    public function testUnboundDefaultsToTheColumnsOwnScale(): void
    {
        self::assertSame(LineDenomination::QUANTITY_SCALE, QuantityScale::decimals());
        self::assertSame(4, QuantityScale::decimals());
        self::assertSame('12.3456', QuantityScale::round('12.3456'));
    }

    public function testUnsetDefaultsToTheColumnsOwnScale(): void
    {
        $this->bindAt(null);

        self::assertSame(LineDenomination::QUANTITY_SCALE, QuantityScale::decimals());
        self::assertSame('12.3456', QuantityScale::round('12.3456'));
    }

    public function testAConfiguredTwoIsHonoured(): void
    {
        $this->bindAt('2');

        self::assertSame(2, QuantityScale::decimals());
        self::assertSame('12.35', QuantityScale::round('12.3456'));
        self::assertSame('2.50', QuantityScale::round('2.5'));
    }

    public function testAConfiguredZeroIsHonoured(): void
    {
        $this->bindAt('0');

        self::assertSame(0, QuantityScale::decimals());
        // No decimal point at all, not "12." and not "12.0".
        self::assertSame('12', QuantityScale::round('12.3456'));
        self::assertSame('3', QuantityScale::round('2.5'), 'half-up at zero places rounds 2.5 to 3');
    }

    public function testAConfiguredSixIsClampedToTheColumnsScale(): void
    {
        $this->bindAt('6');

        self::assertSame(
            LineDenomination::QUANTITY_SCALE,
            QuantityScale::decimals(),
            'six places cannot be stored in decimal(14, 4), so the setting is clamped down to what the column holds',
        );
        self::assertSame('12.3456', QuantityScale::round('12.34564'), 'and the clamped scale is what actually rounds');
    }

    public function testAGarbageOrNegativeSettingFallsBackRatherThanBreakingEveryQuantity(): void
    {
        $this->bindAt('');
        self::assertSame(LineDenomination::QUANTITY_SCALE, QuantityScale::decimals());

        $this->bindAt('four');
        self::assertSame(LineDenomination::QUANTITY_SCALE, QuantityScale::decimals());

        $this->bindAt('-2');
        self::assertSame(0, QuantityScale::decimals(), 'there is no such thing as a negative place');
    }

    // ---------------------------------------------------------------------------- the rounding mode

    /**
     * Half-up, away from zero, pinned with the value that tells it apart from a float multiply.
     *
     * `1.2345 * 1000` is 1234.4999999999998 in binary floating point, so `(int) round(…)` on it
     * gives 1234 and the rule would silently be half-DOWN for exactly the values that test it.
     */
    public function testRoundingIsHalfUpOnTheDigitsAndNotOnAFloat(): void
    {
        $this->bindAt('3');

        self::assertSame('1.235', QuantityScale::round('1.2345'));
        self::assertSame('1.234', QuantityScale::round('1.2344'));
        self::assertSame('-1.235', QuantityScale::round('-1.2345'), 'away from zero, both ways');
        self::assertSame('0.002', QuantityScale::round('0.0015'));
    }

    public function testTheCanonicalFormNeverTrimsAndNeverSeparates(): void
    {
        // That is DisplayNumber's business. What this class hands back is what the column holds.
        $this->bindAt('4');

        self::assertSame('2.5000', QuantityScale::round('2.5'));
        self::assertSame('1234.5000', QuantityScale::round('1234.5'));
        self::assertSame('0.0000', QuantityScale::round(null));
        self::assertSame('0.0000', QuantityScale::round(''));
        self::assertSame('0.0000', QuantityScale::round('not a number'));
    }

    public function testIntegersAndFloatsAreAcceptedAsWellAsStrings(): void
    {
        $this->bindAt('4');

        self::assertSame('3.0000', QuantityScale::round(3));
        self::assertSame('2.5000', QuantityScale::round(2.5));
        self::assertSame('0.0001', QuantityScale::round(0.00005));
    }

    // ------------------------------------------------------------------------------- product override

    /**
     * A unit's own step wins over the global setting entirely — it is not a decimal-place count
     * but the smallest increment the unit may be sold in, so "halves only" or "packs of six" are
     * expressible in a way a decimal count never could be.
     */
    public function testAProductsUnitStepOverridesTheGlobalSetting(): void
    {
        $this->bindAt('4');
        $halves = $this->productWithStep('0.5');

        // Still the canonical MAX_DECIMALS form — the step decides which multiple of itself the
        // figure rounds to, not how many places the returned string carries.
        self::assertSame('2.5000', QuantityScale::round('2.4', $halves));
        self::assertSame('2.5000', QuantityScale::round('2.6', $halves), '2.6 / 0.5 = 5.2, rounds to 5 steps, 5 * 0.5 = 2.5');
        self::assertSame('0.0000', QuantityScale::round('0.2', $halves));
    }

    public function testASixPackStepRoundsToTheNearestPack(): void
    {
        $sixPacks = $this->productWithStep('6');

        self::assertSame('12.0000', QuantityScale::round('10', $sixPacks));
        self::assertSame('18.0000', QuantityScale::round('15', $sixPacks));
    }

    public function testNoProductOrNoUnitFallsBackToTheGlobalSetting(): void
    {
        $this->bindAt('2');

        self::assertSame('12.35', QuantityScale::round('12.3456', null));
        self::assertSame('12.35', QuantityScale::round('12.3456', $this->productWithStep(null)));
    }

    public function testAZeroOrBlankStepFallsBackToTheGlobalSettingRatherThanDividingByZero(): void
    {
        $this->bindAt('3');

        self::assertSame('1.235', QuantityScale::round('1.2345', $this->productWithStep('0')));
    }

    // ---------------------------------------------------------------------------- exact comparison

    /**
     * The classic binary-float trap, which is why nothing here compares as a float: `0.1 + 0.2 > 0.3`
     * is true as floats, and that is how a fully shipped line stays Partially Shipped forever.
     */
    public function testQuantitiesCompareExactlyAndNeverAsFloats(): void
    {
        self::assertSame(0, QuantityScale::compare(QuantityScale::add('0.1', '0.2'), '0.3'));
        self::assertSame(0, QuantityScale::compare(QuantityScale::add('0.6', '0.9'), '1.5'));
        self::assertSame(0, QuantityScale::compare(QuantityScale::add('0.4', '2.1'), '2.5'));
        self::assertSame(-1, QuantityScale::compare('0.5999', '0.6'));
        self::assertSame(1, QuantityScale::compare('0.6001', '0.6'));
    }

    public function testAddSubAndMulCarryEveryDigit(): void
    {
        self::assertSame('0.3000', QuantityScale::add('0.1', '0.2'));
        self::assertSame('0.4000', QuantityScale::sub('0.6', '0.2'));
        self::assertSame('7.50', QuantityScale::mul('2.5', '3', 2));
    }

    // -------------------------------------------------------------------------------- whole units

    public function testIsWholeIsTheSerialRuleAndNothingElse(): void
    {
        self::assertTrue(QuantityScale::isWhole('1'));
        self::assertTrue(QuantityScale::isWhole('3.0000'));
        self::assertFalse(QuantityScale::isWhole('0.4'));
        self::assertFalse(QuantityScale::isWhole('1.6'));
    }

    // ------------------------------------------------------------------------ scaled-integer shims

    public function testUnitsAndFormatRoundTrip(): void
    {
        foreach (['0', '2.5', '12.3456', '-0.6', '1234.5678'] as $quantity) {
            $canonical = QuantityScale::canonical($quantity);
            self::assertSame(
                $canonical,
                QuantityScale::formatAtColumnScale(QuantityScale::unitsAtColumnScale($canonical)),
                sprintf('%s must survive unitsAtColumnScale()/formatAtColumnScale()', $quantity),
            );
        }
    }

    // -------------------------------------------------------------------------------------- trim

    public function testTrimDropsTrailingZerosForProseAndLeavesAWholeNumberAlone(): void
    {
        self::assertSame('5', QuantityScale::trim('5.0000'));
        self::assertSame('2.5', QuantityScale::trim('2.5000'));
        self::assertSame('0', QuantityScale::trim('0.0000'));
        self::assertSame('12', QuantityScale::trim('12'));
    }
}
