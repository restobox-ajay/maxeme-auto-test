# Plan: Unified Import Framework

Status: **design, nothing written yet.**
Date: 2026-09-18

## Overview

Today, imports are built ad-hoc: ProductImportService, VendorSheetImport, and future line importers each own validation, audit, column mapping, and UI. This plan defines a single framework all imports follow, so machinery is built once and reused.

**The lifecycle every import follows:**

```
1. Identify entity(ies) — what table/entity are we writing to?
2. Define identity fields + editable fields — what's the key, what can change?
3. Map columns — CSV column → entity field (reuse: ColumnMapper)
4. Declare actions — append | update | delete (or subset)
5. Validate (optional dry-run) — check rows without persisting
6. Execute — validate + persist
7. Audit — log every run + every row (reuse: ImportRun + ImportRunRow)
```

All imports support steps 1-4, 6-7. Step 5 (validation-only mode) is optional per import.

**Example admin listing:**

| Import Name | Description | Action | Mode | Date/Time | Total | Validated | Errors | Status |
|---|---|---|---|---|---|---|---|---|
| Vendor Sheet | Vendor: Acme Corp | update | ✓ Executed | 2026-09-18 14:23 | 145 | 144 | 0 | completed |
| Product | Source: QB import | append | ⚪ Validation | 2026-09-18 10:15 | 32 | 32 | 0 | completed |
| PO Line | PO: PO-2847 Vendor: XYZ | append, update | ✓ Executed | 2026-09-17 16:45 | 200 | 198 | 2 | completed |
| Vendor Sheet | Vendor: Supplier B | update | ✓ Executed | 2026-09-17 09:00 | 50 | 47 | 3 | completed |
| Product | (file parse failed) | — | ⚪ Validation | 2026-09-16 15:30 | 0 | 0 | ✗ | completed |

---

## 1. Core entities (shared across all imports)

### `ImportRun` — one row per import attempt

```
id (PK)
import_type        varchar: 'vendor_sheet' | 'product' | 'po_line' | 'order_line' | etc.
entity_type        varchar: 'VendorPrice' | 'ProductCore' | 'PurchaseOrderLine' | etc.
description        varchar: free-text context per import (e.g., "Vendor: Acme", "PO: PO-123")
action             varchar: comma-separated actions taken ('append' | 'update' | 'delete')
validation_only    bool: true if this was a dry-run, false if actual execution
status             enum: queued | started | completed
row_count          int: total rows in the file
validated_count    int: rows that passed validation
executed_count     int: rows that were actually persisted (0 if validation_only=true)
error_count        int: rows that failed validation
error              text nullable: top-level error (e.g., file parse failure, unhandled exception)
queued_at          datetime nullable: when dispatched/uploaded
started_at         datetime nullable: when processing began
finished_at        datetime nullable: when processing ended
created_at         datetime: when this record was created
updated_at         datetime: when this record was last updated

-- Queue/trigger metadata:
source             enum: 'ui' | 'cli' (where the import was queued from)
attempt            int: how many times "Retry Queue" was used to re-drain after this ran (0 normally)

-- Import-specific columns (optional, per import type):
vendor_id          FK nullable: for vendor sheet, po line imports
document_id        FK nullable: for line-item imports (PO, Order, etc.)
document_type      varchar nullable: 'purchase_order' | 'sales_order' | etc.
```

### `ImportRunRow` — one row per CSV row per import attempt

```
id (PK)
import_run_id      FK -> ImportRun (cascade delete)
row_number         int: position in the file (1-indexed)
input_data         json: the CSV row as-is (all columns)
mapped_data        json: after column mapping (target field => value)
action             enum: append | update | delete
status             enum: validated | succeeded | failed
error              text nullable: validation/execution error, null on success
created_at         datetime

-- Import-specific columns (optional, per import type):
vendor_sku         varchar nullable: for vendor imports
matched_product_id FK nullable: for product matching
conflict_reason    varchar nullable: why an update was skipped, if applicable
```

