<?php

declare(strict_types=1);

namespace Number1ProductImportBundle\Controller\Admin;

use App\Entity\CustomFieldDefinition;
use App\Entity\FulfillmentRegion;
use App\Service\Region;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\CustomFieldValueRepository;
use App\Service\ProductImport\ProductImportResult;
use App\Service\ProductImport\ProductImportService;
use App\Validation\Constraint\ValidCsvUpload;
use Doctrine\ORM\EntityManagerInterface;
use Number1ProductImportBundle\EventSubscriber\VendorProductFieldSubscriber;
use Number1ProductImportBundle\Service\ProductCsvTransformer;
use Number1ProductImportBundle\Service\ProductImportConfig;
use Number1ProductImportBundle\Service\TireTsbcEligibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Two-step flow, since which FulfillmentRegion each row's inventory belongs to depends
 * on that row's LOCATION cell, and the set of distinct LOCATION values is only known
 * after the file is parsed:
 *
 *   1. index()   — upload the CSV + choose fallback category / missing-rows / metadata
 *                  toggle. The file is stashed to a token-named temp path and every
 *                  distinct LOCATION value found is shown back for mapping.
 *   2. confirm() — admin has picked a FulfillmentRegion (or "skip") per LOCATION value;
 *                  this is where ProductCsvTransformer::transform() and
 *                  ProductImportService::import() actually run.
 */
#[Route('/admin/bundles/number1-product-import')]
final class ProductImportController extends AbstractController
{
    private const PENDING_FILE_PREFIX = 'n1pi_pending_';

    public function __construct(
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly BundleStatusRepository $bundleStatuses,
        private readonly Region $region,
    ) {}

    /**
     * Every bucket a recount may be told to clear, and — for the six that are only ever written by
     * an optional bundle — the bundle that must be Active for the checkbox to mean anything. A
     * checkbox that clears a bucket nothing fills is not merely useless, it is a lie about what the
     * import did (#564, generalized past `received` to all fourteen buckets: see
     * CoreInventoryTotalCountService's own docblock for why none of these may be bundled or
     * defaulted — each is information only the person running the import has).
     *
     * `field` matches ProductImportService::CLEAR_BUCKET_OPTIONS exactly. `bundle` is null for the
     * eight buckets core itself owns unconditionally.
     *
     * @var array<string, array{field: string, label: string, help: string, bundle: ?string}>
     */
    private const CLEAR_BUCKET_FIELDS = [
        'received' => [
            'field' => 'clear_received_balance',
            'label' => 'Clear all Received inventory balance',
            'help' => "Tick this only if the file you are uploading <strong>already includes</strong> the deliveries booked here since your last upload. It zeroes the Received bucket and takes the figures in this file as the new baseline. Leave it unticked if your export predates those deliveries &mdash; otherwise stock that really arrived is discarded.",
            'bundle' => 'ProcurementBundle',
        ],
        'transferIn' => [
            'field' => 'clear_transfer_in_balance',
            'label' => 'Clear all Transfer In balance',
            'help' => 'Zeroes stock this app has recorded as having arrived here from another warehouse via a transfer. Tick this only if the count already reflects those arrivals.',
            'bundle' => 'WarehouseOpsBundle',
        ],
        'transferOut' => [
            'field' => 'clear_transfer_out_balance',
            'label' => 'Clear all Transfer Out balance',
            'help' => 'Zeroes stock this app has recorded as having left here for another warehouse via a transfer, not yet received there. Tick this only if the count was taken with that stock already gone.',
            'bundle' => 'WarehouseOpsBundle',
        ],
        'quarantine' => [
            'field' => 'clear_quarantine_balance',
            'label' => 'Clear all Quarantine balance',
            'help' => 'Zeroes stock held here for inspection or dispute. Tick this only if the count already reflects it being released or resolved.',
            'bundle' => 'InventoryDepthBundle',
        ],
        'writeOff' => [
            'field' => 'clear_write_off_balance',
            'label' => 'Clear all Write-off balance',
            'help' => 'Zeroes stock recorded here as damaged, expired, scrapped or lost. Tick this only if the count no longer includes it.',
            'bundle' => 'InventoryDepthBundle',
        ],
        'incoming' => [
            'field' => 'clear_incoming_balance',
            'label' => 'Clear all Incoming balance',
            'help' => 'Zeroes stock this app is expecting from an open purchase order. Tick this only if the count includes that expectation and you want the forecast reset.',
            'bundle' => 'ProcurementBundle',
        ],
        'cartHold' => [
            'field' => 'clear_hold_balance',
            'label' => 'Clear all Cart Hold balance',
            'help' => 'Zeroes stock currently held by customers\' shopping carts. This is a live, moment-to-moment figure, not something a physical count observes — clearing it releases carts that have not checked out.',
            'bundle' => null,
        ],
        'salesHold' => [
            'field' => 'clear_sales_hold_balance',
            'label' => 'Clear all Sales Hold balance',
            'help' => 'Zeroes the uninvoiced remainder of sales orders. Clearing it does not un-order the stock — the order still exists, only this bucket\'s own tally resets.',
            'bundle' => null,
        ],
        'pending' => [
            'field' => 'clear_pending_balance',
            'label' => 'Clear all Pending balance',
            'help' => 'Zeroes stock held by invoices not yet approved.',
            'bundle' => null,
        ],
        'approved' => [
            'field' => 'clear_approved_balance',
            'label' => 'Clear all Approved balance',
            'help' => "Zeroes the Approved bucket for each product/region this import writes a Starting Inventory value to, and stamps a baseline on every outstanding Approved invoice reservation so the reset sticks. A fresh physical count already reflects what has been approved for shipment.",
            'bundle' => null,
        ],
        'shipped' => [
            'field' => 'clear_shipped_balance',
            'label' => 'Clear all Shipped balance',
            'help' => 'Zeroes the Shipped bucket the same way Approved above does, and stamps the same kind of baseline on Shipped invoice reservations. Independent of the Approved box: tick either, both, or neither.',
            'bundle' => null,
        ],
        'backordered' => [
            'field' => 'clear_backordered_balance',
            'label' => 'Clear all Backordered balance',
            'help' => 'Zeroes stock promised against open backorders.',
            'bundle' => null,
        ],
        'reserved' => [
            'field' => 'clear_reserved_balance',
            'label' => 'Clear all Reserved balance',
            'help' => 'Zeroes the Reserved bucket.',
            'bundle' => null,
        ],
        'manualAdjustment' => [
            'field' => 'clear_manual_adjustment_balance',
            'label' => 'Clear all Manual adjustment balance',
            'help' => 'Zeroes any manual adjustment applied directly against this bucket.',
            'bundle' => null,
        ],
    ];

