<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * app_setting.visibility: tech_support_email belongs to whoever runs the software, not to the store
 * that uses it (#351).
 *
 * The row receives the "database console opened" alert — which admin took raw SQL access, from which
 * IP, at what time. Confidential to the operator, and worse than merely visible before this: because
 * AppSettings prefers a non-blank DB value over the env fallback, a store admin typing their own
 * address into it redirected the alert to themselves.
 *
 * ROLE_TECH_SUPPORT is the operator; ROLE_SUPER_ADMIN is the store owner, and inherits everything
 * else Tech Support can do (security.yaml role_hierarchy) — so these tests are about the one
 * boundary that does not follow from the hierarchy.
 *
 * The two enforcement points are covered separately because they fail independently: hiding the row
 * from the list is cosmetic while /admin/settings/{id}/update still accepts a plain POST from anyone
 * who knows the id. Storage is deliberately not part of the boundary, hence the last test.
 */
final class AdminTechSupportSettingVisibilityCest
{
    private const KEY = 'tech_support_email';

    /**
     * AppSettings caches its rows in a pool that is not part of the per-test transaction, so a value
     * one test writes would otherwise still be visible to the next one after the row itself is gone.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    public function _after(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function admin(FunctionalTester $I, string $email, array $roles): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new AdminUser())->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'test-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    private function actAs(FunctionalTester $I, AdminUser $user): void
    {
        $I->amLoggedInAs($user, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** The stored row as the database holds it, bypassing Doctrine's identity map. */
    private function storedRow(FunctionalTester $I): array
    {
        $row = $I->grabService(EntityManagerInterface::class)->getConnection()
            ->fetchAssociative('SELECT id, setting_value, visibility FROM app_setting WHERE setting_key = ?', [self::KEY]);

        return \is_array($row) ? $row : [];
    }

    private function seedValue(FunctionalTester $I, string $value): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $setting = $entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => self::KEY]);
        if (!$setting instanceof AppSetting) {
            $setting = (new AppSetting())->setSettingKey(self::KEY)->setName('Tech Support Email');
            $entityManager->persist($setting);
        }

        $setting->setVisibility(AppSetting::VISIBILITY_TECH_SUPPORT)->setSettingValue($value);
        $entityManager->flush();
        $I->grabService(AppSettings::class)->clearCache();
    }

    /**
     * Also covers the self-healing path: the store owner's own visit is what creates the row here,
     * and it must come back Tech Support-visible rather than as an ordinary store row.
     */
    public function theStoreOwnerDoesNotSeeItOnTheSettingsList(FunctionalTester $I): void
    {
        $this->actAs($I, $this->admin($I, 'ts-vis-list@example.test', ['ROLE_SUPER_ADMIN']));

        $I->amOnPage('/admin/settings?limit=500');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee(self::KEY);
        $I->dontSee('Tech Support Email');

        $row = $this->storedRow($I);
        $I->assertSame(AppSetting::VISIBILITY_TECH_SUPPORT, $row['visibility'] ?? null, 'the self-healed row must not be store-visible');
    }

    /** The list filter is a convenience; this POST route is the boundary. */
    public function theStoreOwnerCannotChangeItByPostingStraightToTheRow(FunctionalTester $I): void
    {
        $this->seedValue($I, 'ops@saas-operator.example');
        $this->actAs($I, $this->admin($I, 'ts-vis-post@example.test', ['ROLE_SUPER_ADMIN']));

        $id = (int) ($this->storedRow($I)['id'] ?? 0);
        $I->assertGreaterThan(0, $id);

        // A real token, so what is being measured is the authorization refusal and not the CSRF
        // check standing in for it — the Tech Support test below sends the same request and it works.
        $I->amOnPage('/admin/settings');
        $I->sendAjaxPostRequest('/admin/settings/' . $id . '/update', [
            'name' => 'Tech Support Email',
            'setting_value' => 'store-owner@thestore.example',
            '_token' => $I->csrfToken(),
        ]);

        $I->seeResponseCodeIs(403);
        $I->assertSame('ops@saas-operator.example', $this->storedRow($I)['setting_value'] ?? null, 'the alert recipient must not be redirectable');
    }

    public function techSupportSeesItAndCanChangeIt(FunctionalTester $I): void
    {
        $this->actAs($I, $this->admin($I, 'ts-vis-operator@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/settings?limit=500');
        $I->seeResponseCodeIsSuccessful();
        $I->see(self::KEY);

        $id = (int) ($this->storedRow($I)['id'] ?? 0);
        $I->assertGreaterThan(0, $id);

        $I->amOnPage('/admin/settings/' . $id . '/update');
        $I->seeResponseCodeIsSuccessful();
        $I->sendAjaxPostRequest('/admin/settings/' . $id . '/update', [
            'name' => 'Tech Support Email',
            'setting_value' => 'ops@saas-operator.example',
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
        ]);

        $I->seeCurrentUrlEquals('/admin/settings');
        $I->assertSame('ops@saas-operator.example', $this->storedRow($I)['setting_value'] ?? null);
    }

    /**
     * Visibility is a UI/authorization concept, not a storage one. The mailer that sends the console
     * alert reads this through AppSettings while a store admin is the one logged in, so the read has
     * to resolve for whoever is in session — or the alert quietly stops going out.
     */
    public function serverSideReadsStillResolveWhoeverIsLoggedIn(FunctionalTester $I): void
    {
        $this->seedValue($I, 'ops@saas-operator.example');
        $this->actAs($I, $this->admin($I, 'ts-vis-read@example.test', ['ROLE_SUPER_ADMIN']));

        $I->amOnPage('/admin/settings');
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame('ops@saas-operator.example', $I->grabService(AppSettings::class)->get(self::KEY));
    }
}
