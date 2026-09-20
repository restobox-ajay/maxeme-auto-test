<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\Tests\Service;

use App\Entity\AppSetting;
use App\Entity\ProductCategory;
use App\Service\AppSettings;
use App\Tests\DoctrineIntegrationTestCase;
use Number1CategoryProductPageBundle\Service\CategoryPageConfig;

final class CategoryPageConfigTest extends DoctrineIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // AppSettings caches all() for 3600s in a filesystem pool that outlives a single test
        // process — clear it so a stale entry from an earlier run can't leak into these reads,
        // same defensive pattern as StripeWebhookControllerTest/SalesDocumentPdfLayoutTest.
        self::getContainer()->get(AppSettings::class)->clearCache();
    }

    private function config(): CategoryPageConfig
    {
        return self::getContainer()->get(CategoryPageConfig::class);
    }

    private function categoryNamed(string $name): ?ProductCategory
    {
        return $this->em->getRepository(ProductCategory::class)->findOneBy(['name' => $name]);
    }

    public function testRawCategoryIdsDefaultsToZeroForAllRolesWhenUnconfigured(): void
    {
        self::assertSame(
            [CategoryPageConfig::ROLE_TIRE => 0, CategoryPageConfig::ROLE_WHEEL => 0, CategoryPageConfig::ROLE_ACCESSORIES => 0],
            $this->config()->rawCategoryIds(),
        );
    }

    public function testResolveCategoriesFindOrCreatesEachRoleByDefaultNameWhenUnconfigured(): void
    {
        $resolved = $this->config()->resolveCategories($this->em);

        self::assertSame('Tire', $resolved[CategoryPageConfig::ROLE_TIRE]->getName());
        self::assertSame('Wheel', $resolved[CategoryPageConfig::ROLE_WHEEL]->getName());
        self::assertSame('Accessories', $resolved[CategoryPageConfig::ROLE_ACCESSORIES]->getName());
        self::assertNotNull($resolved[CategoryPageConfig::ROLE_TIRE]->getId());
    }

    public function testResolveCategoriesIsCachedWithinOneServiceInstanceAndDoesNotCreateDuplicates(): void
    {
        $config = $this->config();

        $first = $config->resolveCategories($this->em);
        $second = $config->resolveCategories($this->em);

        self::assertSame($first[CategoryPageConfig::ROLE_TIRE], $second[CategoryPageConfig::ROLE_TIRE]);

        $tireCategories = $this->em->getRepository(ProductCategory::class)->findBy(['name' => 'Tire']);
        self::assertCount(1, $tireCategories);
    }

    public function testResolveCategoriesUsesTheConfiguredCategoryOverTheDefaultName(): void
    {
        $custom = (new ProductCategory())->setName('Off-Road Tires');
        $this->em->persist($custom);
        $this->em->flush();

        $this->config()->saveCategoryRoles($this->em, [CategoryPageConfig::ROLE_TIRE => $custom->getId()]);

        // saveCategoryRoles() resets the in-instance cache, but the container still hands back
        // the same service instance within one request/test — fetch it fresh to be explicit.
        $resolved = $this->config()->resolveCategories($this->em);

        self::assertSame($custom->getId(), $resolved[CategoryPageConfig::ROLE_TIRE]->getId());
        self::assertNull($this->categoryNamed('Tire'));
    }

    public function testResolveCategoriesFallsBackToFindOrCreateWhenTheConfiguredCategoryNoLongerExists(): void
    {
        $setting = (new AppSetting())
            ->setSettingKey('number1_category_page_role_category_id_tire')
            ->setName('number1_category_page_role_category_id_tire')
            ->setSettingValue('999999');
        $this->em->persist($setting);
        $this->em->flush();
        self::getContainer()->get(AppSettings::class)->clearCache();

        $resolved = $this->config()->resolveCategories($this->em);

        self::assertSame('Tire', $resolved[CategoryPageConfig::ROLE_TIRE]->getName());
    }

    public function testFindOrCreateByNameIsCaseInsensitiveAndDoesNotDuplicate(): void
    {
        $existing = (new ProductCategory())->setName('tire');
        $this->em->persist($existing);
        $this->em->flush();

        $resolved = $this->config()->resolveCategories($this->em);

        self::assertSame($existing->getId(), $resolved[CategoryPageConfig::ROLE_TIRE]->getId());

        $tireLikeCategories = array_filter(
            $this->em->getRepository(ProductCategory::class)->findBy([]),
            static fn (ProductCategory $c): bool => strtolower($c->getName()) === 'tire',
        );
        self::assertCount(1, $tireLikeCategories);
    }

    public function testSaveCategoryRolesIgnoresZeroAndMissingRoles(): void
    {
        $this->config()->saveCategoryRoles($this->em, [
            CategoryPageConfig::ROLE_TIRE => 0,
            CategoryPageConfig::ROLE_WHEEL => -1,
        ]);

        self::assertSame(
            [CategoryPageConfig::ROLE_TIRE => 0, CategoryPageConfig::ROLE_WHEEL => 0, CategoryPageConfig::ROLE_ACCESSORIES => 0],
            $this->config()->rawCategoryIds(),
        );
    }

    public function testWheelFacetsReturnsEmptyArrayWhenNothingSaved(): void
    {
        self::assertSame([], $this->config()->wheelFacets());
    }

    public function testSaveWheelFacetsAndWheelFacetsRoundTrip(): void
    {
        $facets = [
            ['slug' => 'bolt_pattern', 'label' => 'Bolt Pattern', 'advanced' => false],
            ['slug' => 'offset', 'label' => 'Offset', 'advanced' => true],
        ];

        $this->config()->saveWheelFacets($this->em, $facets);

        self::assertSame($facets, $this->config()->wheelFacets());
    }

    public function testViewConfigDefaultsToGridAndListAvailableWithGridDefaultWhenUnconfigured(): void
    {
        self::assertSame(
            ['gridAvailable' => true, 'listAvailable' => true, 'defaultView' => CategoryPageConfig::DEFAULT_VIEW],
            $this->config()->viewConfig(CategoryPageConfig::ROLE_WHEEL),
        );
    }

    public function testSaveViewConfigAndViewConfigRoundTrip(): void
    {
        $this->config()->saveViewConfig($this->em, [
            CategoryPageConfig::ROLE_WHEEL => ['gridAvailable' => false, 'listAvailable' => true, 'defaultView' => 'LIST_VIEW'],
        ]);

        self::assertSame(
            ['gridAvailable' => false, 'listAvailable' => true, 'defaultView' => 'LIST_VIEW'],
            $this->config()->viewConfig(CategoryPageConfig::ROLE_WHEEL),
        );
    }

    public function testSaveViewConfigLeavesRolesNotPresentInTheGivenArrayAtTheirDefaults(): void
    {
        $this->config()->saveViewConfig($this->em, [
            CategoryPageConfig::ROLE_WHEEL => ['gridAvailable' => false, 'listAvailable' => true, 'defaultView' => 'LIST_VIEW'],
        ]);

        self::assertSame(
            ['gridAvailable' => true, 'listAvailable' => true, 'defaultView' => CategoryPageConfig::DEFAULT_VIEW],
            $this->config()->viewConfig(CategoryPageConfig::ROLE_TIRE),
        );
    }
}
