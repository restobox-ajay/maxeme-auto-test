<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ApiCredential;
use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The credential-handling defects found by review on the thin-wrapper branch. Each case is written
 * against the consequence rather than the mechanism — what an operator or an integrator would
 * actually experience — because every one of these passed a fully green suite.
 */
final class ApiCredentialHardeningCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
    }

    private function makeCompany(FunctionalTester $I, bool $apiEnabled = true, string $status = 'Active'): Company
    {
        $company = (new Company())
            ->setName('Acme Co')->setCode('ACME-' . uniqid())
            ->setApiEnabled($apiEnabled);
        $company->setStatus($status, DocumentActor::system());
        $I->haveInRepository($company);

        return $company;
    }

    private function makeCustomer(FunctionalTester $I, Company $company, bool $apiEnabled = true): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new CustomerUser())
            ->setEmail('api-' . uniqid() . '@example.test')
            ->setFirstName('Pat')->setLastName('Owner')
            ->setCompany($company)
            ->setRoles(['ROLE_COMPANY_OWNER'])
            ->setApiEnabled($apiEnabled);
        $user->setPassword($hasher->hashPassword($user, 'pw-123456789'));
        $I->haveInRepository($user);

        return $user;
    }

    /**
     * The key authenticates as its owner, so it is a credential in the strongest sense. The page
     * that mints it tells the user nobody else can see it — the audit log must not quietly make a
     * liar of that page.
     */
    public function generatingAKeyDoesNotWriteItIntoTheAuditLog(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $user = $this->makeCustomer($I, $company);
        $I->amLoggedInAs($user, 'main');

        $I->amOnPage('/profile/api-key');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/profile/api-key/generate', ['_token' => $token]);

        $credential = $I->grabEntityFromRepository(ApiCredential::class, ['customerUser' => $user]);
        $key = $credential->getApiKey();
        $I->assertNotEmpty($key);

        $em = $I->grabService(EntityManagerInterface::class);
        foreach ($em->getRepository(AuditLog::class)->findAll() as $row) {
            foreach ([$row->getDataBefore(), $row->getDataAfter(), $row->getSummary()] as $field) {
                $I->assertStringNotContainsString($key, (string) $field, 'A live API key must never be stored in the audit log.');
            }
        }
    }

    /**
     * The event itself still belongs in the log — an operator investigating "who gave this account
     * API access" needs to see the key being minted. Only the value is withheld, so redaction must
     * not have been implemented by excluding the entity.
     */
    public function generatingAKeyIsStillRecordedAsAnEvent(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $user = $this->makeCustomer($I, $company);
        $I->amLoggedInAs($user, 'main');

        $I->amOnPage('/profile/api-key');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/profile/api-key/generate', ['_token' => $token]);

        $em = $I->grabService(EntityManagerInterface::class);
        $rows = $em->getRepository(AuditLog::class)->findBy(['entityType' => 'ApiCredential']);
        $I->assertNotEmpty($rows, 'Minting a credential is an auditable event and must still be logged.');
    }

    /**
     * A company an admin has set Inactive must be refused by the authenticator itself, in JSON. It
     * used to slip past a hand-rolled user-only status check and get caught later by
     * InactiveAccountLogoutSubscriber, which answers a curl client with a 302 to an HTML login page.
     */
    public function aKeyWhoseCompanyIsInactiveIsRefusedInJsonNotRedirectedToLogin(FunctionalTester $I): void
    {
        // apiEnabled deliberately left ON, so the only thing refusing this is the account status.
        $company = $this->makeCompany($I, apiEnabled: true, status: 'Inactive');
        $user = $this->makeCustomer($I, $company);
        $I->haveInRepository((new ApiCredential())->setCustomerUser($user)->setApiKey('inactive-co-key-1')->setStatus(ApiCredential::STATUS_ACTIVE));

        $I->haveHttpHeader('X-Api-Key', 'inactive-co-key-1');
        $I->stopFollowingRedirects();
        $I->amOnPage('/api/v1/products?region=West');
        $I->startFollowingRedirects();

        $I->seeResponseCodeIs(401);
        $body = json_decode($I->grabPageSource(), true);
        $I->assertIsArray($body, 'An API client must get JSON, never an HTML login page.');
        $I->assertStringContainsString('company', strtolower((string) $body['error']));
    }

    /**
     * Status matching goes through AccountStatusResolver, which is case-insensitive.
     *
     * 'active' (lowercase) is not a value HasStatus's vocabulary recognises — Company only declares
     * 'Active' — so writing it has to bypass the gate, the same way UnknownStatusIsNotADeadEndCest
     * seeds a legacy value: a raw UPDATE against the row the fixture already persisted correctly.
     */
    public function aLowercaseActiveStatusIsStillAccepted(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $I->haveInRepository($company);
        $user = $this->makeCustomer($I, $company);
        $I->haveInRepository($user);
        $connection = $I->grabService(\Doctrine\DBAL\Connection::class);
        $connection->executeStatement('UPDATE company SET status = ? WHERE id = ?', ['active', $company->getId()]);
        $connection->executeStatement('UPDATE customer_user SET status = ? WHERE id = ?', ['active', $user->getId()]);
        $I->haveInRepository((new ApiCredential())->setCustomerUser($user)->setApiKey('lowercase-key-1')->setStatus(ApiCredential::STATUS_ACTIVE));

        $I->haveHttpHeader('X-Api-Key', 'lowercase-key-1');
        $I->amOnPage('/api/v1/products?region=West');

        // No region configured for this company, so the catalog blocks it — but with 403 from the
        // catalog's own rule, NOT 401 from the authenticator, which is the distinction under test.
        //
        // Asserted as the exact code (#594). `dontSeeResponseCodeIs(401)` admitted every wrong
        // outcome the comment above rules out: a 500 from a case-sensitivity crash, a 403 raised by
        // the authenticator rather than the catalog, a 404 if the route went away, and a 200. A
        // method named "…IsStillAccepted" has to say what acceptance looked like.
        $I->seeResponseCodeIs(403);
    }

    /**
     * last_used_at is telemetry, and telemetry must not turn a read into a write on every call.
     * Stamped at most once per resolution window, so a burst of reads produces one write, not one
     * per request — which is what used to queue on SQLite's 500ms busy_timeout and surface as an
     * HTML 500 from a request that only read data.
     */
    public function repeatedApiCallsDoNotRewriteLastUsedOnEveryRequest(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $user = $this->makeCustomer($I, $company);
        $I->haveInRepository((new ApiCredential())->setCustomerUser($user)->setApiKey('usage-key-1')->setStatus(ApiCredential::STATUS_ACTIVE));

        $em = $I->grabService(EntityManagerInterface::class);

        $I->haveHttpHeader('X-Api-Key', 'usage-key-1');
        $I->amOnPage('/api/v1/products?region=West');
        $em->clear();
        $first = $em->getRepository(ApiCredential::class)->findOneBy(['apiKey' => 'usage-key-1'])->getLastUsedAt();
        $I->assertNotNull($first, 'The first call records that the key was used.');

        $auditRowsAfterFirst = count($em->getRepository(AuditLog::class)->findBy(['entityType' => 'ApiCredential']));

        for ($i = 0; $i < 3; $i++) {
            $I->haveHttpHeader('X-Api-Key', 'usage-key-1');
            $I->amOnPage('/api/v1/products?region=West');
        }

        $em->clear();
        $after = $em->getRepository(ApiCredential::class)->findOneBy(['apiKey' => 'usage-key-1'])->getLastUsedAt();
        $I->assertEquals($first, $after, 'Calls inside the resolution window must not rewrite the timestamp.');

        // And the stamp must not be manufacturing audit noise that buries mint/rotate/revoke.
        $I->assertSame(
            $auditRowsAfterFirst,
            count($em->getRepository(AuditLog::class)->findBy(['entityType' => 'ApiCredential'])),
            'Recording usage must not emit an audit row per request.'
        );
    }

    /**
     * The bug an admin would never connect to their own action: editing an unrelated field on a
     * customer wiped their API access, because the form rendered the checkbox from a row that never
     * carried the stored value.
     */
    public function editingAnUnrelatedFieldDoesNotRevokeApiAccess(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $user = $this->makeCustomer($I, $company, apiEnabled: true);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'pw-123456789'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        // The form must show the stored state, or the admin cannot preserve what they cannot see.
        $I->amOnPage('/admin/user/customer/update/' . $user->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeCheckboxIsChecked('input[name="api_enabled"]');

        // Posted directly rather than via submitForm(): the form's action is an absolute
        // admin.localhost URL, which the Symfony module refuses to follow as external — the same
        // workaround AdminEmailTemplateCatalogueCest and AdminInviteTokenExpiryCest use.
        //
        // The posted body deliberately mirrors what the browser would send for the form AS
        // RENDERED, checkbox included. That is the whole point: the bug was that the box rendered
        // unchecked, so a real admin's save omitted it and cleared the flag.
        $I->sendAjaxPostRequest('/admin/user/customer/update/' . $user->getId(), [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'email' => $user->getEmail(),
            'first_name' => 'Pat',
            'last_name' => 'Owner',
            'phone_number' => '555-0199',
            'status' => 'Active',
            'company' => (string) $company->getId(),
            'role' => 'Owner',
            'api_enabled' => '1',
        ]);

        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $user->getId()]);
        $I->assertTrue($refreshed->isApiEnabled(), 'Editing a phone number must not revoke API access.');
    }
}
