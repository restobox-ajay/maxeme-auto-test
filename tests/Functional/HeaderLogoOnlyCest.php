<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers the header_logo_only AppSetting (#17): base.html.twig's top-left brand block hides
 *  the company name / area label text (e.g. "Admin Console") once this setting is "Yes",
 *  leaving only the logo mark. A single round-trip test rather than separate "default" /
 *  "enabled" tests, matching CartHoldConfigCest's reasoning: this suite shares one persistent
 *  SQLite connection (and the AppSettings cache) across the whole run, so asserting a literal
 *  starting value would depend on test execution order.
 *
 *  Also pins the two rules that came with dropping the hardcoded "WC" tile: the brand mark
 *  renders only for an uploaded logo, and header_logo_only is ignored until there is one — it
 *  would otherwise empty the brand link entirely now that no initials stand in for the name. */
final class HeaderLogoOnlyCest
{
    private const LOGO_PATH = '/uploads/branding/test-logo.png';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('header-logo-only-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    /** Writes a setting through the admin endpoint so the AppSettings cache is invalidated too. */
    private function setSetting(FunctionalTester $I, string $key, string $name, string $value): void
    {
        $setting = $I->grabEntityFromRepository(AppSetting::class, ['settingKey' => $key]);

        $I->amOnPage('/admin/settings/' . $setting->getId() . '/update');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/settings/' . $setting->getId() . '/update', [
            'name' => $name,
            'setting_value' => $value,
            '_token' => $token,
        ]);
        $I->seeCurrentUrlEquals('/admin/settings');
        // The form stores a blank value as NULL rather than an empty string.
        $I->seeInRepository(AppSetting::class, [
            'settingKey' => $key,
            'settingValue' => $value !== '' ? $value : null,
        ]);
    }

    public function enablingLogoOnlyHidesBrandTextInAdminHeader(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        // Visiting the settings list triggers ensureCoreSettingsExist(), which self-heals the
        // header_logo_only and logo_url rows (defaults "No" / "") the same way it does for
        // every other core key.
        $I->amOnPage('/admin/settings');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInRepository(AppSetting::class, ['settingKey' => 'header_logo_only', 'settingValue' => 'No']);

        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('.brand-copy');
        $I->see('Admin Console', '.brand-copy');
        // No logo uploaded: nothing renders in the mark slot. This used to be a hardcoded "WC".
        $I->dontSeeElement('.brand-mark');
        $I->dontSee('WC', '.brand');

        // header_logo_only with no logo would leave the brand link empty, so it is ignored until
        // one is uploaded — the store name keeps the slot rather than nothing at all.
        $this->setSetting($I, 'header_logo_only', 'Header Logo Only', 'Yes');

        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('.brand-copy');
        $I->dontSeeElement('.brand-mark');

        // With a logo to stand in for the name, the setting does what it says.
        $this->setSetting($I, 'logo_url', 'Logo URL', self::LOGO_PATH);

        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('.brand-copy');
        $I->seeElement('.brand-mark');
        $I->seeElement('.brand-mark.brand-mark-logo img[src="' . self::LOGO_PATH . '"]');

        // A logo with the setting back off shows both, and still no initials fallback anywhere.
        $this->setSetting($I, 'header_logo_only', 'Header Logo Only', 'No');

        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('.brand-copy');
        $I->seeElement('.brand-mark.brand-mark-logo img');

        // Restore the blank default: the suite shares one database, and a lingering logo_url
        // would follow every later test's header.
        $this->setSetting($I, 'logo_url', 'Logo URL', '');
    }

    /** The admin login card is rendered logged-out and used to hardcode the same "WC" tile
     *  regardless of branding, so it never showed an uploaded logo. */
    public function adminLoginCardShowsNoInitialsTile(FunctionalTester $I): void
    {
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/login');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('.admin-login-card-head');
        $I->dontSeeElement('.admin-login-card-head .brand-mark');
        $I->dontSee('WC', '.admin-login-card-head');
        $I->see('Admin Console', '.admin-login-card-head');
    }
}
