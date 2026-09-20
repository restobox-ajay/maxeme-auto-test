<?php

namespace App\Service\ProductImport;

use App\Contract\Fee\FeeImportColumnProviderInterface;
use App\Contract\Fee\FeeImportDefaultProviderInterface;
use App\Contract\Import\ImportRowExecutorInterface;
use App\Contract\Import\ImportRunFinalizableInterface;
use App\Entity\FulfillmentRegion;
use App\Entity\ImportRun;
use App\Contract\Inventory\DimensionalImportProviderInterface;
use App\Contract\Inventory\DimensionalImportRefusal;
use App\Entity\Warehouse;
use App\Entity\InvoiceInventoryReservation;
use App\Entity\PriceList;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\ProductPricing;
use App\Entity\UnitOfMeasure;
use App\Enum\ImportAction;
use App\Enum\ProductStatus;
use App\Repository\BundleStatusRepository;
use App\Service\AppSettings;
use App\Service\Inventory\BackorderReleaseService;
use App\Service\Inventory\CoreInventoryTotalCountService;
use App\Service\Inventory\InventoryOperationContext;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\Uom\ProductBaseUnitService;
use App\Service\Uom\UnitOfMeasureRefusal;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Implements ImportRowExecutorInterface/ImportRunFinalizableInterface (see
 * docs/plans/2026-09-18-unified-import-framework.md) so core's own /admin/imports upload path runs
 * through the same ColumnMapper/queue/flock every other import uses, via
 * ProductImportRunnerFactory. import() itself is UNCHANGED — Number1ProductImportBundle and
 * Number1RimImportBundle call it directly and are explicitly out of scope to touch — it now just
 * delegates its per-row body to processDataRow(), the same method execute() calls, instead of
 * inlining that logic in its own loop. Behaviour for those two existing callers is identical; only
 * the internal organisation changed.
 */
final class ProductImportService implements ImportRowExecutorInterface, ImportRunFinalizableInterface
{
    // Doctrine's flush() recomputes change sets over every managed entity, so without a
    // periodic clear() the row loop below goes superlinear: each successive row's flush()
    // gets more expensive as prior rows' entities pile up in the UnitOfWork even though
    // they have nothing left to change. Clearing every N rows caps that cost; the lookup
    // caches (price lists/regions/categories) get rebuilt right after each clear() because
    // clear() detaches them and stale detached entities can't be assigned to associations.
    private const CLEAR_BATCH_SIZE = 50;

    /**
     * @param iterable<object> $feeFieldProviders
     */
    /**
     * Which shape each (product, warehouse) declared in THIS run — 'total' or 'rows' (#565).
     *
     * Run-scoped rather than per-row because the two forms arrive on separate rows and the
     * contradiction is only visible once both have been seen. Reset at the top of every import so
     * one run cannot refuse a product because of what a previous run's file said.
     *
     * @var array<string, string>
     */
    private array $dimensionalShapeSeen = [];

    /** Whether bin rows the file does not mention go to 0 or stay as they are. Set per run. */
    private bool $deleteUnspecifiedBins = false;

    /**
     * Per-run state for the ImportRowExecutorInterface path only (beginRun()/execute()/finalize()).
     * import() keeps its own locals for the exact same concepts — see that method's own setup —
     * so nothing here is read by, or shared with, the legacy entry point Number1ProductImportBundle
     * and Number1RimImportBundle call directly.
     */
    private string $currentPrimaryKey = 'sku';
    private string $currentMissingRows = 'do_nothing';
    private ?string $currentSyncSource = null;
    /** @var list<string> */
    private array $currentClearBuckets = [];
    /** @var array<int|string, PriceList> */
    private array $currentPriceListById = [];
    /** @var array<string, PriceList> */
    private array $currentPriceListBySlug = [];
    /** @var array<int|string, Warehouse> */
    private array $currentWarehouseByRegionSlug = [];
    /** @var array<int|string, ProductCategory> */
    private array $currentCategoryById = [];
    /** @var array<string, ProductCategory> */
    private array $currentCategoryBySlug = [];
    /** @var array<string, bool> */
    private array $currentImportedKeys = [];
    private int $currentRowCounter = 0;
    private ?EntityManagerInterface $currentEntityManager = null;
    private ?ProductImportResult $currentResult = null;

    /**
     * True only under beginRun()/execute() — the ImportRunner-driven path (#769). ImportRunner
     * owns an `ImportRun`/`ImportRunRow` on this SAME EntityManager for the life of the whole run
     * (it flushes updates to them after every row, including the terminal status) — periodically
     * calling clear() (see CLEAR_BATCH_SIZE below) detaches those entities too, since clear() takes
     * no argument in this app's Doctrine ORM version and has no way to spare just this class's own
     * entities. Every write ImportRunner makes to the now-detached run/row after that point
     * silently never reaches the database: `executedCount` stays 0 and the run never leaves
     * 'started', even though every row's own product data keeps being written correctly. import()'s
     * own self-contained loop has no such externally-owned entity to protect, so it keeps clearing.
     */
    private bool $currentSuppressPeriodicClear = false;

    /** Set by processDataRow(), read by execute() immediately after calling it — see execute(). */
    private ?ImportAction $lastRowOutcomeAction = null;
    private ?string $lastRowOutcomeMessage = null;

    /**
     * Every bucket a recount MAY be told to clear, and the form field that says so — one flag per
     * bucket, none bundled and none defaulted, because whether a fresh count already accounts for
     * stock mid-transfer, quarantined, held in a cart, reserved or already committed to an invoice
     * is information only the person running the import has (see CoreInventoryTotalCountService's
     * own docblock for why this app never guesses it). `received` and `approved` used to each hide
     * this behind their own boolean, and `approved` used to silently take `shipped` down with it;
     * both were the same unstated assumption this list no longer makes on anyone's behalf.
     *
     * @var array<string, string>
     */
    private const CLEAR_BUCKET_OPTIONS = [
        'clear_received_balance' => 'received',
        'clear_transfer_in_balance' => 'transferIn',
        'clear_transfer_out_balance' => 'transferOut',
        'clear_quarantine_balance' => 'quarantine',
        'clear_write_off_balance' => 'writeOff',
        'clear_hold_balance' => 'cartHold',
        'clear_sales_hold_balance' => 'salesHold',
        'clear_pending_balance' => 'pending',
        'clear_approved_balance' => 'approved',
        'clear_shipped_balance' => 'shipped',
        'clear_backordered_balance' => 'backordered',
        'clear_reserved_balance' => 'reserved',
        'clear_incoming_balance' => 'incoming',
        'clear_manual_adjustment_balance' => 'manualAdjustment',
    ];

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private function clearBucketsFromOptions(array $options): array
    {
        $buckets = [];
        foreach (self::CLEAR_BUCKET_OPTIONS as $optionKey => $bucketKey) {
            if ((bool) ($options[$optionKey] ?? false)) {
                $buckets[] = $bucketKey;
            }
        }

        return $buckets;
    }

    public function __construct(
        #[AutowireIterator('app.fee_field_provider')]
        private readonly iterable $feeFieldProviders,
        private readonly BundleStatusRepository $bundleStatusRepo,
        private readonly InventoryOperationContext $operations,
        private readonly AppSettings $appSettings,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly BackorderReleaseService $backorderRelease,
        private readonly InventoryModeResolver $inventoryMode,
        private readonly \App\Service\Inventory\DimensionalImportResolver $dimensionalImport,
        private readonly ProductBaseUnitService $baseUnits,
        private readonly CoreInventoryTotalCountService $totalCounts,
    ) {}

