<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\PriceList;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * GitHub issue #405, "Product Pricing (grid)":
 *
 *  1. The price grid (admin_product_price_index) had no product category filter at all, unlike the
 *     Product Detail grid which already has one. Covers that the filter exists, is positioned right
 *     after the product name column/filter, and actually narrows the result set.
 *
 *  2. The "apply to all pages" bulk-apply button (admin_product_price_bulk_apply) ignored the grid's
 *     active filters entirely and rewrote pricing for every non-deleted product in the database,
 *     regardless of what was actually on screen. The per-page button ("1") was always correct because
 *     it only ever touches the rows rendered in the DOM. This covers that "apply to all" now re-runs
 *     the same filters server-side (name, category, price, default price, id) and still reaches every
 *     matching product regardless of the grid's display pagination — it must not silently start
 *     honoring a client-supplied page/limit either.
 */
final class AdminProductPriceGridCategoryFilterAndBulkApplyScopeCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-price-grid-405-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCategory(FunctionalTester $I, string $name): ProductCategory
    {
        $category = (new ProductCategory())->setName($name)->setStatus('Visible');
        $I->haveInRepository($category);

        return $category;
    }

    private function makeProduct(FunctionalTester $I, string $sku, string $name, string $defaultPrice, ?ProductCategory $category = null): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName($name)->setDefaultPrice($defaultPrice)->setCategory($category)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    private function makePriceList(FunctionalTester $I, string $name): PriceList
    {
        $priceList = (new PriceList())->setName($name)->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        return $priceList;
    }

    private function grabPriceToken(FunctionalTester $I, string $name): string
    {
        $I->amOnPage('/admin/product/price/index');

        return (string) $I->grabAttributeFrom('#price-write-tokens', 'data-' . $name . '-token');
    }

    // --- issue #1: category filter ------------------------------------------------------------

    /** Issue #408: SKU moved to right after Product name, and Category moved to right after SKU
     *  (it used to sit right after Product name, before this change), matching the same order as
     *  the Product Detail grid. */
    public function theSkuAndCategoryColumnsAreRenderedRightAfterTheProductNameColumnInThatOrder(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeProduct($I, 'CATCOL-1', 'Category Column Product', '10.00');

        $I->amOnPage('/admin/product/price/index');
        $I->seeResponseCodeIsSuccessful();

        $html = $I->grabPageSource();
        $namePos = strpos($html, 'data-sort-field="name"');
        $skuPos = strpos($html, 'data-sort-field="sku"');
        $categoryPos = strpos($html, 'data-sort-field="category"');
        $pricePos = strpos($html, 'data-sort-field="price"');

        $I->assertNotFalse($namePos, 'Product name header not found.');
        $I->assertNotFalse($skuPos, 'SKU header not found.');
        $I->assertNotFalse($categoryPos, 'Category header not found.');
        $I->assertNotFalse($pricePos, 'Product Price header not found.');
        $I->assertTrue($namePos < $skuPos, 'SKU header must come after Product name.');
        $I->assertTrue($skuPos < $categoryPos, 'Category header must come after SKU.');
        $I->assertTrue($categoryPos < $pricePos, 'Category header must come before Product Price.');

        // The filter-row input follows the same order.
        $nameFilterPos = strpos($html, 'name="filters[name]"');
        $skuFilterPos = strpos($html, 'name="filters[sku]"');
        $categoryFilterPos = strpos($html, 'name="filters[category]"');
        $priceFilterPos = strpos($html, 'name="filters[price]"');
        $I->assertNotFalse($nameFilterPos);
        $I->assertNotFalse($skuFilterPos);
        $I->assertNotFalse($categoryFilterPos);
        $I->assertNotFalse($priceFilterPos);
        $I->assertTrue($nameFilterPos < $skuFilterPos, 'SKU filter input must come after the name filter.');
        $I->assertTrue($skuFilterPos < $categoryFilterPos, 'Category filter input must come after the SKU filter.');
        $I->assertTrue($categoryFilterPos < $priceFilterPos, 'Category filter input must come before the price filter.');
    }

    public function filteringByCategoryIdShowsOnlyProductsInThatCategory(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $tires = $this->makeCategory($I, 'Tires 405');
        $wheels = $this->makeCategory($I, 'Wheels 405');

        $this->makeProduct($I, 'CATFILT-TIRE', 'Category Filter Tire Product', '10.00', $tires);
        $this->makeProduct($I, 'CATFILT-WHEEL', 'Category Filter Wheel Product', '10.00', $wheels);

        $I->amOnPage('/admin/product/price/index?filters[category]=' . $tires->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Category Filter Tire Product');
        $I->dontSee('Category Filter Wheel Product');
    }

    // --- issue #2: "apply to all pages" must respect filters and still span every page ---------

    public function applyToAllPagesOnlyUpdatesProductsMatchingTheActiveNameFilter(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'Bulk Scope Name List');
        $matching = $this->makeProduct($I, 'BULK-SCOPE-MATCH', 'Bulk Scope Match Product', '100.00');
        $other = $this->makeProduct($I, 'BULK-SCOPE-OTHER', 'Totally Different Product', '100.00');

        $token = $this->grabPriceToken($I, 'bulk-apply');
        $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
            '_token' => $token,
            'price_list_id' => (string) $priceList->getId(),
            'field' => 'both',
            'type' => 'Discount%',
            'value' => '20',
            'filters' => ['name' => 'Bulk Scope Match'],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);

        $I->seeInRepository(ProductPricing::class, [
            'product' => $matching->getId(),
            'priceList' => $priceList->getId(),
            'ruleType' => 'Discount%',
            'ruleValue' => '20.00',
            'price' => '80.00',
        ]);
        $I->dontSeeInRepository(ProductPricing::class, [
            'product' => $other->getId(),
            'priceList' => $priceList->getId(),
        ]);
    }

    public function applyToAllPagesOnlyUpdatesProductsMatchingTheActiveCategoryFilter(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'Bulk Scope Category List');
        $wanted = $this->makeCategory($I, 'Bulk Scope Wanted Category');
        $unwanted = $this->makeCategory($I, 'Bulk Scope Unwanted Category');

        $matching = $this->makeProduct($I, 'BULK-CAT-MATCH', 'Bulk Cat Match Product', '200.00', $wanted);
        $other = $this->makeProduct($I, 'BULK-CAT-OTHER', 'Bulk Cat Other Product', '200.00', $unwanted);

        $token = $this->grabPriceToken($I, 'bulk-apply');
        $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
            '_token' => $token,
            'price_list_id' => (string) $priceList->getId(),
            'field' => 'both',
            'type' => 'Discount$',
            'value' => '30',
            'filters' => ['category' => (string) $wanted->getId()],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(ProductPricing::class, [
            'product' => $matching->getId(),
            'priceList' => $priceList->getId(),
            'ruleType' => 'Discount$',
            'ruleValue' => '30.00',
            'price' => '170.00',
        ]);
        $I->dontSeeInRepository(ProductPricing::class, [
            'product' => $other->getId(),
            'priceList' => $priceList->getId(),
        ]);
    }

    /** Confirms the fix didn't trade one bug for another: filtered results must still all be
     *  updated in one call, not truncated to whatever a client-supplied page/limit might imply. */
    public function applyToAllPagesReachesEveryMatchingProductRegardlessOfDisplayPageSize(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'Bulk Scope Span List');
        $shared = $this->makeCategory($I, 'Bulk Scope Span Category');

        $products = [];
        for ($i = 1; $i <= 3; $i++) {
            $products[] = $this->makeProduct($I, 'BULK-SPAN-' . $i, 'Bulk Span Product ' . $i, '50.00', $shared);
        }

        $token = $this->grabPriceToken($I, 'bulk-apply');
        // A client-supplied page/limit (as if the grid were paginated to 1 row per page) must not
        // narrow the server-side selection — this endpoint has no page/limit of its own.
        $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
            '_token' => $token,
            'price_list_id' => (string) $priceList->getId(),
            'field' => 'both',
            'type' => 'Number',
            'value' => '42',
            'filters' => ['category' => (string) $shared->getId()],
            'page' => '1',
            'limit' => '1',
        ]);
        $I->seeResponseCodeIsSuccessful();

        foreach ($products as $product) {
            $I->seeInRepository(ProductPricing::class, [
                'product' => $product->getId(),
                'priceList' => $priceList->getId(),
                'ruleType' => 'Number',
                'ruleValue' => '42.00',
                'price' => '42.00',
            ]);
        }
    }

    public function applyToAllPagesWithNoFiltersStillUpdatesEverythingLikeBefore(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'Bulk Scope Unfiltered List');
        $productA = $this->makeProduct($I, 'BULK-UNFILT-A', 'Bulk Unfiltered Product A', '100.00');
        $productB = $this->makeProduct($I, 'BULK-UNFILT-B', 'Bulk Unfiltered Product B', '100.00');

        $token = $this->grabPriceToken($I, 'bulk-apply');
        $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
            '_token' => $token,
            'price_list_id' => (string) $priceList->getId(),
            'field' => 'both',
            'type' => 'Discount%',
            'value' => '10',
        ]);
        $I->seeResponseCodeIsSuccessful();

        foreach ([$productA, $productB] as $product) {
            $I->seeInRepository(ProductPricing::class, [
                'product' => $product->getId(),
                'priceList' => $priceList->getId(),
                'ruleType' => 'Discount%',
                'ruleValue' => '10.00',
                'price' => '90.00',
            ]);
        }
    }
}
