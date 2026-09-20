<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\SalesOrder;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Service\DocumentActor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin/DashboardController: the KPI dashboard page and its JSON
 *  recent-orders endpoint used by the dashboard's sortable/searchable table. */
final class AdminDashboardCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-dashboard-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    /**
     * A live order, built the only way there is since #539 stage 2: an order is born a Draft and
     * approve() is the transition that accepts it. With no invoices raised against it the deriver
     * leaves it in Approved, which is one of the three statuses the Open Orders tile counts and one
     * of the many the revenue queries include.
     */
    private function approvedOrder(FunctionalTester $I, Company $company, string $number, string $total, ?string $date = null): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($number)
            ->setTotal($total);
        if ($date !== null) {
            $order->setDocumentDate($date);
        }
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->haveInRepository($order);

        return $order;
    }

    /** An order taken back out of the reporting totals — approved, then voided (#539 stage 2). */
    private function voidedOrder(FunctionalTester $I, Company $company, string $number, string $total, ?string $date = null): SalesOrder
    {
        $order = $this->approvedOrder($I, $company, $number, $total, $date);
        $order->setStatus('Void', DocumentActor::system());
        $I->haveInRepository($order);

        return $order;
    }

    /** A draft, i.e. an order nobody has accepted: excluded from revenue and never "open". */
    private function draftOrder(FunctionalTester $I, Company $company, string $number, string $total, ?string $date = null): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($number)
            ->setTotal($total);
        if ($date !== null) {
            $order->setDocumentDate($date);
        }
        $I->haveInRepository($order);

        return $order;
    }

    public function indexShowsKpisAndRecentOrders(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())->setName('Dashboard Test Co')->setCode('DASH-CO-' . uniqid());
        $I->haveInRepository($company);

        $order = $this->approvedOrder($I, $company, 'DASH-ORD-' . uniqid(), '456.78');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Dashboard');
        $I->see('Revenue (MTD)');
        $I->see('Active Customers');
        $I->see('Recent Orders');
        $I->see($order->getOrderNumber());
        $I->see('Dashboard Test Co');
        $I->see('Approved');
    }

    /** The "Today's Quotes" tile replaced the old all-time "System Alerts" error_log count.
     *  It mirrors "Today's Orders": every estimate dated today, whatever its status. */
    public function todaysQuotesTileCountsOnlyEstimatesDatedToday(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();

        $I->see("Today's Quotes");
        $I->dontSee('System Alerts');

        $before = $this->grabQuotesTodayCount($I);

        $company = (new Company())->setName('Quotes Today Co')->setCode('QT-CO-' . uniqid());
        $I->haveInRepository($company);

        // Two dated today — deliberately different statuses, since the tile counts them all.
        foreach (['Draft', 'Accepted'] as $i => $status) {
            $todayQuote = (new Estimate())
                ->setCompany($company)
                ->setDocumentNumber('QT-TODAY-' . $i . '-' . uniqid())
                ->setDocumentDate((new \DateTimeImmutable('today'))->format('Y-m-d'));
            $todayQuote->setStatus($status, DocumentActor::system());
            $I->haveInRepository($todayQuote);
        }

        // One dated in the past, which must not be counted.
        $oldQuote = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('QT-OLD-' . uniqid())
            ->setDocumentDate((new \DateTimeImmutable('-10 days'))->format('Y-m-d'));
        $oldQuote->setStatus('Submitted', DocumentActor::system());
        $I->haveInRepository($oldQuote);

        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame($before + 2, $this->grabQuotesTodayCount($I), "Today's Quotes should count only the two estimates dated today");
    }

    /** Reads the numeric value rendered in the "Today's Quotes" secondary-stat tile. */
    private function grabQuotesTodayCount(FunctionalTester $I): int
    {
        $value = $I->grabTextFrom('//p[contains(text(), "Today\'s Quotes")]/following-sibling::strong');

        return (int) str_replace(',', '', trim($value));
    }

    /**
     * The four statistics on this page that read the document date were raw SQL naming po_date as
     * a literal string until #498 moved them to DQL. Nothing type-checked those strings, so the
     * rename would have left them querying a column that no longer exists — a runtime failure, on
     * the admin landing page, with no test in front of it. These four cover them by VALUE rather
     * than by "the page rendered", which is what the tests here did before and what a query
     * returning the wrong rows still satisfies.
     */
    public function todaysOrdersTileCountsOnlyOrdersDatedToday(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();

        $before = $this->grabSecondaryStat($I, "Today's Orders");

        $company = (new Company())->setName('Orders Today Co')->setCode('OT-CO-' . uniqid());
        $I->haveInRepository($company);

        // Deliberately one of each kind, since the tile counts every order dated today whatever
        // its status.
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->approvedOrder($I, $company, 'OT-TODAY-0-' . uniqid(), '10.00', $today);
        $this->draftOrder($I, $company, 'OT-TODAY-1-' . uniqid(), '10.00', $today);

        $this->approvedOrder(
            $I,
            $company,
            'OT-OLD-' . uniqid(),
            '10.00',
            (new \DateTimeImmutable('-10 days'))->format('Y-m-d')
        );

        $I->amOnPage('/admin');
        $I->assertSame($before + 2, $this->grabSecondaryStat($I, "Today's Orders"), "Today's Orders should count only the two orders dated today");
    }

    /**
     * Revenue (MTD) matches on the first seven characters of the date rather than calling a date
     * function on it. An order dated the last day of LAST month must not be in it — which is the
     * assertion that fails if the year-month comparison is dropped or widened to the whole column.
     *
     * Two statuses are excluded from the sum whatever they are dated: Draft, which was never
     * accepted, and Void, which #539 stage 2 added precisely to take an order out of the reporting
     * totals without deleting it.
     */
    public function revenueMtdSumsOnlyOrdersDatedThisMonth(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin');
        $before = $this->grabRevenueMtd($I);

        $company = (new Company())->setName('Revenue MTD Co')->setCode('RM-CO-' . uniqid());
        $I->haveInRepository($company);

        $today = new \DateTimeImmutable('today');
        $lastMonth = $today->modify('first day of this month')->modify('-1 day');

        $this->approvedOrder($I, $company, 'RM-THIS-' . uniqid(), '1000.00', $today->format('Y-m-d'));

        // Last day of the previous month — the boundary a year-month comparison has to exclude.
        $this->approvedOrder($I, $company, 'RM-PREV-' . uniqid(), '7000.00', $lastMonth->format('Y-m-d'));

        // Draft orders are excluded whatever they are dated.
        $this->draftOrder($I, $company, 'RM-DRAFT-' . uniqid(), '300.00', $today->format('Y-m-d'));

        // And so are voided ones, for the same reason a Draft is: neither is revenue.
        $this->voidedOrder($I, $company, 'RM-VOID-' . uniqid(), '5000.00', $today->format('Y-m-d'));

        $I->amOnPage('/admin');
        $I->assertSame($before + 1000, $this->grabRevenueMtd($I), 'Revenue (MTD) should add only the live order dated this month, excluding the draft and the voided one');
    }

    /** The date reaches the rendered row — i.e. the template reads the key the query actually returns. */
    public function recentOrderRowsShowTheOrderDate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())->setName('Recent Date Co')->setCode('RD-CO-' . uniqid());
        $I->haveInRepository($company);

        $order = $this->approvedOrder($I, $company, 'RD-ORD-' . uniqid(), '12.00', '2026-04-17');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/dashboard/recent-orders?q=' . urlencode($order->getOrderNumber()));
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertStringContainsString('2026-04-17', $response['html'], 'the row should render the order date the query selected');
    }

    /** sort=date orders by the document date, not by whatever the default fallback is. */
    public function recentOrdersEndpointSortsByOrderDate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())->setName('Recent Sort Co')->setCode('RS-CO-' . uniqid());
        $I->haveInRepository($company);

        $token = 'RSORT' . strtoupper(substr(uniqid(), -6));

        // Inserted oldest-id-first but dated newest-first, so sorting by date and sorting by the
        // default id fallback produce OPPOSITE orders — a fallback cannot accidentally pass.
        foreach ([['A', '2026-06-03'], ['B', '2026-06-02'], ['C', '2026-06-01']] as [$suffix, $date]) {
            $this->approvedOrder($I, $company, $token . '-' . $suffix, '5.00', $date);
        }

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/dashboard/recent-orders?sort=date&dir=asc&q=' . urlencode($token));
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertSame(3, $response['total']);

        $positions = array_map(
            static fn (string $n): int => strpos($response['html'], $n),
            [$token . '-C', $token . '-B', $token . '-A'],
        );
        $I->assertSame($positions, array_values(array_filter($positions, static fn ($p) => $p !== false)), 'all three rows should be present');
        $I->assertTrue(
            $positions[0] < $positions[1] && $positions[1] < $positions[2],
            'ascending sort=date should list 2026-06-01 first and 2026-06-03 last, which is the reverse of insertion order',
        );
    }

    /** Reads the numeric value rendered in a named secondary-stat tile. */
    private function grabSecondaryStat(FunctionalTester $I, string $label): int
    {
        // The label is double-quoted inside the XPath, so an apostrophe in it needs no escaping.
        $value = $I->grabTextFrom(sprintf('//p[contains(text(), "%s")]/following-sibling::strong', $label));

        return (int) str_replace(',', '', trim($value));
    }

    /**
     * The Open Orders tile counts the statuses that mean the order is accepted and not finished
     * with: Approved, Partially Invoiced and Invoiced — #539 stage 2's successors to the
     * ('Pending', 'Processing') pair this query used to name. A Draft was never opened, and a Void
     * one is out of the totals, so neither may move the figure.
     */
    public function openOrdersTileCountsAcceptedOrdersAndNeitherDraftsNorVoids(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();
        $before = $this->grabKpiTileNumber($I, 'Open Orders');

        $company = (new Company())->setName('Open Orders Co')->setCode('OO-CO-' . uniqid());
        $I->haveInRepository($company);

        $this->approvedOrder($I, $company, 'OO-LIVE-' . uniqid(), '20.00');
        $this->draftOrder($I, $company, 'OO-DRAFT-' . uniqid(), '20.00');
        $this->voidedOrder($I, $company, 'OO-VOID-' . uniqid(), '20.00');

        $I->amOnPage('/admin');
        $I->assertSame(
            $before + 1,
            $this->grabKpiTileNumber($I, 'Open Orders'),
            'Open Orders should count the approved order and neither the draft nor the voided one'
        );
    }

    /** Reads the numeric value rendered in a named KPI tile. */
    private function grabKpiTileNumber(FunctionalTester $I, string $label): int
    {
        $value = $I->grabTextFrom(sprintf('//article[@class="kpi-tile"][span="%s"]/strong', $label));

        return (int) str_replace([',', '$'], '', trim($value));
    }

    /** Reads the dollar value rendered in the "Revenue (MTD)" KPI tile. */
    private function grabRevenueMtd(FunctionalTester $I): int
    {
        $value = $I->grabTextFrom('//article[@class="kpi-tile"][span="Revenue (MTD)"]/strong');

        return (int) str_replace([',', '$'], '', trim($value));
    }

    public function recentOrdersEndpointFiltersBySearchTerm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())->setName('Recent Orders Search Co')->setCode('RO-SEARCH-' . uniqid());
        $I->haveInRepository($company);

        $matching = $this->approvedOrder($I, $company, 'MATCH-ORD-' . uniqid(), '100.00');
        $other = $this->approvedOrder($I, $company, 'OTHER-ORD-' . uniqid(), '50.00');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/dashboard/recent-orders?q=' . urlencode('MATCH-ORD'));
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertSame(1, $response['total']);
        $I->assertStringContainsString($matching->getOrderNumber(), $response['html']);
        $I->assertStringNotContainsString($other->getOrderNumber(), $response['html']);
    }
}
