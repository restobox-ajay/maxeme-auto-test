<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\AuditLog;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The Audit Log grid's filters as a no-JavaScript GET form: the URL is the whole state.
 *
 * The house rule is that a filtered grid must be reproducible from its address alone — paste the
 * link to somebody else and they see exactly what you saw. That has two halves, and only the first
 * was ever true here. SystemController::auditLog() has always read q, sort, dir, page, limit and
 * filters[actorType|area|action] straight out of the query string, so the *rows* were right. But
 * the controls that produce that query string had no name attributes and no enclosing form: the
 * query string was assembled by JavaScript out of data-filter-field attributes. With scripting off
 * the three selects and the search box did nothing at all, and even with scripting on a pasted URL
 * rendered the search box empty while the rows came back narrowed — the page showed one thing and
 * had done another, and nobody reading it could tell what narrowed the list without inspecting the
 * address bar.
 *
 * So these tests never submit a form and never send an XHR header. They request a URL the way a
 * pasted link arrives, and assert both halves at once: the rows that come back, and the state of
 * the controls that come back with them. A test that only checked the rows would have passed
 * against the old, unusable markup — which is precisely how this survived as long as it did.
 *
 * Kept separate from AdminSystemCest, which covers what SystemController's three log pages *query*
 * (search, the actorType filter, the XHR/JSON branch) and would still pass with the filter row
 * deleted outright, and from AdminDateFilterUtcWindowCest, which covers the UTC-window conversion
 * the Date filter shares with the Email Log's. The subject here is neither the query nor the
 * conversion but the URL-to-markup round trip, and it is the same argument for the Date box, the
 * three selects and the search box. It is the sibling of AdminEmailLogUrlFilterCest, which makes
 * the identical case for the Email Log grid.
 */
final class AdminAuditLogUrlFilterCest
{
    /** UTC-7 through August (PDT), so a Vancouver day starts at 07:00 UTC. */
    private const DISPLAY_TIMEZONE = 'America/Vancouver';

    /** 2026-08-06 20:00 in Vancouver. Display date: the 6th. Stored (UTC) date: the 7th. */
    private const BOUNDARY_UTC = '2026-08-07 03:00:00';

    /**
     * The timezone row is rolled back with the test's transaction, but AppSettings caches all()
     * for an hour in a pool that is not — so a later test would read a zone that no longer exists.
     */
    public function _after(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-audit-log-url-filter@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function setDisplayTimezone(FunctionalTester $I, string $timezone): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $setting = $em->getRepository(AppSetting::class)->findOneBy(['settingKey' => AppSettings::TIMEZONE_KEY])
            ?? (new AppSetting())
                ->setSettingKey(AppSettings::TIMEZONE_KEY)
                ->setName('Timezone')
                ->setCategory('General');

        $setting->setSettingValue($timezone);
        $em->persist($setting);
        $em->flush();
        // all() is cached for an hour; without this the write is invisible to the same process.
        $I->grabService(AppSettings::class)->clearCache();
    }

    /**
     * The Area and Action selects are built from DISTINCT values already in audit_log, so an option
     * only exists to be re-selected if some row carries that value. Every fixture here therefore
     * goes in through the same helper, and the values it is given are the values the assertions
     * expect back in the markup.
     *
     * occurredAt is stamped in AuditLog's constructor with no setter, so a fixture that has to land
     * on a specific calendar day (rather than whenever the test happens to run) is persisted
     * normally and then overwritten over the connection, the same way
     * AdminDateFilterUtcWindowCest::storedAt() does it for Estimate/EmailLog. refresh() matters: the
     * entity is still managed with the constructor's instant, and a later hydration would otherwise
     * render that stale value onto the page.
     */
    private function makeAuditLog(
        FunctionalTester $I,
        string $summary,
        string $actorType = 'admin',
        string $area = 'audit-url-area',
        string $action = 'update',
        ?string $occurredAtUtc = null,
    ): string {
        $entry = (new AuditLog())
            ->setActorType($actorType)
            ->setActorName('Audit Url Filter Actor')
            ->setArea($area)
            ->setEntityType('Company')
            ->setAction($action)
            ->setSummary($summary);
        $I->haveInRepository($entry);

        if ($occurredAtUtc !== null) {
            $em = $I->grabService(EntityManagerInterface::class);
            $em->getConnection()->executeStatement(
                'UPDATE audit_log SET occurred_at = ? WHERE id = ?',
                [$occurredAtUtc, (int) $entry->getId()],
            );
            $em->refresh($entry);
        }

        return $summary;
    }

