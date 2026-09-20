<?php

declare(strict_types=1);

namespace Number1RimImportBundle\Service;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCategory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Translates the rim API's raw `data` array (header row + rows, see RIM_API_IMPORT_PLAN.md §5)
 * into the canonical CSV shape App\Service\ProductImport\ProductImportService already understands
 * (sku, name, category, weight, original_price, remarks, fulfillment_region__<slug>) — same
 * "translate vendor shape, call core's real importer" pattern
 * Number1ProductImportBundle\Service\ProductCsvTransformer already established, just for a JSON
 * API source instead of a CSV upload. Category and the single fulfillment region are passed in
 * already resolved (both are fixed, admin-picked values for the whole batch, not derived per row —
 * see RIM_API_IMPORT_PLAN.md §4), so this class needs no database access at all.
 */
final class RimApiTransformer
{
    /** @var array<string, string> custom-field slug => API column key */
    private const SPEC_FIELD_COLUMNS = [
        // Previously only ever folded into buildName()'s generated product name — never captured
        // as its own value. See CATEGORY_PAGE_FILTER_PLAN.md §2d (the same fix already done for Finish).
        'rim_model' => 'model',
        'rim_offset' => 'offset',
        'rim_pcd' => 'pcd',
        'rim_cb' => 'cb',
        'rim_backspace' => 'backspace',
        'rim_seat' => 'seat',
        'rim_made' => 'made',
        'rim_load_rating' => 'load_rating',
        'rim_size' => 'size',
        // Previously only ever folded into buildName()'s generated product name — never captured
        // as its own value. See CATEGORY_PAGE_FILTER_PLAN.md §2d.
        'rim_finish' => 'finish',
    ];

    /** Matches the API's `Size` column shape confirmed across all 518 sample rows, e.g. "18x7.5". */
    private const SIZE_PATTERN = '/^(\d+(?:\.\d+)?)\s*[xX]\s*(\d+(?:\.\d+)?)$/';

    /** @param list<list<mixed>> $apiRows header row + data rows, exactly as RimApiClient::fetchRows() returns */
    public function transform(array $apiRows, ?ProductCategory $category, ?FulfillmentRegion $region): RimTransformResult
    {
        $header = array_shift($apiRows) ?? [];
        $headerKeys = [];
        foreach ($header as $cell) {
            $headerKeys[] = $this->normalizeHeaderKey((string) $cell);
        }

        $regionColumn = $region instanceof FulfillmentRegion ? 'fulfillment_region__' . $this->slug($region->getName()) : null;

        $canonicalHeader = ['sku', 'name', 'category', 'weight', 'original_price', 'remarks'];
        if ($regionColumn !== null) {
            $canonicalHeader[] = $regionColumn;
        }

        $canonicalRows = [];
        $specFieldsBySku = [];
        $suggestedPriceValueBySku = [];
        $imageUrlsBySku = [];
        $seenSkus = [];

        foreach ($apiRows as $row) {
            $data = $this->rowToMap($headerKeys, $row);
            $sku = trim((string) ($data['part_no'] ?? ''));
            if ($sku === '') {
                // Same convention as ProductCsvTransformer: core's own import() already reports a
                // clean "missing primary key" error for a blank sku cell — no need to duplicate
                // that validation here, just don't emit a row for it.
                continue;
            }

            $canonicalRow = [
                'sku' => $sku,
                'name' => $this->buildName($data),
                'category' => $category?->getName() ?? '',
                'weight' => trim((string) ($data['weight'] ?? '')),
                'original_price' => trim((string) ($data['price_shop'] ?? '')),
                'remarks' => trim((string) ($data['remark'] ?? '')),
            ];
            if ($regionColumn !== null) {
                $canonicalRow[$regionColumn] = trim((string) ($data['qty'] ?? ''));
            }
            $canonicalRows[] = $canonicalRow;

            // Duplicate Part No within one pull (RIM_API_IMPORT_PLAN.md §2): core's own
            // ProductImportService::import() already reports the second occurrence of a repeated
            // sku as a Duplicate row and only ever applies the first. These side maps (custom
            // fields, suggested price, images) mirror that "first occurrence wins" rule exactly —
            // otherwise a later duplicate row would silently overwrite the first's side-map data
            // even though core never actually re-imported the product row itself.
            if (isset($seenSkus[$sku])) {
                continue;
            }
            $seenSkus[$sku] = true;

            $specFields = $this->specFields($data);
            if ($specFields !== []) {
                $specFieldsBySku[$sku] = $specFields;
            }

            $suggestedValue = trim((string) ($data['price_default'] ?? ''));
            if ($suggestedValue !== '') {
                $suggestedPriceValueBySku[$sku] = $suggestedValue;
            }

            $images = $this->imageUrls($data['image'] ?? null);
            if ($images !== []) {
                $imageUrlsBySku[$sku] = $images;
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'n1ri_');
        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary file for the transformed rim import.');
        }

        $fp = fopen($path, 'w');
        fputcsv($fp, $canonicalHeader);
        foreach ($canonicalRows as $row) {
            $line = [];
            foreach ($canonicalHeader as $column) {
                $line[] = $row[$column] ?? '';
            }
            fputcsv($fp, $line);
        }
        fclose($fp);

        $canonicalCsv = new UploadedFile($path, 'number1-rim-canonical-import.csv', 'text/csv', null, true);

        return new RimTransformResult($canonicalCsv, $path, $specFieldsBySku, $suggestedPriceValueBySku, $imageUrlsBySku);
    }