    /** Whether the bucket's checkbox means anything right now — always true for a core-owned bucket, gated by its writing bundle's Active status otherwise (#564). */
    private function clearBucketOffered(string $bucket): bool
    {
        $bundle = self::CLEAR_BUCKET_FIELDS[$bucket]['bundle'] ?? null;

        return $bundle === null || $this->bundleStatuses->isActive($bundle);
    }

    /**
     * True only when the box was ticked AND (for a bundle-gated bucket) the bundle that fills it is
     * Active. A stale form or a bookmarked POST carrying the parameter with the bundle off returns
     * false here, which makes the checkbox a no-op rather than an error — clearing a bucket that is
     * already 0 genuinely is a no-op, and refusing it would turn a harmless stale submit into a
     * failed import.
     */
    private function clearBucketRequested(Request $request, string $bucket): bool
    {
        $field = self::CLEAR_BUCKET_FIELDS[$bucket]['field'];

        return $request->request->get($field) === 'yes' && $this->clearBucketOffered($bucket);
    }

    /**
     * Every bucket actually requested this submission, offered ones only — the list this
     * controller threads through index()/confirm()/runImport() in place of one scalar per bucket.
     *
     * @return list<string>
     */
    private function clearBucketsFromRequest(Request $request): array
    {
        $buckets = [];
        foreach (array_keys(self::CLEAR_BUCKET_FIELDS) as $bucket) {
            if ($this->clearBucketRequested($request, $bucket)) {
                $buckets[] = $bucket;
            }
        }

        return $buckets;
    }

    /**
     * The `clear_*_balance` options ProductImportService::import() actually reads, built from the
     * list above.
     *
     * @param list<string> $clearBuckets
     * @return array<string, bool>
     */
    private function clearBucketOptions(array $clearBuckets): array
    {
        $options = [];
        foreach (self::CLEAR_BUCKET_FIELDS as $bucket => $meta) {
            $options[$meta['field']] = \in_array($bucket, $clearBuckets, true);
        }

        return $options;
    }

