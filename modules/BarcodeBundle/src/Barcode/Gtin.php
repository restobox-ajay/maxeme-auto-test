<?php

declare(strict_types=1);

namespace BarcodeBundle\Barcode;

/**
 * The GS1 check digit, and the one range of numbers this application is allowed to invent (#607).
 *
 * ## Why a check digit is worth the twenty lines
 *
 * A UPC or EAN typed by hand at the barcode screen is the one identifier in this application that
 * carries its own proof. The last digit is a modulo-10 checksum of the twelve before it, so a
 * transposed pair — the commonest typing error there is — produces a number that fails arithmetic
 * rather than a number that quietly names somebody else's product. Refusing it at the form costs a
 * retype; accepting it costs a receiving line booked against the wrong SKU months later.
 *
 * The check is advisory for the part-number kinds and enforced for the GTIN kinds, because a
 * vendor's part number is whatever the vendor says it is and has no checksum to fail.
 *
 * ## Minting: prefix 2, and nothing else
 *
 * GS1 reserves EAN-13 prefixes 02 and 20–29 for **restricted circulation within a company** — the
 * range every supermarket's own in-store labels live in. A number minted there is guaranteed never
 * to collide with a real manufacturer's GTIN anywhere in the world, because no manufacturer can be
 * issued one.
 *
 * That is the whole reason minting starts with `2` rather than with a nicer-looking prefix. A
 * generated code beginning `0` or `5` would be a claim on a GS1 company prefix this business does
 * not own — it would scan, it would look correct, and it would be somebody else's number. Sortly
 * generates its own codes the same way and for the same reason.
 *
 * The minted number is a valid EAN-13 rather than a made-up string for a second reason as well: it
 * is thirteen printable digits, so it costs Code 128 nothing to encode and goes into a QR's byte
 * mode in thirteen bytes. An internal code shaped like `INT/PROD-4213-88271` would be longer, wider
 * on the label, and meaningless to every other system that ever reads it.
 */
final class Gtin
{
    /** GS1's restricted-circulation prefix. Everything this application invents begins with it. */
    public const INTERNAL_PREFIX = '2';

    /** GTIN-8, GTIN-12 (UPC-A), GTIN-13 (EAN-13) and GTIN-14 (the case code). */
    public const LENGTHS = [8, 12, 13, 14];

    /** True when the string is all digits, a GTIN length, and its last digit is the right checksum. */
    public static function isValid(string $value): bool
    {
        $value = trim($value);

        if (preg_match('/^\d+$/', $value) !== 1 || !\in_array(\strlen($value), self::LENGTHS, true)) {
            return false;
        }

        return self::checkDigit(substr($value, 0, -1)) === (int) substr($value, -1);
    }

    /**
     * The GS1 modulo-10 check digit for a body of digits with the check digit REMOVED.
     *
     * Weights alternate 3 and 1 from the RIGHT, which is what makes the same routine work for a
     * 7-, 11-, 12- or 13-digit body without a per-length table. Getting the direction wrong
     * produces a checksum that is right for even lengths and wrong for odd ones, which is the kind
     * of bug that passes a UPC test and fails on the first EAN.
     */
    public static function checkDigit(string $body): int
    {
        if (preg_match('/^\d+$/', $body) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A GTIN check digit is computed over digits only; %s is not.',
                var_export($body, true),
            ));
        }

        $sum = 0;
        $weight = 3;

        for ($i = \strlen($body) - 1; $i >= 0; $i--) {
            $sum += $weight * (int) $body[$i];
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10;
    }

    /** The body with its check digit appended — a complete, valid GTIN. */
    public static function withCheckDigit(string $body): string
    {
        return $body . self::checkDigit($body);
    }

    /**
     * A fresh EAN-13 in the restricted-circulation range, for a product that arrived with no
     * barcode at all.
     *
     * `2` + six digits of the product id + five random digits + the check digit. The product id is
     * in there so a code found on a shelf can be traced back by eye when nothing else is to hand;
     * the five random digits are there so a reprint of a DIFFERENT internal code for the same
     * product is possible, and so that a code cannot be guessed from the id alone. Neither is a
     * uniqueness mechanism: the caller checks the database and mints again, because 10^5 is small
     * enough to collide and a collision must not become a barcode.
     */
    public static function mintInternal(int $productId, ?\Closure $randomDigits = null): string
    {
        $random = $randomDigits ?? static fn (): string => str_pad((string) random_int(0, 99999), 5, '0', \STR_PAD_LEFT);

        $body = self::INTERNAL_PREFIX
            . str_pad((string) ($productId % 1000000), 6, '0', \STR_PAD_LEFT)
            . substr(str_pad($random(), 5, '0', \STR_PAD_LEFT), 0, 5);

        return self::withCheckDigit($body);
    }
}
