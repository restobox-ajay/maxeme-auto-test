<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\Company;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Enum\ProductStatus;
use App\Tests\DoctrineIntegrationTestCase;
use App\Twig\CustomerNavExtension;

/**
 * Exercises getCustomerNav() against a real EntityManager — the category tree building,
 * per-category product counts, and descendant-id aggregation all run real DQL, so a mocked
 * EntityManager would prove nothing about the actual queries.
 */
final class CustomerNavExtensionTest extends DoctrineIntegrationTestCase
{
    private CustomerNavExtension $extension;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension = new CustomerNavExtension($this->em);
    }

    private function newCategory(string $name, ?ProductCategory $parent = null, string $status = 'Visible'): ProductCategory
    {
        $category = (new ProductCategory())->setName($name)->setStatus($status)->setParent($parent);
        $this->em->persist($category);

        return $category;
    }

    private function newProduct(string $sku, ?ProductCategory $category, bool $visible = true, bool $deleted = false, ProductStatus $status = ProductStatus::Active): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku)
            ->setName($sku)
            ->setCategory($category)
            ->setVisible($visible)
            ->setDeleted($deleted)
            ->applyStatusChoice($status)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);

        return $product;
    }

    public function testTopLevelCategoriesAreSortedAlphabeticallyWithProductCounts(): void
    {
        $zebra = $this->newCategory('Zebra');
        $apple = $this->newCategory('Apple');
        $this->newProduct('SKU-1', $apple);
        $this->newProduct('SKU-2', $zebra);
        $this->newProduct('SKU-3', $zebra);
        $this->em->flush();

        $nav = $this->extension->getCustomerNav();

        self::assertSame(['Apple', 'Zebra'], array_column($nav['categories'], 'name'));
        self::assertSame(1, $nav['categories'][0]['count']);
        self::assertSame(2, $nav['categories'][1]['count']);
        self::assertSame(3, $nav['totalProductsCount']);
        self::assertSame(0, $nav['uncategorizedCount']);
    }

    public function testInvisibleCategoryIsExcludedFromTree(): void
    {
        $this->newCategory('Hidden', null, 'Hidden');
        $this->newCategory('Shown', null, 'Visible');
        $this->em->flush();

        $nav = $this->extension->getCustomerNav();

        self::assertSame(['Shown'], array_column($nav['categories'], 'name'));
    }

    public function testChildCategoryCountRollsUpIntoParentAndDescendantIds(): void
    {
        $parent = $this->newCategory('Drinks');
        $child = $this->newCategory('Soda', $parent);
        $grandchild = $this->newCategory('Cola', $child);
        $this->newProduct('SKU-1', $grandchild);
        $this->em->flush();

        $nav = $this->extension->getCustomerNav();

        self::assertCount(1, $nav['categories']);
        $drinks = $nav['categories'][0];
        self::assertSame('Drinks', $drinks['name']);
        // Parent count rolls up through the whole subtree, not just direct children.
        self::assertSame(1, $drinks['count']);
        self::assertContains((string) $grandchild->getId(), $drinks['descendantIds']);
        self::assertContains((string) $child->getId(), $drinks['descendantIds']);

        $soda = $drinks['children'][0];
        self::assertSame('Soda', $soda['name']);
        self::assertSame(1, $soda['count']);

        $cola = $soda['children'][0];
        self::assertSame('Cola', $cola['name']);
        self::assertSame(1, $cola['count']);
    }

    public function testNonVisibleNonActiveOrDeletedProductsAreExcludedFromCount(): void
    {
        $category = $this->newCategory('Snacks');
        $this->newProduct('SKU-hidden', $category, visible: false);
        $this->newProduct('SKU-deleted', $category, deleted: true);
        $this->newProduct('SKU-inactive', $category, status: ProductStatus::Inactive);
        $this->newProduct('SKU-ok', $category);
        $this->em->flush();

        $nav = $this->extension->getCustomerNav();

        self::assertSame(1, $nav['categories'][0]['count']);
        self::assertSame(1, $nav['totalProductsCount']);
    }

    public function testUncategorizedProductsAreCountedSeparately(): void
    {
        $category = $this->newCategory('Snacks');
        $this->newProduct('SKU-in-cat', $category);
        $this->newProduct('SKU-no-cat', null);
        $this->em->flush();

        $nav = $this->extension->getCustomerNav();

        self::assertSame(2, $nav['totalProductsCount']);
        self::assertSame(1, $nav['uncategorizedCount']);
    }

    public function testProductWithMultiplePrivateCompaniesIsNotDoubleCountedInCategory(): void
    {
        $category = $this->newCategory('Snacks');
        $product = $this->newProduct('SKU-private', $category);

        $companyA = (new Company())->setName('Acme')->setCode('ACME');
        $companyB = (new Company())->setName('Beta')->setCode('BETA');
        $this->em->persist($companyA);
        $this->em->persist($companyB);
        $product->addPrivateCompany($companyA)->addPrivateCompany($companyB);
        $this->em->flush();

        $nav = $this->extension->getCustomerNav();

        self::assertSame(1, $nav['categories'][0]['count']);
    }
}
