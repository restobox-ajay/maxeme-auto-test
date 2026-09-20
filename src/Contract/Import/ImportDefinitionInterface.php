<?php

declare(strict_types=1);

namespace App\Contract\Import;

use App\Enum\ImportAction;
use App\Model\ImportTargetField;

/**
 * What one import type is — the metadata ImportRunner, ColumnMapper and the admin UI need without
 * knowing anything about the entity being written. An import (VendorSheet, Product, a per-document
 * line importer, ...) implements this once; everything else in the framework is driven off it.
 *
 * See docs/plans/2026-09-18-unified-import-framework.md for the framework this is part of.
 */
interface ImportDefinitionInterface
{
    /** Machine name, stored verbatim in ImportRun::$importType — e.g. 'vendor_sheet', 'product'. */
    public function name(): string;

    /** Human label for the admin UI — e.g. 'Vendor Sheet Import'. */
    public function label(): string;

    /** Entity short name being written, stored in ImportRun::$entityType — e.g. 'VendorPrice'. */
    public function entityType(): string;

    /**
     * The fields ColumnMapper's mapping screen shows, one row per field, in display order.
     *
     * @return list<ImportTargetField>
     */
    public function targetFields(): array;

    /**
     * Which actions rows from this import may take. A row's actual action still comes from the
     * per-import validator/executor — this is only the allow-list the framework checks it against.
     *
     * @return list<ImportAction>
     */
    public function supportedActions(): array;

    /**
     * Whether a "Validation Only" run makes sense for this import. False for imports where a
     * dry-run can't say anything useful validate() doesn't already say cheaply (rare) — true is the
     * expected answer for nearly every import.
     */
    public function supportsValidationOnly(): bool;
}
