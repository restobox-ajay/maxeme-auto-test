<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TireNumberSearchMatcher;
use PHPUnit\Framework\TestCase;

final class TireNumberSearchMatcherTest extends TestCase
{
    private TireNumberSearchMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new TireNumberSearchMatcher();
    }

    public function testMatchesProductNameContainingSameDigitsWithDifferentFormatting(): void
    {
        self::assertTrue($this->matcher->matches('2356516', 'AQQISHI-AQSONE-A-T-LT235-65R16-121-119R'));
    }

    public function testMatchesWhenSearchStringItselfHasSeparators(): void
    {
        self::assertTrue($this->matcher->matches('235 65 16', 'AQQISHI-AQSONE-A-T-LT235-65R16-121-119R'));
    }

    public function testDoesNotMatchWhenDigitsAppearOutOfSequence(): void
    {
        self::assertFalse($this->matcher->matches('6516235', 'AQQISHI-AQSONE-A-T-LT235-65R16-121-119R'));
    }

    public function testDoesNotMatchUnrelatedProductName(): void
    {
        self::assertFalse($this->matcher->matches('2356516', 'LT275-70R18-125-122S'));
    }

    public function testNotEligibleWithNoDigitsAtAll(): void
    {
        self::assertFalse($this->matcher->isEligible('no digits here'));
        self::assertFalse($this->matcher->matches('no digits here', 'LT235-65R16-121-119R'));
    }

    public function testEligibleWithASingleDigit(): void
    {
        self::assertTrue($this->matcher->isEligible('1'));
        self::assertTrue($this->matcher->matches('1', 'LT235-65R16-121-119R'));
    }

    public function testEligibleWithMoreThanSevenDigitsAmongOtherCharacters(): void
    {
        self::assertTrue($this->matcher->isEligible('LT 235/65 R16-1'));
    }

    public function testExtractDigitsStripsAllNonDigitCharacters(): void
    {
        self::assertSame('2356516', $this->matcher->extractDigits('LT235-65R16'));
    }
}
