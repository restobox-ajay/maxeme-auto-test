<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The 12 line-table columns order, quote and the standalone invoice all share (#full-parity).
 *
 * This is the ONE place they are typed. templates/admin/_partials/sales_line_columns.html.twig's
 * `base()` macro reads it via the `sales_line_columns_base()` Twig function
 * (App\Twig\SalesDocumentLineColumnsExtension) rather than carrying its own literal copy, and
 * InvoiceController's standalone-screen column list is this array merged with its own batch+actions
 * tail — the same merge order does in Twig — instead of a hand-typed PHP duplicate of the same 12
 * entries (which is what it was until 2026-09-13: a fourth copy that happened to still agree with
 * this one, with nothing enforcing that it would keep doing so).
 *
 * @see \App\Controller\Admin\InvoiceController::STANDALONE_LINE_COLUMNS
 */
final class SalesDocumentLineColumns
{
    public const BASE = [
        ['key' => 'product', 'label' => 'Name', 'col' => 'name'],
        ['key' => 'location', 'label' => 'Location'],
        ['key' => 'sku', 'label' => 'SKU'],
        ['key' => 'qty', 'label' => 'Qty'],
        ['key' => 'weight', 'label' => 'Weight'],
        ['key' => 'unit', 'label' => 'U/M'],
        ['key' => 'tax_code', 'label' => 'Tax Code', 'col' => 'tax'],
        ['key' => 'tax_amount', 'label' => 'Tax $', 'col' => 'tax-amount'],
        ['key' => 'cost', 'label' => 'Cost'],
        ['key' => 'original_price', 'label' => 'Original Price', 'col' => 'original'],
        ['key' => 'price', 'label' => 'Price'],
        ['key' => 'subtotal', 'label' => 'Subtotal'],
    ];
}
