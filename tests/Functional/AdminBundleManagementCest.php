<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin/BundleManagementController — the /admin/bundle-management listing of every
 *  registered BundleDescriptorInterface, and the CSRF-protected per-bundle Active/Inactive
 *  toggle. Uses the real CartHoldBundle descriptor (always registered via
 *  modules/CartHoldBundle/config/services.yaml) rather than a fake one, since descriptors are
 *  wired through #[AutowireIterator('app.bundle_descriptor')] and can't be substituted per-test.
 *
 *  Toggle tests read the bundle's current status before acting (rather than assuming it starts
 *  Active) and restore it afterwards, since this suite's tests share one persistent SQLite
 *  connection across the whole run and CartHoldBundle being Inactive would break other Cests
 *  that exercise cart-hold behavior (see CartHoldCest, CartHoldConfigCest). */
final class AdminBundleManagementCest
{
    private function login(FunctionalTester $I, array $roles, string $email): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail($email);
        $admin->setRoles($roles);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');

        return $admin;
    }

    /** App management is Tech Support only (issue #116), so the functional flows act as one. */
    private function loginAsTechSupport(FunctionalTester $I): void
    {
        $this->login($I, ['ROLE_TECH_SUPPORT'], 'techsupport-bundle-mgmt-functional-test@example.test');
    }

    public function aPlainAdminCannotAccessAppManagement(FunctionalTester $I): void
    {
        $this->login($I, ['ROLE_ADMIN'], 'admin-bundle-mgmt-denied-test@example.test');

        $repo = $I->grabService(BundleStatusRepository::class);
        $originalStatus = $repo->ensureBySource('CartHoldBundle')->getStatus();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/bundle-management');
        $I->seeResponseCodeIs(403);

        // A REAL token, so the POST measures the role gate and not the CSRF check standing in for
        // it (#594). With '_token' => 'anything', CsrfProtectionSubscriber replaces the controller
        // on kernel.controller before its body runs, so the 403 arrived whatever role was acting —
        // deleting toggle()'s own denyAccessUnlessGranted('ROLE_TECH_SUPPORT'), which would let any
        // plain admin switch every optional bundle on or off, left this test green. access_control
        // only requires ROLE_ADMIN for ^/admin, so that in-method check is the whole boundary.
        //
        // The token is app-wide (App\Security\Csrf\Csrf::ID), so a page this admin IS allowed to see
        // yields one that the toggle endpoint accepts.
        $I->amOnPage('/admin');
        $token = $I->csrfToken();
        $I->assertNotSame('', $token, 'without a real token this POST would be measuring CSRF, not the role');

        $I->sendAjaxPostRequest('/admin/bundle-management/CartHoldBundle/toggle', ['_token' => $token]);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(BundleStatus::class, ['source' => 'CartHoldBundle', 'status' => $originalStatus]);
    }

    public function indexListsRegisteredBundlesWithDescriptorMetadata(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/bundle-management');
        $I->seeResponseCodeIsSuccessful();

        $I->see('App Management');
        $I->see('Cart Hold');
        $I->see('Inventory');
        $I->seeElement('form[action$="/CartHoldBundle/toggle"] input[name="_token"]');
    }

    public function togglingWithValidTokenFlipsStatusAndCanBeToggledBack(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/bundle-management');
        $I->seeResponseCodeIsSuccessful();

        $repo = $I->grabService(BundleStatusRepository::class);
        $originalStatus = $repo->ensureBySource('CartHoldBundle')->getStatus();
        $flippedStatus = $originalStatus === BundleStatus::STATUS_ACTIVE
            ? BundleStatus::STATUS_INACTIVE
            : BundleStatus::STATUS_ACTIVE;

        $token = $I->grabAttributeFrom('form[action$="/CartHoldBundle/toggle"] input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/bundle-management/CartHoldBundle/toggle', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(BundleStatus::class, ['source' => 'CartHoldBundle', 'status' => $flippedStatus]);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/bundle-management');
        $I->seeResponseCodeIsSuccessful();

        $restoreToken = $I->grabAttributeFrom('form[action$="/CartHoldBundle/toggle"] input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/bundle-management/CartHoldBundle/toggle', ['_token' => $restoreToken]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(BundleStatus::class, ['source' => 'CartHoldBundle', 'status' => $originalStatus]);
    }

    public function toggleWithInvalidCsrfTokenIsRejectedAndLeavesStatusUnchanged(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I);

        $repo = $I->grabService(BundleStatusRepository::class);
        $originalStatus = $repo->ensureBySource('CartHoldBundle')->getStatus();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/bundle-management');
        $I->seeResponseCodeIsSuccessful();

        $I->sendAjaxPostRequest('/admin/bundle-management/CartHoldBundle/toggle', ['_token' => 'not-a-valid-token']);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(BundleStatus::class, ['source' => 'CartHoldBundle', 'status' => $originalStatus]);
    }
}
