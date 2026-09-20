<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\Tests\Menu;

use App\Entity\ProductCategory;
use App\Tests\DoctrineIntegrationTestCase;
use App\Twig\FrontendMenuExtension;
use Number1CategoryProductPageBundle\Menu\AccessoriesFrontendMenuItem;
use Number1CategoryProductPageBundle\Menu\TireFrontendMenuItem;
use Number1CategoryProductPageBundle\Menu\WheelFrontendMenuItem;

final class RoleFrontendMenuItemTest extends DoctrineIntegrationTestCase
{
    public function testTireItemResolvesToTheFindOrCreatedTireCategory(): void
    {
        $item = self::getContainer()->get(TireFrontendMenuItem::class);

        self::assertSame('number1_category_product_page.tire', $item->getKey());
        self::assertSame('Tire', $item->getLabel());
        self::assertSame('customer_catalog', $item->getRoute());
        self::assertSame('Number1CategoryProductPageBundle', $item->getSource());
        self::assertTrue($item->isVisible());

        // getRouteParams() is what triggers the lazy find-or-create — look the category up only after calling it.
        $categoryId = $item->getRouteParams()['ProductSearch[category_id]'];
        $category = $this->em->find(ProductCategory::class, $categoryId);
        self::assertNotNull($category);
        self::assertSame('Tire', $category->getName());
    }

    public function testWheelItemResolvesToTheFindOrCreatedWheelCategory(): void
    {
        $item = self::getContainer()->get(WheelFrontendMenuItem::class);
        self::assertSame('Wheel', $item->getLabel());

        $categoryId = $item->getRouteParams()['ProductSearch[category_id]'];
        $category = $this->em->find(ProductCategory::class, $categoryId);
        self::assertNotNull($category);
        self::assertSame('Wheel', $category->getName());
    }

    public function testAccessoriesItemResolvesToTheFindOrCreatedAccessoriesCategory(): void
    {
        $item = self::getContainer()->get(AccessoriesFrontendMenuItem::class);
        self::assertSame('Accessories', $item->getLabel());

        $categoryId = $item->getRouteParams()['ProductSearch[category_id]'];
        $category = $this->em->find(ProductCategory::class, $categoryId);
        self::assertNotNull($category);
        self::assertSame('Accessories', $category->getName());
    }

    /** Confirms the app.frontend_menu_item tagging in this bundle's services.yaml actually wires up — a class-level test alone wouldn't catch a tag-name typo. */
    public function testTireWheelAndAccessoriesAppearInTheAggregatedFrontendMenuItemsList(): void
    {
        $extension = self::getContainer()->get(FrontendMenuExtension::class);
        $labels = array_column($extension->getFrontendMenuItems(), 'label');

        self::assertContains('Tire', $labels);
        self::assertContains('Wheel', $labels);
        self::assertContains('Accessories', $labels);
    }
}