    /**
     * @param array{primary_key: string, missing_rows: string, progress_token?: string, delete_unspecified_bins?: bool, sync_source?: ?string, ...} $options plus one `clear_*_balance` key per CoreInventoryTotalCountService bucket — see CLEAR_BUCKET_OPTIONS
     */
    public function import(UploadedFile $csv, EntityManagerInterface $entityManager, array $options): ProductImportResult
    {
        $result = new ProductImportResult();

        $primaryKey = $options['primary_key'] === 'id' ? 'id' : 'sku';
        $missingRows = $options['missing_rows'] === 'inactive_missing' ? 'inactive_missing' : 'do_nothing';
        $progressToken = $this->normalizeProgressToken($options['progress_token'] ?? '');
        // Every flag defaults FALSE: clearing a bucket is destructive (Approved/Shipped stamp
        // syncedQuantity = quantity on every outstanding reservation, which no recalc command can
        // rebuild once done) or at best a no-op, never something an unattended import should infer.
        // Each is an explicit opt-in by a caller that knows what this specific count already does
        // and does not account for — see CoreInventoryTotalCountService's own docblock for why
        // this can never be guessed, and CLEAR_BUCKET_OPTIONS above for the full list.
        $clearBuckets = $this->clearBucketsFromOptions($options);
        $this->dimensionalShapeSeen = [];
        $this->deleteUnspecifiedBins = (bool) ($options['delete_unspecified_bins'] ?? false);
        // Caller-supplied on purpose, with no default. This service is shared: the Number 1 CSV
        // import, the rim API import and core's own /admin/product/import all funnel through it,
        // so any value hardcoded here would be a lie for two of the three callers. Each entry
        // point owns its own constant and passes it (ProductCsvTransformer::SYNC_SOURCE,
        // RimApiImportService::SYNC_SOURCE, ProductImportCommand::SYNC_SOURCE); a caller that
        // passes nothing leaves syncSource null, which honestly reads as "unknown", rather than
        // inheriting somebody else's provenance. See issue #477.
        $syncSource = $this->nullableText($options['sync_source'] ?? null);
        $totalRows = $this->countDataRows($csv);
        $processedRows = 0;

        // processDataRow() (the per-row body below, shared with execute()) reads these off $this
        // rather than as closed-over locals — see that method's own docblock.
        $this->currentPrimaryKey = $primaryKey;
        $this->currentMissingRows = $missingRows;
        $this->currentSyncSource = $syncSource;
        $this->currentClearBuckets = $clearBuckets;
        $this->currentEntityManager = $entityManager;
        $this->currentResult = $result;
        $this->currentImportedKeys = [];
        $this->currentSuppressPeriodicClear = false;

        if ($progressToken !== '') {
            $this->writeProgress($progressToken, [
                'started' => true,
                'complete' => false,
                'totalRows' => $totalRows,
                'processedRows' => 0,
                'currentRow' => 0,
                'currentKey' => '',
                'status' => 'starting',
                'message' => 'Preparing import...',
            ]);
        }

        [$this->currentPriceListById, $this->currentPriceListBySlug, $this->currentWarehouseByRegionSlug, $this->currentCategoryById, $this->currentCategoryBySlug] = $this->loadImportLookups($entityManager);

        $file = new \SplFileObject($csv->getPathname());
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(',');

        $header = null;
        $rowNumber = 0;

        foreach ($file as $row) {
            $rowNumber++;
            if (!is_array($row) || $row === [null]) {
                continue;
            }

            if ($header === null) {
                $header = $this->normalizeHeader($row);
                continue;
            }

            $data = $this->rowToMap($header, $row);
            if ($data === []) {
                continue;
            }

            $result->totalRows++;
            $processedRows++;

            $this->processDataRow($data, $rowNumber, $progressToken, $processedRows, $totalRows);
        }

        if ($this->currentMissingRows === 'inactive_missing' && $this->currentImportedKeys !== []) {
            $result->inactivated = $this->inactivateMissing($entityManager, $primaryKey, $this->currentImportedKeys);
        }

        if ($progressToken !== '') {
            $this->writeProgress($progressToken, [
                'started' => true,
                'complete' => true,
                'totalRows' => $result->totalRows,
                'processedRows' => $result->totalRows,
                'currentRow' => $result->rowResults !== [] ? (int) end($result->rowResults)['row'] : 0,
                'currentKey' => $result->rowResults !== [] ? (string) end($result->rowResults)['key'] : '',
                'status' => $result->errorRows > 0 ? 'complete_with_errors' : 'complete',
                'message' => $result->errorRows > 0 ? 'Import completed with some errors.' : 'Import completed successfully.',
                'summary' => [
                    'created' => $result->created,
                    'updated' => $result->updated,
                    'skipped' => $result->skipped,
                    'duplicates' => $result->duplicates,
                    'validRows' => $result->validRows,
                    'warningRows' => $result->warningRows,
                    'errorRows' => $result->errorRows,
                    'unitFlagged' => $result->unitFlagged,
                ],
            ]);
        }

        return $result;
    }

    /**
     * One row's worth of work — everything from resolving the primary key to the periodic
     * clear()+cache-reload — shared verbatim by import()'s own loop and execute() below. Reads its
     * per-run context off $this->current* (see those properties' own docblock) rather than taking
     * it positionally, so neither caller has to thread a dozen parameters through.
     *
     * $progressToken/$processedRows/$totalRows are import()-only concerns (writeProgress() is a
     * no-op on a blank token, which is what execute() below always passes) — kept as parameters
     * rather than promoted to $this->current* because, unlike everything else here, they are
     * genuinely meaningless outside import()'s own progress-polling UI.
     */
    private function processDataRow(array $data, int $rowNumber, string $progressToken, int $processedRows, int $totalRows): void
    {
        $result = $this->currentResult;
        $entityManager = $this->currentEntityManager;
        $primaryKey = $this->currentPrimaryKey;

        $keyValue = $this->keyValue($data, $primaryKey);
        if ($keyValue === '') {
            $message = sprintf('Missing primary key "%s".', $primaryKey);
            $result->errors[] = sprintf('Row %d: %s', $rowNumber, $message);
            $this->pushRowResult($result, $this->rowResult($rowNumber, '', $data, 'error', 'Skipped', [$message], []));
            $this->updateProgressFromRowResult($progressToken, $result, $processedRows, $totalRows, $rowNumber, '', 'error', $message);
            $result->skipped++;
            $this->lastRowOutcomeMessage = $message;

            return;
        }

        $importKey = $primaryKey . ':' . $keyValue;
        if (isset($this->currentImportedKeys[$importKey])) {
            $message = sprintf('Duplicate %s "%s" found in the CSV file. Row skipped.', strtoupper($primaryKey), $keyValue);
            $result->warnings[] = sprintf('Row %d (%s=%s): %s', $rowNumber, $primaryKey, $keyValue, $message);
            $this->pushRowResult($result, $this->rowResult($rowNumber, $keyValue, $data, 'duplicate', 'Duplicate', [$message], []));
            $this->updateProgressFromRowResult($progressToken, $result, $processedRows, $totalRows, $rowNumber, $keyValue, 'duplicate', $message);
            $result->skipped++;
            $result->duplicates++;
            $this->lastRowOutcomeMessage = $message;

            return;
        }

        $product = $this->findOrCreateProduct($entityManager, $primaryKey, $keyValue);
        if (!$product instanceof ProductCore) {
            $message = 'Product not found.';
            $result->errors[] = sprintf('Row %d (%s=%s): %s', $rowNumber, $primaryKey, $keyValue, $message);
            $this->pushRowResult($result, $this->rowResult($rowNumber, $keyValue, $data, 'error', 'Skipped', [$message], []));
            $this->updateProgressFromRowResult($progressToken, $result, $processedRows, $totalRows, $rowNumber, $keyValue, 'error', $message);
            $result->skipped++;
            $this->lastRowOutcomeMessage = $message;

            return;
        }

        $isNew = $product->getId() === null;
        $rowIssues = [];
        $rowNotes = [];
        $rowErrors = $this->validateRowData($data, $isNew);
        if ($rowErrors !== []) {
            foreach ($rowErrors as $message) {
                $result->errors[] = sprintf('Row %d (%s=%s): %s', $rowNumber, $primaryKey, $keyValue, $message);
            }
            $this->pushRowResult($result, $this->rowResult($rowNumber, $keyValue, $data, 'error', 'Skipped', $rowErrors, []));
            $this->updateProgressFromRowResult($progressToken, $result, $processedRows, $totalRows, $rowNumber, $keyValue, 'error', implode(' | ', $rowErrors));
            $result->skipped++;
            $this->lastRowOutcomeMessage = implode(' | ', $rowErrors);

            return;
        }
        $this->applyCoreFields($product, $data, $rowNotes, $result, $rowIssues);
        $this->applyCategory($product, $data, $this->currentCategoryById, $this->currentCategoryBySlug);

        // Creation only. syncSource records where a product came from, not who last touched
        // it, so re-importing an existing SKU must not rewrite it — and re-stamping here
        // would silently move a rim-API product out of RimApiImportService's
        // syncSource-scoped inactivate-missing set, which is a behaviour change this issue
        // explicitly must not make.
        if ($isNew && $this->currentSyncSource !== null) {
            $product->setSyncSource($this->currentSyncSource);
        }

        $entityManager->persist($product);
        if ($isNew) {
            // A brand-new product has no id yet, and applyInventory()/applyPricing() below
            // look up its ProductInventory/ProductPricing rows by 'product' => $product — that
            // criterion only works once the product has a real identifier, so this flush is
            // required here. An existing product already has one, so it can skip this flush
            // and pick up its row's changes in the single flush() at the end of the loop body.
            $entityManager->flush();
            $this->applyFeeImportDefaults($product, $entityManager);
        }
        $this->applyFeeImportColumns($product, $data, $result, $rowNumber, $keyValue, $rowIssues);

        $this->applyInventory($product, $data, $this->currentWarehouseByRegionSlug, $entityManager, $result, $rowNumber, $keyValue, $rowIssues, $this->currentClearBuckets);
        $this->applyPricing($product, $data, $this->currentPriceListById, $this->currentPriceListBySlug, $entityManager, $result, $rowNumber, $keyValue, $rowIssues);

        $entityManager->flush();

        $this->currentImportedKeys[$importKey] = true;
        if ($isNew) {
            $result->created++;
            $result->rowLog[] = ['row' => $rowNumber, 'key' => $keyValue, 'action' => 'created'];
            $this->pushRowResult($result, $this->rowResult($rowNumber, $keyValue, $data, $rowIssues === [] ? 'success' : 'warning', 'Created', $rowIssues, $rowNotes));
            $this->updateProgressFromRowResult($progressToken, $result, $processedRows, $totalRows, $rowNumber, $keyValue, $rowIssues === [] ? 'success' : 'warning', $rowIssues === [] ? 'Created successfully.' : implode(' | ', $rowIssues));
            $this->lastRowOutcomeAction = ImportAction::Append;
        } else {
            $result->updated++;
            $result->rowLog[] = ['row' => $rowNumber, 'key' => $keyValue, 'action' => 'updated'];
            $this->pushRowResult($result, $this->rowResult($rowNumber, $keyValue, $data, $rowIssues === [] ? 'success' : 'warning', 'Updated', $rowIssues, $rowNotes));
            $this->updateProgressFromRowResult($progressToken, $result, $processedRows, $totalRows, $rowNumber, $keyValue, $rowIssues === [] ? 'success' : 'warning', $rowIssues === [] ? 'Updated successfully.' : implode(' | ', $rowIssues));
            $this->lastRowOutcomeAction = ImportAction::Update;
        }
        $this->lastRowOutcomeMessage = null;

        // See CLEAR_BATCH_SIZE: without this, flush() cost grows with every prior row's
        // still-managed entities and the import goes superlinear on large files. clear()
        // detaches everything, including the lookup caches above, so they're rebuilt from
        // fresh (small, cheap) queries right after — the next row's findOrCreateProduct()
        // starts clean regardless, since it always re-queries or constructs a new entity.
        //
        // Skipped entirely under currentSuppressPeriodicClear (#769): clear() takes no argument in
        // this app's Doctrine ORM version, so it cannot spare just this class's own entities — under
        // ImportRunner it would also detach the ImportRun/ImportRunRow ImportRunner keeps flushing
        // updates to for the rest of the run, silently losing every one of those writes (including
        // the terminal 'completed' status and the executed-row count) the moment a run crosses this
        // boundary, while each row's own product data kept being written correctly. import()'s own
        // loop owns nothing external on this EntityManager, so it is unaffected and keeps clearing.
        if (!$this->currentSuppressPeriodicClear && $rowNumber % self::CLEAR_BATCH_SIZE === 0) {
            $entityManager->clear();
            [$this->currentPriceListById, $this->currentPriceListBySlug, $this->currentWarehouseByRegionSlug, $this->currentCategoryById, $this->currentCategoryBySlug] = $this->loadImportLookups($entityManager);
        }
    }

