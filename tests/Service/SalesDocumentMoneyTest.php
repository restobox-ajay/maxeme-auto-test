<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SalesDocumentMoney;
use PHPUnit\Framework\TestCase;

/**
 * The rounding policy itself, away from the two controllers that apply it (#257).
 *
 * The point of the class is negative: an intermediate must NOT arrive at the cent. The tests below
 * are mostly about what it declines to round.
 */
final class SalesDocumentMoneyTest extends TestCase
{
    public function testAnIntermediateKeepsItsSubCentFraction(): void
    {
        // 3 × 12.345 = 37.035. Rounding here is what used to make an order and a quote disagree.
        self::assertSame(37.035, SalesDocumentMoney::intermediate(3 * 12.345));
        self::assertSame(0.005, SalesDocumentMoney::intermediate(0.005));
        self::assertSame(-37.035, SalesDocumentMoney::intermediate(-37.035));
    }

    public function testAnIntermediateIsTrimmedAtTheStatedScaleSoAnAccumulatorCannotDrift(): void
    {
        self::assertSame(8, SalesDocumentMoney::SCALE);
        self::assertSame(0.12345679, SalesDocumentMoney::intermediate(0.123456789));

        // The residue 0.1 + 0.2 leaves is below SCALE, so a running total states the figure rather
        // than the float that produced it — which is what makes the same lines sum the same however
        // they are ordered.
        $forwards = SalesDocumentMoney::intermediate(SalesDocumentMoney::intermediate(0.1 + 0.2) + 0.3);
        $backwards = SalesDocumentMoney::intermediate(SalesDocumentMoney::intermediate(0.3 + 0.2) + 0.1);
        self::assertSame(0.6, $forwards);
        self::assertSame($forwards, $backwards);
    }

    /**
     * The behaviour the policy exists for, in miniature: three lines whose qty × price each land a
     * third of a cent past the cent. Rounding per line loses a cent that rounding once, at the end,
     * keeps — and it was the order flow doing the former while the quote did the latter.
     */
    public function testRoundingOncePreservesACentThatRoundingPerLineLoses(): void
    {
        $lines = [[3, 3.331], [3, 3.331], [3, 3.331]];

        $perLine = 0.0;
        $carried = 0.0;
        foreach ($lines as [$qty, $price]) {
            $perLine += round($qty * $price, 2);
            $carried = SalesDocumentMoney::intermediate($carried + $qty * $price);
        }

        self::assertSame(29.97, round($perLine, 2));
        self::assertSame(29.98, round($carried, 2));
    }
}
