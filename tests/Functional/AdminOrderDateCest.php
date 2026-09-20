<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\SalesOrder;
use App\Service\DocumentActor;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A document's Order Date: where it comes from, where it can be changed, and that it is a calendar
 * date rather than an instant.
 *
 * document_date used to be stamped by AbstractSalesDocument's constructor with new
 * \DateTimeImmutable('today'), which reads PHP's ambient default — pinned to UTC by Kernel. For any
 * shop configured to display a zone behind UTC that stamped tomorrow's date for the last hours of
 * every day, and the DATE column it landed in was then converted back on the way out, so the date
 * shown could differ from the date stored as well as from the date the admin was living in.
 *
 * It is now a 'Y-m-d' string filled at persist time by SalesDocumentDateStamp, from the display
 * timezone. The tests below drive that through the real listener and the real database.
 */
final class AdminOrderDateCest
{
    /**
     * Two zones 25 hours apart, so their calendar dates never agree at any instant.
     *
     * That is the whole point: an assertion that a date matches "today in Vancouver" only fails
     * against a UTC-hardcoded stamp during the hours the two happen to differ, so it would pass
     * with the bug present for most of the day. Comparing these two against each other cannot.
     */
    private const FAR_EAST = 'Pacific/Kiritimati'; // UTC+14
    private const FAR_WEST = 'Pacific/Niue';       // UTC-11

    /**
     * The timezone rows these tests write are rolled back with the test's transaction, but
     * AppSettings caches all() for an hour in a pool that is not — so without this a later test in
     * the same process reads a zone that no longer exists in the database.
     */
    public function _after(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-order-date@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())->setName($name)->setCode('ODATE-' . uniqid());
        $I->haveInRepository($company);

        return $company;
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

    private function persistOrder(FunctionalTester $I, Company $company): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ODATE-ORD-' . uniqid())
            ->setTotal('10.00');
        $I->haveInRepository($order);

