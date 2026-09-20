<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * ROLE_TECH_SUPPORT: same power as Super Admin, invisible to everyone else, and only mintable by an
 * existing holder.
 *
 * The three properties are independent and each is load-bearing, so they are tested separately:
 *
 *  - Power comes from role_hierarchy, so any current or future ROLE_SUPER_ADMIN gate covers Tech
 *    Support without a second gate to maintain.
 *  - Visibility: hidden from the staff list AND unreachable by direct URL. The direct-URL case
 *    answers 404 rather than 403, because a 403 confirms an account exists at that id and lets a
 *    Super Admin enumerate Tech Support accounts.
 *  - Minting is a closed loop: only Tech Support can grant or revoke it. Super Admin has equivalent
 *    permissions but cannot mint, so the role can never bootstrap from a lesser one.
 *
 * See issue #104.
 */
final class AdminTechSupportRoleCest
{
    private function admin(FunctionalTester $I, string $email, array $roles): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new AdminUser())->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'test-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    /** The roles column as stored, bypassing Doctrine's identity map. */
    private function rolesJson(FunctionalTester $I, string $email): string
    {
        return (string) $I->grabService(\Doctrine\ORM\EntityManagerInterface::class)
            ->getConnection()
            ->fetchOne('SELECT roles FROM admin_user WHERE email = ?', [$email]);
    }

    private function actAs(FunctionalTester $I, AdminUser $user): void
    {
        $I->amLoggedInAs($user, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    // ---- power -----------------------------------------------------------------------------

    public function techSupportInheritsEverySuperAdminPermission(FunctionalTester $I): void
    {
        $this->actAs($I, $this->admin($I, 'ts-power@example.test', ['ROLE_TECH_SUPPORT']));

        // Via role_hierarchy, so gates added later are covered without a second check.
        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();
        $I->assertTrue($I->grabService('security.authorization_checker')->isGranted('ROLE_SUPER_ADMIN'));
    }

    public function aSuperAdminDoesNotInheritTechSupport(FunctionalTester $I): void
    {
        $this->actAs($I, $this->admin($I, 'sa-power@example.test', ['ROLE_SUPER_ADMIN']));

        $I->amOnPage('/admin');
        $I->assertFalse($I->grabService('security.authorization_checker')->isGranted('ROLE_TECH_SUPPORT'));
    }

    // ---- visibility ------------------------------------------------------------------------

    public function aSuperAdminCannotSeeTechSupportInTheStaffList(FunctionalTester $I): void
    {
        $hidden = $this->admin($I, 'ts-hidden@example.test', ['ROLE_TECH_SUPPORT']);
        $this->actAs($I, $this->admin($I, 'sa-list@example.test', ['ROLE_SUPER_ADMIN']));

        $I->amOnPage('/admin/user/staff');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee($hidden->getEmail());
    }

    public function techSupportSeesOtherTechSupport(FunctionalTester $I): void
    {
        $other = $this->admin($I, 'ts-visible@example.test', ['ROLE_TECH_SUPPORT']);
        $this->actAs($I, $this->admin($I, 'ts-viewer@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/user/staff');
        $I->see($other->getEmail());
    }

    public function aSuperAdminGetsA404OnTheTechSupportEditUrl(FunctionalTester $I): void
    {
        $hidden = $this->admin($I, 'ts-url@example.test', ['ROLE_TECH_SUPPORT']);
        $this->actAs($I, $this->admin($I, 'sa-url@example.test', ['ROLE_SUPER_ADMIN']));

        // 404 rather than 403: a 403 confirms a record exists at this id, which is exactly what the
        // list filtering is trying to hide.
        $I->amOnPage('/admin/user/staff/update/' . $hidden->getId());
        $I->seeResponseCodeIs(404);
    }

    public function techSupportCanOpenATechSupportEditPage(FunctionalTester $I): void
    {
        $target = $this->admin($I, 'ts-editable@example.test', ['ROLE_TECH_SUPPORT']);
        $this->actAs($I, $this->admin($I, 'ts-editor@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/user/staff/update/' . $target->getId());
        $I->seeResponseCodeIsSuccessful();
    }

    public function theStatusToggleAlsoAnswers404(FunctionalTester $I): void
    {
        $hidden = $this->admin($I, 'ts-destructive@example.test', ['ROLE_TECH_SUPPORT']);
        $this->actAs($I, $this->admin($I, 'sa-destructive@example.test', ['ROLE_SUPER_ADMIN']));

        // The edit form and the active toggle are the two entry points the issue names; the guard is
        // scoped to those rather than every route that resolves a user by id.
        // The Super Admin's own staff list never renders this row, so its CSRF token is taken
        // from the one session that can see it — a Tech Support one — and replayed as the Super
        // Admin. That keeps this a test of the visibility guard rather than of the CSRF gate:
        // the token is genuine, and the answer is still 404.
        $tokenHolder = $this->admin($I, 'ts-destructive-peer@example.test', ['ROLE_TECH_SUPPORT']);
        $this->actAs($I, $tokenHolder);
        $I->amOnPage('/admin/user/staff');
        $token = (string) $I->grabAttributeFrom(
            '.js-user-status-open[data-status-url$="/status/admin/' . $hidden->getId() . '"]',
            'data-status-token'
        );

        $this->actAs($I, $this->admin($I, 'sa-destructive-2@example.test', ['ROLE_SUPER_ADMIN']));
        $I->sendAjaxPostRequest('/admin/user/status/admin/' . $hidden->getId(), [
            'status' => 'Inactive',
            '_token' => $token,
        ]);
        $I->seeResponseCodeIs(404);

        $I->seeInRepository(AdminUser::class, ['email' => 'ts-destructive@example.test', 'status' => 'Active']);
    }

    // ---- minting ---------------------------------------------------------------------------

    public function aSuperAdminCannotMintTechSupport(FunctionalTester $I): void
    {
        $target = $this->admin($I, 'ts-target-mint@example.test', ['ROLE_ADMIN']);
        $this->actAs($I, $this->admin($I, 'sa-minter@example.test', ['ROLE_SUPER_ADMIN']));

        $I->amOnPage('/admin/user/staff/update/' . $target->getId());
        $I->dontSee('Tech Support');

        $I->sendAjaxPostRequest('/admin/user/staff/update/' . $target->getId(), [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'first_name' => 'Escalated',
            'status' => 'Active',
            'role' => 'Tech Support',
        ]);

        $I->assertStringNotContainsString('ROLE_TECH_SUPPORT', $this->rolesJson($I, 'ts-target-mint@example.test'), 'the role must only ever propagate from an existing holder');
    }

    public function techSupportCanGrantAndRevokeIt(FunctionalTester $I): void
    {
        $target = $this->admin($I, 'ts-roundtrip@example.test', ['ROLE_ADMIN']);
        $this->actAs($I, $this->admin($I, 'ts-minter@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/user/staff/update/' . $target->getId());
        $I->sendAjaxPostRequest('/admin/user/staff/update/' . $target->getId(), [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'first_name' => 'Promoted',
            'status' => 'Active',
            'role' => 'Tech Support',
        ]);
        // Read through SQL, not the repository: the entity is already in Doctrine's identity map from
        // the fixture, so findOneBy() can hand back the pre-request object.
        $I->assertStringContainsString('ROLE_TECH_SUPPORT', $this->rolesJson($I, 'ts-roundtrip@example.test'));

        $I->amOnPage('/admin/user/staff/update/' . $target->getId());
        $I->sendAjaxPostRequest('/admin/user/staff/update/' . $target->getId(), [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'first_name' => 'Demoted',
            'status' => 'Active',
            'role' => 'Admin',
        ]);
        $I->assertStringNotContainsString('ROLE_TECH_SUPPORT', $this->rolesJson($I, 'ts-roundtrip@example.test'));
    }

    public function anAdminCannotMintTechSupportEither(FunctionalTester $I): void
    {
        $target = $this->admin($I, 'ts-target-admin@example.test', ['ROLE_ADMIN']);
        $this->actAs($I, $this->admin($I, 'plain-admin@example.test', ['ROLE_ADMIN']));

        $I->amOnPage('/admin/user/staff/update/' . $target->getId());
        $I->sendAjaxPostRequest('/admin/user/staff/update/' . $target->getId(), [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'first_name' => 'Nope',
            'status' => 'Active',
            'role' => 'Tech Support',
        ]);

        $I->assertStringNotContainsString('ROLE_TECH_SUPPORT', $this->rolesJson($I, 'ts-target-admin@example.test'));
    }

    public function creatingAStaffUserAsTechSupportCanSetTheRole(FunctionalTester $I): void
    {
        $this->actAs($I, $this->admin($I, 'ts-creator@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/user/staff/create');
        $I->sendAjaxPostRequest('/admin/user/staff/create', [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'email' => 'ts-created@example.test',
            'first_name' => 'Created',
            'status' => 'Active',
            'role' => 'Tech Support',
        ]);

        $I->assertStringContainsString('ROLE_TECH_SUPPORT', $this->rolesJson($I, 'ts-created@example.test'));
    }

    public function aSuperAdminCannotCreateOneEither(FunctionalTester $I): void
    {
        $this->actAs($I, $this->admin($I, 'sa-creator@example.test', ['ROLE_SUPER_ADMIN']));

        $I->amOnPage('/admin/user/staff/create');
        $I->sendAjaxPostRequest('/admin/user/staff/create', [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'email' => 'sa-created@example.test',
            'first_name' => 'Created',
            'status' => 'Active',
            'role' => 'Tech Support',
        ]);

        // Either refused outright or created without the role; what must not happen is a new holder.
        // Either refused outright or created without the role; what must not happen is a new holder.
        $I->assertStringNotContainsString('ROLE_TECH_SUPPORT', $this->rolesJson($I, 'sa-created@example.test'));
    }

    // ---- auditing --------------------------------------------------------------------------

    public function techSupportActionsAreStillLogged(FunctionalTester $I): void
    {
        $target = $this->admin($I, 'ts-logged-target@example.test', ['ROLE_ADMIN']);
        $this->actAs($I, $this->admin($I, 'ts-logged-actor@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/user/set-password/admin/' . $target->getId());
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/user/set-password', [
            '_token' => $token,
            'type' => 'admin',
            'id' => (string) $target->getId(),
            'mode' => 'generate',
        ]);

        // Invisible in the user list is not the same as invisible in the audit trail.
        $rows = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class)
            ->getConnection()
            ->fetchAllAssociative("SELECT actor_name, summary FROM audit_log WHERE action = 'password_set_by_admin' ORDER BY id DESC LIMIT 1");

        $I->assertNotEmpty($rows);
        $I->assertStringContainsString('ts-logged-actor@example.test', $rows[0]['actor_name'] . ' ' . $rows[0]['summary']);
    }
}
