<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers CartHoldBundle's own admin config page (§3/§5 of the feature) — the bundle's
 *  duration setting is editable at /admin/bundles/cart-hold, backed by a plain AppSetting
 *  row, the same pattern BCTireFeeConfigController uses.
 *
 *  A single round-trip test rather than separate "shows default" / "persists update" tests:
 *  this suite's tests share one persistent SQLite connection across the whole run (not a
 *  fresh DB per test), so asserting a literal starting value in one test would depend on test
 *  execution order relative to any other test that writes the same setting. */
final class CartHoldConfigCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    public function updatingDurationPersistsAndShowsOnReload(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/bundles/cart-hold');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="duration_seconds"]');

        // A relative-path sendAjaxPostRequest (rather than amOnPage + submitForm) sidesteps a
        // real limitation of this app's host-based admin/customer split: the module's
        // in-process browser resolves a crawled <form> action as an *absolute* URL once a
        // custom Host header is in play, which then trips its "external URL" guard even
        // though the host is unchanged — a plain relative path never hits that check at all.
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/bundles/cart-hold', [
            'duration_seconds' => '900',
            '_token' => $token,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(\App\Entity\AppSetting::class, [
            'settingKey' => 'cart_hold_duration_seconds',
            'settingValue' => '900',
        ]);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/bundles/cart-hold');
        $I->seeInField('duration_seconds', '900');
    }
}
