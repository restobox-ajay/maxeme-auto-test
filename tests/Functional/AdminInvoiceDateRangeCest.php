<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePayment;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The Invoices grid's Invoice Date range (From/To), the counterpart of the Orders grid's.
 *
 * Conducted per #624: every test here creates its own invoices, drives the real `/admin/invoice`
 * screen, and asserts the rows that must NOT come back alongside the ones that must. A range
 * filter that returns the right rows while also returning the wrong ones is not a range filter,
 * and only the negative half can tell the difference — so the invoice dated one day outside each
 * bound is the point of the fixture, not padding.
 *
 * Assertions read the Invoice # column's CELLS rather than the page (#627). `see('INV-9')` would
 * match 'INV-90' and, worse, would match the number wherever it appeared — including in a row the
 * filter should have excluded. Every list assertion below compares the whole rendered column, so
 * an empty column (the selector matching nothing, which is how an absence assertion passes for the
 * wrong reason) fails just as loudly as a wrong one.
 *
 * Both bounds are INCLUSIVE. That is where these are usually wrong: `>` instead of `>=` loses the
 * first day of the window and `<` loses the last, and both defects look exactly like a working
 * filter until somebody searches a single month and finds the 1st and the 31st missing. So the
 * fixture puts an invoice on each bound and the range test names them.
 */
final class AdminInvoiceDateRangeCest
{
    /** The five fixture rows, keyed by the label used throughout, valued by invoice_date. */
    private const DATES = [
        'BEFORE' => '2026-05-31',
        'FROMDAY' => '2026-06-01',
        'MIDDLE' => '2026-06-15',
        'TODAY' => '2026-06-30',
        'AFTER' => '2026-07-01',
    ];

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-invoice-date-range@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * June, asked for by its own two edges.
     *
     * The 1st and the 30th are IN — they are days the admin named. The 31st of May and the 1st of
     * July are OUT, one day past each bound, which is the smallest step that can tell an inclusive
     * bound from an exclusive one and a bound from no bound at all.
     */
    public function theRangeIncludesBothBoundaryDaysAndExcludesEitherSide(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $invoices = $this->fiveDatedInvoices($I, 'RANGE');

        $I->amOnPage('/admin/invoice?filters[invoiceDateFrom]=2026-06-01&filters[invoiceDateTo]=2026-06-30');
        $I->seeResponseCodeIsSuccessful();

        // The whole rendered column, newest invoice_date first (the grid's default sort is id DESC,
        // and these were created in date order). Three in, two out, named rather than counted.
        $I->assertSame(
            [
                $invoices['TODAY'],
                $invoices['MIDDLE'],
                $invoices['FROMDAY'],
            ],
            $this->renderedInvoiceNumbers($I),
            'invoice.invoice_date range 2026-06-01..2026-06-30',
        );

        // And the dates those rows actually carry, read from their own cells — a grid can return
        // the right row count off the wrong column.
        $I->assertSame('2026-06-01', $this->dateCellOf($I, $invoices['FROMDAY']), 'invoice.invoice_date on the From boundary row');
        $I->assertSame('2026-06-30', $this->dateCellOf($I, $invoices['TODAY']), 'invoice.invoice_date on the To boundary row');
    }

    /**
     * Each bound on its own, so a green range test cannot be hiding one bound doing nothing.
     *
     * With both boxes filled, a `from` that is ignored entirely still passes the test above as long
     * as `to` works and nothing older than the fixture exists. Filling one box at a time is what
     * separates "the range works" from "half of it works".
     */
    public function eachBoundNarrowsOnItsOwn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $invoices = $this->fiveDatedInvoices($I, 'ONEBOUND');

