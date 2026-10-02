<?php

declare(strict_types=1);

namespace App\Maxeme\Validation;

/**
 * The format rules a form field shares between the browser and the server: each pattern is written
 * once, in the subset of regex syntax both PCRE and the HTML `pattern` attribute (an anchored,
 * v-flag JavaScript regex) accept, so the field's hint and the server's check cannot drift apart.
 *
 * Templates use {{ input_rule('phone') }} for the attributes; constraints use regex().
 */
enum InputRule: string
{
    case Phone = 'phone';
    case PostalCode = 'postal_code';
    case Vin = 'vin';
    case Mileage = 'mileage';
    case LicensePlate = 'license_plate';
    case Money = 'money';

    public function pattern(): string
    {
        return match ($this) {
            // 7+ digits, with spaces, ( ) - . and a leading +; an optional "x 123" / "ext. 123".
            self::Phone => '(?=(?:[^0-9]*[0-9]){7})\+?[0-9 \(\)\-\.]{7,20}(?:\s*(?:[xX]|[eE][xX][tT]\.?)\s*[0-9]{1,6})?',
            // Canadian (V6X 2P9) or US (98101, 98101-1234).
            self::PostalCode => '[A-Za-z][0-9][A-Za-z][ \-]?[0-9][A-Za-z][0-9]|[0-9]{5}(?:-[0-9]{4})?',
            // 17 characters since 1981, 11+ before; never I, O or Q.
            self::Vin => '[A-HJ-NPR-Za-hj-npr-z0-9]{11,17}',
            self::Mileage => '[0-9]{1,3}(?:,?[0-9]{3}){0,2}',
            self::LicensePlate => '[A-Za-z0-9][A-Za-z0-9 \-]{0,9}',
            self::Money => '[0-9]{1,8}(?:\.[0-9]{1,2})?',
        };
    }

    /** The message on the server and the tooltip in the browser. */
    public function message(): string
    {
        return match ($this) {
            self::Phone => 'Enter a phone number with at least 7 digits, e.g. 604-285-8200.',
            self::PostalCode => 'Enter a postal code like V6X 2P9 (or a US ZIP like 98101).',
            self::Vin => 'A VIN is 11 to 17 letters and digits, without I, O or Q.',
            self::Mileage => 'Enter the mileage as a whole number, e.g. 150000.',
            self::LicensePlate => 'A licence plate is up to 10 letters, digits, spaces and dashes.',
            self::Money => 'Enter an amount in dollars, e.g. 12.50.',
        };
    }

    public function inputMode(): ?string
    {
        return match ($this) {
            self::Phone => 'tel',
            self::Mileage => 'numeric',
            self::Money => 'decimal',
            default => null,
        };
    }

    /** The PCRE form of pattern(), anchored like the HTML attribute is. */
    public function regex(): string
    {
        return '/^(?:' . $this->pattern() . ')$/u';
    }
}
