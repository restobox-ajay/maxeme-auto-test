<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\EmailLog;
use App\Entity\Estimate;
use App\Service\DocumentActor;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The three admin grid filters that search a UTC timestamp column by a calendar day the admin
 * typed: the Quotes grid's Created filter (/admin/estimate, filters[createdAt] over
 * Estimate.createdAt), the Email Log's Date filter (/admin/email-log, filters[date] over
 * EmailLog.createdAt), and the Audit Log's Date filter (/admin/audit-log, filters[date] over
 * AuditLog.occurredAt).
 *
 * All three are the same mechanism seen three times, which is why they are tested together in one
 * file rather than split across AdminEstimateCest and AdminSystemCest: each takes the typed day
 * through BusinessDate::localDayRangeUtc() to get the [start, end) pair of UTC instants that day
 * occupies in the display timezone, and compares the stored column against that window. The
 * argument for why the conversion has to be there is one argument, and it belongs in one place.
 *
 * The failure being guarded is not "the filter returns nothing". It is quieter than that. Storage
 * is UTC everywhere (Kernel pins the process to it) but every date the admin reads on these grids
 * is rendered in the configured display zone by DisplayTimezoneSubscriber, so for any shop west of
 * UTC the last hours of every local day are stored under *tomorrow's* UTC date. Compare the typed
 * day against the raw stored value and those rows become unfindable: the admin sees "06-08-2026"
 * in the Created column, types the sixth into the filter directly underneath it, and the row
 * vanishes — while a row they cannot see under that date, one genuinely belonging to the seventh,
 * is what comes back. Nothing errors, the grid just quietly lies about a few hours of every day.
 *
 * So every test here pins the display zone to America/Vancouver (UTC-7 in August) and hangs on a
 * row stored at 2026-08-07 03:00:00 UTC, which is 2026-08-06 20:00 in Vancouver: its display date
 * is the sixth, its UTC date is the seventh, and the two can never be confused for each other.
 * Filtering the sixth must find it and filtering the seventh must not — both halves are asserted
 * every time, because dropping the conversion fails the first and "fixing" that by widening the
 * window to span both days fails the second. A control row on a genuinely different display day
 * rides along so the filter is shown to exclude as well as include.
 *
 * Unparseable input is the one thing all three filters treat identically — by ignoring it. See
 * theEmailLogIgnoresInputThatIsNotAWholeCalendarDay() below for why the Email Log's old fallback
 * branch went; the Audit Log's Date filter never had one to remove.
 */
final class AdminDateFilterUtcWindowCest
{
    /** UTC-7 through August (PDT), so a Vancouver day starts at 07:00 UTC and ends at 07:00 the next. */
    private const DISPLAY_TIMEZONE = 'America/Vancouver';

    /** 2026-08-06 20:00 in Vancouver. Display date: the 6th. Stored (UTC) date: the 7th. */
    private const BOUNDARY_UTC = '2026-08-07 03:00:00';

    /** 2026-08-07 05:00 in Vancouver. Display date and stored date agree on the 7th. */
    private const CONTROL_UTC = '2026-08-07 12:00:00';

