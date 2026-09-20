<?php

declare(strict_types=1);

namespace Number1RimImportBundle\Service;

use App\Entity\CustomFieldDefinition;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\CustomFieldValueRepository;
use App\Service\ProductImport\ProductImportResult;
use App\Service\ProductImport\ProductImportService;
use App\Enum\ProductStatus;
use Doctrine\ORM\EntityManagerInterface;
use Number1RimImportBundle\EventSubscriber\RimSpecFieldSubscriber;

/**
 * The orchestrator — the one thing both SyncRimProductsCommand and RimImportController call.
 * fetch -> transform -> core's ProductImportService::import() -> stamp syncSource + custom
 * fields -> sync images -> (optionally) scoped inactivate-missing. See RIM_API_IMPORT_PLAN.md §4/§6
 * for the full reasoning, especially why the "missing -> inactive" pass is bundle-owned and
 * source-scoped rather than using core's own global flag.
 */
final class RimApiImportService
{
    /** Value stamped on ProductCore::syncSource for every product this bundle creates/updates. */
    public const SYNC_SOURCE = 'number1-rim-api';

    public function __construct(
        private readonly RimApiClient $apiClient,
        private readonly RimApiTransformer $transformer,
        private readonly ProductImportService $importService,
        private readonly RimImageSyncService $imageSyncService,
        private readonly CustomFieldDefinitionRepository $fieldDefRepo,
        private readonly CustomFieldValueRepository $fieldValueRepo,
    ) {}

    /**
     * @param array{
     *     api_url: string,
     *     api_client_id: string,
     *     api_key: string,
     *     category: ?ProductCategory,
     *     region: ?FulfillmentRegion,
     *     deactivate_missing: bool,
     * } $options
     */
    public function sync(EntityManagerInterface $entityManager, array $options): ProductImportResult
    {
        set_time_limit(0);

        // Throws RimApiException on any transport/HTTP-status/JSON-decode failure — deliberately
        // left to propagate to the caller (controller/command), which is also what guarantees
        // the inactivate-missing pass below never runs against a failed fetch.
        $rows = $this->apiClient->fetchRows($options['api_url'], $options['api_client_id'], $options['api_key']);

        $transformResult = $this->transformer->transform($rows, $options['category'], $options['region']);

        try {
            $result = $this->importService->import($transformResult->canonicalCsv, $entityManager, [
                'primary_key' => 'sku',
                // Never core's global flag here — see RIM_API_IMPORT_PLAN.md §3/§4: it scans the
                // entire catalog, which would inactivate every tire and CSV-imported product the
                // moment this bundle runs. The scoped pass below is this bundle's own replacement.
                'missing_rows' => 'do_nothing',
            ]);
        } finally {
            @unlink($transformResult->canonicalCsvPath);
        }

        /** @var array<string, true> */
        $touchedSkus = [];
        foreach ($result->rowLog as $entry) {
            $touchedSkus[$entry['key']] = true;
        }

        $this->applyPerProductData($entityManager, $touchedSkus, $transformResult);

        $result->inactivated = 0;
        if ($options['deactivate_missing'] && $touchedSkus !== []) {
            $result->inactivated = $this->inactivateMissing($entityManager, $touchedSkus);
        }

        return $result;
    }

    /** @param array<string, true> $touchedSkus */
    private function applyPerProductData(EntityManagerInterface $entityManager, array $touchedSkus, RimTransformResult $transformResult): void
    {
        if ($touchedSkus === []) {
            return;
        }

        // This bundle's own rim-spec fields: self-register here too (not just via
        // RimSpecFieldSubscriber's kernel.request hook), since the console-command entry point
        // never dispatches an HTTP request at all — a pure-cron production setup must not depend
        // on an admin having loaded any page first.
        $specDefs = [];
        foreach (RimSpecFieldSubscriber::FIELDS as $slug => $def) {
            $specDefs[$slug] = $this->fieldDefRepo->ensureBySlug(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, $slug, [
                'label' => $def['label'],
                'fieldType' => CustomFieldDefinition::FIELD_TYPE_TEXT,
                'visibleOnAdd' => true,
                'visibleOnEdit' => true,
                'visibleOnListing' => true,
                'source' => RimSpecFieldSubscriber::SOURCE,
            ]);
        }

        $productRepo = $entityManager->getRepository(ProductCore::class);
        foreach (array_keys($touchedSkus) as $sku) {
            $product = $productRepo->findOneBy(['sku' => $sku]);
            if (!$product instanceof ProductCore || $product->getId() === null) {
                continue;
            }

            $product->setSyncSource(self::SYNC_SOURCE)->touch();

            // The API carries no tax code, so default a product with none to S (GST + PST taxable)
            // rather than leaving it Exempt. Only fills an empty code — a tax class set by hand in
            // admin is preserved across syncs. See issue #115.
            if (($product->getSalesTaxCode() ?? '') === '') {
                $product->setSalesTaxCode('S');
            }

            $productId = $product->getId();

            foreach ($transformResult->specFieldsBySku[$sku] ?? [] as $slug => $value) {
                if (isset($specDefs[$slug])) {
                    $this->fieldValueRepo->setValue($specDefs[$slug], $productId, $value);
                }
            }

            // suggested_price_type/suggested_price_value are native ProductCore columns now
            // (SUGGESTED_PRICE_CORE_ADAPTATION_PLAN.md phase 2) — written directly, no Custom Field
            // lookup needed.
            if (isset($transformResult->suggestedPriceValueBySku[$sku])) {
                $product->setSuggestedPriceType('Number');
                $product->setSuggestedPriceValue($transformResult->suggestedPriceValueBySku[$sku]);
            }

            $this->imageSyncService->sync($product, $transformResult->imageUrlsBySku[$sku] ?? [], $entityManager);
        }

        $entityManager->flush();
    }

    /**
     * Scoped to syncSource = SYNC_SOURCE only — mirrors number1_inventory's own Bug-4-fixed logic
     * (RIM_API_IMPORT_PLAN.md §1: source-scoped, not a catalog-wide diff), which is the single
     * most important behavioral detail in this bundle: it's what guarantees this never touches a
     * tire product or a CSV-imported product, only ever ones this same bundle created/updated.
     *
     * @param array<string, true> $touchedSkus
     */
    private function inactivateMissing(EntityManagerInterface $entityManager, array $touchedSkus): int
    {
        $count = 0;
        $products = $entityManager->getRepository(ProductCore::class)->findBy([
            'syncSource' => self::SYNC_SOURCE,
            'deleted' => false,
        ]);

        foreach ($products as $product) {
            if (!$product instanceof ProductCore) {
                continue;
            }

            $sku = $product->getSku();
            if ($sku === '' || isset($touchedSkus[$sku])) {
                continue;
            }

            if ($product->getStatusEnum() !== ProductStatus::Inactive) {
                $product->deactivate()->touch();
                $count++;
            }
        }

        if ($count > 0) {
            $entityManager->flush();
        }

        return $count;
    }
}
