<?php

namespace App\Service\ProductImport;

final class ProductImportResult
{
    public int $totalRows = 0;
    public int $validRows = 0;
    public int $warningRows = 0;
    public int $errorRows = 0;
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;
    public int $duplicates = 0;
    public int $inactivated = 0;

    /**
     * Rows that imported with their unit left for a person to settle.
     *
     * Counted separately from `warningRows` because it is the one warning with work attached: the
     * product is in the catalogue and cannot be sold in the unit the file named until somebody
     * declares one on its product form. A 5,000-row import must not hide forty of those inside a
     * green result, which is the whole reason this is a number on the summary rather than a line
     * buried in the row-by-row report.
     *
     * See ProductImportService::applyUnit() for what puts a row here.
     */
    public int $unitFlagged = 0;

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<array{row: int, key: string, action: string, message?: string}> */
    public array $rowLog = [];

    /** @var list<array{row:int,key:string,sku:string,name:string,status:string,action:string,issues:list<string>,notes:list<string>}> */
    public array $rowResults = [];
}
