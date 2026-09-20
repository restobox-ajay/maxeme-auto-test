<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers ShippingAmazonFBABundle's admin config page at /admin/bundles/shipping/amazon-fba —
 *  the eligible-product-ID list is a plain AppSetting row, edited by one POST, and that POST
 *  now requires a CSRF token like every sibling bundle config screen. */
final class AmazonFBAShippingConfigCest
{
    private const SETTING_KEY = 'shipping_amazon_fba_product_ids';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    public function savingEligibleProductIdsWithTheRenderedTokenPersists(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/bundles/shipping/amazon-fba');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('textarea[name="product_ids"]');

        // A relative-path sendAjaxPostRequest rather than submitForm: the same host-based
        // external-URL guard documented in CartHoldConfigCest applies here too.
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/bundles/shipping/amazon-fba', [
            '_token' => $token,
            'product_ids' => '11, 22',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(AppSetting::class, [
            'settingKey' => self::SETTING_KEY,
            'settingValue' => '[11,22]',
        ]);
    }

    public function savingWithoutAValidTokenIsRejectedAndLeavesTheListAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/bundles/shipping/amazon-fba');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/bundles/shipping/amazon-fba', [
            '_token' => $token,
            'product_ids' => '33',
        ]);

        $I->sendAjaxPostRequest('/admin/bundles/shipping/amazon-fba', [
            '_token' => 'forged',
            'product_ids' => '44, 55',
        ]);

        $I->seeInRepository(AppSetting::class, [
            'settingKey' => self::SETTING_KEY,
            'settingValue' => '[33]',
        ]);
    }
}
