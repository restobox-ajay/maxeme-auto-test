<?php

declare(strict_types=1);

namespace Number1RimImportBundle\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class RimTransformResult
{
    public function __construct(
        public readonly UploadedFile $canonicalCsv,
        public readonly string $canonicalCsvPath,
        /** @var array<string, array<string, string>> sku => [rim_offset => ..., rim_pcd => ..., ...] (RimSpecFieldSubscriber::FIELDS slugs) */
        public readonly array $specFieldsBySku,
        /** @var array<string, string> sku => the row's Price-default value, always paired with suggested_price_type = Number */
        public readonly array $suggestedPriceValueBySku,
        /** @var array<string, list<string>> sku => list of absolute image URLs */
        public readonly array $imageUrlsBySku,
    ) {}
}
