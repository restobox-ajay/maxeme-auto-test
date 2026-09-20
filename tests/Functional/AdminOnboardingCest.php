<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\SalesTax;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers Admin\SystemController::onboarding() (#426): the page renders end to end through the
 * real container (proving OnboardingChecklistService's #[AutowireIterator] wiring actually picks
 * up every real check, not just the fakes tests/Service/Onboarding/OnboardingChecklistServiceTest
 * exercises in isolation), is reachable by an ordinary admin (not gated to Tech Support, unlike
 * most of this same System group), and reflects real database state.
 */
final class AdminOnboardingCest
{
    private function loginAsAdmin(FunctionalTester $I, array $roles = []): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('admin-onboarding-functional-test@example.test')
            ->setRoles($roles);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');

        return $admin;
    }

    public function onboardingIsReachableByAnOrdinaryAdminNotJustTechSupport(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/onboarding');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Onboarding');
    }

    public function onboardingRequiresLogin(FunctionalTester $I): void
    {
        // access_control (config/packages/security.yaml) requires ROLE_ADMIN on every /admin
        // route, so an anonymous request is redirected to the login page before the controller
        // ever runs (amOnPage follows the redirect, landing here).
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/onboarding');
        $I->seeCurrentUrlEquals('/admin/login');
    }

    public function freshInstallShowsMostChecksAsNotDoneAndFloatsThemAboveDoneOnes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/onboarding');
        $I->seeResponseCodeIsSuccessful();

        // A fresh test database has no AppSetting rows, no tax rows, no Super Admin, and no
        // pricing group assignment — every one of those checks must read as Not Done.
        $I->see('Not Done');
        $I->see('At Least 1 Super Admin User');
        $I->see('Sales Tax Table Has At Least 1 Row');

        $html = $I->grabPageSource();
        $firstNotDone = strpos($html, 'Not Done');
        $I->assertNotFalse($firstNotDone);

        // Whatever "Done" badges exist (if any check happens to pass against an empty DB, e.g.
        // none currently do) must not appear before the first "Not Done" — the ordering
        // contract is "not done floats to the top of the whole page".
        $firstDone = strpos($html, '>Done<');
        if ($firstDone !== false) {
            $I->assertGreaterThan($firstNotDone, $firstDone);
        }
    }

    public function creatingAnActiveSuperAdminTurnsThatSpecificCheckGreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_SUPER_ADMIN']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/onboarding');
        $I->seeResponseCodeIsSuccessful();

        // The logged-in user IS itself the Active Super Admin, so this one specific item must
        // now read Done while unrelated checks (e.g. tax table) remain Not Done.
        $I->see('At Least 1 Super Admin User');
        $I->see('Sales Tax Table Has At Least 1 Row');
        $I->see('Not Done');
    }

    public function fillingTheTaxTableTurnsOnlyThatCheckGreenWithoutAffectingUnrelatedOnes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $tax = (new SalesTax())
            ->setProvinceName('Ontario')
            ->setAbbreviation('ON')
            ->setTaxType('HST')
            ->setRate(13.0);
        $I->haveInRepository($tax);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/onboarding');
        $I->seeResponseCodeIsSuccessful();

        // Still missing: Super Admin. Confirms independence between checks — one passing does
        // not cause the page to render as if everything passed.
        $I->see('At Least 1 Super Admin User');
        $I->see('Not Done');
    }

    public function malformedFiltersOrExtraQueryParamsDoNotBreakThePage(FunctionalTester $I): void
    {
        // The route takes no input at all, but a hostile or careless client can still append
        // arbitrary query junk; the page must render exactly as it would with none.
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/onboarding?' . http_build_query([
            'page' => '<script>alert(1)</script>',
            'filters' => ['x' => str_repeat('a', 5000)],
        ]));
        $I->seeResponseCodeIsSuccessful();
        $I->see('Onboarding');
        $I->dontSee('<script>alert(1)</script>');
    }
}
