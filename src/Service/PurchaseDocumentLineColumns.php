<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The line-table columns PurchaseOrder and VendorBill share (buy-side full-parity with the sell
 * side's own SalesDocumentLineColumns::BASE). One place they are typed, exactly like the sell
 * side's own constant — read by templates/admin/_partials/purchase_line_columns.html.twig's
 * `base()` macro via the `purchase_line_columns_base()` Twig function rather than each caller
 * carrying its own literal copy.
 *
 * `unit`, `weight` and `batch` sit here, in BASE, rather than being appended per-caller the way the
 * sell side appends its own `batch` — because both PurchaseOrder and VendorBill carry all three
 * identically, so there is no per-document difference to express by leaving them out of the shared
 * list (#full-parity, 2026-09-13).
 *
 * No Actions column, and no `po_line`/`ordered`/`received`/`remaining` either: those are real,
 * named exceptions each caller appends or omits for itself — see purchase_line_row.html.twig's
 * own docblock for what each key means and which document uses it.
 */
final class PurchaseDocumentLineColumns
{
    public const BASE = [
        ['key' => 'product', 'label' => 'Product', 'col' => 'name'],
        ['key' => 'description', 'label' => 'Description'],
        ['key' => 'location', 'label' => 'Location'],
        ['key' => 'sku', 'label' => 'Our SKU'],
        ['key' => 'vendor_sku', 'label' => 'Their Part No.'],
        ['key' => 'qty', 'label' => 'Quantity'],
        ['key' => 'weight', 'label' => 'Weight'],
        ['key' => 'unit', 'label' => 'U/M'],
        ['key' => 'tax_code', 'label' => 'Tax Code', 'col' => 'tax'],
        ['key' => 'tax_amount', 'label' => 'Tax $', 'col' => 'tax-amount'],
        ['key' => 'cost', 'label' => 'Unit Cost'],
        ['key' => 'original_price', 'label' => 'Vendor Rate'],
        ['key' => 'subtotal', 'label' => 'Subtotal'],
        ['key' => 'batch', 'label' => 'Batch'],
    ];
}
