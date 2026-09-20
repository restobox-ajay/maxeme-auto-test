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
 * A negative Discount% or Discount$ is a deliberate premium — the customer pays MORE than the base
 * price, not less — and Admin\ProductController's price-grid write endpoints (admin_product_price_update,
 * admin_product_price_bulk_apply) must accept it end to end: pass validation, persist the negative
 * rule value, and compute an effective price that is correctly HIGHER than the base price.
 *
 * Covers the bug where both endpoints silently rejected/clamped negative rule values, assuming
 * "discount" could only ever mean a positive reduction.
 *
 * Since #458 the same reasoning reaches the *result*: a discount larger than the base price is a
 * deliberate negative price, stored as the negative it is rather than floored to 0.00, and the rule
 * is a pure calculator — when it resolves to nothing (blank type, absent or non-numeric value) the
 * answer is the product's base price, not whatever figure the client happened to post alongside it.
 */
final class AdminProductPriceDiscountNegativeValueCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-price-negative-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeProduct(FunctionalTester $I, string $sku, string $name, string $defaultPrice): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName($name)->setDefaultPrice($defaultPrice)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    private function makePriceList(FunctionalTester $I, string $name): PriceList
    {
        $priceList = (new PriceList())->setName($name)->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        return $priceList;
    }

    /** The price page mints one token per write endpoint onto a single hidden element. */
    private function grabPriceToken(FunctionalTester $I, string $name): string
    {
        $I->amOnPage('/admin/product/price/index');

        return (string) $I->grabAttributeFrom('#price-write-tokens', 'data-' . $name . '-token');
    }

    public function aNegativeDiscountPercentIsSavedAndIncreasesTheEffectivePrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'NEG-PCT', 'Negative Percent Product', '200.00');
        $priceList = $this->makePriceList($I, 'Negative Percent List');
        $token = $this->grabPriceToken($I, 'price-update');

        // -10% off 200.00 is a premium: the customer pays 220.00, not 180.00.
        $I->sendAjaxPostRequest('/admin/product/price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'price_list_id' => (string) $priceList->getId(),
            'price' => '220.00',
            'rule_type' => 'Discount%',
            'rule_value' => '-10',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);
        $I->assertSame('220.00', $response['price']);

        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'ruleType' => 'Discount%',
            'ruleValue' => '-10.00',
            'price' => '220.00',
        ]);
    }

    public function aNegativeDiscountDollarIsSavedAndIncreasesTheEffectivePrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'NEG-DLR', 'Negative Dollar Product', '200.00');
        $priceList = $this->makePriceList($I, 'Negative Dollar List');
        $token = $this->grabPriceToken($I, 'price-update');

        // -$15 off 200.00 is a premium: the customer pays 215.00, not 185.00.
        $I->sendAjaxPostRequest('/admin/product/price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'price_list_id' => (string) $priceList->getId(),
            'price' => '215.00',
            'rule_type' => 'Discount$',
            'rule_value' => '-15',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);
        $I->assertSame('215.00', $response['price']);

        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'ruleType' => 'Discount$',
            'ruleValue' => '-15.00',
            'price' => '215.00',
        ]);
    }

    /**
     * The headline case of #458: 20 off a base of 10.00 is -10.00, and that is what gets stored.
     * It used to be floored to 0.00, which turned "you owe the customer 10" into "this is free" —
     * two very different statements, one of which nobody made.
     *
     * The posted price is the negative figure the grid's own JS now computes, so this also pins the
     * endpoint's acceptance of it: the old validator rejected any negative `price` outright with a
     * 422, which would have made the floor's removal unreachable from the only screen that saves.
     */
    public function aDiscountLargerThanTheBasePriceIsStoredAsANegativePrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'NEG-EFFECTIVE', 'Below Zero Effective Product', '10.00');
        $priceList = $this->makePriceList($I, 'Below Zero List');
        $token = $this->grabPriceToken($I, 'price-update');

        $I->sendAjaxPostRequest('/admin/product/price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'price_list_id' => (string) $priceList->getId(),
            'price' => '-10.00',
            'rule_type' => 'Discount$',
            'rule_value' => '20',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);
        $I->assertSame('-10.00', $response['price']);

        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'ruleType' => 'Discount$',
            'ruleValue' => '20.00',
            'price' => '-10.00',
        ]);
    }

    /** A Number rule keeps its sign too — the floor was not specific to the discount branches. */
    public function aNegativeNumberRuleIsStoredAsANegativePrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'NEG-NUMBER', 'Negative Number Rule Product', '12.50');
        $priceList = $this->makePriceList($I, 'Negative Number List');
        $token = $this->grabPriceToken($I, 'price-update');

        $I->sendAjaxPostRequest('/admin/product/price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'price_list_id' => (string) $priceList->getId(),
            'price' => '-5.00',
            'rule_type' => 'Number',
            'rule_value' => '-5',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertSame('-5.00', $response['price']);

        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'ruleType' => 'Number',
            'price' => '-5.00',
        ]);
    }

    /**
     * The rule is a calculator, so a rule that resolves to nothing resolves to the base price. The
     * posted `price` is deliberately a different number from the base here: it used to win, which
     * let a stale or hand-edited figure survive as though it were a computed one.
     */
    public function aBlankRuleTypeFallsBackToTheBasePriceRatherThanThePostedPrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'BLANK-TYPE', 'Blank Rule Type Product', '40.00');
        $priceList = $this->makePriceList($I, 'Blank Rule Type List');
        $token = $this->grabPriceToken($I, 'price-update');

        $I->sendAjaxPostRequest('/admin/product/price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'price_list_id' => (string) $priceList->getId(),
            'price' => '999.00',
            'rule_type' => '',
            'rule_value' => '25',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertSame('40.00', $response['price']);

        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'price' => '40.00',
        ]);
    }

    /** Same rule for a chosen type whose value never arrived: nothing to apply, so base it is. */
    public function anAbsentRuleValueFallsBackToTheBasePriceRatherThanThePostedPrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'ABSENT-VALUE', 'Absent Rule Value Product', '40.00');
        $priceList = $this->makePriceList($I, 'Absent Rule Value List');
        $token = $this->grabPriceToken($I, 'price-update');

        $I->sendAjaxPostRequest('/admin/product/price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'price_list_id' => (string) $priceList->getId(),
            'price' => '999.00',
            'rule_type' => 'Discount%',
            'rule_value' => '',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertSame('40.00', $response['price']);

        // And the row, not only the echo (#594). The defect class this file exists for is the
        // posted figure being STORED while a correct number is reported back — the grid then shows
        // 999.00 on the next page load and the JSON assertion above never moves.
        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'price' => '40.00',
        ]);
    }

    /**
     * The posted price is only consulted for the one question the rule cannot answer at all: a
     * product with no base price of its own and no rule that resolves without one.
     */
    public function thePostedPriceIsStillUsedWhenThereIsNoBasePriceToFallBackTo(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = (new ProductCore())->setSku('NO-BASE')->setName('No Base Price Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $priceList = $this->makePriceList($I, 'No Base Price List');
        $token = $this->grabPriceToken($I, 'price-update');

        $I->sendAjaxPostRequest('/admin/product/price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'price_list_id' => (string) $priceList->getId(),
            'price' => '33.00',
            'rule_type' => 'Discount%',
            'rule_value' => '10',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertSame('33.00', $response['price']);

        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'price' => '33.00',
        ]);
    }

    /**
     * Non-numeric rule input is still not a valid discount value: it is not persisted, and it does
     * not compute — so, like any rule that resolves to nothing, it now resolves to the base price
     * (200.00) rather than to the posted figure.
     */
    public function aNonNumericRuleValueIsNotPersisted(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'BAD-RULE', 'Bad Rule Value Product', '200.00');
        $priceList = $this->makePriceList($I, 'Bad Rule Value List');
        $token = $this->grabPriceToken($I, 'price-update');

        $I->sendAjaxPostRequest('/admin/product/price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'price_list_id' => (string) $priceList->getId(),
            'price' => '200.00',
            'rule_type' => 'Discount%',
            'rule_value' => 'not-a-number',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->dontSeeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'ruleValue' => 'not-a-number',
        ]);
    }

    public function bulkApplyAcceptsANegativeDiscountPercentAsAPremium(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'BULK-NEG-PCT', 'Bulk Negative Percent Product', '100.00');
        $priceList = $this->makePriceList($I, 'Bulk Negative Percent List');

        $token = $this->grabPriceToken($I, 'bulk-apply');
        $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
            '_token' => $token,
            'price_list_id' => (string) $priceList->getId(),
            'field' => 'both',
            'type' => 'Discount%',
            'value' => '-20',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);

        // -20% off 100.00 is a premium: 100 * (1 - (-20/100)) = 120.00.
        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'ruleType' => 'Discount%',
            'ruleValue' => '-20.00',
            'price' => '120.00',
        ]);
    }

    /**
     * #462. Bulk apply used to gate the write on `$effectiveNum >= 0`, which did not clamp — it
     * SKIPPED the row, leaving whatever price was there before. That is worse than a clamp: the
     * admin is told "Bulk apply complete" and has no way to see which rows actually took the rule.
     * The previous price must be overwritten with the negative, exactly as updatePrice() does.
     */
    public function bulkApplyWritesABelowZeroResultInsteadOfSkippingTheRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'BULK-BELOW-ZERO', 'Bulk Below Zero Product', '10.00');
        $priceList = $this->makePriceList($I, 'Bulk Below Zero List');

        // A price already standing, so a skipped row is distinguishable from a written one.
        $I->haveInRepository((new ProductPricing())
            ->setProduct($product)
            ->setPriceList($priceList)
            ->setCurrency('USD')
            ->setPrice('99.00'));

        $token = $this->grabPriceToken($I, 'bulk-apply');
        $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
            '_token' => $token,
            'price_list_id' => (string) $priceList->getId(),
            'field' => 'both',
            'type' => 'Discount$',
            'value' => '25',
            'filters' => ['sku' => 'BULK-BELOW-ZERO'],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);

        // $25 off a base of 10.00 is -15.00, and that is what gets stored.
        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'ruleType' => 'Discount$',
            'ruleValue' => '25.00',
            'price' => '-15.00',
        ]);

        // The 99.00 that was standing before must be gone, not left in place by a silent skip.
        $I->dontSeeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'price' => '99.00',
        ]);
    }

    public function bulkApplyStillRejectsANonNumericValue(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'Bulk Non-Numeric List');

        $token = $this->grabPriceToken($I, 'bulk-apply');
        $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
            '_token' => $token,
            'price_list_id' => (string) $priceList->getId(),
            'field' => 'both',
            'type' => 'Discount%',
            'value' => 'not-a-number',
        ]);
        $I->seeResponseCodeIs(422);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
        $I->assertSame('Value must be a valid number.', $response['message']);
    }
}