    /**
     * Prepares per-run state for the execute()/finalize() path — the ImportRowExecutorInterface
     * equivalent of import()'s own setup above. Called once by ProductImportRowExecutorFactory
     * before ImportRunner starts calling execute() per row.
     *
     * @param array{primary_key?: string, missing_rows?: string, delete_unspecified_bins?: bool, sync_source?: ?string, ...} $options plus one `clear_*_balance` key per bucket — see CLEAR_BUCKET_OPTIONS
     */
    public function beginRun(array $options, EntityManagerInterface $entityManager): void
    {
        $this->currentPrimaryKey = ($options['primary_key'] ?? 'sku') === 'id' ? 'id' : 'sku';
        $this->currentMissingRows = ($options['missing_rows'] ?? 'do_nothing') === 'inactive_missing' ? 'inactive_missing' : 'do_nothing';
        $this->currentClearBuckets = $this->clearBucketsFromOptions($options);
        $this->dimensionalShapeSeen = [];
        $this->deleteUnspecifiedBins = (bool) ($options['delete_unspecified_bins'] ?? false);
        $this->currentSyncSource = $this->nullableText($options['sync_source'] ?? null);
        $this->currentImportedKeys = [];
        $this->currentRowCounter = 0;
        $this->currentEntityManager = $entityManager;
        $this->currentResult = new ProductImportResult();
        $this->currentSuppressPeriodicClear = true;

        [$this->currentPriceListById, $this->currentPriceListBySlug, $this->currentWarehouseByRegionSlug, $this->currentCategoryById, $this->currentCategoryBySlug] = $this->loadImportLookups($entityManager);
    }

    /**
     * ImportRowExecutorInterface — called once per row, only from inside import:process, only
     * after beginRun() has prepared this run's context. Delegates to the exact same
     * processDataRow() import()'s own loop calls; see this class's own docblock for why that
     * makes the two entry points behaviourally identical rather than two implementations that can
     * drift apart.
     *
     * @param array<string, mixed> $mappedData
     */
    public function execute(array $mappedData): ImportAction
    {
        ++$this->currentRowCounter;
        $this->currentResult->totalRows++;
        $this->lastRowOutcomeAction = null;
        $this->lastRowOutcomeMessage = 'Row skipped.';

        $this->processDataRow($mappedData, $this->currentRowCounter, '', 0, 0);

        return $this->lastRowOutcomeAction ?? throw new \RuntimeException($this->lastRowOutcomeMessage ?? 'Row skipped.');
    }

