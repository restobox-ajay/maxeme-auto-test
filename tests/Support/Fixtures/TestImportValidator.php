<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixtures;

use App\Contract\Import\ImportValidatorInterface;

final class TestImportValidator implements ImportValidatorInterface
{
    public function validate(array $mappedData): array
    {
        return empty($mappedData['sku']) ? ['sku required'] : [];
    }
}