    /**
     * A plain GET — no scripting, no XHR header — filters the grid on its own.
     *
     * Two filters at once, and two decoys that each match one of them, because a single filter
     * could be satisfied by the `q` search path that already worked; only a URL whose filters[]
     * pair narrows further than either half proves the filter row is what is being read.
     */
    public function aPlainUrlWithNoJavascriptFiltersTheGrid(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $wanted = $this->makeAuditLog($I, 'audit-url-wanted', 'admin', 'audit-url-area', 'update');
        $wrongActor = $this->makeAuditLog($I, 'audit-url-wrong-actor', 'system', 'audit-url-area', 'update');
        $wrongAction = $this->makeAuditLog($I, 'audit-url-wrong-action', 'admin', 'audit-url-area', 'create');

        $I->amOnPage('/admin/audit-log?filters[actorType]=admin&filters[action]=update');
        $I->seeResponseCodeIsSuccessful();
        $I->see($wanted);
        $I->dontSee($wrongActor);
        $I->dontSee($wrongAction);
    }

    /**
     * The same URL renders the controls holding the values it filtered by.
     *
     * This is the copy-paste half, and the half that was broken with JavaScript on as well as off.
     * All three selects plus the search box are asserted rather than a representative one: they are
     * four separate pieces of markup and the old page had all four wrong at once.
     *
     * The search term is chosen to match the Area of every fixture, so `q` genuinely participates
     * in narrowing rather than being an inert string that happens to render back.
     */
    public function theSameUrlRepopulatesEveryFilterControl(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        // Stamped onto 2026-08-06 so the filters[date] below narrows to it rather than to whatever
        // day the test happens to run on; UTC is the display timezone default in this test.
        $this->makeAuditLog($I, 'audit-url-repopulate', 'customer', 'audit-url-repop-area', 'delete', '2026-08-06 12:00:00');

        $I->amOnPage(
            '/admin/audit-log?q=audit-url-repop-area'
            . '&filters[date]=2026-08-06'
            . '&filters[actorType]=customer'
            . '&filters[area]=audit-url-repop-area'
            . '&filters[action]=delete'
        );
        $I->seeResponseCodeIsSuccessful();
        $I->see('audit-url-repopulate');

        $I->seeElement('input[name="q"][value="audit-url-repop-area"]');
        $I->seeElement('input[name="filters[date]"][value="2026-08-06"]');
        $I->seeOptionIsSelected('select[name="filters[actorType]"]', 'Customer');
        $I->seeOptionIsSelected('select[name="filters[area]"]', 'audit-url-repop-area');
        $I->seeOptionIsSelected('select[name="filters[action]"]', 'delete');
    }

    /**
     * With nothing in the URL the controls come back neutral rather than stuck on a previous value.
     *
     * The mirror image of the test above: repopulation that cannot also render "no filter" would
     * make the unfiltered grid claim to be filtered, which is the same lie in the other direction.
     * The actorType select is the one worth pinning because it is the only one whose empty option
     * carries an explicit selected ternary rather than falling out of a loop that matches nothing.
     */
    public function anUnfilteredUrlRendersTheControlsEmpty(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeAuditLog($I, 'audit-url-neutral', 'admin', 'audit-url-neutral-area', 'update');

        $I->amOnPage('/admin/audit-log');
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('input[name="q"][value=""]');
        $I->seeElement('input[name="filters[date]"][value=""]');
        $I->seeOptionIsSelected('select[name="filters[actorType]"]', 'All actors');
        $I->seeOptionIsSelected('select[name="filters[area]"]', 'All areas');
        $I->seeOptionIsSelected('select[name="filters[action]"]', 'All actions');
    }