---

## 2. Shared infrastructure (lives in core)

All framework code lives in `src/Import/` (core). Individual imports (vendor sheet, product, line importer, etc.) conform to the framework but may live in their own bundles (ProcurementBundle, modules, etc.) — they just implement the framework interfaces.

### `ImportDefinition` — metadata about what an import handles

```php
final class ImportDefinition
{
    /**
     * Machine name: 'vendor_sheet', 'product', 'po_line', etc.
     */
    public function name(): string {}

    /**
     * Human-readable: 'Vendor Sheet Import', 'Product CSV Import', etc.
     */
    public function label(): string {}

    /**
     * Entity FQCN or name: 'VendorPrice', 'ProductCore', etc.
     */
    public function entityType(): string {}

    /**
     * Target fields this import needs: name/label/required flag.
     *
     * @return list<ImportTargetField>
     */
    public function targetFields(): array {}

    /**
     * Which actions this import supports.
     *
     * @return list<'append'|'update'|'delete'>
     */
    public function supportedActions(): array {}

    /**
     * Does this import support validation-only (dry-run) mode?
     */
    public function supportsValidationOnly(): bool {}
}
```

### `ImportTargetField` — one field the import needs mapped

```php
final class ImportTargetField
{
    public function __construct(
        public readonly string $key,          // 'vendor_sku', 'our_sku', 'unit_cost'
        public readonly string $label,        // Human label for the mapping UI
        public readonly bool $required = true,
    ) {}
}
```

### `ColumnMapper` — already exists, maps CSV headers → target fields

(Currently in ProcurementBundle on the vendor-sheet branch; extract to core `src/Import/` as part of framework build.)

### `ImportValidator` — validates a row (interface + per-import implementations)

```php
interface ImportValidator
{
    /**
     * Validate one row. Return list of errors, empty = valid.
     *
     * @param array<string, mixed> $mappedData  target field => value
     * @return list<string>        error messages
     */
    public function validate(array $mappedData): array;
}
```

### `ImportRunner` — orchestrates the lifecycle

```php
final class ImportRunner
{
    public function __construct(
        private readonly ImportDefinition $definition,
        private readonly ImportValidator $validator,
        private readonly ColumnMapper $columnMapper,
        private readonly EntityManagerInterface $em,
        private readonly ImportLock $lock,  // serialization
    ) {}

    /**
     * Parse the CSV, detect headers, return mapped rows. In-request, synchronous —
     * safe because nothing is persisted yet.
     *
     * @param UploadedFile $file
     * @param array<string, string> $columnMapping  target field key => chosen header
     *
     * @return ImportRun with rows populated but not persisted
     */
    public function parseAndMap(UploadedFile $file, array $columnMapping): ImportRun {}

    /**
     * Validate rows (dry-run). In-request, synchronous — read-only, no lock needed.
     * Populates validated_count/error_count and per-row error messages.
     * Used both for a user-requested "Validation Only" run (which ends here,
     * ImportRun.status goes straight to 'completed') and as the first phase of
     * a real execution (see executeQueued() below).
     */
    public function validate(ImportRun $run): void {}

    /**
     * Persist the ImportRun (+ rows) with status='queued'. Does NOT execute —
     * that only ever happens inside the drain command, under the lock. This is
     * what every non-validation-only submission calls; there is no in-request
     * execute path.
     */
    public function queueForExecution(ImportRun $run): void {}

    /**
     * Called only from inside `import:process`, which already holds the lock.
     * Re-validates (rules may have changed since queueing — see D3), then persists
     * validated rows respecting action (append/update/delete).
     */
    public function executeQueued(ImportRun $run): void {}
}
```

### `ImportLock` — flock-based serialization (one import at a time)

