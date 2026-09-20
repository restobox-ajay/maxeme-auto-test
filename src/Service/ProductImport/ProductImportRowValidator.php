<?php

declare(strict_types=1);

namespace App\Service\ProductImport;

use App\Contract\Import\ImportValidatorInterface;

/**
 * Deliberately shallow — the one check cheap and stateless enough to run twice per row (once for a
 * "Validation Only" run, again inside executeQueued() per D3) without a database round trip.
 * Everything that actually needs the database (does this SKU already exist, is the category real,
 * ...) already runs inside ProductImportService::processDataRow() exactly as it did before this
 * import went through the framework — duplicating that here would be a second, competing answer to
 * questions the row executor already answers authoritatively.
 */
final class ProductImportRowValidator implements ImportValidatorInterface
{
    public function __construct(
        private readonly string $primaryKey,
    ) {
    }

    public function validate(array $mappedData): array
    {
        $value = trim((string) ($mappedData[$this->primaryKey] ?? ''));

        return $value === '' ? [sprintf('Missing primary key "%s".', $this->primaryKey)] : [];
    }
}
