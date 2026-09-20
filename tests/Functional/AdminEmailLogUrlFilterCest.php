<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\EmailLog;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The Email Log grid's filters as a no-JavaScript GET form: the URL is the whole state.
 *
 * The house rule is that a filtered grid must be reproducible from its address alone — paste the
 * link to somebody else and they see exactly what you saw. That has two halves, and only the first
 * was ever true here. SystemController::emailLog() has always read every filter out of the query
 * string, so the *rows* were right. But the controls that produce that query string had no name
 * attributes, no repopulation and no enclosing form: the query string was assembled by JavaScript
 * from data-filter-field attributes, so with scripting off the boxes did nothing at all, and even
 * with scripting on a pasted URL rendered every box empty — the grid showed filtered rows while
 * claiming, on screen, to be unfiltered. An admin looking at that page could not tell what it was
 * filtered by without reading the address bar.
 *
 * So these tests never submit a form and never send an XHR header. They request a URL the way a
 * pasted link arrives, and assert both halves: the rows that come back, and the state of the
 * controls that come back with them. Anything that only exercises the rows would still pass with
 * the markup in its old, unusable shape — which is exactly how this went unnoticed.
 *
 * Kept separate from AdminSystemCest (which covers what SystemController's log pages *query*) and
 * from AdminDateFilterUtcWindowCest (which covers the one date conversion both admin grids share):
 * the subject here is neither the query nor the conversion but the URL-to-markup round trip, and
 * it is the same argument for all six filters plus the search box.
 */
final class AdminEmailLogUrlFilterCest
{
    /** UTC-7 through August (PDT), so a Vancouver day starts at 07:00 UTC. */
    private const DISPLAY_TIMEZONE = 'America/Vancouver';

    /** 2026-08-06 20:00 in Vancouver. Display date: the 6th. Stored (UTC) date: the 7th. */
    private const BOUNDARY_UTC = '2026-08-07 03:00:00';

    /** 2026-08-07 05:00 in Vancouver. Display date and stored date agree on the 7th. */
    private const CONTROL_UTC = '2026-08-07 12:00:00';

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
        $admin = (new AdminUser())->setEmail('admin-email-log-url-filter@example.test');
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
     * EmailLog stamps createdAt in its constructor and has no setCreatedAt(), so a row that has to
     * sit at an exact instant is persisted normally and then overwritten over the connection. The
     * refresh() matters: the entity is still managed with the constructor's instant, and a later
     * hydration would otherwise render that stale value onto the page.
     */
    private function makeEmailLog(
        FunctionalTester $I,
        string $suffix,
        string $templateCode = 'Order Confirmation',
        string $status = 'Sent',
        ?string $storedUtc = null,
        ?string $body = null,
    ): string {
        $log = (new EmailLog())
            ->setTemplateCode($templateCode)
            ->setRecipient('urlfilter-' . $suffix . '@example.test')
            ->setStatus($status);
        if ($body !== null) {
            $log->setBody($body);
        }
        $I->haveInRepository($log);

        if ($storedUtc !== null) {
            $em = $I->grabService(EntityManagerInterface::class);
            $em->getConnection()->executeStatement(
                'UPDATE email_log SET created_at = ? WHERE id = ?',
                [$storedUtc, (int) $log->getId()],
            );
            $em->refresh($log);
        }

        return $log->getRecipient();
    }

    /**
     * A plain GET — no scripting, no XHR header — filters the grid on its own.
     *
     * Two filters at once, because a single one could be satisfied by the search box's `q` path
     * that already worked; only the filters[] array proves the filter row is what is being read.
     */
    public function aPlainUrlWithNoJavascriptFiltersTheGrid(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $wanted = $this->makeEmailLog($I, 'wanted', 'Order Confirmation', 'Sent');
        $wrongStatus = $this->makeEmailLog($I, 'wrong-status', 'Order Confirmation', 'Failed');
        $wrongRecipient = $this->makeEmailLog($I, 'other-party', 'Order Confirmation', 'Sent');

        $I->amOnPage('/admin/email-log?filters[to]=urlfilter-wanted&filters[status]=Sent');
        $I->seeResponseCodeIsSuccessful();
        $I->see($wanted);
        $I->dontSee($wrongStatus);
        $I->dontSee($wrongRecipient);
    }

    /**
     * The same URL renders the controls holding the values it filtered by.
     *
     * This is the copy-paste half, and the half that was broken with JavaScript on as well as off.
     * Every one of the six filters plus the search box is asserted rather than a representative
     * sample: they are six separate pieces of markup, and the old page had all six wrong at once.
     */
    public function theSameUrlRepopulatesEveryFilterControl(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeEmailLog($I, 'repopulate', 'Order Confirmation', 'Sent', null, 'shipment on its way');

        $I->amOnPage(
            '/admin/email-log?q=urlfilter'
            . '&filters[date]=2026-08-06'
            . '&filters[module]=order'
            . '&filters[to]=urlfilter-repopulate'
            . '&filters[subject]=Order'
            . '&filters[body]=shipment'
            . '&filters[status]=Sent'
        );
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('input[name="q"][value="urlfilter"]');
        $I->seeElement('input[name="filters[date]"][value="2026-08-06"]');
        $I->seeElement('input[name="filters[to]"][value="urlfilter-repopulate"]');
        $I->seeElement('input[name="filters[subject]"][value="Order"]');
        $I->seeElement('input[name="filters[body]"][value="shipment"]');
        $I->seeOptionIsSelected('select[name="filters[module]"]', 'Order');
        $I->seeOptionIsSelected('select[name="filters[status]"]', 'Sent');
    }

    /**
     * The controls are a real GET form that works with scripting off.
     *
     * The date box is asserted to be type="date" specifically: it is what makes "a whole calendar
     * day" the only thing the user can enter, which is in turn what lets the controller drop its
     * partial-input branch (see SystemController::emailLog()).
     */
    public function theFilterControlsFormARealGetFormThatNeedsNoScripting(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/email-log');
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('form#email-log-filters-form[method="get"][action="/admin/email-log"]');
        $I->seeElement('form#email-log-filters-form button[type="submit"]');
        $I->seeElement('input[type="date"][name="filters[date]"][form="email-log-filters-form"]');
        // Sort, direction and page size ride along so a filter submit does not silently reset them.
        foreach (['page', 'limit', 'sort', 'dir'] as $state) {
            $I->seeElement('form#email-log-filters-form input[type="hidden"][name="' . $state . '"]');
        }
        // The controls in the table cells cannot nest inside the form, so they join it by id.
        foreach (['date', 'module', 'to', 'subject', 'body', 'status'] as $field) {
            $I->seeElement('[name="filters[' . $field . ']"][form="email-log-filters-form"]');
        }
    }

    /**
     * A submitted filter keeps the sort and page size that were already in the URL.
     *
     * Filtering is a new GET, so anything not carried by a hidden field is lost. Asserting the
     * rendered hidden values (rather than round-tripping a submit) is what pins them to the
     * *current* state instead of to the defaults.
     */
    public function submittingAFilterCarriesTheSortAndPageSizeAlong(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/email-log?sort=to&dir=asc&limit=20&filters[status]=Sent');
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('form#email-log-filters-form input[type="hidden"][name="sort"][value="to"]');
        $I->seeElement('form#email-log-filters-form input[type="hidden"][name="dir"][value="asc"]');
        $I->seeElement('form#email-log-filters-form input[type="hidden"][name="limit"][value="20"]');
    }

    /**
     * A pasted date URL still lands on the display day, not the stored UTC day.
     *
     * Same fixture shape as AdminDateFilterUtcWindowCest — a row at 2026-08-07 03:00 UTC is the
     * 6th in Vancouver — because turning the box into a date picker must not have moved the
     * conversion: the picker submits Y-m-d, which is precisely what parseLogDateFilter() and
     * BusinessDate::localDayRangeUtc() take. The seventh is asserted too, so a window widened to
     * span both days cannot pass.
     */
    public function aPastedDateUrlStillConvertsThroughTheDisplayTimezoneWindow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->setDisplayTimezone($I, self::DISPLAY_TIMEZONE);

        $boundary = $this->makeEmailLog($I, 'date-boundary', 'Order Confirmation', 'Sent', self::BOUNDARY_UTC);
        $control = $this->makeEmailLog($I, 'date-control', 'Order Confirmation', 'Sent', self::CONTROL_UTC);

        $I->amOnPage('/admin/email-log?filters[date]=2026-08-06');
        $I->seeResponseCodeIsSuccessful();
        $I->see($boundary);
        $I->dontSee($control);
        // And the picker comes back holding the day that was filtered on, not the stored UTC one.
        $I->seeElement('input[name="filters[date]"][value="2026-08-06"]');

        $I->amOnPage('/admin/email-log?filters[date]=2026-08-07');
        $I->seeResponseCodeIsSuccessful();
        $I->see($control);
        $I->dontSee($boundary);
    }
}