    /**
     * The controls are a real GET form that works with scripting off.
     *
     * method="get" and a type="submit" button are the whole no-JavaScript story: without them the
     * page needs script to turn the controls into a query string, which is the defect. The Date box
     * is asserted as type="date" specifically, for the same reason as the Email Log's: it is what
     * makes "a whole calendar day" the only thing that can be typed, which is what lets the
     * controller skip a partial-input fallback branch (see SystemController::auditLog()). The
     * selects live in <th> cells and so cannot be nested inside the form element; they join it by
     * form="audit-log-filters-form", and that association is asserted per control because a select
     * that has a name but no form membership submits nothing at all.
     */
    public function theFilterControlsFormARealGetFormThatNeedsNoScripting(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/audit-log');
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('form#audit-log-filters-form[method="get"][action="/admin/audit-log"]');
        $I->seeElement('form#audit-log-filters-form button[type="submit"]');
        $I->seeElement('form#audit-log-filters-form input[name="q"]');
        $I->seeElement('input[type="date"][name="filters[date]"][form="audit-log-filters-form"]');
        // Sort, direction and page size ride along so a filter submit does not silently reset them.
        foreach (['page', 'limit', 'sort', 'dir'] as $state) {
            $I->seeElement('form#audit-log-filters-form input[type="hidden"][name="' . $state . '"]');
        }
        // The controls in the table cells cannot nest inside the form, so they join it by id.
        foreach (['date', 'actorType', 'area', 'action'] as $field) {
            $I->seeElement('[name="filters[' . $field . ']"][form="audit-log-filters-form"]');
        }
        // The JS hook the shared table script reads elsewhere stays on every control.
        foreach (['date', 'actorType', 'area', 'action'] as $field) {
            $I->seeElement('[name="filters[' . $field . ']"][data-filter-field="' . $field . '"]');
        }
    }

    /**
     * A submitted filter keeps the sort, direction and page size that were already in the URL.
     *
     * Filtering is a fresh GET, so anything not carried by a hidden field is lost — an admin who
     * sorted by Occurred At ascending and then picked an Area would silently be dropped back to the
     * default id/desc ordering. Asserting the *rendered* hidden values rather than round-tripping a
     * submit is what pins them to the current state instead of to the defaults, which is the only
     * version of this assertion that can fail: hardcoded defaults would satisfy a submit test.
     */
    public function submittingAFilterCarriesTheSortDirectionAndPageSizeAlong(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeAuditLog($I, 'audit-url-gridstate', 'admin', 'audit-url-gridstate-area', 'update');

        $I->amOnPage('/admin/audit-log?sort=occurredAt&dir=asc&limit=20&filters[actorType]=admin');
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('form#audit-log-filters-form input[type="hidden"][name="sort"][value="occurredAt"]');
        $I->seeElement('form#audit-log-filters-form input[type="hidden"][name="dir"][value="asc"]');
        $I->seeElement('form#audit-log-filters-form input[type="hidden"][name="limit"][value="20"]');
        // Page resets to 1 deliberately: a narrower result set may not have the page you were on.
        $I->seeElement('form#audit-log-filters-form input[type="hidden"][name="page"][value="1"]');
        // And the filter that was in the URL is still shown, alongside the grid state it kept.
        $I->seeOptionIsSelected('select[name="filters[actorType]"]', 'Admin');
    }

    /** The Reset link is the escape hatch that makes the filters safe to use at all — with state
     *  in the URL, clearing it has to be a link, since there is no script left to blank the boxes. */
    public function theResetLinkClearsTheUrl(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/audit-log?filters[actorType]=admin');
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('form#audit-log-filters-form a[href="/admin/audit-log"]');
    }

    /**
     * A pasted date URL lands on the display day, not the stored UTC day — the same window
     * arithmetic AdminDateFilterUtcWindowCest exercises for the Quotes and Email Log grids, exercised
     * here for the markup round trip: the picker comes back holding the day that was filtered on.
     */
    public function aPastedDateUrlStillConvertsThroughTheDisplayTimezoneWindowAndRepopulates(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->setDisplayTimezone($I, self::DISPLAY_TIMEZONE);

        $boundary = $this->makeAuditLog($I, 'audit-url-date-boundary', 'admin', 'audit-url-date-area', 'update', self::BOUNDARY_UTC);

        $I->amOnPage('/admin/audit-log?filters[date]=2026-08-06');
        $I->seeResponseCodeIsSuccessful();
        $I->see($boundary);
        $I->seeElement('input[name="filters[date]"][value="2026-08-06"]');

        $I->amOnPage('/admin/audit-log?filters[date]=2026-08-07');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee($boundary);
    }
}