```php
final class ImportLock
{
    private const LOCK_FILE = '/tmp/wholesale-imports.lock';

    /**
     * Try to acquire exclusive lock (non-blocking).
     * Writes current PID to lock file.
     * 
     * @return bool true if acquired, false if already held by another process
     */
    public function tryAcquire(): bool {}

    /**
     * Release the lock.
     */
    public function release(): void {}

    /**
     * Check if lock is currently held (by any process).
     * 
     * @return bool
     */
    public function isLocked(): bool {}

    /**
     * Get info about the current lock holder (PID, started_at from lock file).
     * Null if lock is not held.
     *
     * @return array{pid: int, started_at: \DateTime}|null
     */
    public function getHolderInfo(): ?array {}

    /**
     * Force-kill the process holding the lock (admin only).
     * Reads PID from lock file, kills it, releases flock.
     *
     * @throws ProcessNotRunningException if PID not alive
     */
    public function forceKillHolder(): void {}
}
```

Usage in CLI command — this is the only place `executeQueued()` is ever called, and the only place a queued import actually runs:
```php
// bin/console import:process
if (!$lock->tryAcquire()) {
    // Another worker already running, exit cleanly (no queue pileup)
    return Command::SUCCESS;
}

try {
    while ($queuedImport = $em->getRepository(ImportRun::class)->findOneQueued()) {
        $queuedImport->status = 'started';
        $queuedImport->started_at = new \DateTime();
        $em->flush();

        $runner->executeQueued($queuedImport);  // re-validates, then persists rows

        $queuedImport->status = 'completed';
        $queuedImport->finished_at = new \DateTime();
        $em->flush();
        // Loop continues, processing next queued import — one flock hold drains the whole queue
    }
} finally {
    $lock->release();
}
```

### Shared Twig template: column mapper screen

(Reuse `vendor_sheet_import_mapping.html.twig`, parameterize vendor/import context.)

---

## 3. Admin UI (unified across all imports)

### List page: `/admin/system/imports`

Table showing all import runs:
- Import Name (vendor_sheet, product, po_line, etc.)
- Description (vendor, PO + vendor, source, etc.) — free text, import-specific
- Action (append, update, delete, or mixed: `append, update`)
- Mode (✓ Executed | ⚪ Validation Only)
- Date/Time (finished_at)
- Total Rows (row_count)
- Validated (validated_count)
- Errors (error_count; shows "✗" badge if error is not null)
- Status (queued | started | completed)

Filters: date range, import type, mode (executed vs validation-only), status (queued/started/completed), entity type, has-errors.

Click a row → detail page.

### Detail page: `/admin/system/imports/{id}`

Shows the ImportRun + all its ImportRunRow children:
- Summary: row counts, status, error (if any)
- Per-row table: row number, input data (or first few cols), mapped data, action, status, error
- Sortable by row number, status
- Filter by status (succeeded, failed)

### Queue status panel (top of list page)

