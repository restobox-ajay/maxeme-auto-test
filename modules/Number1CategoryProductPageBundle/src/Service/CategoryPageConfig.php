<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\Service;

use App\Entity\AppSetting;
use App\Entity\ProductCategory;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads/writes this bundle's config-screen values via App\Entity\AppSetting — same pattern
 * Number1RimImportBundle\Service\RimImportConfig already uses. One place both the admin config
 * controller and the template-override/facet providers resolve the same "which real
 * ProductCategory plays the Tire/Wheel/Accessories role" mapping from, so they can never drift.
 *
 * There are exactly three roles, not an arbitrary admin-managed list — per the client's own
 * framing (CATEGORY_PAGE_FILTER_PLAN.md §1): "There are 3 categories: Tire, Wheel, Accessories,
 * so 3 twig templates." Each role has a fixed, shipped template; the only thing admin-configurable
 * is *which* ProductCategory fills each role, and (for Wheel) which custom fields act as filters.
 */
final class CategoryPageConfig
{
    public const ROLE_TIRE = 'tire';
    public const ROLE_WHEEL = 'wheel';
    public const ROLE_ACCESSORIES = 'accessories';

    /** @var array<string, string> role => default category name, used by find-or-create */
    public const ROLE_DEFAULT_NAMES = [
        self::ROLE_TIRE => 'Tire',
        self::ROLE_WHEEL => 'Wheel',
        self::ROLE_ACCESSORIES => 'Accessories',
    ];

    public const DEFAULT_VIEW = 'GRID_VIEW';

    private const KEY_CATEGORY_ID_PREFIX = 'number1_category_page_role_category_id_';
    private const KEY_WHEEL_FACETS = 'number1_category_page_wheel_facets';
    private const KEY_VIEW_GRID_PREFIX = 'number1_category_page_view_grid_';
    private const KEY_VIEW_LIST_PREFIX = 'number1_category_page_view_list_';
    private const KEY_VIEW_DEFAULT_PREFIX = 'number1_category_page_view_default_';

    /** @var array<string, ProductCategory>|null in-request cache, see resolveCategories() */
    private ?array $resolvedCategories = null;

    public function __construct(
        private readonly AppSettings $appSettings,
    ) {}

    /** @return array<string, int> role => configured category id (0 if never configured) */
    public function rawCategoryIds(): array
    {
        $ids = [];
        foreach (self::ROLE_DEFAULT_NAMES as $role => $defaultName) {
            $ids[$role] = (int) ($this->appSettings->get(self::KEY_CATEGORY_ID_PREFIX . $role, '0') ?: 0);
        }

        return $ids;
    }

    /**
     * Resolves (and lazily find-or-creates) the real ProductCategory for each of the three roles.
     * Cached for the lifetime of this service instance — the three template-override providers and
     * the one facet provider each call this once per request, and it would otherwise mean up to
     * four redundant lookups per catalog page load.
     *
     * @return array<string, ProductCategory> role => category
     */
    public function resolveCategories(EntityManagerInterface $entityManager): array
    {
        if ($this->resolvedCategories !== null) {
            return $this->resolvedCategories;
        }

        $rawIds = $this->rawCategoryIds();
        $resolved = [];
        foreach (self::ROLE_DEFAULT_NAMES as $role => $defaultName) {
            $category = $rawIds[$role] > 0 ? $entityManager->find(ProductCategory::class, $rawIds[$role]) : null;
            if (!$category instanceof ProductCategory) {
                $category = $this->findOrCreateByName($entityManager, $defaultName);
            }
            $resolved[$role] = $category;
        }

        return $this->resolvedCategories = $resolved;
    }

    /**
     * @return list<array{slug: string, label: string, advanced: bool}>
     */
    public function wheelFacets(): array
    {
        $raw = (string) ($this->appSettings->get(self::KEY_WHEEL_FACETS, '') ?? '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, int> $categoryIdsByRole role => ProductCategory id (0/absent = keep find-or-create default) */
    public function saveCategoryRoles(EntityManagerInterface $entityManager, array $categoryIdsByRole): void
    {
        $values = [];
        foreach (self::ROLE_DEFAULT_NAMES as $role => $defaultName) {
            if (isset($categoryIdsByRole[$role]) && $categoryIdsByRole[$role] > 0) {
                $values[self::KEY_CATEGORY_ID_PREFIX . $role] = (string) $categoryIdsByRole[$role];
            }
        }

        $this->saveSettings($entityManager, $values);
        $this->resolvedCategories = null;
    }

    /** @param list<array{slug: string, label: string, advanced: bool}> $facets */
    public function saveWheelFacets(EntityManagerInterface $entityManager, array $facets): void
    {
        $this->saveSettings($entityManager, [self::KEY_WHEEL_FACETS => json_encode($facets, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Which view mode(s) this role's catalog page allows, and which is the default — read by
     * this bundle's CatalogViewConfigProviderInterface implementations, one per role. Defaults
     * (grid+list both available, grid default) exactly match core's own hardcoded pre-bundle
     * behavior, so a role never configured through this screen changes nothing.
     *
     * @return array{gridAvailable: bool, listAvailable: bool, defaultView: string}
     */
    public function viewConfig(string $role): array
    {
        $gridAvailable = ($this->appSettings->get(self::KEY_VIEW_GRID_PREFIX . $role, '1') ?? '1') !== '0';
        $listAvailable = ($this->appSettings->get(self::KEY_VIEW_LIST_PREFIX . $role, '1') ?? '1') !== '0';
        $defaultView = (string) ($this->appSettings->get(self::KEY_VIEW_DEFAULT_PREFIX . $role, self::DEFAULT_VIEW) ?? self::DEFAULT_VIEW);

        return [
            'gridAvailable' => $gridAvailable,
            'listAvailable' => $listAvailable,
            'defaultView' => $defaultView === 'LIST_VIEW' ? 'LIST_VIEW' : 'GRID_VIEW',
        ];
    }

    /** @param array<string, array{gridAvailable: bool, listAvailable: bool, defaultView: string}> $viewConfigByRole */
    public function saveViewConfig(EntityManagerInterface $entityManager, array $viewConfigByRole): void
    {
        $values = [];
        foreach (self::ROLE_DEFAULT_NAMES as $role => $defaultName) {
            $roleConfig = $viewConfigByRole[$role] ?? null;
            if ($roleConfig === null) {
                continue;
            }

            $values[self::KEY_VIEW_GRID_PREFIX . $role] = $roleConfig['gridAvailable'] ? '1' : '0';
            $values[self::KEY_VIEW_LIST_PREFIX . $role] = $roleConfig['listAvailable'] ? '1' : '0';
            $values[self::KEY_VIEW_DEFAULT_PREFIX . $role] = $roleConfig['defaultView'] === 'LIST_VIEW' ? 'LIST_VIEW' : 'GRID_VIEW';
        }

        $this->saveSettings($entityManager, $values);
    }

    /** @param array<string, string> $values */
    private function saveSettings(EntityManagerInterface $entityManager, array $values): void
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

    /** Case-insensitive find-or-create, same convention as Number1RimImportBundle's default category. */
    private function findOrCreateByName(EntityManagerInterface $entityManager, string $name): ProductCategory
    {
        $existing = $entityManager->getRepository(ProductCategory::class)->createQueryBuilder('c')
            ->andWhere('LOWER(c.name) = :name')
            ->setParameter('name', strtolower($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existing instanceof ProductCategory) {
            return $existing;
        }

        $category = (new ProductCategory())->setName($name);
        $entityManager->persist($category);
        $entityManager->flush();

        return $category;
    }
}
