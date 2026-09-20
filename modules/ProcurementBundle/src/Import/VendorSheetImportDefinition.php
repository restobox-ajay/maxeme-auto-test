<?php

declare(strict_types=1);

namespace ProcurementBundle\Import;

use App\Contract\Import\ImportDefinitionInterface;
use App\Enum\ImportAction;
use App\Model\ImportTargetField;

/**
 * Imports a vendor's price/backorder-quantity sheet against one vendor per file — Feature A of
 * docs/plans/2026-09-15-vendor-sheet-and-po-csv-import.md, §2.
 */
final class VendorSheetImportDefinition implements ImportDefinitionInterface
{
    public const NAME = 'vendor_sheet';

    public function name(): string { return self::NAME; }
    public function label(): string { return 'Vendor Sheet Import'; }
    public function entityType(): string { return 'VendorPrice'; }

    /**
     * Neither vendor_sku nor our_sku is individually required — VendorSkuResolver accepts either
     * — so the "at least one" rule is enforced by VendorSheetImportValidator, not by ColumnMapper's
     * own required-field check.
     *
     * @return list<ImportTargetField>
     */
    public function targetFields(): array
    {
        return [
            new ImportTargetField('vendor_sku', 'Vendor SKU', required: false),
            new ImportTargetField('our_sku', 'Our SKU', required: false),
            new ImportTargetField('vendor_price', 'Price', required: true),
            new ImportTargetField('vendor_quantity', 'Available Quantity', required: false),
            new ImportTargetField('vendor_name', 'Item Name', required: false),
            // Only applied on a resolved product (VendorSheetImportRowExecutor's matched branch) —
            // an UnmatchedVendorSku has no product to categorize yet. Looked up by name against
            // App\Entity\ProductCategory, never auto-created: an admin maps these columns only
            // once the category tree they name already exists.
            new ImportTargetField('vendor_category1', 'Category 1', required: false),
            new ImportTargetField('vendor_category2', 'Category 2', required: false),
        ];
    }

    /** @return list<ImportAction> */
    public function supportedActions(): array { return [ImportAction::Append, ImportAction::Update, ImportAction::Delete]; }
    public function supportsValidationOnly(): bool { return true; }
}
