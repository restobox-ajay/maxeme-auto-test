<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use Tests\Support\FunctionalTester;

/**
 * Covers the server-side list-view sort added for GitHub issue #180: the guest catalog column
 * headers are real <a href> links carrying ProductSearch[sort]/[dir], and CatalogController honors
 * them (whitelisted field + validated direction) so sorting works with JavaScript disabled.
 *
 * The functional suite drives the real kernel with no JS engine, so simply GETting the catalog with
 * the sort query params and asserting the rendered product order is exactly the no-JS path the issue
 * requires. Products are asserted by their (guest-visible) product name; positions in the raw HTML
 * source give the server-rendered order.
 */
final class CatalogSortCest
{
    private function seedGuestRegion(FunctionalTester $I, string $name): void
    {
        $region = (new FulfillmentRegion())->setName($name)->setStatus('Active')->setGuestVisible(true);
        $I->haveInRepository($region);
    }

    /**
     * Names are deliberately NOT in the same order as prices, so a passing price sort can't be an
     * accidental match against the default name-ascending order.
     */
    private function seedPricedProducts(FunctionalTester $I): void
    {
        // name / defaultPrice: alpha order (Alpha, Mid, Zeta) differs from price order (Zeta, Alpha, Mid).
        $I->haveInRepository((new ProductCore())->setSku('SORT-ZETA')->setName('Zeta Sort Product')->setDefaultPrice('10.00')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL));
        $I->haveInRepository((new ProductCore())->setSku('SORT-ALPHA')->setName('Alpha Sort Product')->setDefaultPrice('20.00')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL));
        $I->haveInRepository((new ProductCore())->setSku('SORT-MID')->setName('Mid Sort Product')->setDefaultPrice('30.00')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL));
    }

    private function assertOrder(FunctionalTester $I, string $first, string $second, string $third): void
    {
        $source = $I->grabPageSource();
        $posFirst = strpos($source, $first);
        $posSecond = strpos($source, $second);
        $posThird = strpos($source, $third);

        $I->assertNotFalse($posFirst, sprintf('Expected "%s" to be rendered.', $first));
        $I->assertNotFalse($posSecond, sprintf('Expected "%s" to be rendered.', $second));
        $I->assertNotFalse($posThird, sprintf('Expected "%s" to be rendered.', $third));

        $I->assertLessThan($posSecond, $posFirst, sprintf('Expected "%s" before "%s".', $first, $second));
        $I->assertLessThan($posThird, $posSecond, sprintf('Expected "%s" before "%s".', $second, $third));
    }

    public function priceSortAscendingOrdersProductsByResolvedPrice(FunctionalTester $I): void
    {
        $this->seedGuestRegion($I, 'Catalog Sort Warehouse ASC');
        $this->seedPricedProducts($I);

        $I->amOnPage('/product/index?ProductSearch[sort]=price&ProductSearch[dir]=asc');
        $I->seeResponseCodeIsSuccessful();

        // 10.00, 20.00, 30.00
        $this->assertOrder($I, 'Zeta Sort Product', 'Alpha Sort Product', 'Mid Sort Product');
    }

    public function priceSortDescendingReversesTheOrder(FunctionalTester $I): void
    {
        $this->seedGuestRegion($I, 'Catalog Sort Warehouse DESC');
        $this->seedPricedProducts($I);

        $I->amOnPage('/product/index?ProductSearch[sort]=price&ProductSearch[dir]=desc');
        $I->seeResponseCodeIsSuccessful();

        // 30.00, 20.00, 10.00
        $this->assertOrder($I, 'Mid Sort Product', 'Alpha Sort Product', 'Zeta Sort Product');
    }

    /**
     * Description maps to a real ProductCore column (name), so it exercises the in-query ORDER BY
     * path rather than the PHP row sort used for price/stock/size.
     */
    public function descriptionSortDescendingUsesTheDatabaseOrderBy(FunctionalTester $I): void
    {
        $this->seedGuestRegion($I, 'Catalog Sort Warehouse DESC NAME');
        $this->seedPricedProducts($I);

        $I->amOnPage('/product/index?ProductSearch[sort]=description&ProductSearch[dir]=desc');
        $I->seeResponseCodeIsSuccessful();

        // name descending: Zeta, Mid, Alpha
        $this->assertOrder($I, 'Zeta Sort Product', 'Mid Sort Product', 'Alpha Sort Product');
    }

    /**
     * An unknown sort field must be ignored (no error, no injection) and fall back to the default
     * name-ascending order.
     */
    public function invalidSortFieldIsSafelyIgnoredAndFallsBackToNameOrder(FunctionalTester $I): void
    {
        $this->seedGuestRegion($I, 'Catalog Sort Warehouse INVALID');
        $this->seedPricedProducts($I);

        $I->amOnPage('/product/index?ProductSearch[sort]=p.name);DROP&ProductSearch[dir]=asc');
        $I->seeResponseCodeIsSuccessful();

        // default order = name ascending: Alpha, Mid, Zeta
        $this->assertOrder($I, 'Alpha Sort Product', 'Mid Sort Product', 'Zeta Sort Product');
    }

    /**
     * The headers must be real links (issue #180's no-JS requirement), not the old
     * <button type="button">, and must carry the sort query params.
     */
    public function sortHeadersRenderAsRealLinks(FunctionalTester $I): void
    {
        $this->seedGuestRegion($I, 'Catalog Sort Warehouse LINKS');
        $this->seedPricedProducts($I);

        $I->amOnPage('/product/index');
        $I->seeResponseCodeIsSuccessful();

        $source = $I->grabPageSource();
        $I->assertStringContainsString('class="catalog-list-sort', $source);
        $I->assertStringContainsString('ProductSearch%5Bsort%5D=price', $source);
        // The old JS-only buttons must be gone.
        $I->assertStringNotContainsString('<button class="catalog-list-sort"', $source);
    }
}