        return $order;
    }

    /**
     * The date as the database actually holds it.
     *
     * Read over the connection rather than by clearing the EntityManager and re-finding: clear()
     * detaches the Company fixture the test is still holding, and a second order built against it
     * afterwards fails as an unpersisted association.
     */
    private function storedDate(FunctionalTester $I, int $orderId): ?string
    {
        $value = $I->grabService(EntityManagerInterface::class)
            ->getConnection()
            ->fetchOne('SELECT document_date FROM sales_order WHERE id = ?', [$orderId]);

        return $value === false ? null : (string) $value;
    }

    /** The stored value is a bare calendar date, with nothing in it that could be converted. */
    public function aNewOrderIsDatedWithAPlainCalendarDate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->persistOrder($I, $this->makeCompany($I, 'Order Date Shape Co'));

        $stored = $this->storedDate($I, (int) $order->getId());

        $I->assertIsString($stored);
        $I->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $stored);
    }

    /**
     * The bug, stated as a test: the date comes from the configured timezone, not from UTC.
     *
     * The same code path runs twice under two zones whose calendar dates can never match. A stamp
     * that ignored the setting would produce the same string both times.
     */
    public function anOrderIsDatedInTheShopsOwnTimezoneNotUtc(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Order Date Timezone Co');

        $this->setDisplayTimezone($I, self::FAR_EAST);
        $east = $this->persistOrder($I, $company);
        $eastDate = $this->storedDate($I, (int) $east->getId());

        $this->setDisplayTimezone($I, self::FAR_WEST);
        $west = $this->persistOrder($I, $company);
        $westDate = $this->storedDate($I, (int) $west->getId());

        $I->assertSame(
            (new \DateTimeImmutable('now', new \DateTimeZone(self::FAR_EAST)))->format('Y-m-d'),
            $eastDate,
        );
        $I->assertNotSame($eastDate, $westDate, 'the stamp ignored the configured timezone');
    }

    /** A quote is dated the same way — document_date is shared, and so is the listener. */
    public function aNewQuoteIsDatedTheSameWay(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Date Timezone Co');
        $this->setDisplayTimezone($I, self::FAR_EAST);

        $quote = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('ODATE-QT-' . uniqid());
        $quote->setStatus('Draft', DocumentActor::system());
        $I->haveInRepository($quote);

        $I->assertSame(
            (new \DateTimeImmutable('now', new \DateTimeZone(self::FAR_EAST)))->format('Y-m-d'),
            $quote->getDocumentDate(),
        );
    }

    /** A date the caller chose is what the document is dated. The stamp only fills a blank. */
    public function anExplicitlySetDateIsNeverOverwritten(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Order Date Explicit Co');

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ODATE-ORD-' . uniqid())
            ->setTotal('10.00')
            ->setDocumentDate('2019-03-04');
        $I->haveInRepository($order);

        $I->assertSame('2019-03-04', $this->storedDate($I, (int) $order->getId()));
    }

    /** The field the change moved onto the edit form, doing what it is there for. */
    public function theEditFormSavesAChangedOrderDate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Order Date Edit Co');
        $order = $this->persistOrder($I, $company);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#order-form input[type="date"][name="document_date"]');

        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Draft',
            'document_date' => '2026-01-02',
            'lines' => [['name' => 'Order date line', 'qty' => '1', 'price' => '5.00']],
            'save_mode' => 'draft_recalc',
        ]);

        $I->assertSame('2026-01-02', $this->storedDate($I, (int) $order->getId()));
    }

    /**
     * A blank box leaves the order dated as it was.
     *
     * document_date is NOT NULL and an order is always dated something, so unlike Invoice Date there is
     * no "clear it" for an empty field to mean — a save that only changed the status must not
     * silently blank the date, and a NULL would fail the flush outright.
     */
    public function aBlankOrderDateOnSaveLeavesTheDateAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Order Date Blank Co');
        $order = $this->persistOrder($I, $company);
        $before = $this->storedDate($I, (int) $order->getId());

        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Draft',
            'document_date' => '',
            'lines' => [['name' => 'Order date line', 'qty' => '1', 'price' => '5.00']],
            'save_mode' => 'draft_recalc',
        ]);

        $I->assertSame($before, $this->storedDate($I, (int) $order->getId()));
    }

    /**
     * And so does a date that isn't one. TextInput::calendarDate() refuses '2026-02-31' rather than
     * letting PHP roll it forward to March 3rd and store a day the admin never picked.
     */
    public function anImpossibleOrderDateIsRefusedRatherThanRolledForward(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Order Date Impossible Co');
        $order = $this->persistOrder($I, $company);
        $before = $this->storedDate($I, (int) $order->getId());

        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Draft',
            'document_date' => '2026-02-31',
            'lines' => [['name' => 'Order date line', 'qty' => '1', 'price' => '5.00']],
            'save_mode' => 'draft_recalc',
        ]);

        $stored = $this->storedDate($I, (int) $order->getId());
        $I->assertSame($before, $stored);
        $I->assertNotSame('2026-03-03', $stored);
    }

    /**
     * The detail page shows the date under its own name and offers no way to change it there.
     *
     * It was labelled "Order Time" — a name shared with nothing, while the same column was headed
     * "PO Date" on the order grid, a second name for the same field and just as wrong. Both are
     * now "Order Date", and the inline pencil-edit that posted to an endpoint of its own is gone:
     * this is a view screen.
     */
    public function theDetailPageShowsTheDateAndNoLongerEditsIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Order Date Detail Co');
        $order = $this->persistOrder($I, $company);
        $order->setDocumentDate('2026-05-09');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->see('Order Date:');
        $I->see('2026-05-09');
        $I->dontSee('Order Time:');
        $I->dontSeeElement('.js-order-time-input');
    }

    /**
     * Reading a date is timezone-invariant: the page shows what is stored, under any zone.
     *
     * This is the half of the change that has no unit test standing behind it. CalendarDateExtension
     * is covered across five zones, but the order pages do not use that filter — they print
     * {{ order.documentDate }} raw, precisely because the stored string already IS the display format. So
     * the regression this guards is a small and entirely plausible edit: somebody tidying a template
     * back to {{ order.documentDate|date('Y-m-d') }}, which looks like every other date render in the
     * codebase and silently reintroduces the conversion. Twig's date filter parses a bare string
     * with no zone argument, so it lands on midnight in PHP's ambient default — UTC, pinned by
     * Kernel — and then prints it in the zone DisplayTimezoneSubscriber has already put on the
     * filter. Under a zone behind UTC that midnight falls back across the boundary and the page
     * shows the day before the one in the database, which is verified: rendering documentDate through
     * |date('Y-m-d') turns a stored 2026-05-09 into a displayed 2026-05-08. Every other test here
     * would still pass, because they all run under one zone.
     *
     * Both the detail page and the printable invoice, since they are separate templates and a tidy-up
     * would not necessarily touch both.
     */
    public function theDisplayedDateIsTheStoredDateUnderEveryTimezone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->persistOrder($I, $this->makeCompany($I, 'Order Date Read Co'));
        $order->setDocumentDate('2026-05-09');
        $I->grabService(EntityManagerInterface::class)->flush();

        foreach ([self::FAR_EAST, self::FAR_WEST, 'America/Vancouver', 'UTC'] as $timezone) {
            $this->setDisplayTimezone($I, $timezone);

            $I->amOnPage('/admin/order/detail/' . $order->getId());
            $I->seeResponseCodeIsSuccessful();
            $I->see('2026-05-09');
            // Named explicitly: a conversion under these zones lands on one of the neighbouring
            // days, so seeing either of them is the failure this is looking for.
            $I->dontSee('2026-05-08');
            $I->dontSee('2026-05-10');

            // The order's own document since #539 stage 6 — the page that used to print the order
            // as an invoice is now the invoice's, and this is the order's replacement for it.
            $I->amOnPage('/admin/order/document/' . $order->getId());
            $I->seeResponseCodeIsSuccessful();
            $I->see('2026-05-09');
            $I->dontSee('2026-05-08');
            $I->dontSee('2026-05-10');
        }
    }

    /**
     * Changing the timezone setting does not re-date documents that already exist.
     *
     * The zone is consulted once, when a blank date is filled at persist time, and never again — so
     * a document's date is a property of the moment it was raised, not a lens applied to it
     * afterwards. Two distinct ways that can stop being true, and an assertion for each:
     *
     *  - the stored row being rewritten. The stamp only listens on prePersist today, but "keep the
     *    date in step with the setting" is an easy thing to reach for, and a re-stamp hung on any
     *    event that fires for an already-managed entity — preFlush, preUpdate, postLoad — re-dates
     *    every order still in the identity map the next time anything flushes. Which is why the
     *    row is re-read AFTER a page has been loaded and not only after the setting was written:
     *    the write itself flushes while the old zone is still the cached one, so a re-stamp firing
     *    during it produces the same string it replaces and leaves no trace. It is the next
     *    unrelated request that does the damage. Verified against a preFlush re-stamp, which slips
     *    past the read taken straight after the setting change and is caught by the one after the
     *    page view.
     *  - the value being derived at display time rather than read back. That leaves the row alone
     *    and is invisible to a SELECT, so what the page rendered is asserted as well.
     *
     * The two zones are 25 hours apart and therefore never on the same calendar day, so neither
     * assertion can pass by coincidence: a re-derived date is guaranteed to be a different string,
     * not merely usually one.
     */
    public function changingTheTimezoneDoesNotRedateExistingDocuments(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Order Date Retroactive Co');

        $this->setDisplayTimezone($I, self::FAR_EAST);
        $order = $this->persistOrder($I, $company);
        $dated = $this->storedDate($I, (int) $order->getId());
        $I->assertSame(
            (new \DateTimeImmutable('now', new \DateTimeZone(self::FAR_EAST)))->format('Y-m-d'),
            $dated,
        );

        $this->setDisplayTimezone($I, self::FAR_WEST);

        $I->assertSame($dated, $this->storedDate($I, (int) $order->getId()), 'the stored date was rewritten');

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        // The row first. A re-stamp hung on a flush-time event mutates the entity on its way into
        // the change-set computation, so Doctrine writes the new value out — a row quietly updated
        // by a page view, which only a second SELECT can see. Checked before the rendered output
        // because it is the more serious of the two and the one a failed render would mask.
        $I->assertSame($dated, $this->storedDate($I, (int) $order->getId()), 'viewing the order rewrote its date');

        // Then what was displayed. Unscoped deliberately: the only other date this page renders is
        // the activity log's createdAt, formatted in the *current* zone, which can never coincide
        // with the far-east date being looked for here.
        $I->see($dated);
    }

    /**
     * A date the admin picked is stored as picked, whatever the zone is set to.
     *
     * anExplicitlySetDateIsNeverOverwritten already covers the persist-time stamp leaving a set
     * value alone, but it runs under whatever zone the suite is configured with, so all it proves
     * is that the stamp did not overwrite with *that* zone's today. Under a zone fourteen hours
     * ahead of UTC — and a chosen date in 2019, which no zone's today can ever be — the same
     * assertion tells "the stamp did not fire" apart from "the stamp fired and happened to agree".
     *
     * Three ways a chosen date can be lost, one assertion each, in the order they would happen:
     *
     *  - the stamp firing on a document that already has a date. Note the date is set BEFORE
     *    haveInRepository(), so prePersist sees a filled field — this is the creation path, which
     *    the version of this test that started from persistOrder() never reached, because that
     *    hands the listener a blank date to fill and there is nothing left to overwrite.
     *  - the edit-save path normalizing the posted string through a date object and reformatting
     *    it: '2019-03-05' read as a midnight in one zone and printed in another is a different day.
     *  - the value being re-derived from the setting afterwards instead of read back as stored.
     */
    public function anAdminChosenDateIsUnaffectedByTheTimezone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Order Date Chosen Co');
        $this->setDisplayTimezone($I, self::FAR_EAST);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ODATE-ORD-' . uniqid())
            ->setTotal('10.00')
            ->setDocumentDate('2019-03-04');
        $I->haveInRepository($order);

        $I->assertSame('2019-03-04', $this->storedDate($I, (int) $order->getId()), 'the stamp overwrote a chosen date');

        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Draft',
            'document_date' => '2019-03-05',
            'lines' => [['name' => 'Order date line', 'qty' => '1', 'price' => '5.00']],
            'save_mode' => 'draft_recalc',
        ]);

        $I->assertSame('2019-03-05', $this->storedDate($I, (int) $order->getId()));

        $this->setDisplayTimezone($I, self::FAR_WEST);
        $I->assertSame('2019-03-05', $this->storedDate($I, (int) $order->getId()));
    }

    /** The endpoint that pencil posted to is gone, not merely unlinked. */
    public function theInlineDateEndpointNoLongerExists(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->persistOrder($I, $this->makeCompany($I, 'Order Date Endpoint Co'));

        $I->sendFormPostRequest('/admin/order/update-time/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'date' => '2019-01-01',
        ]);

        $I->seeResponseCodeIs(404);
    }

    /** The grid's Order Date filter still works against the string column. */
    public function theOrderGridFiltersOnTheDateColumn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Order Date Filter Co');

        $wanted = $this->persistOrder($I, $company);
        $wanted->setDocumentDate('2026-04-01');
        $other = $this->persistOrder($I, $company);
        $other->setDocumentDate('2026-04-02');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->amOnPage('/admin/order?filters[documentDate]=2026-04-01');
        $I->seeResponseCodeIsSuccessful();
        $I->see($wanted->getOrderNumber());
        $I->dontSee($other->getOrderNumber());

        // And the From/To range, which is the same comparison over two bounds.
        $I->amOnPage('/admin/order?filters[documentDateFrom]=2026-04-02&filters[documentDateTo]=2026-04-02');
        $I->seeResponseCodeIsSuccessful();
        $I->see($other->getOrderNumber());
        $I->dontSee($wanted->getOrderNumber());
    }
}
