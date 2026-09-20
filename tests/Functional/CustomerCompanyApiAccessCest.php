<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\ApiCredential;
use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\CustomerUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Owner-managed API access (#532).
 *
 * The page hands an owner one power — deciding who at their company MAY hold a key — and must hand
 * them no part of a second: it can never show, create, rotate or revoke a key, its own owner's
 * included. So the cases below are written in matched pairs, the thing an owner can do next to the
 * neighbouring thing they must not, because a page like this fails by granting slightly too much
 * rather than by not working.
 */
final class CustomerCompanyApiAccessCest
{
    private function makeCompany(FunctionalTester $I, bool $apiEnabled = true): Company
    {
        $company = (new Company())
            ->setName('Acme Co')->setCode('ACME-' . uniqid())
            ->setApiEnabled($apiEnabled);
        $I->haveInRepository($company);

        return $company;
    }

    private function makeUser(
        FunctionalTester $I,
        Company $company,
        bool $owner,
        bool $apiEnabled = false,
        string $first = 'Pat',
    ): CustomerUser {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new CustomerUser())
            ->setEmail('capi-' . uniqid() . '@example.test')
            ->setFirstName($first)->setLastName($owner ? 'Owner' : 'Staff')
            ->setCompany($company)
            ->setRoles([$owner ? 'ROLE_COMPANY_OWNER' : 'ROLE_COMPANY_STAFF'])
            ->setApiEnabled($apiEnabled);
        $user->setPassword($hasher->hashPassword($user, 'pw-123456789'));
        $I->haveInRepository($user);

        return $user;
    }

    private function makeKey(FunctionalTester $I, CustomerUser $user, string $key): ApiCredential
    {
        $credential = (new ApiCredential())->setCustomerUser($user)->setApiKey($key)->setStatus(ApiCredential::STATUS_ACTIVE);
        $I->haveInRepository($credential);

        return $credential;
    }

    /** CSRF is enforced globally by CsrfProtectionSubscriber, so a token is not optional here. */
    private function post(FunctionalTester $I, CustomerUser $target, bool $enabled): void
    {
        $I->sendFormPostRequest('/company-users/api-access/' . $target->getId(), [
            '_token' => $I->csrfToken(),
            'enabled' => $enabled ? '1' : '0',
        ]);
    }

    // ---------------------------------------------------------------- who may reach the page

    public function anOwnerSeesTheManageApiAccessButtonAndStaffDoesNot(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeUser($I, $company, owner: true);
        $staff = $this->makeUser($I, $company, owner: false);

        $I->amLoggedInAs($owner);
        $I->amOnPage('/company-users');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInSource('/company-users/api-access');

        $I->amLoggedInAs($staff);
        $I->amOnPage('/company-users');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeInSource('/company-users/api-access');
    }

    /** Hiding the button is presentation. This is the half that actually stops anyone. */
    public function staffCannotOpenThePageEvenByTypingTheUrl(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $staff = $this->makeUser($I, $company, owner: false);

        $I->amLoggedInAs($staff);
        $I->amOnPage('/company-users/api-access');
        $I->seeCurrentUrlEquals('/company-users');
        $I->see('Only a company owner can manage API access.');
    }

    public function staffCannotEnableThemselvesByPostingDirectly(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->makeUser($I, $company, owner: true);
        $staff = $this->makeUser($I, $company, owner: false);

        $I->amLoggedInAs($staff);
        $this->post($I, $staff, true);

        $I->seeInRepository(CustomerUser::class, ['id' => $staff->getId(), 'apiEnabled' => false]);
    }

    // ---------------------------------------------------------------- the company gate

    public function withTheCompanyGateOffThePageExplainsWhoToAskAndOffersNoToggle(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, apiEnabled: false);
        $owner = $this->makeUser($I, $company, owner: true);
        $this->makeUser($I, $company, owner: false);

        $I->amLoggedInAs($owner);
        $I->amOnPage('/company-users/api-access');
        $I->seeResponseCodeIsSuccessful();

        $I->see('API access is not enabled for your company.');
        $I->see('Please ask an administrator to activate API access for your company.');
        // No control at all rather than a disabled one: a greyed-out button reads as broken, not as
        // "not yours to set". Asserted against the route, not the word "Enable" — see()/dontSee()
        // match case-insensitively, so the word alone also matches this page's own "not enabled".
        $I->dontSeeInSource('/company-users/api-access/' . $this->onlyStaffId($I, $company));
    }

    /** The staff member's id, for asserting that no form on the page targets them. */
    private function onlyStaffId(FunctionalTester $I, Company $company): int
    {
        /** @var list<CustomerUser> $users */
        $users = $I->grabEntitiesFromRepository(CustomerUser::class, ['company' => $company->getId()]);
        foreach ($users as $user) {
            if (!in_array('ROLE_COMPANY_OWNER', $user->getRoles(), true)) {
                return (int) $user->getId();
            }
        }

        return 0;
    }

    /** The company gate is the administrator's lever; an owner must not be able to route around it. */
    public function anOwnerCannotEnableAnyoneWhileTheCompanyGateIsOff(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, apiEnabled: false);
        $owner = $this->makeUser($I, $company, owner: true);
        $staff = $this->makeUser($I, $company, owner: false);

        $I->amLoggedInAs($owner);
        $this->post($I, $staff, true);

        $I->seeInRepository(CustomerUser::class, ['id' => $staff->getId(), 'apiEnabled' => false]);
        $I->amOnPage('/company-users/api-access');
        $I->see('Please ask an administrator');
    }

    // ---------------------------------------------------------------- the power the page grants

    public function anOwnerEnablesAndDisablesAStaffMember(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeUser($I, $company, owner: true);
        $staff = $this->makeUser($I, $company, owner: false);

        $I->amLoggedInAs($owner);

        $this->post($I, $staff, true);
        $I->seeInRepository(CustomerUser::class, ['id' => $staff->getId(), 'apiEnabled' => true]);

        $this->post($I, $staff, false);
        $I->seeInRepository(CustomerUser::class, ['id' => $staff->getId(), 'apiEnabled' => false]);
    }

    /**
     * Decision 3 of #532. The page is gated on the role, never on the flag it edits — otherwise a
     * sole owner switching themselves off locks the company out of its own API with one click and
     * needs support to get back in.
     */
    public function anOwnerWhoDisablesThemselvesCanStillReachThePageAndReEnable(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeUser($I, $company, owner: true, apiEnabled: true);

        $I->amLoggedInAs($owner);

        $this->post($I, $owner, false);
        $I->seeInRepository(CustomerUser::class, ['id' => $owner->getId(), 'apiEnabled' => false]);

        $I->amOnPage('/company-users/api-access');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Manage API Access');

        $this->post($I, $owner, true);
        $I->seeInRepository(CustomerUser::class, ['id' => $owner->getId(), 'apiEnabled' => true]);
    }

    public function anOwnerCannotTouchAUserAtAnotherCompany(FunctionalTester $I): void
    {
        $owner = $this->makeUser($I, $this->makeCompany($I), owner: true);
        $outsider = $this->makeUser($I, $this->makeCompany($I), owner: false);

        $I->amLoggedInAs($owner);
        $this->post($I, $outsider, true);

        $I->seeInRepository(CustomerUser::class, ['id' => $outsider->getId(), 'apiEnabled' => false]);
    }

    // ---------------------------------------------------------------- the power it must NOT grant

    /**
     * Disabling is a pause. The credential row survives untouched and keeps its status, so switching
     * the person back on restores the same key with nothing to regenerate — which is exactly what
     * the page promises the owner.
     */
    public function disablingSomeoneDoesNotRevokeOrDeleteTheirKey(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeUser($I, $company, owner: true);
        $staff = $this->makeUser($I, $company, owner: false, apiEnabled: true);
        $this->makeKey($I, $staff, 'key-stays-put-' . uniqid());

        $I->amLoggedInAs($owner);
        $this->post($I, $staff, false);

        $I->seeInRepository(ApiCredential::class, [
            'customerUser' => $staff->getId(),
            'status' => ApiCredential::STATUS_ACTIVE,
        ]);
    }

    /** The whole point of the page is that it grants permission and never operates a key. */
    public function thePageNeverRevealsAnybodysKeyNotEvenTheOwnersOwn(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeUser($I, $company, owner: true, apiEnabled: true);
        $staff = $this->makeUser($I, $company, owner: false, apiEnabled: true);

        $ownerKey = 'owner-secret-' . uniqid();
        $staffKey = 'staff-secret-' . uniqid();
        $this->makeKey($I, $owner, $ownerKey);
        $this->makeKey($I, $staff, $staffKey);

        $I->amLoggedInAs($owner);
        $I->amOnPage('/company-users/api-access');
        $I->seeResponseCodeIsSuccessful();

        $html = $I->grabPageSource();
        $I->assertStringNotContainsString($staffKey, $html);
        $I->assertStringNotContainsString($ownerKey, $html);

        // It may say WHETHER a key exists — that is what makes "enabled but not set up yet"
        // legible to an owner — and it must offer nothing that operates one. Asserted against the
        // routes rather than the button words: see()/dontSee() are case-insensitive, so "Regenerate"
        // also matches this page's own advice to go and regenerate your own key.
        $I->see('Active');
        $I->dontSeeInSource('/profile/api-key/generate');
        $I->dontSeeInSource('/profile/api-key/revoke');
    }

    /** Point 4 of #532: who enabled whom has to be answerable after the fact. */
    public function enablingSomeoneIsRecordedInTheAuditLog(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeUser($I, $company, owner: true);
        $staff = $this->makeUser($I, $company, owner: false);

        $I->amLoggedInAs($owner);
        $this->post($I, $staff, true);

        /** @var list<AuditLog> $rows */
        $rows = $I->grabEntitiesFromRepository(AuditLog::class, [
            'entityType' => 'CustomerUser',
            'entityId' => $staff->getId(),
        ]);
        // "Who enabled whom" needs both halves in the SAME row. Asserting the change appears
        // somewhere and the actor appears somewhere would pass on the row Doctrine wrote when the
        // fixture created this user, which names no actor at all.
        $byOwner = array_values(array_filter(
            $rows,
            static fn (AuditLog $row): bool => $row->getActorId() === $owner->getId()
                && str_contains((string) $row->getDataAfter(), 'apiEnabled')
        ));

        $I->assertNotEmpty($byOwner, 'Changing someone\'s API access must leave an audit row naming the owner who did it.');
        $I->assertSame('customer', $byOwner[0]->getActorType());
    }
}
