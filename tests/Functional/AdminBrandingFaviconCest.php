<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers issue #117: the favicon upload field on the branding settings page and the favicon link
 *  rendered into the page head from the favicon_url setting. */
final class AdminBrandingFaviconCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('branding-favicon-test@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    public function brandingPageOffersAFaviconUpload(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/settings/branding');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Favicon');
        $I->seeElement('input[type="file"][name="favicon"]');
    }

    public function faviconUrlSettingRendersAnIconLinkInTheHead(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $setting = (new AppSetting())
            ->setSettingKey('favicon_url')
            ->setName('Favicon URL')
            ->setSettingValue('/uploads/branding/test-favicon.png');
        $em->persist($setting);
        $em->flush();
        $I->grabService(AppSettings::class)->clearCache();

        $this->loginAsAdmin($I);
        $I->amOnPage('/admin/settings/branding');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('link[rel="icon"][href="/uploads/branding/test-favicon.png"]');
    }
}