    /**
     * One row per bucket for the template's loop — whether it is checked (from either a submitted
     * list or, on the blank GET form, the one bucket that used to default checked) and whether its
     * gate offers it at all.
     *
     * @param list<string> $checked
     * @return list<array{field: string, label: string, help: string, offered: bool, checked: bool}>
     */
    private function clearBucketRows(array $checked): array
    {
        $rows = [];
        foreach (self::CLEAR_BUCKET_FIELDS as $bucket => $meta) {
            $rows[] = [
                'field' => $meta['field'],
                'label' => $meta['label'],
                'help' => $meta['help'],
                'offered' => $this->clearBucketOffered($bucket),
                'checked' => \in_array($bucket, $checked, true),
            ];
        }

        return $rows;
    }

    /** Sentinel `region_ids[]` value meaning "create a new FulfillmentRegion named after this LOCATION and use it". */
    private const CREATE_REGION_SENTINEL = '__create_region__';

    /** Sentinel `fallback_category_id` value meaning "create a category per unmatched NAME segment instead of one fixed fallback". */
    private const CREATE_CATEGORY_SENTINEL = '__create_category__';

    #[Route('', name: 'admin_bundle_number1_product_import_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        ProductCsvTransformer $transformer,
        ProductImportService $importService,
        CustomFieldDefinitionRepository $fieldDefRepo,
        CustomFieldValueRepository $fieldValueRepo,
        ProductImportConfig $config,
        TireTsbcEligibility $tsbcEligibility,
        ValidatorInterface $validator,
    ): Response {
        $categories = $entityManager->getRepository(ProductCategory::class)->findBy([], ['name' => 'ASC']);
        $regions = $entityManager->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            $config->save(
                $entityManager,
                (int) $request->request->get('fallback_region_id', '0'),
                (string) $request->request->get('default_sales_tax_code', 'S')
            );

            /** @var UploadedFile|null $csv */
            $csv = $request->files->get('csv_file');

            $violations = $validator->validate($csv, new ValidCsvUpload());
            if (count($violations) > 0) {
                throw new ValidationFailedException($csv, $violations);
            }

            /** @var UploadedFile $csv */
            $fallbackCategorySelection = trim((string) $request->request->get('fallback_category_id', ''));
            $missingRows = $request->request->get('missing_rows') === 'inactivate' ? 'inactivate' : 'do_nothing';
            $storeMeta = $request->request->get('store_vendor_meta') === 'yes' ? 'yes' : 'no';
            $clearBuckets = $this->clearBucketsFromRequest($request);
            $enableTireTsbc = $request->request->get('enable_tire_tsbc') === 'yes' ? 'yes' : 'no';

            $token = bin2hex(random_bytes(16));
            $csv->move(sys_get_temp_dir(), self::PENDING_FILE_PREFIX . $token . '.csv');

            $pendingPath = $this->pendingFilePath($token);
            $pendingCsv = new UploadedFile($pendingPath, $csv->getClientOriginalName() ?: 'upload.csv', 'text/csv', null, true);
            $locations = $transformer->scanDistinctLocations($pendingCsv);

            if ($locations === []) {
                // Nothing to map — every row either has a blank LOCATION or the file has
                // no LOCATION column at all. Run the import directly with an empty
                // mapping (blank-LOCATION rows fall back to the configured fallback
                // region, if any) instead of showing an empty mapping screen for the
                // admin to click through for no reason.
                return $this->runImport($entityManager, $transformer, $importService, $fieldDefRepo, $fieldValueRepo, $config, $tsbcEligibility, $pendingCsv, [], $fallbackCategorySelection, $missingRows, $storeMeta, $clearBuckets, $enableTireTsbc, $categories);
            }

            $existingRegionIdByLocation = $this->matchExistingRegionByName($locations, $regions);

            return $this->render('@Number1ProductImport/admin/product_import/map_locations.html.twig', [
                'token' => $token,
                'locations' => $locations,
                'regions' => $regions,
                'existingRegionIdByLocation' => $existingRegionIdByLocation,
                'createRegionValue' => self::CREATE_REGION_SENTINEL,
                'fallbackCategorySelection' => $fallbackCategorySelection,
                'missingRows' => $missingRows,
                'storeMeta' => $storeMeta,
                'clearBucketRows' => $this->clearBucketRows($clearBuckets),
                'enableTireTsbc' => $enableTireTsbc,
            ]);
        }

        return $this->render('@Number1ProductImport/admin/product_import/index.html.twig', [
            'result' => null,
            'categories' => $categories,
            'createCategoryValue' => self::CREATE_CATEGORY_SENTINEL,
            'selectedFallbackCategorySelection' => $this->defaultFallbackCategorySelection($categories, $tsbcEligibility),
            'storeMeta' => false,
            // Approved defaults checked, the other thirteen do not: a fresh physical count already
            // reflects what has shipped far more often than it already reflects mid-transfer,
            // quarantined or held stock, so that one box starts pre-ticked as a convenience — never
            // a guess this app is making, since the admin still sees and submits every box as
            // ticked or not before anything runs.
            'clearBucketRows' => $this->clearBucketRows(['approved']),
            'missingRows' => 'do_nothing',
            'regions' => $regions,
            'fallbackRegionId' => $config->getFallbackRegionId(),
            'defaultSalesTaxCode' => $config->getDefaultSalesTaxCode(),
            'tsbcAvailable' => $tsbcEligibility->isAvailable(),
            'enableTireTsbc' => true,
        ]);
    }

    #[Route('/confirm', name: 'admin_bundle_number1_product_import_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        EntityManagerInterface $entityManager,
        ProductCsvTransformer $transformer,
        ProductImportService $importService,
        CustomFieldDefinitionRepository $fieldDefRepo,
        CustomFieldValueRepository $fieldValueRepo,
        ProductImportConfig $config,
        TireTsbcEligibility $tsbcEligibility,
    ): Response {
        $categories = $entityManager->getRepository(ProductCategory::class)->findBy([], ['name' => 'ASC']);

        $token = (string) $request->request->get('token', '');
        $pendingPath = $this->pendingFilePath($token);
        if ($pendingPath === null || !is_file($pendingPath)) {
            $this->addFlash('error', 'Your upload session expired. Please choose the file and try again.');

            return $this->redirectToRoute('admin_bundle_number1_product_import_index');
        }

        $locationValues = array_map('strval', (array) $request->request->all('locations'));
        $regionIdValues = array_map('strval', (array) $request->request->all('region_ids'));
        $provinceValues = array_map('strval', (array) $request->request->all('region_provinces'));
        $countryValues = array_map('strval', (array) $request->request->all('region_countries'));

        // Queue item 61: "Create a Fulfillment Region" also creates the warehouse its stock is
        // counted in, and a warehouse may not exist without a province. A LOCATION column reading
        // "Toronto DC" says nothing about where that building is, so the person mapping the file is
        // asked — the file cannot be, and guessing would stamp a province on a building nobody has
        // described and then compute tax from it forever.
        $addressErrors = $this->validateNewRegionAddresses($locationValues, $regionIdValues, $provinceValues, $countryValues);
        if ($addressErrors !== []) {
            $this->addFlash('error', sprintf(
                'Nothing was imported. %d location%s set to create a new Fulfillment Region need the province of the '
                    . 'warehouse it will create: that province is what purchase orders and vendor bills raised against '
                    . 'it compute tax from, and this file does not carry it.',
                count($addressErrors),
                count($addressErrors) === 1 ? ' is' : 's are',
            ));

            $regions = $entityManager->getRepository(FulfillmentRegion::class)->findBy([], ['name' => 'ASC']);

            return $this->render('@Number1ProductImport/admin/product_import/map_locations.html.twig', [
                'token' => $token,
                'locations' => $locationValues,
                'regions' => $regions,
                'existingRegionIdByLocation' => $this->matchExistingRegionByName($locationValues, $regions),
                'selectedRegionIdByLocation' => $this->byLocation($locationValues, $regionIdValues),
                'provinceByLocation' => $this->byLocation($locationValues, $provinceValues),
                'countryByLocation' => $this->byLocation($locationValues, $countryValues),
                'addressErrors' => $addressErrors,
                'createRegionValue' => self::CREATE_REGION_SENTINEL,
                'fallbackCategorySelection' => trim((string) $request->request->get('fallback_category_id', '')),
                'missingRows' => $request->request->get('missing_rows') === 'inactivate' ? 'inactivate' : 'do_nothing',
                'storeMeta' => $request->request->get('store_vendor_meta') === 'yes' ? 'yes' : 'no',
                'clearBucketRows' => $this->clearBucketRows($this->clearBucketsFromRequest($request)),
                'enableTireTsbc' => $request->request->get('enable_tire_tsbc') === 'yes' ? 'yes' : 'no',
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $regionsByLocation = $this->buildLocationRegionMap($entityManager, $locationValues, $regionIdValues, $provinceValues, $countryValues);

        $fallbackCategorySelection = trim((string) $request->request->get('fallback_category_id', ''));
        $missingRows = $request->request->get('missing_rows') === 'inactivate' ? 'inactivate' : 'do_nothing';
        $storeMeta = $request->request->get('store_vendor_meta') === 'yes' ? 'yes' : 'no';
        $clearBuckets = $this->clearBucketsFromRequest($request);
        $enableTireTsbc = $request->request->get('enable_tire_tsbc') === 'yes' ? 'yes' : 'no';

        $pendingCsv = new UploadedFile($pendingPath, 'number1-pending-import.csv', 'text/csv', null, true);

        return $this->runImport($entityManager, $transformer, $importService, $fieldDefRepo, $fieldValueRepo, $config, $tsbcEligibility, $pendingCsv, $regionsByLocation, $fallbackCategorySelection, $missingRows, $storeMeta, $clearBuckets, $enableTireTsbc, $categories);
    }

    #[Route('/template', name: 'admin_bundle_number1_product_import_template', methods: ['GET'])]
    public function template(): Response
    {
        $columns = [
            'NAME', 'REFNUM', 'INVITEMTYPE', 'DESC', 'QNTY', 'PRICE', 'COST', 'VALUE',
            'TAXABLE', 'SALESTAXCODE', 'ACCNT', 'ASSETACCNT', 'COGSACCNT', 'VENDOR', 'LOCATION', 'NOTES',
        ];
        $example = [
            'TIRE:12\' TIRE:PO1006H1', '1001', 'INVENTORY', '145/70R12 69T POWERTRAC SNOWMARCH', '64', '232.86', '137.89', '8824.96',
            'Y', 'S', 'Sales:Wheels', 'Inventory Asset', 'Cost of Goods Sold:COGS-Wheels', 'Wheel Supplier Ltd.', 'Warehouse C', 'Imported wheel product',
        ];

        $csv = $this->csvLine($columns) . $this->csvLine($example);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="number1-product-import-example.csv"',
        ]);
    }

    /**
     * @param array<string, FulfillmentRegion> $regionsByLocation
     * @param list<ProductCategory> $categories
     */
    private function runImport(
        EntityManagerInterface $entityManager,
        ProductCsvTransformer $transformer,
        ProductImportService $importService,
        CustomFieldDefinitionRepository $fieldDefRepo,
        CustomFieldValueRepository $fieldValueRepo,
        ProductImportConfig $config,
        TireTsbcEligibility $tsbcEligibility,
        UploadedFile $pendingCsv,
        array $regionsByLocation,
        string $fallbackCategorySelection,
        string $missingRows,
        string $storeMeta,
        array $clearBuckets,
        string $enableTireTsbc,
        array $categories,
    ): Response {
        [$fallbackCategory, $autoCreateCategory] = $this->resolveFallbackCategorySelection($entityManager, $fallbackCategorySelection);
        $fallbackRegion = $config->resolveFallbackRegion($entityManager);

        $transformResult = $transformer->transform($pendingCsv, $entityManager, $regionsByLocation, $fallbackCategory, $autoCreateCategory, $fallbackRegion);

        try {
            $result = $importService->import($transformResult->canonicalCsv, $entityManager, [
                'primary_key' => 'sku',
                'missing_rows' => $missingRows === 'inactivate' ? 'inactive_missing' : 'do_nothing',
                ...$this->clearBucketOptions($clearBuckets),
                'sync_source' => ProductCsvTransformer::SYNC_SOURCE,
            ]);
        } finally {
            @unlink($transformResult->canonicalCsvPath);
            @unlink($pendingCsv->getPathname());
        }

        $metaWritten = 0;
        if ($storeMeta === 'yes') {
            $metaWritten = $this->applyVendorMetadata($entityManager, $fieldDefRepo, $fieldValueRepo, $transformResult->vendorMetaBySku);
        }

        // Null (rather than 0) when the checkbox was off, so the summary can stay silent
        // about TSBC instead of reporting a zero the admin never asked about.
        $tsbcApplied = $enableTireTsbc === 'yes'
            ? $tsbcEligibility->applyToNewProducts($result, $entityManager)
            : null;

        $this->flashResultSummary($result, $metaWritten, $tsbcApplied);

        return $this->render('@Number1ProductImport/admin/product_import/index.html.twig', [
            'result' => $result,
            'categories' => $categories,
            'createCategoryValue' => self::CREATE_CATEGORY_SENTINEL,
            'selectedFallbackCategorySelection' => $fallbackCategorySelection,
            'storeMeta' => $storeMeta === 'yes',
            'clearBucketRows' => $this->clearBucketRows($clearBuckets),
            'missingRows' => $missingRows,
            'regions' => $entityManager->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC']),
            'fallbackRegionId' => $config->getFallbackRegionId(),
            'defaultSalesTaxCode' => $config->getDefaultSalesTaxCode(),
            'tsbcAvailable' => $tsbcEligibility->isAvailable(),
            'enableTireTsbc' => $enableTireTsbc === 'yes',
        ]);
    }

    /**
     * The upload form opens on the Tire category, since this importer exists for a tire
     * vendor's catalog and it's the category the TSBC checkbox below it keys off. Falls
     * back to "None" (today's behaviour) when no Tire category exists yet, rather than
     * creating one as a side effect of viewing the page.
     *
     * @param list<ProductCategory> $categories
     */
    private function defaultFallbackCategorySelection(array $categories, TireTsbcEligibility $tsbcEligibility): string
    {
        foreach ($categories as $category) {
            if ($category->getId() !== null && $tsbcEligibility->matchesTireCategory($category->getName())) {
                return (string) $category->getId();
            }
        }

        return '';
    }

    /**
     * @return array{0: ?ProductCategory, 1: bool} [fallback category to use directly, or
     *     null; whether to auto-create a category per unmatched NAME segment instead]
     */
    private function resolveFallbackCategorySelection(EntityManagerInterface $entityManager, string $selection): array
    {
        if ($selection === self::CREATE_CATEGORY_SENTINEL) {
            return [null, true];
        }

        $id = (int) $selection;
        if ($id > 0) {
            $category = $entityManager->getRepository(ProductCategory::class)->find($id);

            return [$category instanceof ProductCategory ? $category : null, false];
        }

        return [null, false];
    }

    private function flashResultSummary(ProductImportResult $result, int $metaWritten, ?int $tsbcApplied): void
    {
        // Named in the flash as well as on the summary tile: a flagged row is work somebody has to
        // do on the product form, and the flash is what an admin reads before scrolling.
        $unitSuffix = $result->unitFlagged > 0
            ? sprintf(' %d product(s) imported with the unit unset because the file named one this system does not have.', $result->unitFlagged)
            : '';

        if ($result->errors === []) {
            $inactiveSuffix = $result->inactivated > 0 ? sprintf(', %d set inactive', $result->inactivated) : '';
            $tsbcSuffix = $tsbcApplied === null ? '' : sprintf(' %d new Tire product(s) set eligible for TSBC.', $tsbcApplied);
            $this->addFlash('success', sprintf(
                'Import completed. %d created, %d updated, %d skipped%s. %d product(s) got Number 1 metadata custom fields.%s%s',
                $result->created,
                $result->updated,
                $result->skipped,
                $inactiveSuffix,
                $metaWritten,
                $tsbcSuffix,
                $unitSuffix
            ));

            return;
        }

        $this->addFlash('error', sprintf(
            'Import completed with %d error(s). %d created, %d updated, %d skipped.%s',
            count($result->errors),
            $result->created,
            $result->updated,
            $result->skipped,
            $unitSuffix
        ));
    }

    /**
     * Re-key a parallel POST array by its LOCATION value, so a re-render can put each typed value
     * back in the row it came from. Positional, tolerant of a short or long array, and it never
     * reorders: the two arrays arrive as parallel lists from the same form.
     *
     * @param list<string> $locationValues
     * @param list<string> $values
     * @return array<string, string>
     */
    private function byLocation(array $locationValues, array $values): array
    {
        $out = [];
        foreach ($locationValues as $i => $location) {
            $location = trim($location);
            if ($location !== '') {
                $out[$location] = trim((string) ($values[$i] ?? ''));
            }
        }

        return $out;
    }

    /**
     * Which of the mapped LOCATIONs set to "create a region" have no usable warehouse address
     * (queue item 61), keyed by LOCATION value with the message to print beside it.
     *
     * Refused as a SET before anything is written: a half-applied import that created three regions
     * and refused the fourth would leave the admin re-uploading a file whose first three LOCATIONs
     * now match by name and whose fourth still does not.
     *
     * @param list<string> $locationValues
     * @param list<string> $regionIdValues
     * @param list<string> $provinceValues
     * @param list<string> $countryValues
     * @return array<string, string>
     */
    private function validateNewRegionAddresses(array $locationValues, array $regionIdValues, array $provinceValues, array $countryValues): array
    {
        $errors = [];
        foreach ($locationValues as $i => $location) {
            $location = trim($location);
            if ($location === '' || trim((string) ($regionIdValues[$i] ?? '')) !== self::CREATE_REGION_SENTINEL) {
                continue;
            }

            $province = trim((string) ($provinceValues[$i] ?? ''));
            $country = trim((string) ($countryValues[$i] ?? ''));
            // Read through `Region` — the geo_country/geo_province rows — because that is what the
            // dropdowns on this screen are built from. It used to read `RegionSeedData`, the
            // static seed those tables were populated from: the two agree until somebody
            // deactivates a row, at which point the list stops offering it and the check goes on
            // accepting it. One list, both ends.
            $resolvedCountry = $this->region->normalizeCountry($country);

            if ($country === '' || $resolvedCountry === null || !$this->region->isValidCountry($resolvedCountry)) {
                $errors[$location] = $country === ''
                    ? 'Country is required to create this region\'s warehouse. Choose one from the list.'
                    : sprintf('"%s" is not a country this application knows. Choose one from the list.', $country);

                continue;
            }

            if ($province === '') {
                $errors[$location] = 'Province is required to create this region\'s warehouse — it is what purchase orders '
                    . 'and vendor bills raised against it compute tax from. Choose one from the list.';
            } elseif (!$this->region->isValidProvince($resolvedCountry, $province)) {
                // Scoped to THIS ROW's country. 'CA' is not a Canadian province and is California,
                // so a country-blind check would create a Canadian warehouse in a US state and
                // compute no tax on every bill raised against it.
                $errors[$location] = sprintf('"%s" is not a province or state of %s. Choose one from the list.', $province, $resolvedCountry);
            }
        }

        return $errors;
    }

    /**
     * @param list<string> $locationValues
     * @param list<string> $regionIdValues
     * @param list<string> $provinceValues
     * @param list<string> $countryValues
     * @return array<string, FulfillmentRegion>
     */
    private function buildLocationRegionMap(EntityManagerInterface $entityManager, array $locationValues, array $regionIdValues, array $provinceValues = [], array $countryValues = []): array
    {
        $map = [];
        foreach ($locationValues as $i => $location) {
            $location = trim($location);
            if ($location === '') {
                continue;
            }

            $selection = trim((string) ($regionIdValues[$i] ?? ''));
            if ($selection === self::CREATE_REGION_SENTINEL) {
                $map[$location] = $this->findOrCreateRegionByName(
                    $entityManager,
                    $location,
                    trim((string) ($provinceValues[$i] ?? '')),
                    trim((string) ($countryValues[$i] ?? '')),
                );
                continue;
            }

            $regionId = (int) $selection;
            if ($regionId <= 0) {
                continue;
            }

            $region = $entityManager->getRepository(FulfillmentRegion::class)->find($regionId);
            if ($region instanceof FulfillmentRegion) {
                $map[$location] = $region;
            }
        }

        return $map;
    }

    /**
     * @param list<string> $locations
     * @param list<FulfillmentRegion> $regions
     * @return array<string, int> location => matching region id (case-insensitive exact
     *     name match); locations with no match are simply absent from the return value.
     */
    private function matchExistingRegionByName(array $locations, array $regions): array
    {
        $idByLowerName = [];
        foreach ($regions as $region) {
            if ($region instanceof FulfillmentRegion && $region->getId() !== null) {
                $idByLowerName[strtolower($region->getName())] = $region->getId();
            }
        }

        $out = [];
        foreach ($locations as $location) {
            $id = $idByLowerName[strtolower($location)] ?? null;
            if ($id !== null) {
                $out[$location] = $id;
            }
        }

        return $out;
    }

    /**
     * Case-insensitive find-or-create — re-uploading the same file (or a file sharing a
     * LOCATION value with an earlier upload) reuses the same region instead of creating
     * a duplicate every time.
     *
     * Trimmed as well as folded since `Version20260918120000`: `uniq_fulfillment_region_name` is a
     * UNIQUE index over `LOWER(TRIM(name))`, so a `LOCATION` column reading `" Main"` against a
     * region called `Main` is the same region to the database. Matching on `LOWER()` alone would
     * have missed it and turned the flush below into a `UniqueConstraintViolationException` — a 500
     * on an import screen, for a spreadsheet with a stray space in it. `LOWER(TRIM(...))` here is
     * literally the index's own expression, which is the only way the two stay in agreement.
     */
    private function findOrCreateRegionByName(EntityManagerInterface $entityManager, string $name, string $province, string $country): FulfillmentRegion
    {
        $name = trim($name);

        $existing = $entityManager->getRepository(FulfillmentRegion::class)->createQueryBuilder('r')
            ->andWhere('LOWER(TRIM(r.name)) = :name')
            ->setParameter('name', strtolower($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existing instanceof FulfillmentRegion) {
            return $existing;
        }

        $region = (new FulfillmentRegion())->setName($name);
        $entityManager->persist($region);
        // A region with no warehouse can be picked but has nowhere to draw stock from, so the
        // building is created with it and the two are linked (#546). Without this every row
        // mapped to a freshly created LOCATION would import zero stock. The province and country
        // come from the mapping screen (queue item 61) — validated as a set before this method is
        // reached, so the factory's own refusal here is a backstop rather than the gate.
        $this->warehouses->createWarehouseForRegion($region, $province, $country);
        $entityManager->flush();

        return $region;
    }

    /**
     * @param array<string, array{qb_item_name: string, supplier: string, gl_accounts: string, notes: string}> $vendorMetaBySku
     */
    private function applyVendorMetadata(
        EntityManagerInterface $entityManager,
        CustomFieldDefinitionRepository $fieldDefRepo,
        CustomFieldValueRepository $fieldValueRepo,
        array $vendorMetaBySku,
    ): int {
        if ($vendorMetaBySku === []) {
            return 0;
        }

        $definitions = [];
        foreach (VendorProductFieldSubscriber::FIELDS as $slug => $def) {
            $definitions[$slug] = $fieldDefRepo->ensureBySlug(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, $slug, [
                'label' => $def['label'],
                'fieldType' => CustomFieldDefinition::FIELD_TYPE_TEXT,
                'visibleOnAdd' => true,
                'visibleOnEdit' => true,
                'visibleOnListing' => true,
                'source' => VendorProductFieldSubscriber::SOURCE,
            ]);
        }

        $written = 0;
        $productRepo = $entityManager->getRepository(ProductCore::class);
        foreach ($vendorMetaBySku as $sku => $meta) {
            $product = $productRepo->findOneBy(['sku' => $sku]);
            if (!$product instanceof ProductCore || $product->getId() === null) {
                continue;
            }

            $valueBySlug = [
                'number1_qb_item_name' => $meta['qb_item_name'],
                'number1_supplier' => $meta['supplier'],
                'number1_gl_accounts' => $meta['gl_accounts'],
                'number1_notes' => $meta['notes'],
            ];

            foreach ($valueBySlug as $slug => $value) {
                if ($value === '') {
                    continue;
                }
                $fieldValueRepo->setValue($definitions[$slug], $product->getId(), $value);
            }

            $written++;
        }

        $entityManager->flush();

        return $written;
    }

    private function pendingFilePath(string $token): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token) !== 1) {
            return null;
        }

        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::PENDING_FILE_PREFIX . $token . '.csv';
    }

    /** @param list<string> $cells */
    private function csvLine(array $cells): string
    {
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $cells);
        rewind($fp);
        $line = stream_get_contents($fp) ?: '';
        fclose($fp);

        return $line;
    }
}