    /** @param array<string, mixed> $data */
    private function buildName(array $data): string
    {
        $brand = trim((string) ($data['brand'] ?? ''));
        $model = trim((string) ($data['model'] ?? ''));
        $finish = trim((string) ($data['finish'] ?? ''));
        $size = trim((string) ($data['size'] ?? ''));

        $name = trim($brand . ' ' . $model);
        if ($finish !== '') {
            $name .= ' — ' . $finish;
        }
        if ($size !== '') {
            $name .= ' (' . $size . ')';
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function specFields(array $data): array
    {
        $out = [];
        foreach (self::SPEC_FIELD_COLUMNS as $slug => $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                $out[$slug] = $value;
            }
        }

        // rim_dimension/rim_width are derived from rim_size ("18x7.5" => "18" / "7.5"), not their
        // own API column — same data, just split so each half is independently filterable. See
        // CATEGORY_PAGE_FILTER_PLAN.md §2d. Left unset (not "0" or blank) if size doesn't match the
        // expected NxM shape, same as every other spec field's "skip if not present" behavior.
        $size = trim((string) ($data['size'] ?? ''));
        if ($size !== '' && preg_match(self::SIZE_PATTERN, $size, $matches) === 1) {
            $out['rim_dimension'] = $matches[1];
            $out['rim_width'] = $matches[2];
        }

        return $out;
    }

    /** @return list<string> */
    private function imageUrls(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [$raw];

        $urls = [];
        foreach ($values as $value) {
            $url = trim((string) $value);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @param list<string> $headerKeys
     * @param list<mixed> $row
     * @return array<string, mixed>
     */
    private function rowToMap(array $headerKeys, array $row): array
    {
        $map = [];
        foreach ($headerKeys as $i => $key) {
            if ($key === '') {
                continue;
            }
            $map[$key] = $row[$i] ?? null;
        }

        return $map;
    }

    /**
     * Must match App\Service\ProductImport\ProductImportService::normalizeHeaderKey() exactly —
     * this is how the API's own column labels ("Part No", "Load Rating") get matched below.
     */
    private function normalizeHeaderKey(string $value): string
    {
        $value = trim($value);
        $value = strtolower($value);
        $value = str_replace(['-', ' '], '_', $value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? $value;
        $value = preg_replace('/_+/', '_', $value) ?? $value;

        return trim($value, '_');
    }

    /**
     * Must match App\Service\ProductImport\ProductImportService::slug() exactly — this is how
     * core matches "fulfillment_region__<slug>" columns against existing FulfillmentRegion names.
     */
    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['-', ' '], '_', $value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? $value;
        $value = preg_replace('/_+/', '_', $value) ?? $value;

        return trim($value, '_');
    }
}
