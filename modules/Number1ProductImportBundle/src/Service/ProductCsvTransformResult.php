<?php

declare(strict_types=1);

namespace Number1ProductImportBundle\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProductCsvTransformResult
{
    public function __construct(
        public readonly UploadedFile $canonicalCsv,
        public readonly string $canonicalCsvPath,
        /** @var array<string, array{qb_item_name: string, supplier: string, gl_accounts: string, notes: string}> keyed by REFNUM (= sku) */
        public readonly array $vendorMetaBySku,
    ) {}
}