    /**
     * ImportRunFinalizableInterface — the missing_rows: inactive_missing pass, run once after
     * every row via ImportRunner, mirroring import()'s own post-loop call to the same method. Also
     * appends unitFlagged onto $run->description — see ImportRunFinalizableInterface's own
     * docblock for why: it is the one count that must not be able to hide inside an otherwise-green
     * run (queue item 9, #624), and the generic per-run/per-row ledger has no field of its own for
     * an import-specific figure like this.
     */
    public function finalize(ImportRun $run): void
    {
        if ($this->currentMissingRows === 'inactive_missing' && $this->currentImportedKeys !== []) {
            $this->currentResult->inactivated = $this->inactivateMissing($this->currentEntityManager, $this->currentPrimaryKey, $this->currentImportedKeys);
        }

        if ($this->currentResult->unitFlagged > 0) {
            $run->setDescription(trim($run->getDescription() . sprintf(' — %d unit(s) flagged for review', $this->currentResult->unitFlagged)));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function readProgress(string $token): array
    {
        $token = $this->normalizeProgressToken($token);
        if ($token === '') {
            return ['started' => false, 'complete' => false];
        }

        $path = $this->progressFile($token);
        if (!is_file($path)) {
            return ['started' => false, 'complete' => false];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return ['started' => false, 'complete' => false];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['started' => false, 'complete' => false];
        }

        return is_array($decoded) ? $decoded : ['started' => false, 'complete' => false];
    }

    public function templateCsv(EntityManagerInterface $entityManager): string
    {
        $regions = $entityManager->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC']);
        $priceLists = $entityManager->getRepository(PriceList::class)->findBy(['status' => 'Active'], ['id' => 'ASC']);

        $columns = [
            'sku',
            'name',
            'status',
            'category',
            'type',
            'unit',
            'weight',
            'cost_price',
            'original_price',
            'deposit',
            'visible',
            'private',
            'deleted',
            'sales_tax_code',
            'remarks',
            'short_description',
            'long_description',
        ];

        foreach ($regions as $region) {
            if ($region instanceof FulfillmentRegion) {
                $columns[] = 'fulfillment_region__' . $this->slug($region->getName());
            }
        }

        foreach ($priceLists as $pl) {
            if ($pl instanceof PriceList && $pl->getId() !== null) {
                $columns[] = 'price__' . (string) $pl->getId();
            }
        }

        foreach ($this->feeImportColumns() as $feeColumn) {
            $columns[] = $feeColumn;
        }

        $example = array_fill(0, count($columns), '');
        $example[array_search('sku', $columns, true)] = 'SKU-001';
        $example[array_search('name', $columns, true)] = 'Example product name';
        $example[array_search('status', $columns, true)] = ProductStatus::Active->value;
        $example[array_search('visible', $columns, true)] = 'Yes';
        $example[array_search('private', $columns, true)] = 'No';
        $example[array_search('deleted', $columns, true)] = 'No';

        $csv = $this->csvLine($columns) . $this->csvLine($example);

        return $csv;
    }

    public function templateGuideWorkbook(EntityManagerInterface $entityManager): string
    {
        $regions = $entityManager->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC']);
        $priceLists = $entityManager->getRepository(PriceList::class)->findBy(['status' => 'Active'], ['id' => 'ASC']);

        $columns = [
            'sku',
            'name',
            'status',
            'category',
            'type',
            'unit',
            'weight',
            'cost_price',
            'original_price',
            'deposit',
            'visible',
            'private',
            'deleted',
            'sales_tax_code',
            'remarks',
            'short_description',
            'long_description',
        ];

        foreach ($regions as $region) {
            if ($region instanceof FulfillmentRegion) {
                $columns[] = 'fulfillment_region__' . $this->slug($region->getName());
            }
        }

        foreach ($priceLists as $pl) {
            if ($pl instanceof PriceList && $pl->getId() !== null) {
                $columns[] = 'price__' . (string) $pl->getId();
            }
        }

        foreach ($this->feeImportColumns() as $feeColumn) {
            $columns[] = $feeColumn;
        }

        $example = array_fill(0, count($columns), '');
        $example[array_search('sku', $columns, true)] = 'SKU-001';
        $example[array_search('name', $columns, true)] = 'Example product name';
        $example[array_search('status', $columns, true)] = ProductStatus::Active->value;
        $example[array_search('category', $columns, true)] = 'Beverage';
        // The unit example is READ from the instance's own units of measure rather than written
        // here. It used to say `12/Case`, which is a pack size and not a unit — and the import
        // taught it to everyone who downloaded the guide, which is how products arrive counted in
        // bags and cases. Any literal put here would teach a vocabulary this instance may not have,
        // and #659 made the vocabulary the one place a conversion is defined, so the guide shows a
        // term that really exists or shows nothing at all.
        $unitExample = $this->firstUnitOfMeasureCode($entityManager);
        $example[array_search('unit', $columns, true)] = $unitExample;
        $example[array_search('weight', $columns, true)] = '10lbs';
        $example[array_search('cost_price', $columns, true)] = '8.50';
        $example[array_search('original_price', $columns, true)] = '12.00';
        $example[array_search('deposit', $columns, true)] = '0.00';
        $example[array_search('visible', $columns, true)] = 'Yes';
        $example[array_search('private', $columns, true)] = 'No';
        $example[array_search('deleted', $columns, true)] = 'No';

        $instructionRows = [
            ['Field', 'Required', 'How to use'],
            ['sku', 'Yes', 'Unique product SKU. Do not repeat the same SKU twice in one import file.'],
            ['name', 'Yes for new products', 'Required when the SKU does not already exist in the system.'],
            ['status', 'Recommended', sprintf(
                'Use one of: %s. Anything else is not applied and the row is reported as a warning. '
                    . 'Draft means the product is not ready to transact and is waiting on somebody — it is not '
                    . 'sellable, not in the customer catalogue and not counted.',
                implode(', ', ProductStatus::values()),
            )],
            ['category', 'Optional', 'Use an existing category name or category ID.'],
            ['unit', 'Optional', $unitExample === ''
                ? 'Use a unit code or name from Units of Measure. No units exist yet — add one there first. '
                    . 'A pack size such as "12/Case" is not a unit of measure: a row carrying one still imports, '
                    . 'with its unit left unset and flagged on the product form for someone to settle. The import '
                    . 'never creates a unit.'
                : sprintf(
                    'Use a unit code or name from Units of Measure, for example %s. A pack size such as "12/Case" '
                        . 'is not a unit of measure: a row carrying one still imports, with its unit left unset and '
                        . 'flagged on the product form for someone to settle. The import never creates a unit.',
                    $unitExample,
                )],
            ['weight', 'Optional', 'Use 10, 10.5, 10lbs, or 10 kg. The importer auto-cleans the number and stores only 10 or 10.5.'],
            ['cost_price / original_price / deposit', 'Optional', 'Use numeric values only, for example 12.50. Do not type currency symbols.'],
            ['fulfillment_region__main', 'Optional', 'Use a whole number only, for example 0, 5, or 100.'],
            ['price__1', 'Optional', 'Use a numeric price only. Keep the template price/inventory columns exactly as downloaded.'],
            ['fee__BC-tsbc', 'Optional', 'Use 1/yes/true to enable, 0/no/false to disable. Blank leaves the existing value alone.'],
            ['Blank optional fields', 'Allowed', 'Leave blank if you do not want to update that value.'],
            ['Upload rule', 'Important', 'Upload CSV only. If you edit this guide in Excel, save the Products sheet back as CSV before importing.'],
        ];

        return $this->spreadsheetXmlWorkbook([
            ['name' => 'Products', 'rows' => [$columns, $example]],
            ['name' => 'Instructions', 'rows' => $instructionRows],
        ]);
    }

    /**
     * The lowest unit code this instance actually has, or '' when it has none.
     *
     * One row, ordered, rather than the whole table: the vocabulary is global and unbounded under
     * #659 — every distinct pack size anybody ships is a term — so the guide asks for an example,
     * not a catalogue.
     */
    private function firstUnitOfMeasureCode(EntityManagerInterface $entityManager): string
    {
        $units = $entityManager->getRepository(UnitOfMeasure::class)->findBy([], ['code' => 'ASC'], 1);
        $first = $units[0] ?? null;

        return $first instanceof UnitOfMeasure ? $first->getCode() : '';
    }

    /**
     * @return array{0: array<int, PriceList>, 1: array<string, PriceList>, 2: array<string, Warehouse>, 3: array<int, ProductCategory>, 4: array<string, ProductCategory>}
     */
    private function loadImportLookups(EntityManagerInterface $entityManager): array
    {
        $priceLists = $entityManager->getRepository(PriceList::class)->findBy([], ['id' => 'ASC']);
        $priceListById = [];
        $priceListBySlug = [];
        foreach ($priceLists as $pl) {
            if ($pl instanceof PriceList) {
                $priceListById[(int) $pl->getId()] = $pl;
                $priceListBySlug[$this->slug($pl->getName())] = $pl;
            }
        }

        // The CSV's columns are `fulfillment_region__<slug of region name>` — that is the
        // published import format and it stays a REGION name, because that is what the person
        // filling in the sheet knows. What the stock lands in is the warehouse serving that
        // region (#546).
        $warehouseByRegionSlug = [];
        foreach ($this->warehouses->warehousesByLowerRegionName() as $lowerRegionName => $warehouse) {
            $warehouseByRegionSlug[$this->slug($lowerRegionName)] = $warehouse;
        }

        $categories = $entityManager->getRepository(ProductCategory::class)->findBy([], ['id' => 'ASC']);
        $categoryById = [];
        $categoryBySlug = [];
        foreach ($categories as $cat) {
            if ($cat instanceof ProductCategory) {
                $categoryById[(int) $cat->getId()] = $cat;
                $categoryBySlug[$this->slug($cat->getName())] = $cat;
            }
        }

        return [$priceListById, $priceListBySlug, $warehouseByRegionSlug, $categoryById, $categoryBySlug];
    }

    /** @param list<mixed> $row */
    private function normalizeHeader(array $row): array
    {
        $header = [];
        foreach ($row as $cell) {
            $key = $this->normalizeHeaderKey((string) $cell);
            $header[] = $key;
        }

        return $header;
    }

    /**
     * Public so ProductImportController can pre-fill the (now real, ColumnMapper-driven) mapping
     * screen: a CSV whose headers already match what this importer expects — the common case,
     * since templateCsv() hands out exactly these names — needs the admin to do nothing but glance
     * and click Import, same UX as before this import went through the framework.
     */
    public function normalizeHeaderKey(string $value): string
    {
        $value = trim($value);
        $value = strtolower($value);
        $value = str_replace(['-', ' '], '_', $value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? $value;
        $value = preg_replace('/_+/', '_', $value) ?? $value;
        $value = trim($value, '_');

        if (str_starts_with($value, 'fulfillment_region_') && !str_starts_with($value, 'fulfillment_region__')) {
            $value = 'fulfillment_region__' . substr($value, strlen('fulfillment_region_'));
        }

        if (str_starts_with($value, 'price_') && !str_starts_with($value, 'price__') && !in_array($value, ['price_list', 'price_list_id'], true)) {
            $value = 'price__' . substr($value, strlen('price_'));
        }

        if (str_starts_with($value, 'fee_') && !str_starts_with($value, 'fee__')) {
            $value = 'fee__' . substr($value, strlen('fee_'));
        }

        return $value;
    }

    /**
     * @param list<string> $header
     * @param list<mixed> $row
     * @return array<string, string>
     */
    private function rowToMap(array $header, array $row): array
    {
        $map = [];
        foreach ($header as $i => $key) {
            if ($key === '') {
                continue;
            }
            $value = isset($row[$i]) ? trim((string) $row[$i]) : '';
            $map[$key] = $value;
        }

        // Drop rows that are completely empty.
        foreach ($map as $value) {
            if ($value !== '') {
                return $map;
            }
        }

        return [];
    }

    /** @param array<string, string> $data */
    private function keyValue(array $data, string $primaryKey): string
    {
        if ($primaryKey === 'id') {
            $value = $data['id'] ?? '';
            return ctype_digit($value) ? $value : '';
        }

        return trim($data['sku'] ?? '');
    }

    private function findOrCreateProduct(EntityManagerInterface $entityManager, string $primaryKey, string $keyValue): ?ProductCore
    {
        if ($primaryKey === 'id') {
            $product = $entityManager->find(ProductCore::class, (int) $keyValue);
            return $product instanceof ProductCore ? $product : null;
        }

        $existing = $entityManager->getRepository(ProductCore::class)->findOneBy(['sku' => $keyValue]);
        if ($existing instanceof ProductCore) {
            return $existing;
        }

        return (new ProductCore())->setSku($keyValue);
    }

    /**
     * @param array<string, string> $data
     * @param list<string> $rowNotes
     * @param list<string> $rowIssues
     */
    private function applyCoreFields(ProductCore $product, array $data, array &$rowNotes, ProductImportResult $result, array &$rowIssues): void
    {
        if (($data['name'] ?? '') !== '') {
            $product->setName($data['name']);
        }

        // The file's own status cell used to be written into the column verbatim, which is how a
        // free-string status with no declared set stayed free-string: a spreadsheet saying 'active'
        // or 'ACTIVE' produced a row that no `= 'Active'` comparison anywhere matched, and nothing
        // told anybody. A value ProductStatus knows is applied through the entity's verb; anything
        // else leaves the product's status alone and is reported on the row (a warning, not an
        // error — the rest of the row is good and the product still imports).
        $rawStatus = trim((string) ($data['status'] ?? ''));
        if ($rawStatus !== '') {
            $status = ProductStatus::tryFrom($rawStatus);
            if ($status !== null) {
                $product->applyStatusChoice($status);
            } else {
                $rowIssues[] = sprintf(
                    'Status "%s" is not a product status this application knows, so it was not applied and the '
                        . 'product kept "%s". The statuses are %s.',
                    $rawStatus,
                    $product->getStatus(),
                    implode(', ', ProductStatus::values()),
                );
            }
        }

        // Blank optional cells mean "leave this value alone" per the Import Template Guide's own
        // instructions — only a non-blank cell should overwrite an existing product's field.
        if (array_key_exists('plant', $data) && trim((string) $data['plant']) !== '') {
            $product->setPlant($this->nullableText($data['plant']));
        }
        if (array_key_exists('type', $data) && trim((string) $data['type']) !== '') {
            $product->setType($this->nullableText($data['type']));
        }
        $this->applyUnit($product, $data, $result, $rowIssues);
        if (array_key_exists('weight', $data) && trim((string) $data['weight']) !== '') {
            $rawWeight = trim((string) $data['weight']);
            $normalizedWeight = $this->normalizeImportedWeight($rawWeight);
            $product->setWeight($normalizedWeight);
            if ($normalizedWeight !== null && $normalizedWeight !== $rawWeight) {
                $rowNotes[] = sprintf('Weight normalized from "%s" to "%s".', $rawWeight, $normalizedWeight);
            }
        }

        if (array_key_exists('cost_price', $data) && trim((string) $data['cost_price']) !== '') {
            $product->setCostPrice($this->nullableMoney($data['cost_price']));
        }
        if (array_key_exists('original_price', $data) && trim((string) $data['original_price']) !== '') {
            $product->setOriginalPrice($this->nullableMoney($data['original_price']));
        }
        if (array_key_exists('deposit', $data) && trim((string) $data['deposit']) !== '') {
            $product->setDeposit($this->nullableMoney($data['deposit']));
        }

        if (array_key_exists('visible', $data) && $data['visible'] !== '') {
            $product->setVisible($this->boolValue($data['visible']));
        }
        if (array_key_exists('private', $data) && $data['private'] !== '') {
            $product->setPrivate($this->boolValue($data['private']));
        }
        if (array_key_exists('deleted', $data) && $data['deleted'] !== '') {
            $product->setDeleted($this->boolValue($data['deleted']));
        }

        // Sales tax code: an explicit value wins; a blank cell or a missing column defaults to the
        // "default_sales_tax_code" setting (GST + PST taxable, "S", unless an admin changes it) rather
        // than leaving the product Exempt, which is what an unset code maps to at tax time. See issue #115.
        $salesTaxCode = $this->normalizeSalesTaxCode($this->nullableText($data['sales_tax_code'] ?? null));
        $defaultSalesTaxCode = $this->normalizeSalesTaxCode($this->appSettings->get('default_sales_tax_code', 'S')) ?: 'S';
        $product->setSalesTaxCode($salesTaxCode ?? $defaultSalesTaxCode);
        if (array_key_exists('remarks', $data) && trim((string) $data['remarks']) !== '') {
            $product->setRemarks($this->nullableText($data['remarks']));
        }
        if (array_key_exists('short_description', $data) && trim((string) $data['short_description']) !== '') {
            $product->setShortDescription($this->nullableText($data['short_description']));
        }
        if (array_key_exists('long_description', $data) && trim((string) $data['long_description']) !== '') {
            $product->setLongDescription($this->nullableText($data['long_description']));
        }

        $short = trim($data['short_description'] ?? '');
        $long = trim($data['long_description'] ?? '');
        if ($long !== '' || $short !== '') {
            $product->setDescription($long !== '' ? $long : $short);
        }

        $product->touch();
    }

    /**
     * The `unit` cell: DECLARED when it names a real unit of measure, and flagged when it does not.
     *
     * ## What was wrong
     *
     * This method used to be one line — `setUnit($data['unit'])` — writing whatever the file said
     * straight into the free-text label. The import's own template guide documented the column with
     * the example `12/Case`, which is a PACK SIZE and not a unit of measure, and the live data shows
     * what that teaches: `Anchor Bolt Sleeve M12 (bag of 50)` imported with U/M `BAG`. If `BAG` is
     * what the product is counted in then its stock is counted in bags, and every quantity ever
     * recorded against it means something other than the person entering it intended.
     *
     * ## The ruling, which is the shape of this method
     *
     * **Accept the row, leave the unit unset, flag it for a human.** Not reject it, and not invent
     * a unit:
     *
     *  - One bad cell must not kill a 5,000-row import, so an unresolvable value is a row WARNING
     *    and never an error. The product, its prices and its stock all land.
     *  - The importer genuinely cannot know the ratio. `12/Case` says a case holds twelve of
     *    something — not twelve of what, and not what the base unit is. Under #659 a pack IS a unit
     *    (`CASE-12`, with a factor of 12 on {@see \App\Entity\UnitOfMeasure}), but WHICH term a
     *    given `12/Case` meant is a person's call. Guessing would write a number nobody typed.
     *  - So the product imports and simply cannot be sold by the case until somebody says what a
     *    case is.
     *
     * ## The flag is one that already existed
     *
     * A product that declares no base unit while carrying a label matching no unit's code or name is
     * flagged on the product form — "Unmapped: this product's stored label is `12/Case`, which
     * matches no unit's code or name" — beside the select that resolves it. That flag is why the
     * raw cell is still written to the legacy label here: it is the evidence a human needs, and
     * dropping it would leave the row indistinguishable from a product whose `unit` cell was blank.
     * What the import no longer does is DECLARE anything from it: `product_core.unit_id` stays NULL,
     * so nothing is counted in a pack size.
     *
     * A value that DOES name a unit is declared through {@see ProductBaseUnitService::assign()},
     * which is also what keeps the label in step — the import is not allowed its own opinion about
     * what the two columns should say, and `EA` in a file means the same thing as `EA` chosen on the
     * form.
     *
     * No import ever creates a `unit_of_measure` row. A unit freezes the moment a document
     * references it, so letting a file mint one is how a typo becomes permanent.
     *
     * ## Two cases that keep an existing declaration
     *
     * A product that already declares a unit keeps it, and the row is flagged instead:
     *
     *  - the cell does not resolve — overwriting the label of a product whose declaration is sound
     *    would recreate exactly the disagreement between `unit` and `unit_id` that #601 removed;
     *  - the cell resolves to a DIFFERENT unit and something is already counted in the old one —
     *    `assign()` refuses, because re-pointing it would restate every one of those figures at once
     *    without moving a thing. The refusal is caught rather than thrown: it is one row's problem,
     *    not the import's.
     *
     * @param array<string, string> $data
     * @param list<string> $rowIssues
     */
    private function applyUnit(ProductCore $product, array $data, ProductImportResult $result, array &$rowIssues): void
    {
        // A blank cell or an absent column means "leave this value alone", the rule every optional
        // column in this file follows — and here that covers the declaration as well as the label.
        if (!array_key_exists('unit', $data)) {
            return;
        }

        $raw = trim((string) $data['unit']);
        if ($raw === '') {
            return;
        }

        $unit = $this->baseUnits->resolveLegacyLabel($raw);

        if ($unit !== null) {
            try {
                $this->baseUnits->assign($product, $unit);

                return;
            } catch (UnitOfMeasureRefusal $refusal) {
                $result->unitFlagged++;
                $rowIssues[] = sprintf(
                    'Unit "%s" was not applied: %s The product imported with the unit it already had.',
                    $raw,
                    $refusal->getMessage(),
                );

                return;
            }
        }

        $result->unitFlagged++;

        if ($product->getBaseUnit() !== null) {
            $rowIssues[] = sprintf(
                'Unit "%s" matches no unit of measure, so it was not applied. This product already declares %s '
                    . 'and keeps it. Add the term on Units of Measure if the file is right.',
                $raw,
                $product->getBaseUnit()->getCode(),
            );

            return;
        }

        $product->setUnit($raw);
        $rowIssues[] = sprintf(
            'Unit "%s" matches no unit of measure, so the product imported with its unit unset and is flagged on '
                . 'its product form. A pack size — "12/Case" — is not a unit: declare the base unit on the product, '
                . 'then add the pack as its own term on Units of Measure.',
            $raw,
        );
    }

    /**
     * @param array<string, string> $data
     * @return list<string>
     */
    private function validateRowData(array $data, bool $isNew): array
    {
        $issues = [];

        if ($isNew && trim((string) ($data['name'] ?? '')) === '') {
            $issues[] = 'Product name is required because this SKU does not exist yet. Fill the "name" column for new products.';
        }

        foreach ([
            'cost_price' => 'Cost Price',
            'original_price' => 'Original Price',
            'sale_price' => 'Sale Price',
            'deposit' => 'Deposit',
        ] as $field => $label) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '' && !$this->isNonNegativeNumeric($value)) {
                $issues[] = sprintf('%s must be a number like 12 or 12.50. Do not use negative values or extra text.', $label);
            }
        }

        $weight = trim((string) ($data['weight'] ?? ''));
        if ($weight !== '' && $this->normalizeImportedWeight($weight) === null) {
            $issues[] = 'Weight must start with a valid non-negative number, for example 10, 10.5, or 10lbs. Values like -5 or text without a number are not allowed.';
        }

        foreach ($data as $key => $value) {
            if (($key === '' || $value === '')) {
                continue;
            }

            if (str_starts_with($key, 'fulfillment_region__') && !$this->isIntegerLike($value)) {
                $issues[] = sprintf('Inventory value for "%s" must be a whole number like 0, 5, or 25.', $key);
            }

            if (str_starts_with($key, 'price__') && !$this->isNonNegativeNumeric($value)) {
                $issues[] = sprintf('Price value for "%s" must be a number like 12 or 12.50. Do not use currency symbols or text.', $key);
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param array<string, string> $data
     * @param array<int, ProductCategory> $categoryById
     * @param array<string, ProductCategory> $categoryBySlug
     */
    private function applyCategory(ProductCore $product, array $data, array $categoryById, array $categoryBySlug): void
    {
        $categoryId = $data['category_id'] ?? '';
        if ($categoryId !== '' && ctype_digit($categoryId)) {
            $cat = $categoryById[(int) $categoryId] ?? null;
            $product->setCategory($cat instanceof ProductCategory ? $cat : null);
            return;
        }

        $categoryName = $data['category'] ?? ($data['category_name'] ?? '');
        if (trim($categoryName) === '') {
            return;
        }

        $slug = $this->slug($categoryName);
        $product->setCategory($categoryBySlug[$slug] ?? null);
    }

    // Only called for newly-created products (see $isNew in import()) — never re-stamps
    // a default onto a product an admin has already manually configured on a re-import.
    private function applyFeeImportDefaults(ProductCore $product, EntityManagerInterface $entityManager): void
    {
        $applied = false;
        foreach ($this->feeFieldProviders as $provider) {
            if ($provider instanceof FeeImportDefaultProviderInterface && $this->bundleStatusRepo->isActiveForInstance($provider)) {
                $provider->applyImportDefault($product);
                $applied = true;
            }
        }

        if ($applied) {
            $entityManager->flush();
        }
    }

    /**
     * Runs for every row (new or existing) — an explicit CSV column value is an
     * admin's stated choice, so it always wins over applyFeeImportDefaults()'s
     * category-seeded default and whatever was stored on a prior import. A
     * fee__* column with no matching provider is flagged the same way an
     * unrecognized fulfillment_region__/price__ column already is.
     *
     * @param array<string, string> $data
     * @param list<string> $rowIssues
     */
    private function applyFeeImportColumns(ProductCore $product, array $data, ProductImportResult $result, int $rowNumber, string $keyValue, array &$rowIssues): void
    {
        // Matched against the column name after the same normalizeHeaderKey() transform the CSV
        // header itself already went through — a provider can declare a natural-looking name
        // (e.g. 'fee__BC-tsbc') without needing to know it must already be lowercase/underscored.
        $providersByColumn = [];
        foreach ($this->feeFieldProviders as $provider) {
            if ($provider instanceof FeeImportColumnProviderInterface && $this->bundleStatusRepo->isActiveForInstance($provider)) {
                $providersByColumn[$this->normalizeHeaderKey($provider->getImportColumnName())] = $provider;
            }
        }

        foreach ($data as $key => $value) {
            if (!str_starts_with($key, 'fee__') || $value === '') {
                continue;
            }

            $provider = $providersByColumn[$key] ?? null;
            if ($provider === null) {
                $message = sprintf('Unknown fee column "%s".', $key);
                $result->warnings[] = sprintf('Row %d (%s): %s', $rowNumber, $keyValue, $message);
                $rowIssues[] = $message;
                continue;
            }

            $provider->applyImportValue($product, $this->boolValue($value));
        }
    }

    /** Public so ProductImportDefinition::targetFields() can build the dynamic mapping-screen list. */
    /** @return list<string> */
    public function feeImportColumns(): array
    {
        $columns = [];
        foreach ($this->feeFieldProviders as $provider) {
            if ($provider instanceof FeeImportColumnProviderInterface && $this->bundleStatusRepo->isActiveForInstance($provider)) {
                $columns[] = $provider->getImportColumnName();
            }
        }

        return $columns;
    }

    /**
     * @param array<string, string> $data
     * @param array<string, Warehouse> $warehouseByRegionSlug
     */
    /** @param list<string> $clearBuckets */
    private function applyInventory(ProductCore $product, array $data, array $warehouseByRegionSlug, EntityManagerInterface $entityManager, ProductImportResult $result, int $rowNumber, string $keyValue, array &$rowIssues, array $clearBuckets = []): void
    {
        // #550, and the dangerous one of the three quantity writers. A CSV setting a flat quantity
        // on a dimensional product would wipe out its bin/lot/serial breakdown unnoticed, precisely
        // because a bulk import is not read line by line — nobody would see it happen. So a
        // dimensional product's inventory columns are skipped and the skip is WARNED about rather
        // than silent: the importer needs to know the file did not do what it says. Everything else
        // on the row (name, prices, fees, status) still applies.
        //
        // Never reached for a simple product, and never reached at all with no depth bundle
        // installed, so every existing import behaves exactly as it did.
        if ($this->inventoryMode->isDimensional($product)) {
            $provider = $this->dimensionalImport->activeProvider();

            // No provider — bundle deleted or Inactive — means the advanced mode does not exist, so
            // the old behaviour stands: skip the quantity, and WARN rather than skip silently,
            // because a bulk import is not read line by line and nobody would otherwise see that
            // the file did not do what it says.
            if ($provider === null) {
                $hasInventoryColumns = false;
                foreach (array_keys($data) as $key) {
                    if (str_starts_with((string) $key, 'fulfillment_region__') && ($data[$key] ?? '') !== '') {
                        $hasInventoryColumns = true;
                        break;
                    }
                }

                if ($hasInventoryColumns) {
                    $message = 'Product is on dimensional inventory; its quantity is maintained by its bin, lot and serial breakdown and was NOT changed by this file.';
                    $result->warnings[] = sprintf('Row %d (%s): %s', $rowNumber, $keyValue, $message);
                    $rowIssues[] = $message;
                }

                return;
            }

            // A dimensional product's detail rows are its own richer reconciliation — see
            // DimensionalImportProvider::applyDeclaration() — and only ever clear `received`; the
            // other thirteen flags have no effect here, the same way this product's Starting
            // quantity itself is skipped above rather than read from the file.
            $this->applyDimensionalInventory($product, $data, $warehouseByRegionSlug, $entityManager, $result, $rowNumber, $keyValue, $rowIssues, $provider, \in_array('received', $clearBuckets, true));

            return;
        }

        foreach ($data as $key => $value) {
            if (!str_starts_with($key, 'fulfillment_region__') || $value === '') {
                continue;
            }

            $slug = substr($key, strlen('fulfillment_region__'));
            $warehouse = $warehouseByRegionSlug[$slug] ?? null;
            if (!$warehouse instanceof Warehouse) {
                $message = sprintf('Unknown fulfillment region "%s".', $slug);
                $result->warnings[] = sprintf('Row %d (%s): %s', $rowNumber, $keyValue, $message);
                $rowIssues[] = $message;
                continue;
            }

            $qty = (int) preg_replace('/[^0-9\-]+/', '', $value);
            $qty = max(0, $qty);

            // Read before the write: whether this row is a RESTOCK is the difference between the
            // two numbers, and an import that lowers a count is not one (#548).
            $previousQuantity = $entityManager->getRepository(ProductInventory::class)->findOneBy([
                'product' => $product,
                'warehouse' => $warehouse,
            ])?->getQuantity() ?? '0.0000';

            // The write, and the one detail row behind it, together — CoreInventoryTotalCountService
            // is the one place a fresh count of a non-dimensional product's stock gets written, for
            // every caller, not just this one. Every checked bucket folds in here rather than as a
            // later reset: they used to need separate flushes to get separate operation names in
            // the change log (#582), and consolidating the write into one call makes that moot —
            // one flush, one name, 'import_count_set'.
            $row = $this->operations->run(
                'import_count_set',
                fn (): ProductInventory => $this->totalCounts->setCount($product, $warehouse, $qty, $clearBuckets),
            );

            // Approved/Shipped carry a second effect setCount() does not and must not know about:
            // an invoice reservation baseline, so the reset sticks rather than silently reappearing
            // the next time InventoryReservationReconciler runs. Its OWN operation, same as before —
            // the change log has always named this reset apart from the count write.
            $clearedReservationBuckets = array_intersect($clearBuckets, ['approved', 'shipped']);
            if ($clearedReservationBuckets !== []) {
                $this->operations->run('import_approved_reset', function () use ($product, $warehouse, $entityManager, $clearedReservationBuckets): void {
                    $this->stampApprovedShippedReservationBaselines($product, $warehouse, $entityManager, $clearedReservationBuckets);
                });
            }

            $entityManager->persist($row);

            // Stock arriving by import is stock arriving (#548), so it shrinks a preorder cap and
            // clears the waiting queue on exactly the same terms as an admin typing a new figure
            // into the inventory grid — same primitive, same audit trail, same 'System' resolver.
            // A row that has opted into neither, which is every row by default, is untouched.
            //
            // Deliberately AFTER the approved-balance reset above: that reset is what makes a
            // recount's own quantity stop counting against availability, and releasing before it
            // would measure the arrival against a bucket the recount is about to clear.
            $this->backorderRelease->applyRestock($row, $previousQuantity, $entityManager);
        }
    }


    /**
     * The second effect of clearing Approved and/or Shipped that CoreInventoryTotalCountService's
     * generic bucket-zeroing does not, and must not, know about: a baseline on every outstanding
     * invoice reservation in whichever of those two buckets was actually named, so the reset
     * sticks rather than silently reappearing next time InventoryReservationReconciler runs.
     *
     * `shipped` joined this alongside `approved` on 2026-09-15
     * (docs/plans/2026-09-15-shipment-approved-to-shipped-bucket.md): it holds exactly the same
     * "released by import/recount only" rule `approved` always has — an invoice reaching Completed
     * only relabels its hold from `approved` to `shipped`, it does not change which mechanism gets
     * to release it. They are independent flags now (#565-followup), not a pair — a caller may
     * clear one without the other, and this only ever stamps the reservation buckets it was
     * actually told to.
     *
     * Split out of applyInventory() so it can run inside its own ambient operation (#582): the log
     * row for the bucket change is written from the Doctrine changeset, and the only thing that can
     * tell it this was 'import_approved_reset' rather than 'import_count_set' is which operation
     * was open when the flush at the end of this method ran.
     *
     * @param list<string> $clearedBuckets 'approved' and/or 'shipped' — never empty, the caller only invokes this when at least one was named
     */
    private function stampApprovedShippedReservationBaselines(ProductCore $product, Warehouse $warehouse, EntityManagerInterface $entityManager, array $clearedBuckets): void
    {
        $reservationBuckets = [];
        if (\in_array('approved', $clearedBuckets, true)) {
            $reservationBuckets[] = InvoiceInventoryReservation::BUCKET_APPROVED;
        }
        if (\in_array('shipped', $clearedBuckets, true)) {
            $reservationBuckets[] = InvoiceInventoryReservation::BUCKET_SHIPPED;
        }

        // max(0, quantity - syncedQuantity) is what actually contributes to
        // approvedQuantity/shippedQuantity (see InventoryReservationReconciler::reconcile()), so
        // setting syncedQuantity = quantity here makes the reset stick — if one of these documents
        // is edited later, only the amount beyond this recount re-enters its bucket instead of the
        // whole thing silently reappearing.
        //
        // Both buckets are the INVOICE's from #539 stage 3 (and 2026-09-15 for shipped), so the
        // baselines are stamped on the invoice ledger. An order's own ledger only ever holds
        // `sales_hold`, which no recount clears — that quantity has not been billed, let alone
        // shipped.
        $reservations = $entityManager->getRepository(InvoiceInventoryReservation::class)->findBy([
            'product' => $product,
            'warehouse' => $warehouse,
            'bucket' => $reservationBuckets,
        ]);
        foreach ($reservations as $reservation) {
            $reservation->setSyncedQuantity($reservation->getQuantity())->touch();
            $entityManager->persist($reservation);
        }

        $entityManager->flush();
    }

    /**
     * The advanced import for one dimensional product (#565).
     *
     * Two shapes are legal and they are mutually exclusive:
     *
     *  - a product-level total per warehouse, with no bin columns — the difference lands on the
     *    sentinel row and is allowed to go negative
     *  - bin rows, which ARE the declaration and whose sum is the total
     *
     * Both at once is two answers to one question, so that product is refused outright: its rows
     * and its total are left exactly as they were, the summary names it, and **the rest of the file
     * still imports**. A run that aborts on one contradictory product would satisfy a naive "it was
     * rejected" assertion while being unusable against a 5,000-row file.
     *
     * @param array<string, string>   $data
     * @param array<string, Warehouse> $warehouseByRegionSlug
     * @param list<string>            $rowIssues
     */
    private function applyDimensionalInventory(
        ProductCore $product,
        array $data,
        array $warehouseByRegionSlug,
        EntityManagerInterface $entityManager,
        ProductImportResult $result,
        int $rowNumber,
        string $keyValue,
        array &$rowIssues,
        DimensionalImportProviderInterface $provider,
        bool $clearReceivedBalance,
    ): void {
        $advanced = [];
        foreach ($provider->advancedColumns() as $column) {
            $value = trim((string) ($data[$column] ?? ''));
            if ($value !== '') {
                $advanced[$column] = $value;
            }
        }

        $totals = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'fulfillment_region__') && trim((string) $value) !== '') {
                $totals[substr($key, strlen('fulfillment_region__'))] = (int) preg_replace('/[^0-9\-]+/', '', (string) $value);
            }
        }

        if ($advanced !== [] && \count($totals) > 1) {
            $message = 'Row carries bin/lot detail and more than one region total. Bin rows declare one warehouse; this product was left unchanged.';
            $result->warnings[] = sprintf('Row %d (%s): %s', $rowNumber, $keyValue, $message);
            $rowIssues[] = $message;

            return;
        }

        foreach ($totals as $slug => $declared) {
            $warehouse = $warehouseByRegionSlug[$slug] ?? null;
            if (!$warehouse instanceof Warehouse) {
                $message = sprintf('Unknown fulfillment region "%s".', $slug);
                $result->warnings[] = sprintf('Row %d (%s): %s', $rowNumber, $keyValue, $message);
                $rowIssues[] = $message;
                continue;
            }

            // A product that already declared bin rows in this file cannot also declare a total,
            // and vice versa. Tracked across the run because the two forms arrive on separate rows.
            $shape = $advanced === [] ? 'total' : 'rows';
            $seenKey = ($product->getId() ?? $keyValue) . '@' . ($warehouse->getId() ?? 0);
            $previous = $this->dimensionalShapeSeen[$seenKey] ?? null;

            if ($previous !== null && $previous !== $shape) {
                $message = 'Product declares both a total and bin rows in this file. Those are two answers to one question, so it was left exactly as it was.';
                $result->warnings[] = sprintf('Row %d (%s): %s', $rowNumber, $keyValue, $message);
                $rowIssues[] = $message;
                continue;
            }
            $this->dimensionalShapeSeen[$seenKey] = $shape;

            // The core row FIRST, and always (#572). The provider plugs its sentinel row against
            // `quantity + received`, so both have to be settled before it runs — and `quantity` is
            // written from this file whatever the checkbox says. See rebaselineCoreRow().
            $row = $this->coreRow($product, $warehouse, $entityManager);
            $previousQuantity = $row->getQuantity();
            $previousReceived = $row->getReceivedQuantity();

            // The operation the change log records this under (#582). It has to be open across
            // rebaselineCoreRow()'s own flush, which is where the `received` reset becomes a
            // changeset the listener can see — hence a run() around the call rather than anything
            // inside the method.
            $this->operations->run('import_count_rebaseline', function () use ($row, $declared, $clearReceivedBalance, $entityManager): void {
                $this->rebaselineCoreRow($row, $declared, $clearReceivedBalance, $entityManager);
            });

            try {
                $warnings = $provider->applyDeclaration(
                    $product,
                    $warehouse,
                    $declared,
                    $advanced === [] ? [] : [$advanced + ['quantity' => (string) $declared]],
                    $this->deleteUnspecifiedBins,
                );
            } catch (DimensionalImportRefusal $refusal) {
                // A refused declaration leaves the product exactly as it was found, which now has to
                // include the core row this method wrote a moment ago.
                //
                // Named, like the rebaseline it is undoing (#582). Putting `received` back is a
                // bucket change in its own right and lands in the change log as one; a row saying
                // 'import_count_rebaseline: 50 -> 0' with no matching row putting it back is a
                // history that lies about where the stock went.
                $this->operations->run('import_rebaseline_reverted', function () use ($row, $previousQuantity, $previousReceived, $entityManager): void {
                    $row->setQuantity($previousQuantity)->setReceivedQuantity($previousReceived);
                    $entityManager->persist($row);
                    $entityManager->flush();
                });

                $result->warnings[] = sprintf('Row %d (%s): %s', $rowNumber, $keyValue, $refusal->getMessage());
                $rowIssues[] = $refusal->getMessage();
                continue;
            }

            foreach ($warnings as $warning) {
                $result->warnings[] = sprintf('Row %d (%s): %s', $rowNumber, $keyValue, $warning);
            }
        }
    }

    /** The (product, warehouse) stock row, existing or brand new. Not persisted or written here. */
    private function coreRow(ProductCore $product, Warehouse $warehouse, EntityManagerInterface $entityManager): ProductInventory
    {
        return $entityManager->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $warehouse,
        ]) ?? (new ProductInventory())->setProduct($product)->setWarehouse($warehouse);
    }

    /**
     * Writes the core row from the import: `quantity` always, `received` only if the box was ticked.
     *
     * Two separate rules, and the bug this replaces was running them as one (#572).
     *
     * **`quantity` is written every time, mechanically, straight from the file.** It is the client's
     * number, this app is not its author, and an import is the only thing that sets it — so the
     * declared figure goes in whether or not anybody ticked anything. It was previously written only
     * when the box was ticked, and derived from `availableTotal()` rather than from the file, which
     * made the client's own figure conditional on a checkbox about a different column and let it
     * drift from what they actually sent us.
     *
     * **`clear_received_balance` governs `received` and nothing else.** Ticked means the person
     * running the import is telling us their file already includes the deliveries we booked since
     * the last one, so the bucket goes to zero. Unticked means DO NOT TOUCH IT — not "clear it if it
     * looks stale", not "it was zero anyway". Only they know: the file carries no count date, so
     * there is nothing to compare against, and clearing a `received` that was still true undercounts
     * the stock.
     *
     * The detail rows are reconciled either way — that is what applyDeclaration() does, and the
     * sentinel row plugs the difference against whatever this leaves behind.
     *
     * `incoming` is deliberately NOT touched. It is stock on a purchase order that has not arrived,
     * so a count of what is on the shelf observes nothing about it — zeroing it would delete a
     * forecast the count never saw. The negative buckets are left alone for the same reason: they
     * are claims made since the snapshot, and clearing `approved` is already its own explicit
     * opt-in rather than a side effect of counting.
     */
    private function rebaselineCoreRow(
        ProductInventory $row,
        int $declared,
        bool $clearReceivedBalance,
        EntityManagerInterface $entityManager,
    ): void {
        $row->setQuantity($declared)->touch();

        if ($clearReceivedBalance) {
            $row->setReceivedQuantity(0);
        }

        $entityManager->persist($row);

        // Flushed rather than left to the importer's batch, because the very next thing that runs is
        // the provider reading `quantity + received` back out of this row to size its sentinel. A
        // row that is only persisted is invisible to that query.
        //
        // That flush is also what App\EventSubscriber\InventoryBucketChangeLogger reads (#582), so
        // the whole method runs inside the 'import_count_rebaseline' operation opened by the caller
        // below — the `received` reset above is only a log row's worth of history if the operation
        // naming it is still open when this line runs. The `if ($previousReceived !== 0)` guard the
        // old manual call needed is gone with it: a write of 0 over 0 produces no changeset entry,
        // so it produces no row, without anyone having to check.
        $entityManager->flush();
    }

    /**
     * @param array<string, string> $data
     * @param array<int, PriceList> $priceListById
     * @param array<string, PriceList> $priceListBySlug
     */
    private function applyPricing(ProductCore $product, array $data, array $priceListById, array $priceListBySlug, EntityManagerInterface $entityManager, ProductImportResult $result, int $rowNumber, string $keyValue, array &$rowIssues): void
    {
        // Simple mode (one price list + one sale price)
        $simplePrice = $data['sale_price'] ?? '';
        if ($simplePrice !== '') {
            $list = null;
            $id = $data['price_list_id'] ?? '';
            if ($id !== '' && ctype_digit($id)) {
                $list = $priceListById[(int) $id] ?? null;
            } elseif (($data['price_list'] ?? '') !== '') {
                $list = $priceListBySlug[$this->slug($data['price_list'])] ?? null;
            }

            if ($list instanceof PriceList) {
                $this->upsertPricing($product, $list, $simplePrice, $entityManager);
            } else {
                $message = '"sale_price" was provided but no valid price list was found.';
                $result->warnings[] = sprintf('Row %d (%s): %s', $rowNumber, $keyValue, $message);
                $rowIssues[] = $message;
            }
        }

        // Grid mode (multiple price list columns)
        foreach ($data as $key => $value) {
            if (!str_starts_with($key, 'price__') || $value === '') {
                continue;
            }

            $suffix = substr($key, strlen('price__'));
            $list = null;
            if (ctype_digit($suffix)) {
                $list = $priceListById[(int) $suffix] ?? null;
            } else {
                $list = $priceListBySlug[$suffix] ?? null;
            }

            if (!$list instanceof PriceList) {
                $message = sprintf('Unknown price list "%s".', $suffix);
                $result->warnings[] = sprintf('Row %d (%s): %s', $rowNumber, $keyValue, $message);
                $rowIssues[] = $message;
                continue;
            }

            $this->upsertPricing($product, $list, $value, $entityManager);
        }
    }

    private function upsertPricing(ProductCore $product, PriceList $priceList, string $value, EntityManagerInterface $entityManager): void
    {
        $price = $this->nullableMoney($value) ?? '0.00';
        $pricing = $entityManager->getRepository(ProductPricing::class)->findOneBy([
            'product' => $product,
            'priceList' => $priceList,
        ]) ?? (new ProductPricing())->setProduct($product)->setPriceList($priceList);

        $pricing->setPrice($price)->setCurrency($priceList->getCurrency());
        $entityManager->persist($pricing);
    }

    /**
     * @param array<string, true> $importedKeys
     */
    private function inactivateMissing(EntityManagerInterface $entityManager, string $primaryKey, array $importedKeys): int
    {
        $count = 0;
        $products = $entityManager->getRepository(ProductCore::class)->findBy(['deleted' => false], ['id' => 'ASC']);
        foreach ($products as $product) {
            if (!$product instanceof ProductCore) {
                continue;
            }

            $keyValue = $primaryKey === 'id' ? (string) ($product->getId() ?? '') : $product->getSku();
            if ($keyValue === '') {
                continue;
            }

            if (!isset($importedKeys[$primaryKey . ':' . $keyValue])) {
                if ($product->getStatusEnum() !== ProductStatus::Inactive) {
                    $product->deactivate()->touch();
                    $count++;
                }
            }
        }

        if ($count > 0) {
            $entityManager->flush();
        }

        return $count;
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

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    // Same legacy-input mapping as Admin\ProductController::normalizeSalesTaxCode() —
    // import files may still carry pre-short-code values.
    private function normalizeSalesTaxCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return match (strtoupper($code)) {
            '0', 'EXEMPT', 'E' => 'E',
            '5', 'GST', 'G' => 'G',
            'L', 'PST', 'S' => 'S',
            default => $code,
        };
    }

    /**
     * Strips thousands commas AND a leading currency symbol before casting — found missing the
     * second half while testing a real vendor CSV against ProcurementBundle's vendor-sheet
     * importer (docs/plans/2026-09-15-vendor-sheet-and-po-csv-import.md), which hits the exact
     * same shape of cell. Without it, a cost/price/deposit column exported as "$1.82" silently
     * became $0.00: (float) "$1.82" is 0.0, not an error — the "$" is dropped from nowhere, not
     * caught anywhere, and the row still reports success.
     */
    private function nullableMoney(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return number_format((float) str_replace([',', '$'], '', $value), 2, '.', '');
    }

    private function normalizeImportedWeight(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $normalized = str_replace(',', '', $value);
        if ($this->isNonNegativeNumeric($normalized)) {
            return $this->normalizeNumericString($normalized);
        }

        if (preg_match('/^\s*([+-]?\d+(?:\.\d+)?)/', $normalized, $matches) !== 1) {
            return null;
        }

        $number = $matches[1] ?? '';
        if ($number === '' || !is_numeric($number) || (float) $number < 0) {
            return null;
        }

        return $this->normalizeNumericString($number);
    }

    private function normalizeNumericString(string $value): string
    {
        $value = str_replace(',', '', trim($value));
        if ($value === '') {
            return '0';
        }

        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return ltrim($value, '+');
    }

    /**
     * @param list<array{name:string,rows:list<list<string>>}> $worksheets
     */
    private function spreadsheetXmlWorkbook(array $worksheets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<?mso-application progid="Excel.Sheet"?>';
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
        $xml .= ' xmlns:o="urn:schemas-microsoft-com:office:office"';
        $xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
        $xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $xml .= '<Styles>';
        $xml .= '<Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#EAF7F3" ss:Pattern="Solid"/></Style>';
        $xml .= '</Styles>';

        foreach ($worksheets as $worksheet) {
            $xml .= '<Worksheet ss:Name="' . $this->xmlAttr($worksheet['name']) . '"><Table>';
            foreach ($worksheet['rows'] as $rowIndex => $row) {
                $xml .= '<Row>';
                foreach ($row as $cell) {
                    $style = $rowIndex === 0 ? ' ss:StyleID="Header"' : '';
                    $xml .= '<Cell' . $style . '><Data ss:Type="String">' . $this->xmlText($cell) . '</Data></Cell>';
                }
                $xml .= '</Row>';
            }
            $xml .= '</Table></Worksheet>';
        }

        $xml .= '</Workbook>';

        return $xml;
    }

    private function xmlText(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function xmlAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function isNonNegativeNumeric(string $value): bool
    {
        $normalized = str_replace(',', '', trim($value));
        return $normalized !== '' && is_numeric($normalized) && (float) $normalized >= 0;
    }

    private function isIntegerLike(string $value): bool
    {
        return preg_match('/^\s*-?\d+\s*$/', $value) === 1;
    }

    private function boolValue(string $value): bool
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['1', 'yes', 'y', 'true', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'no', 'n', 'false', 'off'], true)) {
            return false;
        }

        return $value !== '';
    }

    /**
     * Public so ProductImportDefinition can build the same `fulfillment_region__<slug>` field key
     * this class's own templateCsv()/loadImportLookups()/normalizeHeaderKey() all use — a second,
     * independently-written slug() there (`-`-joined, this one `_`-joined) is what let a multi-word
     * region name's key mismatch everywhere it mattered (#780).
     */
    public function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['-', ' '], '_', $value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? $value;
        $value = preg_replace('/_+/', '_', $value) ?? $value;

        return trim($value, '_');
    }

    private function normalizeProgressToken(string $token): string
    {
        $token = trim($token);

        return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token) === 1 ? $token : '';
    }

    private function progressDir(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'import_progress';
    }

    private function progressFile(string $token): string
    {
        return $this->progressDir() . DIRECTORY_SEPARATOR . $token . '.json';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeProgress(string $token, array $payload): void
    {
        if ($token === '') {
            return;
        }

        $dir = $this->progressDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        if (!is_string($json)) {
            return;
        }

        @file_put_contents($this->progressFile($token), $json);
    }

    private function countDataRows(UploadedFile $csv): int
    {
        $file = new \SplFileObject($csv->getPathname());
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(',');

        $headerSeen = false;
        $count = 0;
        foreach ($file as $row) {
            if (!is_array($row) || $row === [null]) {
                continue;
            }

            if (!$headerSeen) {
                $headerSeen = true;
                continue;
            }

            foreach ($row as $cell) {
                if (trim((string) $cell) !== '') {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    private function updateProgressFromRowResult(
        string $token,
        ProductImportResult $result,
        int $processedRows,
        int $totalRows,
        int $rowNumber,
        string $keyValue,
        string $status,
        string $message
    ): void {
        if ($token === '') {
            return;
        }

        $this->writeProgress($token, [
            'started' => true,
            'complete' => false,
            'totalRows' => $totalRows,
            'processedRows' => $processedRows,
            'currentRow' => $rowNumber,
            'currentKey' => $keyValue,
            'status' => $status,
            'message' => $message,
            'summary' => [
                'created' => $result->created,
                'updated' => $result->updated,
                'skipped' => $result->skipped,
                'duplicates' => $result->duplicates,
                'validRows' => $result->validRows,
                'warningRows' => $result->warningRows,
                'errorRows' => $result->errorRows,
            ],
        ]);
    }

    /**
     * @param array<string, string> $data
     * @param list<string> $issues
     * @param list<string> $notes
     * @return array{row:int,key:string,sku:string,name:string,status:string,action:string,issues:list<string>,notes:list<string>}
     */
    private function rowResult(int $rowNumber, string $keyValue, array $data, string $status, string $action, array $issues, array $notes): array
    {
        return [
            'row' => $rowNumber,
            'key' => $keyValue,
            'sku' => trim((string) ($data['sku'] ?? '')),
            'name' => trim((string) ($data['name'] ?? '')),
            'status' => $status,
            'action' => $action,
            'issues' => array_values(array_unique(array_filter($issues, static fn (string $issue): bool => trim($issue) !== ''))),
            'notes' => array_values(array_unique(array_filter($notes, static fn (string $note): bool => trim($note) !== ''))),
        ];
    }

    /**
     * @param array{row:int,key:string,sku:string,name:string,status:string,action:string,issues:list<string>,notes:list<string>} $row
     */
    private function pushRowResult(ProductImportResult $result, array $row): void
    {
        $result->rowResults[] = $row;

        $status = strtolower($row['status']);
        if ($status === 'error') {
            $result->errorRows++;
            return;
        }

        if ($status === 'warning' || $status === 'duplicate') {
            $result->warningRows++;
        }

        $result->validRows++;
    }
}
