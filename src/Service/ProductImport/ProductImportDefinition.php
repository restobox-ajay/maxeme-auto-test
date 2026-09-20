<?php

declare(strict_types=1);

namespace App\Service\ProductImport;

use App\Contract\Import\ImportDefinitionInterface;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Enum\ImportAction;
use App\Model\ImportTargetField;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The unified framework's metadata for core's own /admin/imports product importer. The column set
 * is dynamic (one per active fulfillment region, one per active price list, one per installed fee
 * provider — see templateCsv(), which this mirrors), which is exactly the shape ColumnMapper's
 * mapping screen was built for: it shows every field, pre-fills what already matches, and an admin
 * whose CSV already uses the expected header names never has to touch it (see
 * ProductImportController::mapping(), which uses ProductImportService's own normalizeHeaderKey()
 * to compute that pre-fill).
 */
final class ProductImportDefinition implements ImportDefinitionInterface
{
    public const NAME = 'product';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductImportService $productImportService,
    ) {
    }

    public function name(): string { return self::NAME; }
    public function label(): string { return 'Product CSV Import'; }
    public function entityType(): string { return 'ProductCore'; }

    /** @return list<ImportTargetField> */
    public function targetFields(): array
    {
        $fields = [
            new ImportTargetField('sku', 'SKU', required: false),
            new ImportTargetField('id', 'Product ID', required: false),
            new ImportTargetField('name', 'Name', required: false),
            new ImportTargetField('status', 'Status', required: false),
            new ImportTargetField('category', 'Category', required: false),
            new ImportTargetField('type', 'Type', required: false),
            new ImportTargetField('unit', 'Unit', required: false),
            new ImportTargetField('weight', 'Weight', required: false),
            new ImportTargetField('cost_price', 'Cost Price', required: false),
            new ImportTargetField('original_price', 'Original Price', required: false),
            new ImportTargetField('deposit', 'Deposit', required: false),
            new ImportTargetField('visible', 'Visible', required: false),
            new ImportTargetField('private', 'Private', required: false),
            new ImportTargetField('deleted', 'Deleted', required: false),
            new ImportTargetField('sales_tax_code', 'Sales Tax Code', required: false),
            new ImportTargetField('remarks', 'Remarks', required: false),
            new ImportTargetField('short_description', 'Short Description', required: false),
            new ImportTargetField('long_description', 'Long Description', required: false),
        ];

        foreach ($this->em->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC']) as $region) {
            $fields[] = new ImportTargetField(
                'fulfillment_region__' . $this->productImportService->slug($region->getName()),
                'Quantity: ' . $region->getName(),
                required: false,
            );
        }

        foreach ($this->em->getRepository(PriceList::class)->findBy(['status' => 'Active'], ['id' => 'ASC']) as $priceList) {
            if ($priceList->getId() === null) {
                continue;
            }
            $fields[] = new ImportTargetField(
                'price__' . $priceList->getId(),
                'Price: ' . $priceList->getName(),
                required: false,
            );
        }

        foreach ($this->productImportService->feeImportColumns() as $feeColumn) {
            $fields[] = new ImportTargetField($feeColumn, $feeColumn, required: false);
        }

        return $fields;
    }

    /** @return list<ImportAction> */
    public function supportedActions(): array { return [ImportAction::Append, ImportAction::Update]; }
    public function supportsValidationOnly(): bool { return true; }
}
