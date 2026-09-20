<?php

declare(strict_types=1);

namespace App\Api;

/**
 * The product payload, written out in full.
 *
 * This file IS the contract: what an integrator receives is exactly the keys below, and adding one
 * is a deliberate edit here rather than a side effect of something changing elsewhere. The API used
 * to return the catalog controller's internal row array instead, which meant two things that only
 * looked fine because they happened to line up:
 *
 *  - the payload silently exceeded the page (it carried images, both description halves and custom
 *    field values that the fragment never rendered), so it could only be trusted as long as nobody
 *    added a field to that row for a template's benefit;
 *  - prices arrived as display strings — `"$1,299.00"`, which casts to 0.0, and `"TBD"` — so an
 *    integration importing them wrote zeros.
 *
 * The rule this encodes: the API owns its SHAPE and nothing else. It never decides what a price is,
 * which products are visible, or who may see them — those answers arrive already made, from the
 * same customer controller that serves the website. It only decides which of them are published,
 * and says so explicitly.
 *
 * Custom fields are deliberately absent for now. They are per-installation, admin-created data, so
 * whether any given one may be published is a judgement core cannot make on the admin's behalf —
 * that needs a per-field setting, which is its own piece of work. Absent is the safe default;
 * emitting them because they happened to be in the row was not a decision anyone took.
 */
final class ProductPresenter
{
    /**
     * Every key in the response, in order. Kept as data so a test can assert the payload matches it
     * EXACTLY — equality, not subset, because subset catches a field appearing and misses one
     * quietly disappearing, and an integration notices the second one just as hard.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'id',
        'sku',
        'name',
        'category',
        'unit',
        'size',
        'description',
        'remarks',
        'image',
        'inStock',
        'availableQuantity',
        'companyPrice',
        'retailPrice',
    ];

    /**
     * @param array<string, mixed> $row a customerProductRow() from the catalog controller
     * @return array<string, mixed>
     */
    public static function fromCatalogRow(array $row, string $imageBaseUrl = ''): array
    {
        $image = (string) ($row['image'] ?? '');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'sku' => (string) ($row['sku'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'unit' => (string) ($row['unit'] ?? ''),
            'size' => (string) ($row['size'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'remarks' => (string) ($row['remarks'] ?? ''),
            'image' => $image === '' ? null : $imageBaseUrl . '/uploads/products/' . $image,
            // The page prints "In stock" / "Out of stock"; a caller wants the predicate, and the
            // number it was derived from is right below it.
            'inStock' => ($row['availableQuantity'] ?? 0) > 0,
            'availableQuantity' => (int) ($row['availableQuantity'] ?? 0),
            // Null means "no price for you", which is what the page shows as "TBD". It is not zero,
            // and must never be serialised as zero.
            'companyPrice' => $row['priceAmount'] ?? null,
            'retailPrice' => $row['retailPriceAmount'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function fromCatalogRows(array $rows, string $imageBaseUrl = ''): array
    {
        return array_map(static fn (array $row): array => self::fromCatalogRow($row, $imageBaseUrl), $rows);
    }
}
