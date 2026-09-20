<?php

declare(strict_types=1);

namespace Number1ProductImportBundle\Service;

use App\Entity\AppSetting;
use App\Entity\FulfillmentRegion;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists this bundle's cross-upload settings (the fallback Fulfillment Region and the
 * default sales tax code) via App\Entity\AppSetting, same pattern
 * Number1RimImportBundle\Service\RimImportConfig already uses. Everything else on the
 * upload form (fallback category, missing-rows behaviour, store-metadata toggle) is
 * re-chosen on every upload and stays a plain POST field — only these two need to
 * persist, since they're meant to apply automatically on every future import without
 * being re-picked each time.
 *
 * default_sales_tax_code's key intentionally has no `number1_product_import_` prefix:
 * App\Service\ProductImport\ProductImportService reads this exact key for every import
 * source, not just this bundle's. See issue #419.
 */
final class ProductImportConfig
{
    public const KEY_FALLBACK_REGION_ID = 'number1_product_import_fallback_region_id';
    public const KEY_DEFAULT_SALES_TAX_CODE = 'default_sales_tax_code';

    /** @var list<string> Matches the codes App\Service\ProductImport\ProductImportService::normalizeSalesTaxCode() accepts. */
    public const SALES_TAX_CODES = ['E', 'G', 'S'];

    public function __construct(
        private readonly AppSettings $appSettings,
    ) {}

    public function getFallbackRegionId(): int
    {
        return (int) ($this->appSettings->get(self::KEY_FALLBACK_REGION_ID, '0') ?: 0);
    }

    public function resolveFallbackRegion(EntityManagerInterface $entityManager): ?FulfillmentRegion
    {
        $id = $this->getFallbackRegionId();
        if ($id <= 0) {
            return null;
        }

        $region = $entityManager->find(FulfillmentRegion::class, $id);

        return $region instanceof FulfillmentRegion ? $region : null;
    }

    public function getDefaultSalesTaxCode(): string
    {
        $code = strtoupper((string) $this->appSettings->get(self::KEY_DEFAULT_SALES_TAX_CODE, 'S'));

        return in_array($code, self::SALES_TAX_CODES, true) ? $code : 'S';
    }

    public function save(EntityManagerInterface $entityManager, int $fallbackRegionId, string $defaultSalesTaxCode): void
    {
        $this->putSetting($entityManager, self::KEY_FALLBACK_REGION_ID, (string) max(0, $fallbackRegionId));

        $salesTaxCode = strtoupper($defaultSalesTaxCode);
        $this->putSetting($entityManager, self::KEY_DEFAULT_SALES_TAX_CODE, in_array($salesTaxCode, self::SALES_TAX_CODES, true) ? $salesTaxCode : 'S');

        $entityManager->flush();
        $this->appSettings->clearCache();
    }

    private function putSetting(EntityManagerInterface $entityManager, string $key, string $value): void
    {
        $repo = $entityManager->getRepository(AppSetting::class);
        $setting = $repo->findOneBy(['settingKey' => $key]);
        if ($setting === null) {
            $setting = (new AppSetting())->setSettingKey($key)->setName($key);
            $entityManager->persist($setting);
        }
        $setting->setSettingValue($value)->touch();
    }
}
