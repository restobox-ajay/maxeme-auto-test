<?php

declare(strict_types=1);

namespace ProcurementBundle\Import;

/**
 * A vendor sheet's price/quantity cells are typed by a person into a spreadsheet, and a real one
 * (found testing against an actual vendor export) comes through as "$1.82", not "1.82". Neither
 * is_numeric() nor a bare (float) cast treats a leading "$" as anything but garbage —
 * is_numeric("$1.82") is false, and (float) "$1.82" is silently 0.0, not an error. Comma
 * thousands-separators ("$1,234.56") are stripped for the same reason.
 *
 * App\Service\ProductImport\ProductImportService::nullableMoney() had the identical gap (strips
 * commas, not the currency symbol) — fixed alongside this, once found here.
 */
final class VendorSheetMoney
{
    public static function strip(string $value): string
    {
        return trim(str_replace([',', '$'], '', $value));
    }
}
