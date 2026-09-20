<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\AuditLog;
use App\Entity\Estimate;
use App\Entity\ProductCategory;
use App\Service\DocumentActor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A CSRF refusal must refuse the REQUEST, never the SESSION.
 *
 * The distinction matters because CSRF tokens here are session-backed (framework.yaml pins
 * SessionTokenStorage), so "stale token" and "expired session" look identical from the outside: an
 * admin bounced to the login page after a double-submit cannot tell which one happened, and the
 * natural reading is that the CSRF check logged them out. It does not — CsrfProtectionSubscriber
 * only replaces the controller with a rejection response, and never touches the token storage or
 * the session — but nothing pinned that, so a later change to the rejection path could start
 * destroying live sessions without a single test noticing.
 *
 * Each case therefore asserts three things, not one: the write did not happen, the session cookie
 * was not cleared, and a following authenticated request still succeeds. The last one is the real
 * subject; a 403 alone would pass just as happily on a request that had also been logged out.
 *
 * The quote activity-log endpoint is where this was reported, but the check is global
 * (CsrfProtectionSubscriber, deny-by-default on kernel.controller), so the category form below
 * covers the same refusal on a differently shaped route — a no-JS form POST that redirects rather
 * than an XHR that answers in JSON.
 */
final class CsrfRejectionKeepsTheSessionCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('csrf-session-survival-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeEstimate(FunctionalTester $I): Estimate
    {
        $company = (new Company())->setName('Csrf Session Co')->setCode('CSRF-' . uniqid());
        $I->haveInRepository($company);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('EST-' . uniqid())
            ->setSource('Admin')
            ->setSubtotal('100.00')
            ->setTax('5.00')
            ->setTotal('105.00');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $I->haveInRepository($estimate);

        return $estimate;
    }

    /** The admin is still the admin: a page only ROLE_ADMIN can see still renders, and is not login. */
    private function seeStillLoggedIn(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/estimate');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeInCurrentUrl('/admin/login');
    }

    public function aMissingTokenIsRefusedAndLeavesTheAdminLoggedIn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->makeEstimate($I);

        $I->sendAjaxPostRequest('/admin/estimate/log/add/' . $estimate->getId(), [
            'message' => 'Sent with no token at all',
        ]);

        $I->seeResponseCodeIs(403);
        $refusal = json_decode($I->grabPageSource(), true);
        $I->assertFalse($refusal['ok']);
        // By MESSAGE, not by "this quote has no log rows": since the status seam the fixture's own
        // Draft -> Submitted move leaves a row, so the bare absence claim would have become
        // untrue for a reason that has nothing to do with CSRF. The positive control on the same
        // table is the line under it — the fixture's row IS there, so the absence above is a real
        // observation rather than a query that matched nothing.
        $I->dontSeeInRepository(AuditLog::class, ['entityType' => 'Estimate', 'entityId' => $estimate->getId(), 'summary' => 'Sent with no token at all']);
        $I->seeInRepository(AuditLog::class, ['entityType' => 'Estimate', 'entityId' => $estimate->getId(), 'summary' => 'Status changed from Draft to Submitted.']);

        // The refusal must not have cleared the session cookie on its way out. seeCookie() cannot
        // show this — it reads the client's cookie jar, not the response's headers — so it is the
        // Set-Cookie header that gets asserted. See Helper\Functional::dontSeeSessionCookieCleared().
        $I->dontSeeSessionCookieCleared();
        $this->seeStillLoggedIn($I);
    }

    public function aGarbageTokenIsRefusedAndLeavesTheAdminLoggedIn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->makeEstimate($I);

        $I->sendAjaxPostRequest('/admin/estimate/log/add/' . $estimate->getId(), [
            '_token' => 'not-a-real-token',
            'message' => 'Sent with a forged token',
        ]);

        $I->seeResponseCodeIs(403);
        $I->dontSeeInRepository(AuditLog::class, ['entityType' => 'Estimate', 'entityId' => $estimate->getId(), 'summary' => 'Sent with a forged token']);
        $I->seeInRepository(AuditLog::class, ['entityType' => 'Estimate', 'entityId' => $estimate->getId(), 'summary' => 'Status changed from Draft to Submitted.']);

        $I->dontSeeSessionCookieCleared();
        $this->seeStillLoggedIn($I);
    }

    /**
     * The session that survives has to be the SAME one, not a fresh anonymous replacement: the
     * token minted before the refusal must still verify afterwards. This is what would break if the
     * rejection path ever started invalidating or migrating the session — the admin would appear
     * logged in and then fail CSRF forever, which is the more confusing half of the reported bug.
     */
    public function theSessionsOwnCsrfTokenStillWorksAfterARefusal(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->makeEstimate($I);

        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $token = $I->csrfToken();
        $I->assertNotSame('', $token);

        $I->sendAjaxPostRequest('/admin/estimate/log/add/' . $estimate->getId(), [
            '_token' => 'not-a-real-token',
            'message' => 'Refused',
        ]);
        $I->seeResponseCodeIs(403);

        $I->sendAjaxPostRequest('/admin/estimate/log/add/' . $estimate->getId(), [
            '_token' => $token,
            'message' => 'Accepted after the refusal',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeInRepository(AuditLog::class, [
            'entityType' => 'Estimate',
            'entityId' => $estimate->getId(),
            'summary' => 'Accepted after the refusal',
        ]);
    }

    /**
     * The no-JS shape of the same refusal, on a different controller: a form POST is sent back to
     * the page it came from with a flash, never to the login form.
     */
    public function aFormPostRefusalReturnsToThePageNotToLogin(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/category/create');
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest('/admin/category/create', [
            'name' => 'Csrf Refused Category',
            'status' => 'Visible',
        ]);

        $I->dontSeeInCurrentUrl('/admin/login');
        $I->dontSeeInRepository(ProductCategory::class, ['name' => 'Csrf Refused Category']);

        $I->dontSeeSessionCookieCleared();
        $this->seeStillLoggedIn($I);
    }

    /** The other half of the contract: a request with no session at all is still sent to login. */
    public function aGenuinelyUnauthenticatedPostStillGoesToTheLoginPage(FunctionalTester $I): void
    {
        $I->haveHttpHeader('Host', 'admin.localhost');

        $I->sendFormPostRequest('/admin/estimate/log/add/1', ['message' => 'From nobody']);

        $I->seeInCurrentUrl('/admin/login');
    }
}
