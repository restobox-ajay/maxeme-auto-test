<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Issue #429: the "Suggested Price" column group's Type/Value headers were missing the [1]/[A]
 * bulk-apply buttons and sort links that every real price-list group already has — the group was
 * added as a plain `<th>Type</th>` / `<th>Value</th>` pair with none of the header affordances the
 * per-price-list columns get.
 *
 * Follow-up to #429: the Effective sub-column was still left behind — a real price-list group's
 * Effective header is sortable (`pl_effective_{id}`, sorted PHP-side since it's a computed value,
 * not a raw column), but Suggested Price's Effective header stayed a plain, unsortable `<th>`.
 *
 * Covers:
 *  1. The Type/Value headers now render the same sort link + bulk-apply button markup as a
 *     price-list group's Type/Value headers.
 *  2. Sorting by `suggested_type` / `suggested_value` actually orders the grid by those columns
 *     (ProductController::prices() now maps them to real ProductCore columns).
 *  3. The new "apply to all pages" endpoint (admin_product_suggested_price_bulk_apply) writes
 *     suggestedPriceType/suggestedPriceValue onto every matching product and, like the price-list
 *     bulk-apply endpoint, only touches products matching the grid's active filters.
 *  4. The Effective header now renders a sort link too, and sorting by `suggested_effective`
 *     orders the grid by the computed suggested effective price (mirrors `pl_effective_{id}`).
 */
final class AdminProductSuggestedPriceGridParityCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-suggested-price-grid-429-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeProduct(FunctionalTester $I, string $sku, string $name, string $defaultPrice, ?ProductCategory $category = null): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName($name)->setDefaultPrice($defaultPrice)->setCategory($category)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    private function grabPriceToken(FunctionalTester $I, string $name): string
    {
        $I->amOnPage('/admin/product/price/index');

        return (string) $I->grabAttributeFrom('#price-write-tokens', 'data-' . $name . '-token');
    }

    public function theSuggestedPriceTypeAndValueHeadersHaveTheSameSortAndBulkApplyMarkupAsAPriceListGroup(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeProduct($I, 'SUGG-HDR-1', 'Suggested Header Product', '10.00');

        $I->amOnPage('/admin/product/price/index');
        $I->seeResponseCodeIsSuccessful();

        $html = $I->grabPageSource();

        $I->assertStringContainsString('sort=suggested_type', $html);
        $I->assertStringContainsString('sort=suggested_value', $html);
        $I->assertStringContainsString('sort=suggested_effective', $html);

        // No price groups are selected by default, so these buttons can only come from the
        // Suggested Price group: two bulk-apply icons ("1" and "A") per column (Type, Value),
        // marked with data-suggested so the click handler routes them to the suggested-price
        // endpoints instead of a price list's.
        $I->assertSame(4, substr_count($html, 'th-iconbox-page js-bulk-apply') + substr_count($html, 'th-iconbox-all js-bulk-apply'));
        $I->assertSame(4, substr_count($html, 'data-suggested="1"'));

        // Both buttons in a column carry that column's own field — [1] and [A] alike. This used to
        // assert one "type", one "value" and two "both", which was the shape of #529: every [A] was
        // marked "both", so applying to all pages from the Type column also overwrote Value (and
        // vice versa) for every product. "both" is no longer emitted by this page at all.
        $I->assertSame(2, substr_count($html, 'data-bulk-field="type"'));
        $I->assertSame(2, substr_count($html, 'data-bulk-field="value"'));
        $I->assertSame(0, substr_count($html, 'data-bulk-field="both"'));
    }

    public function sortingBySuggestedTypeOrdersTheGridByThatColumn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $zType = $this->makeProduct($I, 'SUGG-SORT-Z', 'Suggested Sort Z Product', '100.00');
        $aType = $this->makeProduct($I, 'SUGG-SORT-A', 'Suggested Sort A Product', '100.00');
        $aType->setSuggestedPriceType('Markup%')->setSuggestedPriceValue('5.00');
        $zType->setSuggestedPriceType('Number')->setSuggestedPriceValue('50.00');
        $I->haveInRepository($aType);
        $I->haveInRepository($zType);

        $I->amOnPage('/admin/product/price/index?sort=suggested_type&dir=asc');
        $I->seeResponseCodeIsSuccessful();

        $html = $I->grabPageSource();
        $markupPos = strpos($html, 'Suggested Sort A Product');
        $numberPos = strpos($html, 'Suggested Sort Z Product');
        $I->assertNotFalse($markupPos);
        $I->assertNotFalse($numberPos);
        $I->assertTrue($markupPos < $numberPos, '"Markup%" should sort before "Number" ascending.');
    }

    public function sortingBySuggestedEffectiveOrdersTheGridByThatColumn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $high = $this->makeProduct($I, 'SUGG-EFF-HIGH', 'Suggested Effective High Product', '100.00');
        $low = $this->makeProduct($I, 'SUGG-EFF-LOW', 'Suggested Effective Low Product', '100.00');
        $high->setSuggestedPriceType('Number')->setSuggestedPriceValue('90.00');
        $low->setSuggestedPriceType('Number')->setSuggestedPriceValue('10.00');
        $I->haveInRepository($high);
        $I->haveInRepository($low);

        $I->amOnPage('/admin/product/price/index?sort=suggested_effective&dir=asc');
        $I->seeResponseCodeIsSuccessful();

        $html = $I->grabPageSource();
        $lowPos = strpos($html, 'Suggested Effective Low Product');
        $highPos = strpos($html, 'Suggested Effective High Product');
        $I->assertNotFalse($lowPos);
        $I->assertNotFalse($highPos);
        $I->assertTrue($lowPos < $highPos, 'The lower effective price (10.00) should sort before the higher one (90.00) ascending.');
    }

    public function applyToAllPagesOnlyUpdatesProductsMatchingTheActiveNameFilter(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $matching = $this->makeProduct($I, 'SUGG-BULK-MATCH', 'Suggested Bulk Match Product', '100.00');
        $other = $this->makeProduct($I, 'SUGG-BULK-OTHER', 'Totally Different Suggested Product', '100.00');

        $token = $this->grabPriceToken($I, 'bulk-apply');
        $I->sendAjaxPostRequest('/admin/product/suggested-price/bulk-apply', [
            '_token' => $token,
            'type' => 'Markup%',
            'value' => '15',
            'filters' => ['name' => 'Suggested Bulk Match'],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);

        $I->seeInRepository(ProductCore::class, [
            'id' => $matching->getId(),
            'suggestedPriceType' => 'Markup%',
            'suggestedPriceValue' => '15.00',
        ]);
        $I->dontSeeInRepository(ProductCore::class, [
            'id' => $other->getId(),
            'suggestedPriceType' => 'Markup%',
        ]);
    }

    public function applyToAllPagesOnlyUpdatesProductsMatchingTheActiveCategoryFilter(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $wanted = (new ProductCategory())->setName('Suggested Bulk Wanted Category')->setStatus('Visible');
        $unwanted = (new ProductCategory())->setName('Suggested Bulk Unwanted Category')->setStatus('Visible');
        $I->haveInRepository($wanted);
        $I->haveInRepository($unwanted);

        $matching = $this->makeProduct($I, 'SUGG-CAT-MATCH', 'Suggested Cat Match Product', '200.00', $wanted);
        $other = $this->makeProduct($I, 'SUGG-CAT-OTHER', 'Suggested Cat Other Product', '200.00', $unwanted);

        $token = $this->grabPriceToken($I, 'bulk-apply');
        $I->sendAjaxPostRequest('/admin/product/suggested-price/bulk-apply', [
            '_token' => $token,
            'type' => 'Number',
            'value' => '42',
            'filters' => ['category' => (string) $wanted->getId()],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(ProductCore::class, [
            'id' => $matching->getId(),
            'suggestedPriceType' => 'Number',
            'suggestedPriceValue' => '42.00',
        ]);
        $I->dontSeeInRepository(ProductCore::class, [
            'id' => $other->getId(),
            'suggestedPriceType' => 'Number',
        ]);
    }

    public function applyToAllPagesRejectsAnInvalidType(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeProduct($I, 'SUGG-BULK-BADTYPE', 'Suggested Bulk Bad Type Product', '100.00');

        $token = $this->grabPriceToken($I, 'bulk-apply');
        $I->sendAjaxPostRequest('/admin/product/suggested-price/bulk-apply', [
            '_token' => $token,
            'type' => 'No Price',
            'value' => '10',
        ]);
        $I->seeResponseCodeIs(400);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
    }
}
