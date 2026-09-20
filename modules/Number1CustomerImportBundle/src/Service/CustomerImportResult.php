<?php

declare(strict_types=1);

namespace Number1CustomerImportBundle\Service;

final class CustomerImportResult
{
    public int $totalRows = 0;
    public int $validRows = 0;
    public int $warningRows = 0;
    public int $errorRows = 0;
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;
    public int $companiesCreated = 0;
    public int $invitesSent = 0;

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<array{row:int,email:string,company:string,status:string,action:string,issues:list<string>,notes:list<string>}> */
    public array $rowResults = [];
}