    /**
     * The timezone row this writes is rolled back with the test's transaction, but AppSettings
     * caches all() for an hour in a pool that is not — so without this a later test in the same
     * process reads a zone that no longer exists in the database.
     */
    public function _after(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-date-filter-utc@example.test');
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
     * Force a row's stored instant to an exact UTC value.
     *
     * Neither Estimate (via AbstractSalesDocument) nor EmailLog has a setCreatedAt() — createdAt is
     * stamped in the constructor and read-only afterwards, which is correct and is not going to be
     * relaxed for a test's convenience. So the row is persisted normally and then overwritten over
     * the connection, in the format Doctrine's SQLite DATETIME mapping uses. The refresh() is not
     * optional: the entity is still managed with the constructor's instant in the identity map, and
     * a later hydration would keep that stale value and render the wrong date on the page.
     */
    private function storedAt(FunctionalTester $I, object $entity, string $table, int $id, string $utc): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            \sprintf('UPDATE %s SET created_at = ? WHERE id = ?', $table),
            [$utc, $id],
        );
        $em->refresh($entity);
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())->setName('UTC Window Co')->setCode('UTCWIN-' . uniqid());
        $I->haveInRepository($company);

        return $company;
    }

    private function makeEstimate(FunctionalTester $I, Company $company, string $suffix, string $utc): string
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('UTCWIN-QT-' . $suffix . '-' . uniqid())
            ->setTotal('25.00');
        $estimate->setStatus('Draft', DocumentActor::system());
        $I->haveInRepository($estimate);

        $this->storedAt($I, $estimate, 'estimate', (int) $estimate->getId(), $utc);

        return $estimate->getDocumentNumber();
    }

    private function makeEmailLog(FunctionalTester $I, string $suffix, string $utc): string
    {
        $log = (new EmailLog())
            ->setTemplateCode('Order Confirmation')
            ->setRecipient('utcwin-' . $suffix . '@example.test')
            ->setStatus('Sent');
        $I->haveInRepository($log);

        $this->storedAt($I, $log, 'email_log', (int) $log->getId(), $utc);

        return $log->getRecipient();
    }

    /** AuditLog.occurredAt is the column under test; occurred_at is the table's column name. */
    private function makeAuditLog(FunctionalTester $I, string $suffix, string $utc): string
    {
        $summary = 'utcwin-audit-' . $suffix;
        $log = (new AuditLog())
            ->setActorType('admin')
            ->setActorName('UTC Window Actor')
            ->setArea('utc-window-area')
            ->setEntityType('Company')
            ->setAction('update')
            ->setSummary($summary);
        $I->haveInRepository($log);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            'UPDATE audit_log SET occurred_at = ? WHERE id = ?',
            [$utc, (int) $log->getId()],
        );
        $em->refresh($log);

        return $summary;
    }

    /**
     * The Quotes grid finds the quote by the date it shows, not by the date it stores.
     *
     * The seventh is asserted as well as the sixth so that a window widened to cover both days —
     * the obvious wrong fix once the first assertion starts failing — cannot pass either.
     */
    public function theQuoteGridFiltersOnTheDisplayDayNotTheStoredUtcDay(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->setDisplayTimezone($I, self::DISPLAY_TIMEZONE);
        $company = $this->makeCompany($I);

        $boundary = $this->makeEstimate($I, $company, 'BOUNDARY', self::BOUNDARY_UTC);
        $control = $this->makeEstimate($I, $company, 'CONTROL', self::CONTROL_UTC);

        $I->amOnPage('/admin/estimate?filters[createdAt]=2026-08-06');
        $I->seeResponseCodeIsSuccessful();
        $I->see($boundary);
        $I->dontSee($control);

        $I->amOnPage('/admin/estimate?filters[createdAt]=2026-08-07');
        $I->seeResponseCodeIsSuccessful();
        $I->see($control);
        $I->dontSee($boundary);
    }

    /**
     * Both grids print the same day in their date column that their filter box accepts for it.
     *
     * Those columns are rendered by Twig's date filter, which DisplayTimezoneSubscriber has pointed
     * at the configured zone — so this is the admin's actual experience of the row, and the whole
     * reason the filter has to agree with it. The Email Log prints a time as well, which pins the
     * conversion exactly: 03:00 UTC read as 8:00 PM the previous evening.
     *
     * If either of these ever prints the seventh, the premise of every other test in this file is
     * gone and they should be re-derived rather than patched.
     */
    public function bothGridsPrintTheSameDayTheyFilterOn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->setDisplayTimezone($I, self::DISPLAY_TIMEZONE);
        $quote = $this->makeEstimate($I, $this->makeCompany($I), 'PRINTED', self::BOUNDARY_UTC);
        $log = $this->makeEmailLog($I, 'log-printed', self::BOUNDARY_UTC);

        $I->amOnPage('/admin/estimate?filters[documentNumber]=' . $quote);
        $I->seeResponseCodeIsSuccessful();
        $I->see($quote);
        $I->see('2026-08-06');
        $I->dontSee('2026-08-07');

        $I->amOnPage('/admin/email-log?filters[to]=' . urlencode($log));
        $I->seeResponseCodeIsSuccessful();
        $I->see($log);
        $I->see('06-08-2026 8:00 PM');
        $I->dontSee('07-08-2026');
    }

    /**
     * A date typed the American way lands on the same day, window and all.
     *
     * parseEstimateDateFilter() accepts m/d/Y among others, and the conversion happens after
     * parsing — so an admin who types 08/06/2026 into the box must get exactly what 2026-08-06
     * gives, including the boundary row that only the timezone conversion reaches.
     */
    public function theQuoteGridAcceptsANonIsoDateAndStillConvertsIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->setDisplayTimezone($I, self::DISPLAY_TIMEZONE);
        $company = $this->makeCompany($I);

        $boundary = $this->makeEstimate($I, $company, 'USFMT', self::BOUNDARY_UTC);
        $control = $this->makeEstimate($I, $company, 'USFMTCTL', self::CONTROL_UTC);

        $I->amOnPage('/admin/estimate?filters[createdAt]=' . urlencode('08/06/2026'));
        $I->seeResponseCodeIsSuccessful();
        $I->see($boundary);
        $I->dontSee($control);
    }

    /** The Email Log's Date filter, over the same window arithmetic on a different entity. */
    public function theEmailLogFiltersOnTheDisplayDayNotTheStoredUtcDay(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->setDisplayTimezone($I, self::DISPLAY_TIMEZONE);

        $boundary = $this->makeEmailLog($I, 'log-boundary', self::BOUNDARY_UTC);
        $control = $this->makeEmailLog($I, 'log-control', self::CONTROL_UTC);

        $I->amOnPage('/admin/email-log?filters[date]=2026-08-06');
        $I->seeResponseCodeIsSuccessful();
        $I->see($boundary);
        $I->dontSee($control);

        $I->amOnPage('/admin/email-log?filters[date]=2026-08-07');
        $I->seeResponseCodeIsSuccessful();
        $I->see($control);
        $I->dontSee($boundary);
    }

    /** Same non-ISO input, same day: parseLogDateFilter() accepts the same five formats. */
    public function theEmailLogAcceptsANonIsoDateAndStillConvertsIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->setDisplayTimezone($I, self::DISPLAY_TIMEZONE);

        $boundary = $this->makeEmailLog($I, 'log-usfmt', self::BOUNDARY_UTC);
        $control = $this->makeEmailLog($I, 'log-usfmt-ctl', self::CONTROL_UTC);

        $I->amOnPage('/admin/email-log?filters[date]=' . urlencode('08/06/2026'));
        $I->seeResponseCodeIsSuccessful();
        $I->see($boundary);
        $I->dontSee($control);
    }

    /**
     * The Audit Log's Date filter, over occurredAt rather than createdAt but the same window
     * arithmetic: occurred_at is stored UTC and printed in the display timezone exactly like the
     * other two columns, so a boundary row is found or missed by the same rule.
     */
    public function theAuditLogFiltersOnTheDisplayDayNotTheStoredUtcDay(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->setDisplayTimezone($I, self::DISPLAY_TIMEZONE);

        $boundary = $this->makeAuditLog($I, 'log-boundary', self::BOUNDARY_UTC);
        $control = $this->makeAuditLog($I, 'log-control', self::CONTROL_UTC);

        $I->amOnPage('/admin/audit-log?filters[date]=2026-08-06');
        $I->seeResponseCodeIsSuccessful();
        $I->see($boundary);
        $I->dontSee($control);

        $I->amOnPage('/admin/audit-log?filters[date]=2026-08-07');
        $I->seeResponseCodeIsSuccessful();
        $I->see($control);
        $I->dontSee($boundary);
    }

    /** Same non-ISO input, same day: parseLogDateFilter() accepts the same five formats. */
    public function theAuditLogAcceptsANonIsoDateAndStillConvertsIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->setDisplayTimezone($I, self::DISPLAY_TIMEZONE);

        $boundary = $this->makeAuditLog($I, 'log-usfmt', self::BOUNDARY_UTC);
        $control = $this->makeAuditLog($I, 'log-usfmt-ctl', self::CONTROL_UTC);

        $I->amOnPage('/admin/audit-log?filters[date]=' . urlencode('08/06/2026'));
        $I->seeResponseCodeIsSuccessful();
        $I->see($boundary);
        $I->dontSee($control);
    }

    /**
     * Unparseable input is ignored rather than guessed at, exactly as the Email Log's Date filter
     * ignores it (see theEmailLogIgnoresInputThatIsNotAWholeCalendarDay() for why) — the Audit
     * Log's never had a CAST-based fallback to begin with, so there is nothing to remove here, only
     * the same behaviour to pin down. The September fixture is the same trap: stored just after
     * midnight UTC on the first, printed on the 31st in Vancouver, so a raw substring match on
     * '2026-08' would misfile it.
     */
    public function theAuditLogIgnoresInputThatIsNotAWholeCalendarDay(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->setDisplayTimezone($I, self::DISPLAY_TIMEZONE);

        $august = $this->makeAuditLog($I, 'partial-boundary', self::BOUNDARY_UTC);
        $september = $this->makeAuditLog($I, 'partial-september', '2026-09-01 03:00:00');

        foreach (['2026-08', '2026', '6 Aug 2026'] as $partial) {
            $I->amOnPage('/admin/audit-log?filters[date]=' . urlencode($partial));
            $I->seeResponseCodeIsSuccessful();
            $I->see($august);
            $I->see($september);
        }

        $I->amOnPage('/admin/audit-log?filters[date]=2026-08-06');
        $I->seeResponseCodeIsSuccessful();
        $I->see($august);
        $I->dontSee($september);
    }

    /**
     * Input that is not a whole calendar day is ignored — the grid loads unfiltered rather than
     * guessing, erroring, or answering a different question than the one asked.
     *
     * This test used to assert a 500, and recorded why: the Email Log had a second branch for
     * unparseable input that ran `CAST(e.createdAt AS string) LIKE '%input%'`, and CAST is not a
     * DQL function, so Doctrine's parser rejected it before any database saw it. That branch has
     * since been deleted, so the 500 is gone with it — but it was not deleted merely because it
     * threw. Even repaired into something DQL could express, it was answering the wrong question:
     * a substring match runs against the *raw UTC* string, so '2026-08' would have meant
     * August-in-UTC while every date on the page is printed in the display zone. The rows in the
     * seven hours either side of each month boundary would have been sorted into the wrong month —
     * the precise bug the window conversion above exists to prevent, reintroduced on the branch
     * next door. The September fixture below is that case: stored 2026-09-01 03:00 UTC, printed
     * 2026-08-31 in Vancouver, so the two readings of "August" disagree about it.
     *
     * Ignoring is also what makes the two grids consistent: EstimateController::index() has never
     * had a fallback for createdAt. And the Email Log's Date box is now a date picker, so partial
     * input can no longer be produced by half-finished typing — only by hand-editing the URL.
     */
    public function theEmailLogIgnoresInputThatIsNotAWholeCalendarDay(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->setDisplayTimezone($I, self::DISPLAY_TIMEZONE);

        $august = $this->makeEmailLog($I, 'log-partial-boundary', self::BOUNDARY_UTC);
        // 2026-08-31 20:00 in Vancouver: displays in August, stored in September.
        $september = $this->makeEmailLog($I, 'log-partial-september', '2026-09-01 03:00:00');

        foreach (['2026-08', '2026', '6 Aug 2026'] as $partial) {
            $I->amOnPage('/admin/email-log?filters[date]=' . urlencode($partial));
            $I->seeResponseCodeIsSuccessful();
            // Ignored, not applied: both rows are still here, including the one whose stored month
            // and displayed month disagree and which any substring match would have mis-filed.
            $I->see($august);
            $I->see($september);
        }

        // The parseable branch on the same grid still narrows, so "ignored" is a property of the
        // unparseable input and not of the filter having stopped working.
        $I->amOnPage('/admin/email-log?filters[date]=2026-08-06');
        $I->seeResponseCodeIsSuccessful();
        $I->see($august);
        $I->dontSee($september);
    }
}