**Lock status badge:**
- 🔴 **LOCKED** — flock is held, import in progress
  - PID of current process
  - Current ImportRun (type, description, started_at, elapsed time)
  - **Kill button** (admin only, ROLE_TECH_SUPPORT) — force-kill the process
    - Confirms: "Killing will release the lock. Partial writes may remain."
    - Kills PID, sets ImportRun.status='completed' + ImportRun.error='Killed by admin' (status
      stays workflow-only — completed just means processing stopped; the error column is what
      says it didn't succeed), releases flock
    - Next import can proceed immediately
    
- 🟢 **FREE** — no import running, queue is idle
  - Last completed import (finished_at, status)
  - Queued count: N imports waiting
  - **Process Queue button** (spawns `bin/console import:process --detached`)
    - Only appears if: queued imports exist AND lock is free
    - User can also manually trigger from CLI
    - flock prevents duplicate workers even if clicked twice

---

## 4. How each import implements it

### 4a. VendorSheetImport

```php
final class VendorSheetImportDefinition implements ImportDefinition
{
    public function name(): string { return 'vendor_sheet'; }
    public function label(): string { return 'Vendor Sheet Import'; }
    public function entityType(): string { return 'VendorPrice'; }

    public function targetFields(): array
    {
        return [
            new ImportTargetField('vendor_sku', 'Vendor SKU', required: true),
            new ImportTargetField('our_sku', 'Our SKU', required: false),
            new ImportTargetField('vendor_price', 'Price', required: true),
            new ImportTargetField('vendor_quantity', 'Available Qty', required: false),
            new ImportTargetField('vendor_name', 'Product Name', required: false),
        ];
    }

    public function supportedActions(): array
    {
        return ['append', 'update'];  // No delete
    }

    public function supportsValidationOnly(): bool { return true; }
}

final class VendorSheetImportValidator implements ImportValidator
{
    public function __construct(
        private readonly VendorSkuResolver $resolver,
    ) {}

    public function validate(array $mappedData): array
    {
        $errors = [];
        if (empty($mappedData['vendor_sku']) && empty($mappedData['our_sku'])) {
            $errors[] = 'Must provide vendor_sku or our_sku';
        }
        if (!is_numeric($mappedData['vendor_price'] ?? null)) {
            $errors[] = 'Price must be numeric';
        }
        // ... more validation
        return $errors;
    }
}

final class VendorSheetImportService
{
    public function __construct(
        private readonly ImportRunner $runner,
        // ... other deps
    ) {}

    public function import(UploadedFile $file, Vendor $vendor, array $columnMapping, bool $dryRun = false): ImportRun
    {
        $run = $this->runner->parseAndMap($file, $columnMapping);
        $run->vendor_id = $vendor->getId();
        $run->description = "Vendor: {$vendor->getName()}";

        if ($dryRun) {
            // In-request, synchronous: read-only, nothing queued, status goes to 'completed' directly.
            $this->runner->validate($run);
            $run->validation_only = true;
            $run->status = 'completed';
            $run->finished_at = new \DateTime();
            $this->em->persist($run);
            $this->em->flush();
        } else {
            // Never executes in-request. Queues only; import:process (spawned below) does
            // the actual write, under the flock. UnmatchedVendorSku linking happens there too.
            $this->runner->queueForExecution($run);
            $this->spawnDrainProcess();  // exec('bin/console import:process --detached'); flock protects duplicates
        }

        return $run;
    }
}
```

### 4b. ProductImport (refactored to use framework)

```php
final class ProductImportDefinition implements ImportDefinition
{
    public function name(): string { return 'product'; }
    // ...
    public function supportedActions(): array
    {
        return ['append', 'update', 'delete'];  // Supports all three
    }
    public function supportsValidationOnly(): bool { return true; }
}

// Existing ProductImportService refactored to use ImportRunner instead of hand-rolling.
```

### 4c. Unified commercial doc line importer (Order, Invoice, Estimate, PO, Bill)

```php
final class LineImportDefinition implements ImportDefinition
{
    public function __construct(private readonly string $documentType) {}  // 'order' | 'invoice' | etc.

    public function name(): string { return "{$this->documentType}_line"; }
    public function label(): string { return ucfirst($this->documentType) . ' Line Import'; }
    public function entityType(): string { return 'SalesOrderLine'; }  // or PurchaseOrderLine, etc.

    public function targetFields(): array
    {
        return [
            new ImportTargetField('product_sku', 'Product SKU', required: true),
            new ImportTargetField('quantity', 'Quantity', required: true),
            new ImportTargetField('unit_cost', 'Unit Cost', required: false),  // optional, defaults to vendor price
        ];
    }

    public function supportedActions(): array
    {
        return ['append'];  // Line imports always add; never update/delete existing lines
    }
}

// Shared validator: resolve product SKU, qty must be positive, etc.
// Shared executor: create line rows, persist via the document's controller
```

---

## 5. Lifecycle walkthrough: VendorSheet import

**User flow:**

1. POST `/admin/bundles/procurement/vendor-sheet-import` (upload)
   - Validate file, detect headers, redirect to mapping screen
   - Store file in temp (token-named, not DB)

2. GET `/admin/bundles/procurement/vendor-sheet-import-mapping`
   - ColumnMapper shows target fields + remembered mapping
   - Admin adjusts, POSTs

3. POST (with mapping)
   - ImportRunner.parseAndMap() → ImportRun + rows (in-request, nothing persisted yet)
   - IF "Validation Only" checked: runner.validate() → ImportRun persisted with status='completed',
     validation_only=true, executed_count=0 → show results directly, nothing queued
   - ELSE: runner.queueForExecution() → ImportRun persisted with status='queued' →
     spawn `exec('bin/console import:process --detached')` → redirect to the run's detail page,
     which shows "queued" (or "started"/"completed" if the drain was fast enough to already finish)

4. GET `/admin/system/imports` → list all imports, plus the queue status panel (locked/free)
5. GET `/admin/system/imports/{id}` → detail page (auto-refreshes while status is queued/started)

**Behind the scenes:**

- ImportRun.import_type = 'vendor_sheet'
- ImportRun.entity_type = 'VendorPrice'
- ImportRun.description = "Vendor: Acme Corp"
- ImportRun.status = queued → started → completed — started/completed are only ever set inside
  `import:process`, under the flock; a validation-only run skips straight to completed in-request
- ImportRun.error_count and .error columns track validation/execution failures separately
- ImportRunRow per CSV row: input_data, mapped_data, action, status, error (if validation failed)
- If execution succeeds: VendorPrice rows created/updated, UnmatchedVendorSku rows flagged
- Per-import detail page shows row-by-row audit

---

## 6. Key design decisions

**D1: Where does `ImportRun` get written?**

- Validation-only: written once, in-request, straight to status='completed' — never queued, since nothing needs the lock.
- Real execution: written in-request as status='queued', then `import:process` (under the flock) flips it to 'started' and finally 'completed'. So a real run is always visible in the list immediately as "queued," even before a worker picks it up.

**D2: Rollback on partial failure?**

- Currently: `executeQueued()` persists all validated rows regardless of a later row's outcome; if row 100 fails, rows 1-99 are persisted anyway.
- No transaction wrapping by default; each import decides (VendorPrice is idempotent updates, ProductImport already handles partial success).
- Alternative: wrap in transaction, rollback on any failure. Slower, but cleaner. Decide per import.
- Either way this happens inside `import:process` under the flock, so it never races another import — only the question of whether it can race *itself* (one bad row vs. the whole file).

**D3: What if validation rules change between upload and execute?**

- Possible: admin uploads file, walks away, validation rules get patched, then clicks execute.
- Framework re-validates on execute (not just at upload).
- ImportRunRow.error stays fresh.

**D4: Import-specific metadata**

- Via optional columns on ImportRun/ImportRunRow (FK to vendor, document type, etc.).
- Via extending the entities: `class VendorSheetImportRun extends ImportRun { vendor_id, ... }`
- Or: JSON blob on ImportRun.metadata.
- Chosen: optional columns (simplest, no subclass inheritance mess).

**D5: Audit retention**

- ImportRun/ImportRunRow are never deleted (or pruned after N days via a scheduled job, future).
- Never update either entity after creation (immutable ledger).

---

## 7. Implementation order

1. **Create ImportRun + ImportRunRow entities + migration**
2. **Create ImportDefinition, ImportTargetField, ImportValidator interfaces**
3. **Extract ColumnMapper** from VendorSheet into shared location (core or shared bundle)
4. **Extract shared Twig template** for column mapping UI
5. **Refactor VendorSheetImport** to use the framework (test thoroughly)
6. **Build unified line importer** on the framework
7. **Refactor ProductImportService** (optional, lower priority — works today)
8. **Create admin pages** for `/admin/system/imports` list + detail
9. **Merge vendor-sheet branch**, then line-importer branch

---

## 8. Code structure

### Core framework (`src/Import/`)

```
src/Import/
├── ImportDefinition.php          interface
├── ImportTargetField.php         value object
├── ImportValidator.php           interface
├── ImportRunner.php              orchestrator
├── ImportLock.php                flock-based lock for serialization (one import at a time)
├── ColumnMapper.php              maps CSV headers → target fields (extracted from ProcurementBundle)
├── Entity/
│   ├── ImportRun.php
│   └── ImportRunRow.php
├── Repository/
│   ├── ImportRunRepository.php
│   └── ImportRunRowRepository.php
├── Controller/
│   └── AdminImportLogController.php  list + detail pages at /admin/system/imports,
│                                      queue status panel, kill + "Process Queue" actions
└── Command/
    └── ImportProcessCommand.php      `bin/console import:process` — the queue drain loop
```

### Vendor sheet import (`modules/ProcurementBundle/`)

```
modules/ProcurementBundle/
├── src/Import/
│   ├── VendorSheetImportDefinition.php  implements ImportDefinition
│   ├── VendorSheetImportValidator.php   implements ImportValidator
│   └── VendorSheetImportService.php     orchestrates the import
├── src/Controller/
│   └── VendorSheetImportController.php  upload + mapping screens
└── templates/
    ├── vendor_sheet_import.html.twig
    └── vendor_sheet_import_mapping.html.twig
```

### Unified line importer (core)

```
src/Import/
├── LineImportDefinition.php    implements ImportDefinition
├── LineImportValidator.php     implements ImportValidator
└── LineImportService.php       orchestrates per-document-type

src/Controller/Admin/
└── LineImportController.php    upload + mapping for all doc types (Order, Invoice, Estimate, PO, Bill)

templates/admin/
└── line_import_mapping.html.twig  shared template (parameterized for doc type)
```

---

## 9. Decisions still open

**D6: Queue-based async + flock serialization**

- Imports always queue first (ImportRun.status='queued') instead of executing in-request.
- CLI command `bin/console import:process` drains the queue:
  - Tries to acquire flock on `/tmp/wholesale-imports.lock`
  - If flock held (another worker running), exits cleanly (no queue pileup)
  - If acquired: loops through queued imports, executes each, moves to next
  - When queue empty: releases flock and exits
- UI can dispatch `exec('bin/console import:process --detached')` to kick off drain, or user triggers manually
- flock prevents duplicate workers even if user clicks button twice
- Admin page shows lock status clearly (LOCKED/FREE) + queued count
- If stuck: admin kills PID from UI, clicks "Retry Queue" to drain remaining imports
- No Messenger dependency, no persistent daemon, no queue pileup.

**D7: Column mapper persistence**

- VendorSheet already remembers per (vendor, kind).
- Should line imports also remember per (document_type)?
- Probably yes, but defer to when line importer is built.

**D8: One controller per import, or unified?**

- VendorSheetImportController stays in ProcurementBundle (vendor-specific).
- LineImportController lives in core, handles all 5 doc types.
- ProductImportController already in core; keep as-is initially (refactor later if it fits).

**D9: Kill stuck import — graceful or force?**

- **Graceful:** set a flag in a cache pool, import worker checks it between rows and exits cleanly.
  - Pro: no partial writes, no orphaned state.
  - Con: running import must cooperate; if it's stuck mid-row, flag is ignored.
- **Force (chosen):** kill -9 the PID, release the lock immediately, set ImportRun.status='completed'
  with ImportRun.error='Killed by admin' (status is workflow-only per the schema above — the error
  column, not the status enum, is what marks it as not having succeeded).
  - Pro: always works, unblocks the queue immediately.
  - Con: mid-transaction writes may be orphaned (but on SQLite, a killed process rolls back auto).
  - Lock is flock-based, so release is guaranteed (not tied to process exit).
  - Admin sees the failure in ImportRun.error, can investigate.
- Decision: force kill, with clear warning on the button ("This will kill the process; partial writes may remain in the database").