        // From alone: everything on or after the 15th, which is the 15th itself upward.
        $I->amOnPage('/admin/invoice?filters[invoiceDateFrom]=2026-06-15');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            [$invoices['AFTER'], $invoices['TODAY'], $invoices['MIDDLE']],
            $this->renderedInvoiceNumbers($I),
            'invoice.invoice_date >= 2026-06-15',
        );

        // To alone: everything on or before the 15th, the 15th included again — the same row is on
        // the inside of both bounds, which is what inclusive means.
        $I->amOnPage('/admin/invoice?filters[invoiceDateTo]=2026-06-15');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            [$invoices['MIDDLE'], $invoices['FROMDAY'], $invoices['BEFORE']],
            $this->renderedInvoiceNumbers($I),
            'invoice.invoice_date <= 2026-06-15',
        );
    }

    /** A single day asked for as a range of one — from and to on the same date. */
    public function aOneDayRangeReturnsThatDayAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $invoices = $this->fiveDatedInvoices($I, 'ONEDAY');

        $I->amOnPage('/admin/invoice?filters[invoiceDateFrom]=2026-06-15&filters[invoiceDateTo]=2026-06-15');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$invoices['MIDDLE']], $this->renderedInvoiceNumbers($I), 'invoice.invoice_date = 2026-06-15 as a range');
    }

    /** From after To is a typo, not an empty result: the bounds swap, as the Orders grid's do. */
    public function aReversedRangeIsReadTheWayRound(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $invoices = $this->fiveDatedInvoices($I, 'REVERSED');

        $I->amOnPage('/admin/invoice?filters[invoiceDateFrom]=2026-06-30&filters[invoiceDateTo]=2026-06-01');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            [$invoices['TODAY'], $invoices['MIDDLE'], $invoices['FROMDAY']],
            $this->renderedInvoiceNumbers($I),
            'reversed bounds still bracket June',
        );
    }

    /**
     * A bound that is not a date leaves the page standing, and filters nothing.
     *
     * Typed rubbish reaches this filter easily — the boxes are `<input type="date">`, but the
     * query string behind them is hand-editable and shared by URL, and the parser is deliberately
     * forgiving about typed formats (`3/14/2026`) rather than strict. calendarDateFilter() answers
     * null for anything it cannot read, and null is read as "no filter", so the grid stays up.
     *
     * NOT covered here: `filters[invoiceDateFrom][]=x`, an array where a scalar belongs.
     * calendarDateFilter() itself is guarded (is_scalar, so no cast and no #395 stack trace) — but
     * the page 500s anyway, and did so before this range existed. Two layers do it, neither of them
     * this filter. Every OTHER filter on both grids casts straight to string in the controller, so
     * `?filters[status][]=Draft` alone takes down /admin/invoice (InvoiceController's
     * InvoiceStatus::tryFrom) and /admin/order (its own $filters['status'] cast). And both grids
     * render every filter value blindly in Twig — the per-page form's `{{ value }}` loop, and the
     * From box itself, which is why `?filters[documentDateFrom][]=` already takes down the Orders
     * grid at its own range box that the one added here mirrors.
     *
     * So it is systemic, it predates this change, and this change neither widens nor narrows it.
     * Reported rather than asserted: a test pinning it to these two boxes would have to move when
     * the list-screen footer is fixed, and the fix does not belong to a date range.
     */
    public function aMalformedBoundDoesNotBreakTheGrid(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $invoices = $this->fiveDatedInvoices($I, 'MALFORMED');
        $all = [
            $invoices['AFTER'],
            $invoices['TODAY'],
            $invoices['MIDDLE'],
            $invoices['FROMDAY'],
            $invoices['BEFORE'],
        ];

        foreach (['not-a-date', '%%%', '0000-00-00', '2026-06-31'] as $rubbish) {
            $I->amOnPage('/admin/invoice?filters[invoiceDateFrom]=' . urlencode($rubbish));
            $I->seeResponseCodeIsSuccessful();
            $I->amOnPage('/admin/invoice?filters[invoiceDateTo]=' . urlencode($rubbish));
            $I->seeResponseCodeIsSuccessful();
        }

        // Unparseable text is no filter at all — every row is still listed, which is also the
        // positive control proving the cell selector matches something.
        $I->amOnPage('/admin/invoice?filters[invoiceDateFrom]=not-a-date&filters[invoiceDateTo]=not-a-date');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame($all, $this->renderedInvoiceNumbers($I), 'an unparseable bound filters nothing');
    }

    /**
     * A bound typed in a format the date box would never emit is still understood.
     *
     * The parser predates the `<input type="date">` boxes and stays forgiving on purpose — an
     * admin pasting `06/01/2026` into the query string, or a browser that renders the box as plain
     * text, both land here. It is the comparison VALUE that must be the stored 'Y-m-d' format, and
     * that is the half calendarDateFilter() adds.
     */
    public function aBoundTypedInAnotherFormatIsStillUnderstood(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $invoices = $this->fiveDatedInvoices($I, 'FORMATS');

        $I->amOnPage('/admin/invoice?filters[invoiceDateFrom]=' . urlencode('06/01/2026') . '&filters[invoiceDateTo]=' . urlencode('06/30/2026'));
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            [$invoices['TODAY'], $invoices['MIDDLE'], $invoices['FROMDAY']],
            $this->renderedInvoiceNumbers($I),
            'm/d/Y bounds compared against a Y-m-d column',
        );
    }

    /**
     * The per-column single-day filter still works, and the range wins when both are sent.
     *
     * The two would otherwise intersect into a window nobody asked for — an admin who sets a range
     * and then picks a day in the header would get the days that satisfy both, which is usually
     * nothing. Same precedence as the Orders grid.
     */
    public function theSingleDayColumnFilterStillWorksAndTheRangeOutranksIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $invoices = $this->fiveDatedInvoices($I, 'EXACT');

        // Unchanged behaviour: the header box on its own is still an exact match.
        $I->amOnPage('/admin/invoice?filters[invoiceDate]=2026-06-15');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$invoices['MIDDLE']], $this->renderedInvoiceNumbers($I), 'invoice.invoice_date = 2026-06-15');

        // Both sent: the range applies and the single day is ignored, rather than the two ANDing
        // into an empty result.
        $I->amOnPage('/admin/invoice?filters[invoiceDate]=2026-06-15&filters[invoiceDateFrom]=2026-06-01&filters[invoiceDateTo]=2026-06-30');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            [$invoices['TODAY'], $invoices['MIDDLE'], $invoices['FROMDAY']],
            $this->renderedInvoiceNumbers($I),
            'the range outranks the single-day column filter',
        );
    }

    /**
     * The range is a plain GET form, so it applies with JavaScript off (#617 baseline).
     *
     * What a scriptless browser does with this bar is fully determined by markup: the boxes are
     * inside a `method="get"` form aimed at the grid's own route, and pressing its ordinary submit
     * button sends exactly the query string the second half of this test loads by hand. Nothing
     * here has an event handler, and nothing needs one.
     */
    public function theRangeSubmitsAndAppliesWithoutJavaScript(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $invoices = $this->fiveDatedInvoices($I, 'NOJS');

        $I->amOnPage('/admin/invoice');
        $I->seeResponseCodeIsSuccessful();

        $form = 'form#invoice-grid-filters-form';
        $I->assertSame('get', strtolower((string) $I->grabAttributeFrom($form, 'method')), 'the filter bar is a GET form');
        $I->assertSame('/admin/invoice', $I->grabAttributeFrom($form, 'action'), 'the filter bar posts back to the grid');

        // Both boxes are DESCENDANTS of that form — not merely on the page wired up by script —
        // so an unscripted submit carries them.
        $I->seeElement($form . ' input[type="date"][name="filters[invoiceDateFrom]"]');
        $I->seeElement($form . ' input[type="date"][name="filters[invoiceDateTo]"]');
        // And a real submit button to press, with no handler attached to it.
        $I->seeElement($form . ' button[type="submit"]');
        $I->assertNull($I->grabAttributeFrom($form . ' button[type="submit"]', 'onclick'), 'the Search button needs no script');
        $I->assertNull($I->grabAttributeFrom($form . ' input[name="filters[invoiceDateFrom]"]', 'onchange'), 'the From box needs no script');
        $I->assertNull($I->grabAttributeFrom($form . ' input[name="filters[invoiceDateTo]"]', 'onchange'), 'the To box needs no script');

        // What that unscripted submit sends, and what it comes back with.
        $I->amOnPage('/admin/invoice?page=1&limit=100&sort=id&dir=desc&filters[invoiceDateFrom]=2026-06-01&filters[invoiceDateTo]=2026-06-30');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            [$invoices['TODAY'], $invoices['MIDDLE'], $invoices['FROMDAY']],
            $this->renderedInvoiceNumbers($I),
            'the range applies from a plain GET submit',
        );

        // The boxes come back filled, so a second unscripted submit refines rather than resets.
        $I->seeInField($form . ' input[name="filters[invoiceDateFrom]"]', '2026-06-01');
        $I->seeInField($form . ' input[name="filters[invoiceDateTo]"]', '2026-06-30');
    }

    /**
     * The range narrows alongside another filter rather than replacing it.
     *
     * Two invoices inside June, one Cancelled; asking for June AND Cancelled must return the one
     * row that is both, not every June row and not every cancelled row.
     */
    public function theRangeCombinesWithTheStatusFilter(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $invoices = $this->fiveDatedInvoices($I, 'COMBINED');

        $em = $I->grabService(EntityManagerInterface::class);
        $em->getRepository(Invoice::class)->findOneBy(['documentNumber' => $invoices['MIDDLE']])
            ->setStatus('Cancelled', DocumentActor::system(), 'Invoice cancelled: range test');
        // A cancelled row OUTSIDE the range, so "Cancelled" alone cannot produce the same answer.
        $em->getRepository(Invoice::class)->findOneBy(['documentNumber' => $invoices['AFTER']])
            ->setStatus('Cancelled', DocumentActor::system(), 'Invoice cancelled: range test');
        $em->flush();

        $I->amOnPage('/admin/invoice?filters[invoiceDateFrom]=2026-06-01&filters[invoiceDateTo]=2026-06-30&filters[status]=Cancelled');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$invoices['MIDDLE']], $this->renderedInvoiceNumbers($I), 'June AND Cancelled');

        // Positive controls: each half on its own returns more than the intersection, so the
        // assertion above is not passing because one of them matched nothing.
        $I->amOnPage('/admin/invoice?filters[status]=Cancelled');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame([$invoices['AFTER'], $invoices['MIDDLE']], $this->renderedInvoiceNumbers($I), 'Cancelled alone');

        $I->amOnPage('/admin/invoice?filters[invoiceDateFrom]=2026-06-01&filters[invoiceDateTo]=2026-06-30');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            [$invoices['TODAY'], $invoices['MIDDLE'], $invoices['FROMDAY']],
            $this->renderedInvoiceNumbers($I),
            'June alone',
        );
    }

    /**
     * Every other filter this grid already had, still filtering — proved, not assumed.
     *
     * They share one query builder with the new range, and an `andWhere` added in the wrong place
     * is exactly the kind of change that leaves one of them silently doing nothing.
     */
    public function theGridsOtherFiltersAreUnchanged(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        [$mineOrder, $mine] = $this->invoicedOrder($I, 'OTHERA', 'Date Range Alpha Co', '2026-06-15', '111.11');
        [, $theirs] = $this->invoicedOrder($I, 'OTHERB', 'Date Range Beta Co', '2026-06-16', '222.22');

        $em = $I->grabService(EntityManagerInterface::class);
        $payment = (new InvoicePayment())->setMethod('Bank Transfer')->setAmount($theirs->getTotal());
        $theirs->recordPayment(DocumentActor::system(), $payment);
        $em->persist($payment);
        $mine->startProcessing(DocumentActor::system());
        $em->flush();

        $both = [$theirs->getDocumentNumber(), $mine->getDocumentNumber()];
        $cases = [
            'documentNumber' => ['filters[documentNumber]=' . urlencode($mine->getDocumentNumber()), [$mine->getDocumentNumber()]],
            'orderNumber' => ['filters[orderNumber]=' . urlencode($mineOrder->getOrderNumber()), [$mine->getDocumentNumber()]],
            'company' => ['filters[company]=' . urlencode('Date Range Alpha'), [$mine->getDocumentNumber()]],
            'total' => ['filters[total]=' . urlencode('111.11'), [$mine->getDocumentNumber()]],
            'status' => ['filters[status]=Processing', [$mine->getDocumentNumber()]],
            'paymentStatus' => ['filters[paymentStatus]=Paid', [$theirs->getDocumentNumber()]],
            'invoiceDate' => ['filters[invoiceDate]=2026-06-15', [$mine->getDocumentNumber()]],
        ];

        // The positive control for every dontSee below: unfiltered, the grid lists both rows, so a
        // filtered result of one is a filter working rather than a selector matching nothing.
        $I->amOnPage('/admin/invoice');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame($both, $this->renderedInvoiceNumbers($I), 'both fixture invoices are listed unfiltered');

        foreach ($cases as $field => [$query, $expected]) {
            $I->amOnPage('/admin/invoice?' . $query);
            $I->seeResponseCodeIsSuccessful();
            $I->assertSame($expected, $this->renderedInvoiceNumbers($I), 'filters[' . $field . ']');
        }
    }

    /** The Orders grid's own range is untouched by the helper moving to the base controller. */
    public function theOrdersGridRangeStillWorks(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = (new Company())
            ->setName('Date Range Orders Co')
            ->setCode('DRO-' . uniqid());
        $I->haveInRepository($company);

        $inside = (new SalesOrder())->setCompany($company)->setOrderNumber('DRO-IN-' . uniqid())
            ->setDocumentDate('2026-06-01')->setTotal('10.00');
        $outside = (new SalesOrder())->setCompany($company)->setOrderNumber('DRO-OUT-' . uniqid())
            ->setDocumentDate('2026-05-31')->setTotal('10.00');
        $I->haveInRepository($inside);
        $I->haveInRepository($outside);

        $I->amOnPage('/admin/order?filters[documentDateFrom]=2026-06-01&filters[documentDateTo]=2026-06-30');
        $I->seeResponseCodeIsSuccessful();
        $I->see($inside->getOrderNumber());
        $I->dontSee($outside->getOrderNumber());
        // The Orders grid's From bound is inclusive too, and this row sits exactly on it.
        $I->assertSame(
            '2026-06-01',
            trim((string) $I->grabTextFrom(
                '//tbody/tr[td[@data-label="Order #"][normalize-space()="' . $inside->getOrderNumber() . '"]]/td[@data-label="Order Date"]',
            )),
            'sales_order.document_date on the From boundary row',
        );
    }

    /**
     * The Invoice # column as the grid actually rendered it, top row first.
     *
     * Reading the CELLS, never the page: `see('INV-9')` matches 'INV-90' and matches it anywhere,
     * including inside a row the filter should have dropped (#627). An empty list here is a
     * failure like any other, which is what stops an absence assertion passing because the
     * selector found nothing.
     *
     * @return list<string>
     */
    private function renderedInvoiceNumbers(FunctionalTester $I): array
    {
        return array_values(array_map(
            trim(...),
            $I->grabMultiple('table.order-list-table tbody td[data-label="Invoice #"]'),
        ));
    }

    /** The Invoice Date cell of the row carrying this invoice number. */
    private function dateCellOf(FunctionalTester $I, string $documentNumber): string
    {
        return trim((string) $I->grabTextFrom(
            '//tbody/tr[td[@data-label="Invoice #"][normalize-space()="' . $documentNumber . '"]]/td[@data-label="Invoice Date"]',
        ));
    }

    /**
     * One invoice on each of the five fixture dates, created oldest first.
     *
     * Oldest first matters: the grid's default sort is id DESC, so the expected orderings in the
     * tests above read newest date to oldest and a wrong row cannot hide behind a coincidental
     * ordering.
     *
     * @return array<string, string> label => document number
     */
    private function fiveDatedInvoices(FunctionalTester $I, string $prefix): array
    {
        $company = (new Company())
            ->setName('Invoice Date Range Co')
            ->setCode($prefix . '-' . uniqid())
            ->setPrimaryEmail('buyer@invoice-date-range.example');
        $I->haveInRepository($company);

        $em = $I->grabService(EntityManagerInterface::class);
        $numbers = [];
        foreach (self::DATES as $label => $date) {
            $invoice = (new Invoice())
                ->setCompany($company)
                ->setDocumentNumber($prefix . '-INV-' . $label . '-' . uniqid())
                // documentDate is held CONSTANT across all five on purpose: it is a different
                // column from invoiceDate (the base stamps it at persist time; invoiceDate is the
                // accounting date an admin chooses), and a range filter reading the wrong one of
                // the two would otherwise return the same answer as one reading the right one.
                ->setDocumentDate('2026-01-01')
                ->setInvoiceDate($date)
                ->setSubtotal('50.00')
                ->setTax('0.00')
                ->setTotal('50.00');
            $invoice->addLine(
                (new InvoiceLine())->setName('Range Widget')->setQuantity('2.00')->setPrice('25.00')->setSubtotal('50.00'),
            );
            $em->persist($invoice);
            $invoice->issue(DocumentActor::system());
            $numbers[$label] = $invoice->getDocumentNumber();
        }
        $em->flush();

        return $numbers;
    }

    /**
     * An approved order with one issued invoice for its whole value, for the other-filters test.
     *
     * @return array{0: SalesOrder, 1: Invoice}
     */
    private function invoicedOrder(FunctionalTester $I, string $prefix, string $companyName, string $invoiceDate, string $total): array
    {
        $company = (new Company())
            ->setName($companyName)
            ->setCode($prefix . '-' . uniqid())
            ->setPrimaryEmail('buyer@invoice-date-range.example');
        $I->haveInRepository($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($prefix . 'SO-' . uniqid())
            ->setDocumentDate('2026-06-01')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $order->addLine(
            (new SalesOrderLine())->setName('Range Widget')->setQuantity('1.00')->setPrice($total)->setSubtotal($total),
        );
        $I->haveInRepository($order);

        $em = $I->grabService(EntityManagerInterface::class);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber($prefix . '-INV-' . uniqid())
            ->setDocumentDate('2026-01-01')
            ->setInvoiceDate($invoiceDate)
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $order->addInvoice($invoice);
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($order->getLines()->first())
                ->setName('Range Widget')
                ->setQuantity('1.00')
                ->setPrice($total)
                ->setSubtotal($total),
        );
        $em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $em->flush();

        return [$order, $invoice];
    }
}
