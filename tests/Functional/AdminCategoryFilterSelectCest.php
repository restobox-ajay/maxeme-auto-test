<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Issue #424: the category filter on the three admin grids (Product Detail, Price grid, Inventory)
 * was an `<input type="text" list="...">` + `<datalist>`, with the backend guessing id-vs-name by
 * ctype_digit(). It is now a plain `<select name="filters[category]">` of category ids, enhanced
 * client-side by the existing initSearchableSelect() widget, and the backend does one unconditional
 * id match.
 *
 * This is the acceptance test for that: FunctionalTester drives the app through the Symfony kernel
 * and executes NO JavaScript, so everything asserted here is what a no-JS admin actually gets. That
 * is the whole point of the spec'd design — a real `<select>` needs no script to be usable, whereas
 * the searchable-select enhancement is pure progressive enhancement on top.
 *
 * Covers, on all three grids:
 *  1. A real `<select name="filters[category]">` is rendered, and the old `<input ... list=`/
 *     `<datalist>` markup is gone.
 *  2. `<option value="{id}" selected>` reflects the active filter.
 *  3. `?filters[category]={id}` actually narrows the result set with zero script execution.
 *
 * It also pins the removal of the old behavior: a category *name* is no longer a valid filter value,
 * because the backend now matches on id only.
 */
final class AdminCategoryFilterSelectCest
{
    /**
     * The three grids that share the category filter, each with the `<datalist>` id its old
     * category input used. Only that datalist must be gone — Product Detail still uses datalists
     * for its visible/status/privacy filters, which #424 explicitly leaves out of scope.
     *
     * @var array<string, string>
     */
    private const GRID_URLS = [
        '/admin/product/detail/index' => 'product-category-options',
        '/admin/product/price/index' => 'price-grid-category-options',
        '/admin/inventory' => 'inventory-category-options',
    ];

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-category-filter-424-test@example.test');
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

    private function makeProduct(FunctionalTester $I, string $sku, string $name, ?ProductCategory $category): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName($name)->setDefaultPrice('10.00')->setCategory($category)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    public function everyGridRendersARealSelectInsteadOfADatalistInput(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeCategory($I, 'Filter Select Tires 424');

        foreach (self::GRID_URLS as $url => $oldDatalistId) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            $html = $I->grabPageSource();

            $I->assertStringContainsString('<select name="filters[category]"', $html, $url . ' should render a real <select> for the category filter.');
            $I->assertStringContainsString('js-searchable-select', $html, $url . ' should mark the category select for the searchable-select enhancement.');

            // The replaced markup must be gone, not merely bypassed.
            $I->assertStringNotContainsString($oldDatalistId, $html, $url . ' should no longer render the category <datalist>.');

            // The rule is "nothing a person can TYPE a category into", asserted against the DOM
            // rather than as a substring. This used to read
            // `assertStringNotContainsString('name="filters[category]" value=', $html)`, a proxy for
            // the old `<input type="text" … list=…>` that in fact catches ANY element pairing that
            // name with a value attribute. /admin/inventory now renders two further forms beside the
            // filter row — the warehouse picker and the column chooser — and each carries the active
            // filters as `<input type="hidden" name="filters[category]" value=…>` so that submitting
            // one does not silently discard the other's state, which is what a no-JS admin needs.
            // That is state preservation, not a text box, and the substring could not tell them
            // apart. Hidden inputs are excluded here and nothing else is: put the typed category box
            // back, in any form, and this still fails.
            $I->dontSeeElement('input[name="filters[category]"]:not([type="hidden"])');

            // A blank "all categories" option, so the filter can be cleared without JS.
            $I->assertStringContainsString('<option value="">All categories</option>', $html, $url . ' should offer a blank "All categories" option.');
        }
    }

    public function everyGridMarksTheActiveCategoryOptionSelected(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $category = $this->makeCategory($I, 'Filter Select Selected 424');
        $this->makeProduct($I, 'CATSEL-1', 'Category Select Selected Product', $category);

        $categoryId = (int) $category->getId();

        foreach (array_keys(self::GRID_URLS) as $url) {
            $I->amOnPage($url . '?filters[category]=' . $categoryId);
            $I->seeResponseCodeIsSuccessful();

            // The <select> has to round-trip the active filter, otherwise a no-JS admin loses their
            // selection on every page/sort navigation.
            $I->seeElement('select[name="filters[category]"] option[selected]', ['value' => (string) $categoryId]);
        }
    }

    public function filteringByCategoryIdNarrowsEveryGridWithoutAnyScript(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $tires = $this->makeCategory($I, 'Filter Select Off-Road 424');
        $wheels = $this->makeCategory($I, 'Filter Select Chrome 424');

        $this->makeProduct($I, 'CATID-TIRE', 'Category Id Filter Tire Product', $tires);
        $this->makeProduct($I, 'CATID-WHEEL', 'Category Id Filter Wheel Product', $wheels);

        foreach (array_keys(self::GRID_URLS) as $url) {
            $I->amOnPage($url . '?filters[category]=' . (int) $tires->getId());
            $I->seeResponseCodeIsSuccessful();
            $I->see('Category Id Filter Tire Product');
            $I->dontSee('Category Id Filter Wheel Product');
        }
    }

    public function aCategoryNameIsNoLongerAValidFilterValue(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $tires = $this->makeCategory($I, 'Filter Select Name Gone 424');
        $this->makeProduct($I, 'CATNAME-GONE', 'Category Name Gone Product', $tires);

        // The old <datalist> submitted the category *name* and the backend fell back to a name LIKE
        // match. Both are gone: the filter is an id match now, so a name matches nothing rather than
        // silently working. Asserting this keeps the removed branch from creeping back.
        foreach (array_keys(self::GRID_URLS) as $url) {
            $I->amOnPage($url . '?filters[category]=Filter Select Name Gone 424');
            $I->seeResponseCodeIsSuccessful();
            $I->dontSee('Category Name Gone Product');
        }
    }
}
