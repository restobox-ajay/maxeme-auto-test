<?php

declare(strict_types=1);

namespace Number1ProductImportBundle\Service;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Translates Number 1 Tire Centre's QuickBooks Item List export CSV
 * (NAME, REFNUM, INVITEMTYPE, DESC, QNTY, PRICE, COST, VALUE, TAXABLE, SALESTAXCODE,
 * ACCNT, ASSETACCNT, COGSACCNT, VENDOR, LOCATION, NOTES) into the canonical CSV shape
 * App\Service\ProductImport\ProductImportService already understands (sku, name,
 * status, category, type, cost_price, original_price, sales_tax_code,
 * fulfillment_region__<slug>), so the actual upsert/validation/matching logic is never
 * duplicated — only the column mapping lives here. See modules/Number1ProductImportBundle/README.md
 * for the full column mapping table and the reasoning behind it.
 *
 * LOCATION (e.g. "Warehouse A") is the same idea as our FulfillmentRegion, so it drives
 * real per-row inventory placement rather than being stored as inert reference text: the
 * admin maps each distinct LOCATION value found in the file to a FulfillmentRegion (via
 * ProductImportController's two-step upload -> map -> confirm flow), and that mapping is
 * what transform() is given here.
 */
final class ProductCsvTransformer
{
    /**
     * Value stamped on ProductCore::syncSource for every product this bundle's import creates.
     *
     * Deliberately owned by the bundle, exactly as Number1RimImportBundle owns
     * RimApiImportService::SYNC_SOURCE — core's shared ProductImportService takes it as a
     * caller-supplied option and holds no registry of who its callers are. See issue #477.
     */
    public const SYNC_SOURCE = 'number1-product-csv-import';

    /** @return list<string> distinct non-blank LOCATION values, in the order they first appear */
    public function scanDistinctLocations(UploadedFile $vendorCsv): array
    {
        $seen = [];
        $locations = [];
        foreach ($this->parseRows($vendorCsv) as $data) {
            $location = trim($data['location'] ?? '');
            if ($location === '' || isset($seen[$location])) {
                continue;
            }
            $seen[$location] = true;
            $locations[] = $location;
        }

        return $locations;
    }

    /**
     * @param array<string, FulfillmentRegion> $regionsByLocation keyed by the raw
     *     (trimmed) LOCATION value; a non-blank LOCATION with no entry here (the admin
     *     explicitly chose "Don't update inventory" for it on the map-locations step)
     *     gets no inventory update at all — the product itself is still created/updated
     *     normally, just no fulfillment_region__<slug> cell is emitted for that row. A
     *     blank LOCATION cell instead falls back to $fallbackRegion, if configured.
     */
    public function transform(
        UploadedFile $vendorCsv,
        EntityManagerInterface $entityManager,
        array $regionsByLocation,
        ?ProductCategory $fallbackCategory,
        bool $autoCreateCategory = false,
        ?FulfillmentRegion $fallbackRegion = null,
    ): ProductCsvTransformResult {
        $categoryBySlug = [];
        foreach ($entityManager->getRepository(ProductCategory::class)->findAll() as $category) {
            if ($category instanceof ProductCategory) {
                $categoryBySlug[$this->slug($category->getName())] = $category;
            }
        }

        $regionColumnByLocation = [];
        foreach ($regionsByLocation as $location => $region) {
            $regionColumnByLocation[$location] = 'fulfillment_region__' . $this->slug($region->getName());
        }
        $fallbackRegionColumn = $fallbackRegion !== null ? 'fulfillment_region__' . $this->slug($fallbackRegion->getName()) : null;
        $usedRegionColumns = array_values(array_unique(array_filter([...$regionColumnByLocation, $fallbackRegionColumn])));

        $canonicalRows = [];
        $vendorMetaBySku = [];

        foreach ($this->parseRows($vendorCsv) as $data) {
            $refnum = trim($data['refnum'] ?? '');
            if ($refnum === '') {
                // Left blank on purpose: ProductImportService already reports a clean
                // "missing primary key" error for a blank sku cell, no need to duplicate
                // that validation here — just skip emitting a row for it.
                continue;
            }

            $qbName = trim($data['name'] ?? '');
            $name = trim($data['desc'] ?? '') ?: $qbName;

            $categoryToken = trim(explode(':', $qbName, 2)[0]);
            $category = $categoryToken !== '' ? ($categoryBySlug[$this->slug($categoryToken)] ?? null) : null;
            if ($category === null && $categoryToken !== '' && $autoCreateCategory) {
                $category = $this->findOrCreateCategory($entityManager, $categoryToken, $categoryBySlug);
            } else {
                $category ??= $fallbackCategory;
            }

            $taxable = strtoupper(trim($data['taxable'] ?? ''));
            $salesTaxCode = $taxable === 'N' ? 'E' : trim($data['salestaxcode'] ?? '');

            $row = [
                'sku' => $refnum,
                'name' => $name,
                'category' => $category?->getName() ?? '',
                'type' => trim($data['invitemtype'] ?? ''),
                'cost_price' => trim($data['cost'] ?? ''),
                'original_price' => trim($data['price'] ?? ''),
                'sales_tax_code' => $salesTaxCode,
            ];

            $location = trim($data['location'] ?? '');
            // A blank LOCATION cell falls back to the configured fallback region (if any);
            // a non-blank LOCATION with no mapping entry means the admin explicitly chose
            // "Don't update inventory" for it on the map-locations step, so it's left alone.
            $regionColumn = $location === '' ? $fallbackRegionColumn : ($regionColumnByLocation[$location] ?? null);
            if ($regionColumn !== null) {
                $row[$regionColumn] = trim($data['qnty'] ?? '');
            }

            $canonicalRows[] = $row;

            $vendorMetaBySku[$refnum] = [
                'qb_item_name' => $qbName,
                'supplier' => trim($data['vendor'] ?? ''),
                'gl_accounts' => $this->joinNonEmpty([
                    'Sales' => trim($data['accnt'] ?? ''),
                    'Asset' => trim($data['assetaccnt'] ?? ''),
                    'COGS' => trim($data['cogsaccnt'] ?? ''),
                ]),
                'notes' => trim($data['notes'] ?? ''),
            ];
        }

        $canonicalHeader = array_merge(
            ['sku', 'name', 'category', 'type', 'cost_price', 'original_price', 'sales_tax_code'],
            $usedRegionColumns
        );

        $path = tempnam(sys_get_temp_dir(), 'n1pi_');
        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary file for the transformed import.');
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

        $canonicalCsv = new UploadedFile($path, 'number1-canonical-import.csv', 'text/csv', null, true);

        return new ProductCsvTransformResult($canonicalCsv, $path, $vendorMetaBySku);
    }

    /**
     * @param array<string, ProductCategory> $categoryBySlug updated in place so a token
     *     repeated across rows in the same import (e.g. "WHEEL" and "WHEELS" appearing
     *     dozens of times) only ever creates one category, not one per row.
     */
    private function findOrCreateCategory(EntityManagerInterface $entityManager, string $token, array &$categoryBySlug): ProductCategory
    {
        $slug = $this->slug($token);
        if (isset($categoryBySlug[$slug])) {
            return $categoryBySlug[$slug];
        }

        // Vendor tokens come through as raw QuickBooks item-path segments (e.g. "TIRE",
        // "WHEELS") — title-cased here so an auto-created category reads like a real
        // category name ("Tire", "Wheels") instead of shouting in all caps.
        $category = (new ProductCategory())->setName(ucwords(strtolower($token)));
        $entityManager->persist($category);
        $entityManager->flush();

        $categoryBySlug[$slug] = $category;

        return $category;
    }

    /** @return iterable<array<string, string>> */
    private function parseRows(UploadedFile $csv): iterable
    {
        $file = new \SplFileObject($csv->getPathname());
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(',');

        $header = null;
        foreach ($file as $row) {
            if (!is_array($row) || $row === [null]) {
                continue;
            }

            if ($header === null) {
                $header = $this->normalizeHeader($row);
                continue;
            }

            $data = $this->rowToMap($header, $row);
            if ($data === []) {
                continue;
            }

            yield $data;
        }
    }

    /** @param list<mixed> $row @return list<string> */
    private function normalizeHeader(array $row): array
    {
        $header = [];
        foreach ($row as $cell) {
            $header[] = $this->normalizeHeaderKey((string) $cell);
        }

        return $header;
    }

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
     * @param list<string> $header
     * @param list<mixed> $row
     * @return array<string, string>
     */
    private function rowToMap(array $header, array $row): array
    {
        $map = [];
        foreach ($header as $i => $key) {
            if ($key === '') {
                continue;
            }
            $map[$key] = isset($row[$i]) ? trim((string) $row[$i]) : '';
        }

        foreach ($map as $value) {
            if ($value !== '') {
                return $map;
            }
        }

        return [];
    }

    /** @param array<string, string> $labeled */
    private function joinNonEmpty(array $labeled): string
    {
        $parts = [];
        foreach ($labeled as $label => $value) {
            if ($value !== '') {
                $parts[] = $label . ': ' . $value;
            }
        }

        return implode(' | ', $parts);
    }

    /**
     * Must match App\Service\ProductImport\ProductImportService::slug() exactly — this
     * is how core matches "category" cells and "fulfillment_region__<slug>" columns
     * against existing ProductCategory/FulfillmentRegion names, and we need to predict
     * that same match here so we can pre-resolve a fallback category and build the
     * right region column names.
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
