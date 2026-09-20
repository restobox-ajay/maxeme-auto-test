<?php

declare(strict_types=1);

namespace App\Model;

/**
 * One field an import needs mapped from the uploaded file — what ColumnMapper's mapping screen
 * shows, one row per field. `$key` is what the rest of the framework (validators, executors) reads
 * the mapped value back by; `$label` is what the admin sees next to the header dropdown.
 */
final class ImportTargetField
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $required = true,
    ) {
    }
}
