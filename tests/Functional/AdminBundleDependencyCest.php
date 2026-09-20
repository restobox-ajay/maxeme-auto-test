<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Command\Bundle\BundleActivateCommand;
use App\Command\Bundle\BundleDeactivateCommand;
use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * #788: WarehouseOpsBundle declares InventoryDepthBundle as required
 * (WarehouseOpsBundleDescriptor::getRequiredBundles()), enforced the one place status is written,
 * {@see BundleStatusRepository}. This drives the real screens and console commands rather than
 * calling the repository directly, the same discipline as AdminBundleManagementCest and
 * BundlesAreInertUntilActivatedCest.
 *
 * CartHoldBundle is the unrelated control throughout: it has no declared requirement and no
 * dependent, so it is what proves a deactivation/activation did not sweep in more than it should.
 */
final class AdminBundleDependencyCest
{
    private const DEPENDENT = 'WarehouseOpsBundle';
    private const REQUIREMENT = 'InventoryDepthBundle';
    private const UNRELATED = 'CartHoldBundle';

    private function loginAsTechSupport(FunctionalTester $I, string $email): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail($email)->setStatus('Active');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** Both Active — the starting shape every case here needs, made explicit rather than assumed. */
    private function ensureBothActive(FunctionalTester $I): void
    {
        $repo = $I->grabService(BundleStatusRepository::class);
        $repo->activate(self::REQUIREMENT);
        $repo->activate(self::DEPENDENT);
        $repo->activate(self::UNRELATED);
    }

    private function toggleToken(FunctionalTester $I, string $source): string
    {
        $I->amOnPage('/admin/bundle-management');
        $I->seeResponseCodeIsSuccessful();

        return (string) $I->grabAttributeFrom('form[action$="/' . $source . '/toggle"] input[name="_token"]', 'value');
    }

    // ----------------------------------------------------------------- cascade + confirm

    /**
     * The confirm step, isolated: the FIRST post to a source with an Active dependent must write
     * nothing at all — not the target's row, not the dependent's.
     */
    public function theFirstPostOfADeactivationWithActiveDependentsChangesNoRow(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I, 'techsupport-788-confirm-1@example.test');
        $this->ensureBothActive($I);

        $token = $this->toggleToken($I, self::REQUIREMENT);
        $I->sendFormPostRequest('/admin/bundle-management/' . self::REQUIREMENT . '/toggle', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        // The confirm page, not a redirect to the index — and nothing written yet.
        $I->see('Turn off');
        $I->seeElement('input[name="confirmed"]');
        $I->seeInRepository(BundleStatus::class, ['source' => self::REQUIREMENT, 'status' => BundleStatus::STATUS_ACTIVE]);
        $I->seeInRepository(BundleStatus::class, ['source' => self::DEPENDENT, 'status' => BundleStatus::STATUS_ACTIVE]);
    }

    /**
     * The second post — carrying `confirmed=1` and its own token off the confirm page — performs
     * the cascade: the dependent goes off with the target, an unrelated bundle is untouched, and
     * the flash names the dependent.
     */
    public function theSecondPostCascadesAndNamesTheDependent(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I, 'techsupport-788-confirm-2@example.test');
        $this->ensureBothActive($I);

