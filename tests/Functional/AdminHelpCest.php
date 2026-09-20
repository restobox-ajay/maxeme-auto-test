<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin/HelpController::index — the admin help guide page and its
 *  ROLE_TECH_SUPPORT-gated link to the raw technical-docs spec. */
final class AdminHelpCest
{
    /**
     * Authenticates via the security token storage directly (Codeception's Symfony module
     * `amLoggedInAs()`) rather than POSTing the real login form: this app's admin/customer
     * split is host-based (AdminHostSubscriber), and the in-process HttpKernel browser this
     * module uses treats a login redirect to a different host as an external URL it refuses
     * to follow.
     */
    private function loginAsAdmin(FunctionalTester $I, array $roles = []): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('admin-help-functional-test@example.test')
            ->setRoles($roles);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');

        return $admin;
    }

    public function helpPageRendersForAPlainAdmin(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/help');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Help Guide');
        $I->see('Inventory Calculation');
        $I->see('Available = Starting Inventory − Cart Hold − Pending Orders − Approved Orders');
        $I->dontSee('View full spec');
    }

    public function helpPageShowsRawSpecLinkForTechSupportRole(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/help');
        $I->seeResponseCodeIsSuccessful();

        $I->see('View full spec');
        $I->seeInSource('/admin/technical-docs/view/inventory-calculation.md');
    }

    /**
     * #521: the Customer API topic. A plain admin gets it — the whole point of the topic is that
     * support and account staff can answer "why is their integration returning 403" without a
     * developer, so gating it behind ROLE_TECH_SUPPORT would defeat it.
     */
    public function helpPageIncludesTheCustomerApiTopicForAPlainAdmin(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/help');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Customer API');
        $I->see('An API key is a person, not an application.');
        // Both download links are the point of the topic for an admin handing files to an
        // integrator, so their absence is a real regression, not a cosmetic one.
        $I->seeInSource('/admin/help/api-doc/openapi');
        $I->seeInSource('/admin/help/api-doc/postman');
    }

    public function theOpenApiDescriptionDownloads(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/help/api-doc/openapi');
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame('application/yaml; charset=utf-8', $I->grabResponseHeader('Content-Type'));
        $I->assertStringContainsString('attachment', (string) $I->grabResponseHeader('Content-Disposition'));
        $I->seeInSource('/api/v1/products');
    }

    public function thePostmanCollectionDownloadsAndIsValidJson(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/help/api-doc/postman');
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame('application/json; charset=utf-8', $I->grabResponseHeader('Content-Type'));
        $I->assertStringContainsString('attachment', (string) $I->grabResponseHeader('Content-Disposition'));

        // A collection that does not parse is a collection Postman refuses to import, and hand-edited
        // JSON is exactly the kind of file that breaks quietly.
        $collection = json_decode($I->grabPageSource(), true, 512, \JSON_THROW_ON_ERROR);
        $I->assertSame('https://schema.getpostman.com/json/collection/v2.1.0/collection.json', $collection['info']['schema']);
    }

    /**
     * {key} is a lookup index in a fixed allowlist, never a path — so nothing outside it is
     * reachable. Both cases are here because they fail in different places and only one of them
     * reaches the controller: an encoded traversal is refused by the router before routing
     * resolves, so on its own it would assert nothing about the allowlist.
     */
    public function anUnknownApiDocKeyIs404(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $I->haveHttpHeader('Host', 'admin.localhost');

        $I->amOnPage('/admin/help/api-doc/security.yaml');
        $I->seeResponseCodeIs(404);

        $I->amOnPage('/admin/help/api-doc/..%2F..%2Fconfig%2Fpackages%2Fsecurity.yaml');
        $I->seeResponseCodeIs(404);
    }
}
