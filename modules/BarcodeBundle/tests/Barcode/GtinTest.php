<?php

declare(strict_types=1);

namespace BarcodeBundle\Tests\Barcode;

use BarcodeBundle\Barcode\Gtin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The check digit, and the range this application is allowed to invent numbers in.
 *
 * A check digit computed the wrong way round passes on even-length values and fails on odd-length
 * ones, which means a UPC test would go green and the first EAN a receiver typed would be rejected.
 * So both lengths are checked, against real published barcodes rather than numbers made up here.
 */
final class GtinTest extends TestCase
{
    /**
     * Real barcodes, taken from the standard's own worked examples and from the packaging they are
     * printed on. Made-up numbers would only prove the implementation agrees with itself.
     *
     * @return list<array{0: string}>
     */
    public static function valid(): array
    {
        return [
            ['4006381333931'],  // EAN-13, the standard's worked example
            ['036000291452'],   // UPC-A
            ['0123456789012'],  // EAN-13 with a leading zero, which is a UPC-A in disguise
            ['96385074'],       // EAN-8
            ['10012345678902'], // GTIN-14, a case code
        ];
    }

    #[DataProvider('valid')]
    public function testARealBarcodeIsAccepted(string $code): void
    {
        self::assertTrue(Gtin::isValid($code), sprintf('%s is a real barcode and was refused', $code));
    }

    /**
     * A transposed pair is the commonest typing error there is, and catching it is the entire reason
     * the check digit is worth verifying at the form rather than trusting what was typed.
     */
    public function testATransposedPairIsCaught(): void
    {
        self::assertTrue(Gtin::isValid('4006381333931'));
        self::assertFalse(Gtin::isValid('4006381333913'), 'the last two digits swapped must not validate');
        self::assertFalse(Gtin::isValid('4006383133931'), 'a pair swapped in the middle must not validate');
    }

    public function testAWrongCheckDigitIsRefused(): void
    {
        self::assertFalse(Gtin::isValid('4006381333930'));
        self::assertFalse(Gtin::isValid('4006381333932'));
    }

    public function testSomethingThatIsNotAGtinAtAllIsRefused(): void
    {
        self::assertFalse(Gtin::isValid(''));
        self::assertFalse(Gtin::isValid('WIDGET-1'));
        self::assertFalse(Gtin::isValid('123'), 'three digits is not a GTIN length');
        self::assertFalse(Gtin::isValid('12345678901'), 'eleven digits is not a GTIN length either');
        self::assertFalse(Gtin::isValid('400638133393X'));
    }

    public function testTheCheckDigitIsRefusedOnAnythingButDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Gtin::checkDigit('40063813339X');
    }

    /**
     * The whole point of prefix 2: GS1 reserves it for codes a business issues for its own use, so a
     * number minted there can never collide with a manufacturer's. Minting anything else would be
     * claiming a company prefix this business does not own.
     */
    public function testAMintedCodeIsAValidEan13InTheRestrictedCirculationRange(): void
    {
        for ($productId = 1; $productId < 2000; $productId += 337) {
            $code = Gtin::mintInternal($productId);

            self::assertSame(13, \strlen($code), sprintf('%s is not thirteen digits', $code));
            self::assertSame('2', $code[0], sprintf('%s is outside the range GS1 reserves for in-house codes', $code));
            self::assertTrue(Gtin::isValid($code), sprintf('%s does not carry a valid check digit', $code));
        }
    }

    /** The product id is legible in the middle of the code, so a stray label can be traced by eye. */
    public function testTheMintedCodeCarriesTheProductIdWhereAPersonCanReadIt(): void
    {
        $code = Gtin::mintInternal(4213, static fn (): string => '00000');

        self::assertSame('2004213000008', $code);
        self::assertTrue(Gtin::isValid($code));
    }

    /** Two mints for one product differ, so a replacement label is possible without reusing a number. */
    public function testTwoMintsForOneProductAreDifferentNumbers(): void
    {
        $seen = [];
        for ($i = 0; $i < 25; $i++) {
            $seen[Gtin::mintInternal(7)] = true;
        }

        self::assertGreaterThan(1, \count($seen), 'every mint for one product returned the same number');
    }
}
