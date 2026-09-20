<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A negative Markup% or Markup$ on the "Suggested Price" fields is a deliberate below-cost/
 * loss-leader price — a markup is already relative to the base price, so negative just means
 * "under base" instead of "over base", the same reframing as the earlier Discount%/Discount$
 * premium fix (AdminProductPriceDiscountNegativeValueCest) but for the opposite direction: a
 * negative markup pushes the suggested price BELOW the base/cost, not above it.
 *
 * Admin\ProductController::updateSuggestedPrice() (admin_product_suggested_price_update) must
 * accept it end to end: pass validation, persist the negative value, and compute an effective
 * price that is correctly LOWER than the base price.
 *
 * Covers the bug where the endpoint rejected any negative value outright, assuming a markup could
 * only ever mean a positive increase — flagged as out of scope by the earlier Discount fix, which
 * only touched the price-list rule fields (Discount%/Discount$/Number), not Suggested Price.
 */
final class AdminProductSuggestedPriceNegativeMarkupCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-suggested-price-negative-test@example.test');
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

    /** The price page mints one token per write endpoint onto a single hidden element. */
    private function grabPriceToken(FunctionalTester $I, string $name): string
    {
        $I->amOnPage('/admin/product/price/index');

        return (string) $I->grabAttributeFrom('#price-write-tokens', 'data-' . $name . '-token');
    }

    public function aNegativeMarkupPercentIsSavedAndDecreasesTheEffectivePriceBelowBase(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'NEG-MKUP-PCT', 'Negative Markup Percent Product', '200.00');
        $token = $this->grabPriceToken($I, 'suggested-price-update');

        // -10% markup on 200.00: 200 + (200 * -10 / 100) = 180.00 — below base, not above it.
        $I->sendAjaxPostRequest('/admin/product/suggested-price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'type' => 'Markup%',
            'value' => '-10',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);
        $I->assertSame('-10.00', $response['value']);
        $I->assertSame('180.00', $response['effective']);

        $I->seeInRepository(ProductCore::class, [
            'id' => $product->getId(),
            'suggestedPriceType' => 'Markup%',
            'suggestedPriceValue' => '-10.00',
        ]);
    }

    public function aNegativeMarkupDollarIsSavedAndDecreasesTheEffectivePriceBelowBase(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'NEG-MKUP-DLR', 'Negative Markup Dollar Product', '200.00');
        $token = $this->grabPriceToken($I, 'suggested-price-update');

        // -$15 markup on 200.00: 200 + (-15) = 185.00 — below base, not above it.
        $I->sendAjaxPostRequest('/admin/product/suggested-price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'type' => 'Markup$',
            'value' => '-15',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);
        $I->assertSame('-15.00', $response['value']);
        $I->assertSame('185.00', $response['effective']);

        $I->seeInRepository(ProductCore::class, [
            'id' => $product->getId(),
            'suggestedPriceType' => 'Markup$',
            'suggestedPriceValue' => '-15.00',
        ]);
    }

    /**
     * The endpoint's other half of #458: an effective price below zero is returned as the negative
     * it is. `Number` used to be floored to 0.00 here while a big enough negative Markup$ sailed
     * through, so both directions are pinned — a markup that overshoots the base, and a Number typed
     * negative outright.
     */
    public function anEffectivePriceBelowZeroIsReturnedAsTheNegativeItIs(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'NEG-EFF-MKUP', 'Below Zero Suggested Product', '10.00');
        $token = $this->grabPriceToken($I, 'suggested-price-update');

        // -$20 markup on 10.00: 10 + (-20) = -10.00.
        $I->sendAjaxPostRequest('/admin/product/suggested-price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'type' => 'Markup$',
            'value' => '-20',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertSame('-10.00', $response['effective']);

        $I->sendAjaxPostRequest('/admin/product/suggested-price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'type' => 'Number',
            'value' => '-5',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertSame('-5.00', $response['effective']);

        $I->seeInRepository(ProductCore::class, [
            'id' => $product->getId(),
            'suggestedPriceType' => 'Number',
            'suggestedPriceValue' => '-5.00',
        ]);
    }

    /** Non-numeric input is still not a valid markup value — it is rejected outright. */
    public function aNonNumericValueIsRejected(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'BAD-MKUP', 'Bad Markup Value Product', '200.00');
        $token = $this->grabPriceToken($I, 'suggested-price-update');

        $I->sendAjaxPostRequest('/admin/product/suggested-price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'type' => 'Markup%',
            'value' => 'not-a-number',
        ]);
        $I->seeResponseCodeIs(422);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
        $I->assertSame('Value must be a valid number.', $response['message']);

        $I->dontSeeInRepository(ProductCore::class, [
            'id' => $product->getId(),
            'suggestedPriceType' => 'Markup%',
        ]);
    }

    /**
     * Exponent notation is not a markup value either, and this is #464's one change to the SERVER
     * rather than to the browser. `is_numeric('1e3')` is true, so `1e3` used to be accepted and
     * stored as a markup of 1000.00 — from three keystrokes in a field where every other entry is a
     * plain decimal, which is far likelier to be a typo or a pasted identifier than an intention.
     *
     * Both letter cases are asserted because `is_numeric()` accepts both: an implementation that
     * reached for `strpos()` instead of `stripos()` would refuse `1e3` and pass `1E3` straight
     * through, which looks fixed from the outside.
     *
     * Both endpoints are covered — a single-cell save and a bulk apply — because bulk apply writes
     * the same value to every product matching the grid's filters, so a value that slipped past it
     * would not be one mistake but a page of them.
     */
    public function anExponentValueIsRejectedByBothSuggestedPriceEndpoints(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'EXP-MKUP', 'Exponent Markup Value Product', '200.00');
        $updateToken = $this->grabPriceToken($I, 'suggested-price-update');
        $bulkToken = $this->grabPriceToken($I, 'bulk-apply');

        foreach (['1e3', '1E3', '1e-3', '-2.5E2'] as $value) {
            $I->sendAjaxPostRequest('/admin/product/suggested-price/update', [
                '_token' => $updateToken,
                'product_id' => (string) $product->getId(),
                'type' => 'Markup%',
                'value' => $value,
            ]);
            $I->seeResponseCodeIs(422);

            $response = json_decode($I->grabPageSource(), true);
            $I->assertFalse($response['ok'], sprintf('"%s" is not a markup value (#464).', $value));
            $I->assertSame('Value must be a valid number.', $response['message']);

            $I->sendAjaxPostRequest('/admin/product/suggested-price/bulk-apply', [
                '_token' => $bulkToken,
                'type' => 'Markup%',
                'value' => $value,
            ]);
            $I->seeResponseCodeIs(422);

            $bulk = json_decode($I->grabPageSource(), true);
            $I->assertFalse($bulk['ok'], sprintf('"%s" is not a bulk markup value either (#464).', $value));
        }

        $I->dontSeeInRepository(ProductCore::class, [
            'id' => $product->getId(),
            'suggestedPriceType' => 'Markup%',
        ]);
    }

    /**
     * The other side of that line. Refusing exponents must not turn into refusing anything unusual,
     * so the shapes an admin actually types keep working — including the negatives this whole Cest
     * is about, and a decimal whose only crime is sitting next to `1e3` in the grammar.
     */
    public function ordinaryDecimalsAndNegativesAreStillAcceptedAfterTheExponentRule(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $token = $this->grabPriceToken($I, 'suggested-price-update');

        // value, the effective price it produces as a Markup$ over a base of 200.00
        $cases = [
            ['3', '203.00'],
            ['3.5', '203.50'],
            ['.5', '200.50'],
            ['0', '200.00'],
            ['-5', '195.00'],
        ];

        foreach ($cases as $index => [$value, $expected]) {
            $product = $this->makeProduct($I, 'OK-MKUP-' . $index, 'Ordinary Markup Product', '200.00');

            $I->sendAjaxPostRequest('/admin/product/suggested-price/update', [
                '_token' => $token,
                'product_id' => (string) $product->getId(),
                'type' => 'Markup$',
                'value' => $value,
            ]);
            $I->seeResponseCodeIsSuccessful();

            $response = json_decode($I->grabPageSource(), true);
            $I->assertSame(
                $expected,
                $response['effective'],
                sprintf('Markup$ %s over 200.00 must still be accepted (#464).', $value),
            );
        }
    }
}
