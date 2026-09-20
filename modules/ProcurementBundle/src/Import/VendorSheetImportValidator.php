<?php

declare(strict_types=1);

namespace ProcurementBundle\Import;

use App\Contract\Import\ImportValidatorInterface;
use App\Contract\Import\ImportWholeRunCheckInterface;
use App\Entity\ImportRun;

/**
 * @see VendorSheetMoney for why a raw is_numeric()/(float) cast is wrong here — real vendor
 * sheets export price/quantity cells as "$1.82", which both treat as non-numeric/zero.
 */

final class VendorSheetImportValidator implements ImportValidatorInterface, ImportWholeRunCheckInterface
{
    public function validate(array $mappedData): array
    {
        $errors = [];

        $vendorSku = trim((string) ($mappedData['vendor_sku'] ?? ''));
        $ourSku = trim((string) ($mappedData['our_sku'] ?? ''));
        if ($vendorSku === '' && $ourSku === '') {
            $errors[] = 'Row names neither vendor_sku nor our_sku.';
        }

        // A blank cell is the vendor giving no price at all (e.g. a stock-only refresh row) —
        // treated as 0, not rejected; a cell that HAS something non-numeric in it still fails.
        $price = trim((string) ($mappedData['vendor_price'] ?? ''));
        if ($price !== '' && !is_numeric(VendorSheetMoney::strip($price))) {
            $errors[] = 'vendor_price must be numeric.';
        }

        $quantity = $mappedData['vendor_quantity'] ?? null;
        if ($quantity !== null && trim((string) $quantity) !== '' && !is_numeric(VendorSheetMoney::strip((string) $quantity))) {
            $errors[] = 'vendor_quantity must be numeric.';
        }

        return $errors;
    }

    /**
     * our_sku is this sheet's upsert key — every row naming it resolves to (or creates) the SAME
     * product. Two rows naming the same our_sku for two DIFFERENT items is not a row-level problem
     * (each row is individually well-formed); it means the key itself is broken for this file, and
     * per-row execution would silently let whichever row runs last overwrite the other's price with
     * unrelated data. Caught this way after a vendor sheet whose SKU column started reusing values
     * for unrelated products partway through clobbered 203 of 204 freshly-created products' prices
     * with garbage from the wrong item — nothing about any ONE of those rows was invalid.
     *
     * Compares vendor_name, not price/quantity: two rows CAN legitimately restate the same item's
     * price further down a sheet (a correction, a second sighting) — that's an update, not
     * corruption. It's only a broken key when the NAME disagrees, meaning the sheet is using the
     * same our_sku for what are, in its own words, two different things.
     */
    public function checkWholeRun(ImportRun $run): ?string
    {
        $seenNameBySku = [];

        foreach ($run->getRows() as $row) {
            $data = $row->getMappedData();
            $sku = trim((string) ($data['our_sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $name = trim((string) ($data['vendor_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if (!isset($seenNameBySku[$sku])) {
                $seenNameBySku[$sku] = $name;

                continue;
            }

            if ($seenNameBySku[$sku] !== $name) {
                return sprintf(
                    'our_sku "%s" is used for more than one different item in this file (e.g. "%s" and "%s") — refusing the whole file rather than letting rows overwrite each other with unrelated data.',
                    $sku,
                    $seenNameBySku[$sku],
                    $name,
                );
            }
        }

        return null;
    }
}
