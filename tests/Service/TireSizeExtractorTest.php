<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TireSizeExtractor;
use PHPUnit\Framework\TestCase;

final class TireSizeExtractorTest extends TestCase
{
    private TireSizeExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new TireSizeExtractor();
    }

    public function testExtractsAllDigitsIncludingModelNumberAndLoadIndex(): void
    {
        self::assertSame('8102554519104', $this->extractor->extract('DURINGON DP810 255/45ZR19 104W'));
    }

    public function testDifferentSpeedRatingLetterYieldsTheSameDigitCode(): void
    {
        // Confirms parity with the reference app: "R19" and "ZR19" variants of the same size
        // show the identical Size value, since the speed-rating letter contributes no digit.
        self::assertSame(
            $this->extractor->extract('DURINGON DP810 255/45R19 104W'),
            $this->extractor->extract('DURINGON DP810 255/45ZR19 104W')
        );
    }

    public function testExtractsAllDigitsFromADashSeparatedSkuLikeName(): void
    {
        self::assertSame('2356516121119', $this->extractor->extract('AQQISHI-AQSONE-A-T-LT235-65R16-121-119R'));
    }

    public function testExtractsAllDigitsFromALightTruckFlotationSizeName(): void
    {
        self::assertSame('33125020114', $this->extractor->extract('AQQISHI AQSONE A/T 33X12.50R20LT 114Q'));
    }

    public function testExtractsAllDigitsFromAnotherFlotationSizeWithDifferentRimDiameter(): void
    {
        self::assertSame('35125022117', $this->extractor->extract('AQQISHI AQSONE A/T 35X12.50R22LT 117Q'));
    }

    public function testReturnsNullWhenNoSizePatternIsPresent(): void
    {
        self::assertNull($this->extractor->extract('Sizeless Tire Alpha'));
    }

    public function testReturnsNullForAnEmptyString(): void
    {
        self::assertNull($this->extractor->extract(''));
    }
}