        $token = $this->toggleToken($I, self::REQUIREMENT);
        $I->sendFormPostRequest('/admin/bundle-management/' . self::REQUIREMENT . '/toggle', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $confirmToken = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundle-management/' . self::REQUIREMENT . '/toggle', [
            '_token' => $confirmToken,
            'confirmed' => '1',
        ]);

        // sendFormPostRequest() follows the redirect, so the flash is read (and rendered) right
        // here — asserted before anything else re-navigates and consumes it a second time.
        $I->see('also turned off');
        $I->see('Warehouse Operations');

        $I->seeInRepository(BundleStatus::class, ['source' => self::REQUIREMENT, 'status' => BundleStatus::STATUS_INACTIVE]);
        $I->seeInRepository(BundleStatus::class, ['source' => self::DEPENDENT, 'status' => BundleStatus::STATUS_INACTIVE]);
        // The row that must NOT change.
        $I->seeInRepository(BundleStatus::class, ['source' => self::UNRELATED, 'status' => BundleStatus::STATUS_ACTIVE]);
    }

    /** A deactivation with nothing to cascade — the unrelated control — stays a single POST. */
    public function deactivatingABundleWithNoDependentsStaysOnePost(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I, 'techsupport-788-single@example.test');
        $this->ensureBothActive($I);

        $token = $this->toggleToken($I, self::UNRELATED);
        $I->sendFormPostRequest('/admin/bundle-management/' . self::UNRELATED . '/toggle', ['_token' => $token]);

        $I->seeInRepository(BundleStatus::class, ['source' => self::UNRELATED, 'status' => BundleStatus::STATUS_INACTIVE]);
        $I->dontSeeElement('input[name="confirmed"]');
    }

    // ----------------------------------------------------------------- activation refusal

    public function activatingADependentWithAnInactiveRequirementIsRefused(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I, 'techsupport-788-refuse@example.test');
        $repo = $I->grabService(BundleStatusRepository::class);
        $repo->deactivate(self::DEPENDENT);
        $repo->deactivate(self::REQUIREMENT);

        // The client-visible half: no form at all for a blocked row, just a disabled button and
        // the explanation — see it before proving the server refuses too.
        $I->amOnPage('/admin/bundle-management');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('form[action$="/' . self::DEPENDENT . '/toggle"]');
        $I->see('Needs Inventory Depth Active first.');

        // The server-side half: BundleStatusRepository::activate() refuses this regardless of how
        // the request reaches it, not just because the UI happens to agree today. A real token
        // scraped off a page this admin can reach (not the removed form) proves the POST was
        // refused on its OWN terms, not on CSRF standing in for it (#594's own reasoning).
        $token = $I->csrfToken();
        $I->assertNotSame('', $token, 'without a real token this POST would be measuring CSRF, not the refusal');

        $I->sendFormPostRequest('/admin/bundle-management/' . self::DEPENDENT . '/toggle', ['_token' => $token]);

        $I->see('needs');
        $I->see('Inventory Depth');
        $I->seeInRepository(BundleStatus::class, ['source' => self::DEPENDENT, 'status' => BundleStatus::STATUS_INACTIVE]);
    }

    /** Reactivating the requirement is not the same as reactivating what was cascaded off it. */
    public function reactivatingTheRequirementLeavesTheDependentInactive(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I, 'techsupport-788-reactivate@example.test');
        $repo = $I->grabService(BundleStatusRepository::class);
        $repo->deactivate(self::DEPENDENT);
        $repo->deactivate(self::REQUIREMENT);

        $token = $this->toggleToken($I, self::REQUIREMENT);
        $I->sendFormPostRequest('/admin/bundle-management/' . self::REQUIREMENT . '/toggle', ['_token' => $token]);

        $I->seeInRepository(BundleStatus::class, ['source' => self::REQUIREMENT, 'status' => BundleStatus::STATUS_ACTIVE]);
        $I->seeInRepository(BundleStatus::class, ['source' => self::DEPENDENT, 'status' => BundleStatus::STATUS_INACTIVE]);
    }

    // ----------------------------------------------------------------- console parity

    /** app:bundle:deactivate cascades the same way the screen does. */
    public function theConsoleDeactivateCascadesTheSameWayTheScreenDoes(FunctionalTester $I): void
    {
        $this->ensureBothActive($I);

        $deactivate = new CommandTester($I->grabService(BundleDeactivateCommand::class));
        $deactivate->execute(['source' => self::REQUIREMENT]);

        $I->assertSame(0, $deactivate->getStatusCode());
        $I->seeInRepository(BundleStatus::class, ['source' => self::REQUIREMENT, 'status' => BundleStatus::STATUS_INACTIVE]);
        $I->seeInRepository(BundleStatus::class, ['source' => self::DEPENDENT, 'status' => BundleStatus::STATUS_INACTIVE]);
        $I->assertStringContainsString(self::DEPENDENT, $deactivate->getDisplay());
    }

    /** app:bundle:activate refuses the same way the screen does, and writes nothing. */
    public function theConsoleActivateRefusesTheSameWayTheScreenDoes(FunctionalTester $I): void
    {
        $repo = $I->grabService(BundleStatusRepository::class);
        $repo->deactivate(self::DEPENDENT);
        $repo->deactivate(self::REQUIREMENT);

        $activate = new CommandTester($I->grabService(BundleActivateCommand::class));
        $activate->execute(['source' => self::DEPENDENT]);

        $I->assertSame(1, $activate->getStatusCode());
        $I->assertStringContainsString(self::REQUIREMENT, $activate->getDisplay());
        $I->seeInRepository(BundleStatus::class, ['source' => self::DEPENDENT, 'status' => BundleStatus::STATUS_INACTIVE]);
    }

    // ----------------------------------------------------------------- CSRF on the confirm step

    /**
     * The confirm page's own POST is CSRF-protected too, not just the first one — driven with
     * sendAjaxPostRequest() rather than sendFormPostRequest() specifically to get the JSON 403 path
     * (CsrfProtectionSubscriber::expectsJson()) instead of the flash-and-redirect a real no-JS
     * browser gets, the same choice AdminBundleManagementCest's own CSRF test already makes.
     */
    public function forgedCsrfOnTheConfirmPostIsRefusedAndCascadesNothing(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I, 'techsupport-788-csrf@example.test');
        $this->ensureBothActive($I);

        $token = $this->toggleToken($I, self::REQUIREMENT);
        $I->sendFormPostRequest('/admin/bundle-management/' . self::REQUIREMENT . '/toggle', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="confirmed"]');

        $I->sendAjaxPostRequest('/admin/bundle-management/' . self::REQUIREMENT . '/toggle', [
            '_token' => 'not-a-valid-token',
            'confirmed' => '1',
        ]);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(BundleStatus::class, ['source' => self::REQUIREMENT, 'status' => BundleStatus::STATUS_ACTIVE]);
        $I->seeInRepository(BundleStatus::class, ['source' => self::DEPENDENT, 'status' => BundleStatus::STATUS_ACTIVE]);
    }
}
