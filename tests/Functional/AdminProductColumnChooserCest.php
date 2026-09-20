<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminColumnPreference;
use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers issue #120 part 5: the per-user Choose Columns feature on the Product Detail grid — personal
 *  selection, the Tech-Support "apply to all" global default, and its access control. */
final class AdminProductColumnChooserCest
{
    private function login(FunctionalTester $I, array $roles, string $email): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail($email);
        $admin->setRoles($roles);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        return $admin;
    }

    private function grabToken(FunctionalTester $I): string
    {
        $I->amOnPage('/admin/product/detail/index');
        $I->seeResponseCodeIsSuccessful();

        return $I->grabAttributeFrom('#column-chooser input[name="_token"]', 'value');
    }

    public function savingAPersonalSelectionHidesTheOtherColumns(FunctionalTester $I): void
    {
        $admin = $this->login($I, ['ROLE_ADMIN'], 'colchooser-personal@example.test');
        $token = $this->grabToken($I);

        $I->sendAjaxPostRequest('/admin/product/detail/columns', ['_token' => $token, 'columns' => ['id', 'name']]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(AdminColumnPreference::class, ['admin' => $admin->getId(), 'viewKey' => 'product_detail']);

        $I->amOnPage('/admin/product/detail/index');
        // Deselected columns are hidden; selected ones are not.
        $I->seeInSource('.col-sku { display: none !important;');
        $I->seeInSource('.col-shipping_class { display: none !important;');
        $I->dontSeeInSource('.col-id { display: none !important;');
        $I->dontSeeInSource('.col-name { display: none !important;');
    }

    /** Issue #348-follow-up: hiding columns must shrink the grouped header row's colspans to match, or
     *  the "Product core" / "Product pricing" / "Catalog controls" banner drifts out of alignment with
     *  the individual column headers and data cells actually left in the table. */
    public function hidingColumnsShrinksTheGroupedHeaderColspansToMatch(FunctionalTester $I): void
    {
        $this->login($I, ['ROLE_ADMIN'], 'colchooser-colspan@example.test');
        $token = $this->grabToken($I);

        // Keep only 2 "core" columns and none of the "pricing" or "catalog" ones.
        $I->sendAjaxPostRequest('/admin/product/detail/columns', ['_token' => $token, 'columns' => ['id', 'name']]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/product/detail/index');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInSource('<th colspan="2">Product core</th>');
        $I->dontSeeInSource('colspan="11"');
        $I->dontSeeInSource('>Product pricing</th>');
        $I->dontSeeInSource('>Catalog controls</th>');
    }

    public function techSupportCanSetTheDefaultForAllUsers(FunctionalTester $I): void
    {
        $this->login($I, ['ROLE_TECH_SUPPORT'], 'colchooser-ts@example.test');
        $token = $this->grabToken($I);

        $I->sendAjaxPostRequest('/admin/product/detail/columns', ['_token' => $token, 'columns' => ['id', 'sku'], 'apply_all' => '1']);
        $I->seeResponseCodeIsSuccessful();

        // A global row (admin_id NULL) is created with the chosen columns.
        $em = $I->grabService(EntityManagerInterface::class);
        $global = $em->getRepository(AdminColumnPreference::class)->findOneBy(['admin' => null, 'viewKey' => 'product_detail']);
        $I->assertNotNull($global);
        $I->assertSame(['id', 'sku'], $global->getColumns());
    }

    public function aPlainAdminCannotApplyToAllUsers(FunctionalTester $I): void
    {
        $this->login($I, ['ROLE_ADMIN'], 'colchooser-noapplyall@example.test');
        $token = $this->grabToken($I);

        $I->sendAjaxPostRequest('/admin/product/detail/columns', ['_token' => $token, 'columns' => ['id'], 'apply_all' => '1']);
        $I->seeResponseCodeIs(403);

        $em = $I->grabService(EntityManagerInterface::class);
        $I->assertNull($em->getRepository(AdminColumnPreference::class)->findOneBy(['admin' => null, 'viewKey' => 'product_detail']));
    }

    public function reapplyingToAllAfterChangingTheSelectionUpdatesTheExistingGlobalDefault(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $global = (new AdminColumnPreference())->setViewKey('product_detail')->setColumns(['id', 'name']);
        $em->persist($global);
        $em->flush();

        $this->login($I, ['ROLE_TECH_SUPPORT'], 'colchooser-ts-reapply@example.test');
        $token = $this->grabToken($I);

        $I->sendAjaxPostRequest('/admin/product/detail/columns', ['_token' => $token, 'columns' => ['sku', 'type'], 'apply_all' => '1']);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $global = $em->getRepository(AdminColumnPreference::class)->findOneBy(['admin' => null, 'viewKey' => 'product_detail']);
        $I->assertNotNull($global);
        $I->assertSame(['sku', 'type'], $global->getColumns());
    }

    public function applyingToAllOverridesTheActingAdminsOwnStalePersonalSelectionToo(FunctionalTester $I): void
    {
        $admin = $this->login($I, ['ROLE_TECH_SUPPORT'], 'colchooser-ts-selfstale@example.test');
        $token = $this->grabToken($I);

        // The acting admin already has a personal selection from an earlier, unrelated save.
        $I->sendAjaxPostRequest('/admin/product/detail/columns', ['_token' => $token, 'columns' => ['id', 'name']]);
        $I->seeResponseCodeIsSuccessful();

        // Now they pick a new set and apply it as the default for everyone, including themselves.
        $I->sendAjaxPostRequest('/admin/product/detail/columns', ['_token' => $token, 'columns' => ['sku', 'type'], 'apply_all' => '1']);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/product/detail/index');
        $I->seeResponseCodeIsSuccessful();
        // The acting admin should now see the just-applied default, not their earlier stale personal pick.
        $I->seeInSource('.col-id { display: none !important;');
        $I->seeInSource('.col-name { display: none !important;');
        $I->dontSeeInSource('.col-sku { display: none !important;');
        $I->dontSeeInSource('.col-type { display: none !important;');
    }

    public function theGlobalDefaultAppliesToAdminsWithoutTheirOwnSelection(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $global = (new AdminColumnPreference())->setViewKey('product_detail')->setColumns(['id', 'name']);
        $em->persist($global);
        $em->flush();

        $this->login($I, ['ROLE_ADMIN'], 'colchooser-inherit@example.test');
        $I->amOnPage('/admin/product/detail/index');
        $I->seeResponseCodeIsSuccessful();

        $I->seeInSource('.col-sku { display: none !important;');
        $I->dontSeeInSource('.col-id { display: none !important;');
    }
}
