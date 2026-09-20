<?php

declare(strict_types=1);

namespace Number1RimImportBundle\Service;

use App\Entity\AppSetting;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCategory;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads/writes this bundle's config-screen values via App\Entity\AppSetting — same pattern
 * PaymentStripeBundle\Service\StripeConfigProvider already uses. One place both
 * RimImportController (the "Sync now" / config screen path) and SyncRimProductsCommand (the cron
 * path) resolve the same settings from, so they can never drift.
 */
final class RimImportConfig
{
    public const KEY_API_URL = 'number1_rim_import_api_url';
    public const KEY_API_CLIENT_ID = 'number1_rim_import_api_client_id';
    public const KEY_API_KEY = 'number1_rim_import_api_key';
    public const KEY_CATEGORY_ID = 'number1_rim_import_category_id';
    public const KEY_REGION_ID = 'number1_rim_import_region_id';
    public const KEY_DEACTIVATE_MISSING = 'number1_rim_import_deactivate_missing';
    public const KEY_LAST_SYNC_AT = 'number1_rim_import_last_sync_at';
    public const KEY_LAST_SYNC_SUMMARY = 'number1_rim_import_last_sync_summary';

    public const DEFAULT_API_URL = 'https://wheels.rimalloycanada.com/v1/get-wheels';
    public const DEFAULT_CATEGORY_NAME = 'Wheels';

    public function __construct(
        private readonly AppSettings $appSettings,
    ) {}

    /**
     * @return array{api_url: string, api_client_id: string, api_key: string, category_id: int, region_id: int, deactivate_missing: bool}
     */
    public function raw(): array
    {
        return [
            'api_url' => trim((string) ($this->appSettings->get(self::KEY_API_URL, self::DEFAULT_API_URL) ?? self::DEFAULT_API_URL)),
            'api_client_id' => trim((string) ($this->appSettings->get(self::KEY_API_CLIENT_ID, '') ?? '')),
            'api_key' => trim((string) ($this->appSettings->get(self::KEY_API_KEY, '') ?? '')),
            'category_id' => (int) ($this->appSettings->get(self::KEY_CATEGORY_ID, '0') ?: 0),
            'region_id' => (int) ($this->appSettings->get(self::KEY_REGION_ID, '0') ?: 0),
            'deactivate_missing' => ($this->appSettings->get(self::KEY_DEACTIVATE_MISSING, '1') ?? '1') === '1',
        ];
    }

    /**
     * @return array{api_url: string, api_client_id: string, api_key: string, category: ?ProductCategory, region: ?FulfillmentRegion, deactivate_missing: bool}
     */
    public function resolve(EntityManagerInterface $entityManager): array
    {
        $raw = $this->raw();

        $category = $raw['category_id'] > 0 ? $entityManager->find(ProductCategory::class, $raw['category_id']) : null;
        if (!$category instanceof ProductCategory) {
            $category = $this->findOrCreateDefaultCategory($entityManager);
        }

        $region = $raw['region_id'] > 0 ? $entityManager->find(FulfillmentRegion::class, $raw['region_id']) : null;

        return [
            'api_url' => $raw['api_url'],
            'api_client_id' => $raw['api_client_id'],
            'api_key' => $raw['api_key'],
            'category' => $category,
            'region' => $region instanceof FulfillmentRegion ? $region : null,
            'deactivate_missing' => $raw['deactivate_missing'],
        ];
    }

    /** @param array<string, string> $values */
    public function save(EntityManagerInterface $entityManager, array $values): void
    {
        $repo = $entityManager->getRepository(AppSetting::class);
        foreach ($values as $key => $value) {
            $setting = $repo->findOneBy(['settingKey' => $key]);
            if ($setting === null) {
                $setting = (new AppSetting())->setSettingKey($key)->setName($key);
                $entityManager->persist($setting);
            }
            $setting->setSettingValue($value)->touch();
        }

        $entityManager->flush();
        $this->appSettings->clearCache();
    }

    /** Case-insensitive find-or-create, reused on every sync so a "Wheels" category is never duplicated. */
    private function findOrCreateDefaultCategory(EntityManagerInterface $entityManager): ProductCategory
    {
        $existing = $entityManager->getRepository(ProductCategory::class)->createQueryBuilder('c')
            ->andWhere('LOWER(c.name) = :name')
            ->setParameter('name', strtolower(self::DEFAULT_CATEGORY_NAME))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existing instanceof ProductCategory) {
            return $existing;
        }

        $category = (new ProductCategory())->setName(self::DEFAULT_CATEGORY_NAME);
        $entityManager->persist($category);
        $entityManager->flush();

        return $category;
    }
}
