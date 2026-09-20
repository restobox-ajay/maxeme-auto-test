<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\EmailLog;
use App\Entity\ErrorLog;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin\SystemController: the email-log, error-log (+ detail), and audit-log (+ detail)
 *  listing pages (search, XHR/JSON mode), the ROLE_TECH_SUPPORT gate on error-log/error-detail/
 *  the database console, and its own render. */
final class AdminSystemCest
{
    private function loginAsAdmin(FunctionalTester $I, array $roles = []): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('admin-system-functional-test@example.test')
            ->setRoles($roles);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');

        return $admin;
    }

    public function emailLogListsEntriesAndAppliesSearch(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $matching = (new EmailLog())
            ->setTemplateCode('Password Reset')
            ->setRecipient('sys-email-match@example.test')
            ->setStatus('Sent');
        $I->haveInRepository($matching);

        $other = (new EmailLog())
            ->setTemplateCode('Order Confirmation')
            ->setRecipient('sys-email-other@example.test')
            ->setStatus('Sent');
        $I->haveInRepository($other);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/email-log');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Email Log');
        $I->see('sys-email-match@example.test');
        $I->see('sys-email-other@example.test');

        $I->amOnPage('/admin/email-log?q=sys-email-match');
        $I->seeResponseCodeIsSuccessful();
        $I->see('sys-email-match@example.test');
        $I->dontSee('sys-email-other@example.test');
    }

    public function emailLogAsXhrReturnsJsonWithRenderedRowsAndPagination(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $log = (new EmailLog())
            ->setTemplateCode('Invitation')
            ->setRecipient('sys-email-xhr@example.test')
            ->setStatus('Sent');
        $I->haveInRepository($log);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxGetRequest('/admin/email-log?q=sys-email-xhr');
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertStringContainsString('sys-email-xhr@example.test', $response['html']);
        $I->assertSame(1, $response['total']);
        $I->assertSame(1, $response['page']);
        $I->assertSame(1, $response['pages']);
    }

    public function errorLogIsBlockedForAPlainAdminButVisibleToTechSupport(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/error-log');
        $I->seeResponseCodeIs(403);
    }

    public function errorLogListsEntriesAndAppliesSearchForTechSupport(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $matching = (new ErrorLog())
            ->setLevel('error')
            ->setArea('sys-error-match-area')
            ->setMessage(json_encode(['message' => 'Boom'], JSON_THROW_ON_ERROR));
        $I->haveInRepository($matching);

        $other = (new ErrorLog())
            ->setLevel('warning')
            ->setArea('sys-error-other-area')
            ->setMessage(json_encode(['message' => 'Unrelated'], JSON_THROW_ON_ERROR));
        $I->haveInRepository($other);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/error-log');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Error Log');
        $I->see('sys-error-match-area');
        $I->see('sys-error-other-area');

        $I->amOnPage('/admin/error-log?q=sys-error-match-area');
        $I->seeResponseCodeIsSuccessful();
        $I->see('sys-error-match-area');
        $I->dontSee('sys-error-other-area');
    }

    public function errorDetailShowsDecodedPayloadAndRedirectsWhenNotFound(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $error = (new ErrorLog())
            ->setLevel('error')
            ->setArea('sys-error-detail-area')
            ->setMessage(json_encode([
                'method' => 'POST',
                'url' => '/detail/path',
                'message' => 'Detailed failure message',
                'file' => '/app/src/Foo.php',
                'line' => 42,
                'trace' => 'trace-line-one',
            ], JSON_THROW_ON_ERROR));
        $I->haveInRepository($error);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/error-log/' . $error->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('ERROR LOG DETAIL: ' . $error->getId());
        $I->see('Detailed failure message');
        $I->see('trace-line-one');

        $I->amOnPage('/admin/error-log/999999');
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin/error-log');
        $I->see('Error log entry could not be found.');
    }

    public function auditLogListsEntriesAndAppliesActorTypeFilter(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $adminEntry = (new AuditLog())
            ->setActorType('admin')
            ->setActorName('Sys Audit Admin')
            ->setArea('sys-audit-area')
            ->setEntityType('Company')
            ->setAction('update')
            ->setSummary('sys-audit-admin-summary');
        $I->haveInRepository($adminEntry);

        $systemEntry = (new AuditLog())
            ->setActorType('system')
            ->setActorName('System')
            ->setArea('sys-audit-area')
            ->setEntityType('Company')
            ->setAction('create')
            ->setSummary('sys-audit-system-summary');
        $I->haveInRepository($systemEntry);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/audit-log');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Audit Log');
        $I->see('sys-audit-admin-summary');
        $I->see('sys-audit-system-summary');

        $I->amOnPage('/admin/audit-log?filters[actorType]=admin');
        $I->seeResponseCodeIsSuccessful();
        $I->see('sys-audit-admin-summary');
        $I->dontSee('sys-audit-system-summary');
    }

    public function auditLogAsXhrReturnsJsonWithRenderedRowsAndPagination(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $entry = (new AuditLog())
            ->setActorType('admin')
            ->setActorName('Sys Audit Xhr Admin')
            ->setArea('sys-audit-xhr-area')
            ->setEntityType('Company')
            ->setAction('update')
            ->setSummary('sys-audit-xhr-summary');
        $I->haveInRepository($entry);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxGetRequest('/admin/audit-log?q=sys-audit-xhr-summary');
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertStringContainsString('sys-audit-xhr-summary', $response['html']);
        $I->assertSame(1, $response['total']);
    }

    /**
     * #496: the actor's name and an entity's numeric ID are now part of what `q` searches, so an
     * admin can find a row by who did it or by the record's ID without knowing its area or summary.
     * entityId matches whole rather than partially (see SystemController::auditLog() for why: DQL
     * has no CAST, so a LIKE against an integer column isn't expressible), so the search term has to
     * equal the ID exactly.
     */
    public function auditLogSearchMatchesActorNameAndEntityIdExactly(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $namedActor = (new AuditLog())
            ->setActorType('admin')
            ->setActorName('Searchable Actor Name')
            ->setArea('sys-audit-actor-search-area')
            ->setEntityType('Company')
            ->setAction('update')
            ->setSummary('sys-audit-actor-search-summary');
        $I->haveInRepository($namedActor);

        $otherActor = (new AuditLog())
            ->setActorType('admin')
            ->setActorName('Unrelated Actor')
            ->setArea('sys-audit-actor-search-area')
            ->setEntityType('Company')
            ->setAction('update')
            ->setSummary('sys-audit-actor-other-summary');
        $I->haveInRepository($otherActor);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/audit-log?q=Searchable+Actor+Name');
        $I->seeResponseCodeIsSuccessful();
        $I->see('sys-audit-actor-search-summary');
        $I->dontSee('sys-audit-actor-other-summary');

        $withId = (new AuditLog())
            ->setActorType('admin')
            ->setActorName('Entity Id Search Actor')
            ->setArea('sys-audit-entity-id-area')
            ->setEntityType('Company')
            ->setEntityId(424242)
            ->setAction('update')
            ->setSummary('sys-audit-entity-id-summary');
        $I->haveInRepository($withId);

        $wrongId = (new AuditLog())
            ->setActorType('admin')
            ->setActorName('Entity Id Search Actor')
            ->setArea('sys-audit-entity-id-area')
            ->setEntityType('Company')
            ->setEntityId(424243)
            ->setAction('update')
            ->setSummary('sys-audit-entity-wrong-id-summary');
        $I->haveInRepository($wrongId);

        $I->amOnPage('/admin/audit-log?q=424242');
        $I->seeResponseCodeIsSuccessful();
        $I->see('sys-audit-entity-id-summary');
        $I->dontSee('sys-audit-entity-wrong-id-summary');
    }

    /**
     * #496: the combined "Actor" and "Entity" columns each split into two — Actor/Type and Entity
     * Type/Entity ID — so the actor's name and an entity's numeric ID are each their own cell rather
     * than folded into a badge or a "#id" suffix next to something else.
     */
    public function auditLogListSplitsActorAndEntityIntoSeparateColumns(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $entry = (new AuditLog())
            ->setActorType('customer')
            ->setActorName('Split Column Actor')
            ->setArea('sys-audit-split-area')
            ->setEntityType('SplitColumnEntity')
            ->setEntityId(778899)
            ->setAction('update')
            ->setSummary('sys-audit-split-summary');
        $I->haveInRepository($entry);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/audit-log');
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('th[data-sort-field="actorName"]');
        $I->seeElement('th[data-sort-field="actorType"]');
        $I->seeElement('th[data-sort-field="entityType"]');
        $I->seeElement('th[data-sort-field="entityId"]');
        $I->see('Split Column Actor');
        $I->see('SplitColumnEntity');
        $I->see('778899');
    }

    /**
     * #496: the "View" action is a real link to a standalone, no-JavaScript-reachable page rather
     * than a button that only a client-side handler could act on — it has to work with scripting
     * off. The detail page renders the before/after diff table server-side; Twig autoescapes by
     * default, so a value that looks like markup (something an admin's own edit put into
     * dataBefore/dataAfter) reaches the page as inert text rather than being interpreted as HTML.
     * That is the point of moving this off the client: the escaping doesn't depend on every call
     * site remembering to do it.
     */
    public function auditLogDetailShowsFieldDiffEscapedAndRedirectsWhenNotFound(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $entry = (new AuditLog())
            ->setActorType('admin')
            ->setActorName('Detail Page Actor')
            ->setArea('sys-audit-detail-area')
            ->setEntityType('Company')
            ->setEntityId(555)
            ->setAction('update')
            ->setSummary('sys-audit-detail-summary')
            ->setDataBefore(json_encode(['status' => 'Draft', 'note' => '<script>alert(1)</script>'], JSON_THROW_ON_ERROR))
            ->setDataAfter(json_encode(['status' => 'Active', 'note' => '<script>alert(1)</script>'], JSON_THROW_ON_ERROR));
        $I->haveInRepository($entry);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/audit-log/' . $entry->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('AUDIT LOG DETAIL: ' . $entry->getId());
        $I->see('Detail Page Actor');
        $I->see('sys-audit-detail-summary');
        $I->see('Draft');
        $I->see('Active');
        // The script tag is on the page as text, not as an executable element: the raw markup
        // response never contains an unescaped "<script>".
        $I->dontSeeInSource('<script>alert(1)</script>');
        $I->seeInSource('&lt;script&gt;alert(1)&lt;/script&gt;');

        // The row's "View" link points straight at this page.
        $I->amOnPage('/admin/audit-log');
        $I->seeElement('a[href="/admin/audit-log/' . $entry->getId() . '"].js-audit-view-detail');

        $I->amOnPage('/admin/audit-log/999999');
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin/audit-log');
        $I->see('Audit log entry could not be found.');
    }

    /**
     * /admin/phpliteadmin was a placeholder page that said "Not wired up yet". It is now the real
     * console at /admin/db — see AdminDatabaseConsoleCest for the behaviour; these two keep asserting
     * the role gate from this suite's perspective.
     */
    public function theDatabaseConsoleIsBlockedForAPlainAdmin(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/db');
        $I->seeResponseCodeIs(403);
    }

    public function theDatabaseConsoleRendersForTechSupport(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, ['ROLE_TECH_SUPPORT']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/db');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Database Console');
    }
}
