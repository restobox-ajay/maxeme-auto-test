<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Issue #529: on the price grid, [A] ("apply to all pages") applied the first row's Type AND Value,
 * whatever column it was clicked in — so pressing it under Type also rewrote every product's Value,
 * and vice versa. [1] ("apply to this page") was always correct.
 *
 * Two causes, both fixed: every [A] was marked with a bulk-field of "both" in the markup, and the
 * page's JS additionally passed a hardcoded "both" to the price-list endpoint regardless. The
 * suggested-price endpoint also had no field parameter at all and always wrote both columns.
 *
 * These tests post to the endpoints directly, because that is where the damage was done — markup
 * assertions live in AdminProductSuggestedPriceGridParityCest. Each case sets the two columns to
 * distinguishable values first, applies ONE of them, and asserts the other survived untouched.
 */
final class AdminProductPriceBulkApplyColumnScopeCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-bulk-column-529@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function token(FunctionalTester $I, string $name): string
    {
        $I->amOnPage('/admin/product/price/index');

        return (string) $I->grabAttributeFrom('#price-write-tokens', 'data-' . $name . '-token');
    }

    private function makeProduct(FunctionalTester $I, string $sku, string $defaultPrice = '100.00'): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku)->setName('Product ' . $sku)
            ->setDefaultPrice($defaultPrice)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    // --- suggested price -----------------------------------------------------------------------

    public function applyingSuggestedTypeToAllPagesLeavesTheValueColumnAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->makeProduct($I, 'SUGG-529-TYPE');
        $product->setSuggestedPriceType('Markup%')->setSuggestedPriceValue('15.00');
        $I->haveInRepository($product);

        $I->sendAjaxPostRequest('/admin/product/suggested-price/bulk-apply', [
            '_token' => $this->token($I, 'bulk-apply'),
            'field' => 'type',
            'type' => 'Markup$',
            // Deliberately posted too: the server must ignore it because field says type only.
            'value' => '999.00',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $refreshed = $I->grabEntityFromRepository(ProductCore::class, ['sku' => 'SUGG-529-TYPE']);
        $I->assertSame('Markup$', $refreshed->getSuggestedPriceType(), 'The clicked column is applied.');
        // Compared numerically: suggestedPriceValue is a decimal column and SQLite hands back
        // '15' for a stored '15.00'. The formatting is not what this test is about.
        $I->assertEquals(15.00, (float) $refreshed->getSuggestedPriceValue(), 'The other column must be untouched.');
    }

    public function applyingSuggestedValueToAllPagesLeavesTheTypeColumnAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->makeProduct($I, 'SUGG-529-VALUE');
        $product->setSuggestedPriceType('Markup%')->setSuggestedPriceValue('15.00');
        $I->haveInRepository($product);

        $I->sendAjaxPostRequest('/admin/product/suggested-price/bulk-apply', [
            '_token' => $this->token($I, 'bulk-apply'),
            'field' => 'value',
            'value' => '42.00',
            'type' => 'Number',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $refreshed = $I->grabEntityFromRepository(ProductCore::class, ['sku' => 'SUGG-529-VALUE']);
        $I->assertEquals(42.00, (float) $refreshed->getSuggestedPriceValue(), 'The clicked column is applied.');
        $I->assertSame('Markup%', $refreshed->getSuggestedPriceType(), 'The other column must be untouched.');
    }

    /** Omitting the field keeps the old behaviour, so nothing that still posts without it breaks. */
    public function omittingTheFieldStillAppliesBothForSuggestedPrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->makeProduct($I, 'SUGG-529-BOTH');
        $product->setSuggestedPriceType('Markup%')->setSuggestedPriceValue('15.00');
        $I->haveInRepository($product);

        $I->sendAjaxPostRequest('/admin/product/suggested-price/bulk-apply', [
            '_token' => $this->token($I, 'bulk-apply'),
            'type' => 'Number',
            'value' => '7.00',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $refreshed = $I->grabEntityFromRepository(ProductCore::class, ['sku' => 'SUGG-529-BOTH']);
        $I->assertSame('Number', $refreshed->getSuggestedPriceType());
        $I->assertEquals(7.00, (float) $refreshed->getSuggestedPriceValue());
    }

    // --- price list ----------------------------------------------------------------------------

    public function applyingPriceListTypeToAllPagesLeavesTheValueColumnAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $priceList = (new PriceList())->setName('Wholesale 529')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        $product = $this->makeProduct($I, 'PL-529-TYPE');
        $I->haveInRepository(
            (new ProductPricing())->setProduct($product)->setPriceList($priceList)
                ->setRuleType('Discount%')->setRuleValue('10')->setPrice('90.00')
        );

        $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
            '_token' => $this->token($I, 'bulk-apply'),
            'price_list_id' => (string) $priceList->getId(),
            'field' => 'type',
            'type' => 'Discount$',
            'value' => '999',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $pricing = $I->grabEntityFromRepository(ProductPricing::class, ['product' => $product, 'priceList' => $priceList]);
        $I->assertSame('Discount$', $pricing->getRuleType(), 'The clicked column is applied.');
        $I->assertSame('10', $pricing->getRuleValue(), 'The other column must be untouched.');
    }

    public function applyingPriceListValueToAllPagesLeavesTheTypeColumnAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $priceList = (new PriceList())->setName('Wholesale 529b')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        $product = $this->makeProduct($I, 'PL-529-VALUE');
        $I->haveInRepository(
            (new ProductPricing())->setProduct($product)->setPriceList($priceList)
                ->setRuleType('Discount%')->setRuleValue('10')->setPrice('90.00')
        );

        $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
            '_token' => $this->token($I, 'bulk-apply'),
            'price_list_id' => (string) $priceList->getId(),
            'field' => 'value',
            'value' => '25',
            'type' => 'Number',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $pricing = $I->grabEntityFromRepository(ProductPricing::class, ['product' => $product, 'priceList' => $priceList]);
        $I->assertSame('25', $pricing->getRuleValue(), 'The clicked column is applied.');
        $I->assertSame('Discount%', $pricing->getRuleType(), 'The other column must be untouched.');
    }
}
