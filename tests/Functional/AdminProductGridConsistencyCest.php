<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * GitHub issue #408, "Product admin page alignment": the three product admin grids — Product
 * Detail (ProductController::details()), Product Pricing (ProductController::prices()), and
 * Inventory (InventoryController::index()) — must:
 *
 *  1. Put Category right after SKU on the Detail grid (moved from after Status).
 *  2. Have a Category filter on the Inventory grid, right after SKU (it had none before).
 *  3. Filter/sort identically across all three for id/name/sku/category, so switching between
 *     the Pricing/Details/Inventory nav buttons with the same query shows the same products.
 *  4. Carry the current filter/sort/pagination state through those three nav buttons instead of
 *     resetting to an unfiltered view.
 */
final class AdminProductGridConsistencyCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('product-grid-consistency-408@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCategory(FunctionalTester $I, string $name, ?ProductCategory $parent = null): ProductCategory
    {
        $category = (new ProductCategory())->setName($name)->setStatus('Visible')->setParent($parent);
        $I->haveInRepository($category);

        return $category;
    }

    private function makeProduct(FunctionalTester $I, string $sku, string $name, ?ProductCategory $category = null): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName($name)->setCategory($category)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    // --- item A / #408 item 1: Detail grid column order ----------------------------------------

    public function theDetailGridPutsCategoryRightAfterSku(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeProduct($I, 'DETCOL-1', 'Detail Column Order Product');

        $I->amOnPage('/admin/product/detail/index');
        $I->seeResponseCodeIsSuccessful();

        $html = $I->grabPageSource();
        $skuPos = strpos($html, 'data-sort-field="sku"');
        $categoryPos = strpos($html, 'data-sort-field="category"');
        $typePos = strpos($html, 'data-sort-field="type"');

        $I->assertNotFalse($skuPos, 'SKU header not found.');
        $I->assertNotFalse($categoryPos, 'Category header not found.');
        $I->assertNotFalse($typePos, 'Type header not found.');
        $I->assertTrue($skuPos < $categoryPos, 'Category header must come right after SKU.');
        $I->assertTrue($categoryPos < $typePos, 'Category header must come before Type.');

        $skuFilterPos = strpos($html, 'name="filters[sku]"');
        $categoryFilterPos = strpos($html, 'name="filters[category]"');
        $typeFilterPos = strpos($html, 'name="filters[type]"');
        $I->assertNotFalse($skuFilterPos);
        $I->assertNotFalse($categoryFilterPos);
        $I->assertNotFalse($typeFilterPos);
        $I->assertTrue($skuFilterPos < $categoryFilterPos, 'Category filter must come right after the SKU filter.');
        $I->assertTrue($categoryFilterPos < $typeFilterPos, 'Category filter must come before the type filter.');
    }

    // --- #408 item 3: Inventory gets a category filter, right after SKU ------------------------

    public function theInventoryGridHasACategoryFilterRightAfterSku(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $tires = $this->makeCategory($I, 'Inventory Filter Tires');
        $wheels = $this->makeCategory($I, 'Inventory Filter Wheels');
        $this->makeProduct($I, 'INVCAT-TIRE', 'Inventory Category Tire Product', $tires);
        $this->makeProduct($I, 'INVCAT-WHEEL', 'Inventory Category Wheel Product', $wheels);

        $I->amOnPage('/admin/inventory');
        $I->seeResponseCodeIsSuccessful();

        $html = $I->grabPageSource();
        $skuFilterPos = strpos($html, 'name="filters[sku]"');
        $categoryFilterPos = strpos($html, 'name="filters[category]"');
        $I->assertNotFalse($skuFilterPos, 'SKU filter not found on Inventory grid.');
        $I->assertNotFalse($categoryFilterPos, 'Category filter not found on Inventory grid.');
        $I->assertTrue($skuFilterPos < $categoryFilterPos, 'Category filter must come right after SKU on Inventory.');

        // And it actually filters.
        $I->amOnPage('/admin/inventory?filters[category]=' . $tires->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Inventory Category Tire Product');
        $I->dontSee('Inventory Category Wheel Product');
    }

    // --- #408 item 4: identical filter/sort results across all three pages ---------------------

    public function theSameNameFilterMatchesTheSameProductOnAllThreePages(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeProduct($I, 'XPAGE-MATCH', 'Cross Page Match Product');
        $this->makeProduct($I, 'XPAGE-OTHER', 'Totally Unrelated Product');

        foreach ([
            '/admin/product/detail/index',
            '/admin/product/price/index',
            '/admin/inventory',
        ] as $path) {
            $I->amOnPage($path . '?filters[name]=Cross Page Match');
            $I->seeResponseCodeIsSuccessful();
            $I->see('Cross Page Match Product');
            $I->dontSee('Totally Unrelated Product');
        }
    }

    public function theSameSkuFilterMatchesTheSameProductOnAllThreePages(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeProduct($I, 'XPAGE-SKU-MATCH', 'Sku Match Product');
        $this->makeProduct($I, 'XPAGE-SKU-OTHER', 'Sku Other Product');

        foreach ([
            '/admin/product/detail/index',
            '/admin/product/price/index',
            '/admin/inventory',
        ] as $path) {
            $I->amOnPage($path . '?filters[sku]=XPAGE-SKU-MATCH');
            $I->seeResponseCodeIsSuccessful();
            $I->see('Sku Match Product');
            $I->dontSee('Sku Other Product');
        }
    }

    public function theSameCategoryFilterMatchesTheSameProductOnAllThreePages(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $wanted = $this->makeCategory($I, 'Cross Page Wanted Category');
        $unwanted = $this->makeCategory($I, 'Cross Page Unwanted Category');
        $this->makeProduct($I, 'XPAGE-CAT-MATCH', 'Cross Page Cat Match Product', $wanted);
        $this->makeProduct($I, 'XPAGE-CAT-OTHER', 'Cross Page Cat Other Product', $unwanted);

        foreach ([
            '/admin/product/detail/index',
            '/admin/product/price/index',
            '/admin/inventory',
        ] as $path) {
            $I->amOnPage($path . '?filters[category]=' . $wanted->getId());
            $I->seeResponseCodeIsSuccessful();
            $I->see('Cross Page Cat Match Product');
            $I->dontSee('Cross Page Cat Other Product');
        }
    }

    public function sortingByCategoryOrdersProductsTheSameWayOnAllThreePages(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $catA = $this->makeCategory($I, 'AAA Sort Category');
        $catZ = $this->makeCategory($I, 'ZZZ Sort Category');
        $this->makeProduct($I, 'SORT-Z', 'Sort Product In Z Category', $catZ);
        $this->makeProduct($I, 'SORT-A', 'Sort Product In A Category', $catA);

        foreach ([
            '/admin/product/detail/index',
            '/admin/product/price/index',
            '/admin/inventory',
        ] as $path) {
            $I->amOnPage($path . '?sort=category&dir=asc&filters[name]=Sort Product In');
            $I->seeResponseCodeIsSuccessful();

            $html = $I->grabPageSource();
            $aPos = strpos($html, 'Sort Product In A Category');
            $zPos = strpos($html, 'Sort Product In Z Category');
            $I->assertNotFalse($aPos, "A-category product row missing on $path");
            $I->assertNotFalse($zPos, "Z-category product row missing on $path");
            $I->assertTrue($aPos < $zPos, "Sorting by category ascending must list the A category first on $path");
        }
    }

    /**
     * A grid's Category column showing only the leaf name is indistinguishable from a totally
     * different top-level category that happens to share a child name — "Plumbing > Fittings" and
     * "HVAC > Fittings" both showed as just "Fittings". Found via a real vendor sheet import whose
     * products all landed under "Plumbing Fittings" with no way to tell it apart from a same-named
     * category elsewhere in the tree. All three grids share AbstractAdminController::categoryPath().
     */
    public function theProductGridsShowTheFullCategoryPathNotJustTheLeafName(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $plumbing = $this->makeCategory($I, 'Category Path Plumbing');
        $fittings = $this->makeCategory($I, 'Category Path Fittings', $plumbing);
        $this->makeProduct($I, 'CATPATH-1', 'Category Path Product', $fittings);

        foreach ([
            '/admin/product/detail/index',
            '/admin/product/price/index',
            '/admin/inventory',
        ] as $path) {
            $I->amOnPage($path . '?filters[sku]=CATPATH-1');
            $I->seeResponseCodeIsSuccessful();
            $I->see('Category Path Plumbing > Category Path Fittings');
        }
    }

    // --- #408 item 5: switching tabs carries the filter/sort/pagination state through ----------

    /** @return array<string, string> */
    private function grabNavLinkQuery(FunctionalTester $I, string $hrefContains): array
    {
        $href = $I->grabAttributeFrom('.switch-nav a[href*="' . $hrefContains . '"]', 'href');
        $query = (string) parse_url($href, PHP_URL_QUERY);
        parse_str($query, $parsed);

        return $parsed;
    }

    public function switchingFromDetailToPricingAndInventoryCarriesTheCurrentFilterSortAndPage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeProduct($I, 'NAVCARRY-1', 'Nav Carry Product');

        $I->amOnPage('/admin/product/detail/index?sort=sku&dir=desc&limit=20&page=2&filters[name]=Widget&filters[sku]=WID&filters[category]=7');
        $I->seeResponseCodeIsSuccessful();

        $pricingQuery = $this->grabNavLinkQuery($I, 'admin/product/price/index');
        $I->assertSame('sku', $pricingQuery['sort'] ?? null);
        $I->assertSame('desc', $pricingQuery['dir'] ?? null);
        $I->assertSame('20', $pricingQuery['limit'] ?? null);
        $I->assertSame('2', $pricingQuery['page'] ?? null);
        $I->assertSame('Widget', $pricingQuery['filters']['name'] ?? null);
        $I->assertSame('WID', $pricingQuery['filters']['sku'] ?? null);
        $I->assertSame('7', $pricingQuery['filters']['category'] ?? null);

        $inventoryQuery = $this->grabNavLinkQuery($I, 'admin/inventory');
        $I->assertSame('sku', $inventoryQuery['sort'] ?? null);
        $I->assertSame('desc', $inventoryQuery['dir'] ?? null);
        $I->assertSame('Widget', $inventoryQuery['filters']['name'] ?? null);
        $I->assertSame('7', $inventoryQuery['filters']['category'] ?? null);
    }

    public function switchingFromInventoryToDetailAndPricingCarriesTheCurrentFilterSortAndPage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeProduct($I, 'NAVCARRY-2', 'Nav Carry Product Two');

        $I->amOnPage('/admin/inventory?sort=name&dir=asc&limit=50&page=1&filters[sku]=NAVCARRY');
        $I->seeResponseCodeIsSuccessful();

        $detailQuery = $this->grabNavLinkQuery($I, 'admin/product/detail/index');
        $I->assertSame('name', $detailQuery['sort'] ?? null);
        $I->assertSame('asc', $detailQuery['dir'] ?? null);
        $I->assertSame('50', $detailQuery['limit'] ?? null);
        $I->assertSame('NAVCARRY', $detailQuery['filters']['sku'] ?? null);

        $pricingQuery = $this->grabNavLinkQuery($I, 'admin/product/price/index');
        $I->assertSame('name', $pricingQuery['sort'] ?? null);
        $I->assertSame('NAVCARRY', $pricingQuery['filters']['sku'] ?? null);
    }
}
