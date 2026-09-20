<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceIssueIntent;
use App\Service\DocumentActor;
use App\Service\OrderInvoicingService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The three sell-side DETAIL screens, held against each other and against their own PDFs.
 *
 * /admin/order/detail/{id}, /admin/invoice/detail/{id} and /admin/estimate/detail/{id} render the
 * same document three ways, and each had drifted somewhere the other two had not. The SALES ORDER
 * is the standard where they disagree; the two exceptions are named on the cases that carry them
 * (the quote's TBD pricing, and the quantity formatting rule, which is the owner's and outranks
 * all three screens).
 *
 * ## How these are written (#624, #627)
 *
 * Conducted: every fixture is built here, the real screens and the real POST endpoints are driven,
 * and what the screen claims is read back out of the COLUMN that stores it rather than trusted from
 * the response or from an entity fetched beforehand.
 *
 * No assertion is a bare number or a bare word. Every figure is grabbed from a cell addressed by
 * the class of the table it sits in plus the label beside it, and compared exactly — `see('3')`
 * matches '13', '3.5' and a postal code alike, which on these screens is most of the page.
 *
 * Every absence is paired with a positive control on the SAME element, so "the page did not render"
 * cannot pass as "the thing is correctly gone".
 *
 * Three of these are "the screen disagrees with its own PDF" defects (shipping rows on the quote,
 * fee placement on the invoice, the missing shipping stand-in on the invoice). Those cases assert
 * BOTH renderings in one test, so what is pinned is that the two agree — a test reading one side
 * would not have caught any of them.
 *
 * No state is kept on $this: Codeception reuses one Cest instance across every method in the file.
 */
final class AdminSellSideDetailParityCest
{
    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('sell-side-detail-parity@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    // ── D1 · the quote's activity log is sorted oldest-first ──────────────────────────

    /**
     * The order's controller reads its timeline `['createdAt' => 'DESC']`; the quote's never asked
     * for an order at all and got the relation's insertion order, so the newest message on a quote
     * sat at the bottom of a growing list while the same message on an order sat at the top.
     *
     * Conducted on both screens with the same shape of data, so what is pinned is that they agree.
     * The two timestamps are moved apart afterwards because both messages are posted inside the
     * same second: `created_at` has second granularity, and a tie orders by rowid, which is the
     * insertion order this case is about and would mask the fix.
     */
    public function theQuoteActivityLogPutsTheNewestMessageFirstAsTheOrderDoes(FunctionalTester $I): void
    {
        $connection = $this->connection($I);

        $company = $this->makeCompany($I, 'Timeline Order Co');
        $estimate = $this->makeEstimate($I, $company);
        $order = $this->makeOrder($I, $company);

        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $token = $I->csrfToken();

        $I->sendAjaxPostRequest('/admin/estimate/log/add/' . $estimate->getId(), [
            '_token' => $token,
            'message' => 'Quote message posted on the first',
            'type' => 'For Internal',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->sendAjaxPostRequest('/admin/estimate/log/add/' . $estimate->getId(), [
            '_token' => $token,
            'message' => 'Quote message posted on the second',
            'type' => 'For Internal',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->sendAjaxPostRequest('/admin/order/log/add/' . $order->getId(), [
            '_token' => $token,
            'message' => 'Order message posted on the first',
            'type' => 'For Internal',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->sendAjaxPostRequest('/admin/order/log/add/' . $order->getId(), [
            '_token' => $token,
            'message' => 'Order message posted on the second',
            'type' => 'For Internal',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $this->stampLog($connection, 'Quote message posted on the first', '2026-03-01 09:05:00');
        $this->stampLog($connection, 'Quote message posted on the second', '2026-03-02 14:30:00');
        $this->stampLog($connection, 'Order message posted on the first', '2026-03-01 09:05:00');
        $this->stampLog($connection, 'Order message posted on the second', '2026-03-02 14:30:00');
        // The order also carries the System entry its own approve() wrote. It is stamped older than
        // both messages rather than filtered out, so the assertion below is over the WHOLE column.
        $this->stampLog($connection, 'Order approved.', '2026-02-28 08:00:00');
        // And since the status seam the QUOTE carries the same kind of entry, written by setStatus()
        // when the fixture moved it to Priced. Stamped the same way and for the same reason: this
        // assertion is about the ordering of the whole column, so hiding a row from it would weaken
        // it rather than simplify it.
        $this->stampLog($connection, 'Status changed from Draft to Priced.', '2026-02-28 08:00:00');

        // ── The quote screen ─────────────────────────────────────────────────────────
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        // Positive control for the dontSee-shaped claim below: both messages really are on the page.
        $I->assertSame(
            ['Quote message posted on the second', 'Quote message posted on the first', 'Status changed from Draft to Priced.'],
            $this->activityLogColumn($I, 3),
            'The quote lists its messages newest first, exactly as the order does.',
        );
        $I->assertSame(
            ['2026-03-02 2:30:00 PM', '2026-03-01 9:05:00 AM', '2026-02-28 8:00:00 AM'],
            $this->activityLogColumn($I, 1),
            'And the dates it prints are the ones stored against those messages.',
        );

        // ── The order screen, which is the standard being conformed to ───────────────
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            ['Order message posted on the second', 'Order message posted on the first', 'Order approved.'],
            $this->activityLogColumn($I, 3),
        );

        // ── Read the column back ─────────────────────────────────────────────────────
        // Not the entity built above, and not the collection the template walked: the ORDER BY the
        // screen must agree with is the one the database answers.
        $I->assertSame(
            ['Quote message posted on the second', 'Quote message posted on the first', 'Status changed from Draft to Priced.'],
            $connection->fetchFirstColumn(
                "SELECT summary FROM audit_log WHERE entity_type = 'Estimate' AND entity_id = ? AND actor_type = 'document' ORDER BY occurred_at DESC",
                [$estimate->getId()],
            ),
        );
        $I->assertSame(
            ['Order message posted on the second', 'Order message posted on the first', 'Order approved.'],
            $connection->fetchFirstColumn(
                "SELECT summary FROM audit_log WHERE entity_type = 'SalesOrder' AND entity_id = ? AND actor_type = 'document' ORDER BY occurred_at DESC",
                [$order->getId()],
            ),
        );
    }

    // ── D2 · the quote sums shipping into one figure ──────────────────────────────────

    /**
     * A document can carry a carrier charge, a fuel surcharge and a residential fee, each labelled
     * as it was entered. The order's totals box prints one row each and so does the quote's own
     * PDF; the quote's SCREEN printed a single summed "Shipping Fee", so three charges the customer
     * was quoted for became one figure nobody could take apart.
     *
     * Screen and PDF are asserted together, because the defect was precisely that they disagreed.
     */
    public function theQuoteNamesEveryShippingRowOnTheScreenAndInItsPdf(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, 'Split Shipping Quote Co');
        $estimate = $this->makeEstimate($I, $company, [
            'feeLines' => json_encode([
                $this->feeRow('carrier-charge', 'Carrier Charge', 12.34, 'main_line', 'shipping'),
                $this->feeRow('fuel-surcharge', 'Fuel Surcharge', 5.66, 'main_line', 'shipping'),
            ]),
        ]);

        // ── The screen ───────────────────────────────────────────────────────────────
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            ['Carrier Charge:' => '$12.34', 'Fuel Surcharge:' => '$5.66'],
            $this->screenShippingRows($I),
            'One row per shipping line on the quote screen, labelled as entered.',
        );
        // Paired with the positive control above: the rows render, and none of them is the summed
        // stand-in that used to print the two charges added together under one generic label.
        $I->assertArrayNotHasKey('Shipping Fee:', $this->screenShippingRows($I));
        $I->see('$12.34');
        $I->dontSee('$18.00');

        // ── The PDF, which had it right all along ────────────────────────────────────
        $I->amOnPage('/admin/estimate/quote/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            ['Carrier Charge:' => '$12.34', 'Fuel Surcharge:' => '$5.66'],
            $this->pdfShippingRows($I),
            'The quote PDF and the quote screen now name the same rows.',
        );

        // ── Read the column back ─────────────────────────────────────────────────────
        $stored = json_decode(
            (string) $this->connection($I)->fetchOne('SELECT fee_lines FROM estimate WHERE id = ?', [$estimate->getId()]),
            true,
        );
        $I->assertSame(
            [['Carrier Charge', 12.34], ['Fuel Surcharge', 5.66]],
            array_map(static fn (array $r): array => [$r['label'], $r['amount']], $stored),
            'Both screens print what the estimate.fee_lines snapshot actually holds.',
        );
    }

    /**
     * The exception to the order being the standard, and it is a rule rather than drift (#254/#255):
     * where the order stands in a $0.00 shipping row, an UNPRICED quote says TBD, because a quote is
     * allowed to leave shipping unstated and a stated $0.00 would tell the customer it ships free.
     * The quote's PDF has always done this and the screen has to keep doing it too.
     */
    public function anUnpricedQuoteStillSaysTbdForShippingOnTheScreenAndInItsPdf(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, 'Unpriced Shipping Quote Co');
        $estimate = $this->makeEstimate($I, $company, ['feeLines' => null]);

        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            ['Shipping Fee:' => 'TBD'],
            $this->screenShippingRows($I),
            'No shipping row at all is TBD on a quote, not $0.00 and not nothing.',
        );

        $I->amOnPage('/admin/estimate/quote/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(['Shipping Fee:' => 'TBD'], $this->pdfShippingRows($I));

        $I->assertNull(
            $this->connection($I)->fetchOne('SELECT fee_lines FROM estimate WHERE id = ?', [$estimate->getId()]),
            'TBD because the snapshot holds no shipping row — not because a figure was hidden.',
        );
    }

    // ── D3 · the invoice ignores fee placement ────────────────────────────────────────

    /**
     * Three charges, one of each placement, on one invoice.
     *
     * The invoice looped its charge rows unfiltered into its `<tfoot>`, so a charge placed AFTER tax
     * printed above the tax rows and a main-line charge — a line item by design — appeared in the
     * footer. The order's layout is the authority (recorded 2026-09-12) and the invoice's own PDF
     * already followed it, which is the disagreement asserted here on both sides.
     *
     * The data comes from the `feeLineRows` accessor rather than a template-side `json_decode` of
     * the blob; the LAYOUT is the order's. That is the better half of each.
     */
    public function theInvoicePlacesEachChargeWhereItsPlacementSaysOnTheScreenAndInItsPdf(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, 'Placement Invoice Co');
        $invoice = $this->makeStandaloneInvoice($I, $company, [
            'feeLines' => json_encode([
                $this->feeRow('handling', 'Handling Charge', 7.25, 'main_line'),
                $this->feeRow('pallet-deposit', 'Pallet Deposit', 3.50, 'before_tax_line'),
                $this->feeRow('late-fee', 'Late Fee', 9.75, 'after_tax_line'),
            ]),
        ]);

        // ── The screen ───────────────────────────────────────────────────────────────
        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();

        // main_line is a line item: it belongs in the table BODY, beside the products.
        $I->assertSame(
            '$7.25',
            $this->lineCell($I, 'Handling Charge', 7),
            'A main-line charge is a row of the line table, as it is on the order.',
        );
        // Paired with the assertion above — the row exists, and it is not ALSO in the footer.
        $I->assertNotContains('Handling Charge', $this->footRowLabels($I));

        // before_tax sits above the tax rows; after_tax sits below Total Tax. Read as positions in
        // the one list of footer labels, so the claim is about order and not merely presence.
        $labels = $this->footRowLabels($I);
        $I->assertContains('Pallet Deposit', $labels);
        $I->assertContains('Late Fee', $labels);
        $I->assertLessThan(
            array_search('GST 5%', $labels, true),
            array_search('Pallet Deposit', $labels, true),
            'A before-tax charge is charged before tax, so it prints above the tax rows.',
        );
        $I->assertGreaterThan(
            array_search('Total Tax', $labels, true),
            array_search('Late Fee', $labels, true),
            'An after-tax charge prints below Total Tax — the defect was that it printed above it.',
        );
        $I->assertSame('$3.50', $this->footRow($I, 'Pallet Deposit'));
        $I->assertSame('$9.75', $this->footRow($I, 'Late Fee'));

        // ── The PDF, which had it right all along ────────────────────────────────────
        $I->amOnPage('/admin/invoice/print/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $pdfLabels = $this->pdfTotalsLabels($I);
        $I->assertLessThan(
            array_search('GST 5%:', $pdfLabels, true),
            array_search('Pallet Deposit:', $pdfLabels, true),
        );
        $I->assertGreaterThan(
            array_search('Total Tax:', $pdfLabels, true),
            array_search('Late Fee:', $pdfLabels, true),
        );
        $I->assertNotContains(
            'Handling Charge:',
            $pdfLabels,
            'The PDF has always printed the main-line charge as a line row, not a totals row.',
        );

        // ── Read the column back ─────────────────────────────────────────────────────
        $stored = json_decode(
            (string) $this->connection($I)->fetchOne('SELECT fee_lines FROM invoice WHERE id = ?', [$invoice->getId()]),
            true,
        );
        $I->assertSame(
            ['Handling Charge' => 'main_line', 'Pallet Deposit' => 'before_tax_line', 'Late Fee' => 'after_tax_line'],
            array_combine(
                array_column($stored, 'label'),
                array_column($stored, 'placement'),
            ),
            'Each row printed where the placement stored against it says it goes.',
        );
    }

    // ── D4 · the invoice omits shipping entirely when there is none ───────────────────

    /**
     * "A summed Shipping Fee stands in when the document has none, so the invoice never simply omits
     * shipping" — the invoice PDF's own comment, and the rule the order's totals box follows. The
     * screen dropped the row, so an invoice with no shipping charge was silent about shipping
     * instead of saying it cost nothing.
     *
     * The invoice WITH a shipping row is the positive control on the same element: the stand-in has
     * to be a stand-in, not a row printed unconditionally.
     */
    public function theInvoiceStandsInAZeroShippingRowWhenItHasNoneOnTheScreenAndInItsPdf(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, 'Shipping Stand-in Invoice Co');
        $bare = $this->makeStandaloneInvoice($I, $company, ['feeLines' => null]);
        $shipped = $this->makeStandaloneInvoice($I, $company, [
            'feeLines' => json_encode([
                $this->feeRow('carrier-charge', 'Carrier Charge', 21.50, 'main_line', 'shipping'),
            ]),
        ]);

        // ── No shipping charge: the screen says $0.00 rather than nothing ────────────
        $I->amOnPage('/admin/invoice/detail/' . $bare->getId());
        $I->seeResponseCodeIsSuccessful();
        // The invoice uses the same totals box as the order and the quote now, so it labels this
        // row "Shipping Fee:" as they do — the punctuation converged with the placement.
        $I->assertSame(
            ['Shipping Fee:' => '$0.00'],
            $this->screenShippingRows($I),
        );

        $I->amOnPage('/admin/invoice/print/' . $bare->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(['Shipping Fee:' => '$0.00'], $this->pdfShippingRows($I));

        // ── The positive control on the same row: a real charge replaces the stand-in ─
        $I->amOnPage('/admin/invoice/detail/' . $shipped->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            ['Carrier Charge:' => '$21.50'],
            $this->screenShippingRows($I),
            'A document that HAS a shipping row names it, and does not also print the stand-in.',
        );

        $I->amOnPage('/admin/invoice/print/' . $shipped->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(['Carrier Charge:' => '$21.50'], $this->pdfShippingRows($I));

        // ── Read the column back ─────────────────────────────────────────────────────
        $connection = $this->connection($I);
        $I->assertNull(
            $connection->fetchOne('SELECT fee_lines FROM invoice WHERE id = ?', [$bare->getId()]),
            'The $0.00 row is a stand-in for an empty snapshot, not a stored zero.',
        );
        $I->assertSame(
            'shipping',
            json_decode((string) $connection->fetchOne('SELECT fee_lines FROM invoice WHERE id = ?', [$shipped->getId()]), true)[0]['type'],
        );
    }

    // ── D5 · quantity formatting ──────────────────────────────────────────────────────

    /**
     * The owner's formatting rule for the whole application, which outranks all three screens:
     * a QUANTITY prints to at most six decimal places, and a whole number prints with no decimals
     * at all. So 2.5 reads "2.5", 3 reads "3", and never "3.000000", "3.0" or — the defect on the
     * order and the quote — a rounded "3".
     *
     * The invoice's `+ 0` already produced exactly that; the order and the quote ran the figure
     * through `number_format(0)`, which rounded 2.5 up to 3 on the screen while the column held 2.5.
     *
     * DISPLAY only. Nothing here is about entry.
     */
    public function everySellSideDetailScreenPrintsAQuantityWithoutRoundingItAndWithoutTrailingZeros(FunctionalTester $I): void
    {
        $connection = $this->connection($I);
        $company = $this->makeCompany($I, 'Quantity Display Co');

        $order = $this->makeOrder($I, $company, ['quantities' => ['2.5000', '3.0000']]);
        $estimate = $this->makeEstimate($I, $company, ['quantities' => ['2.5000', '3.0000']]);
        $invoice = $this->makeStandaloneInvoice($I, $company, ['quantities' => ['2.5000', '3.0000']]);

        foreach ([
            '/admin/order/detail/' . $order->getId(),
            '/admin/estimate/detail/' . $estimate->getId(),
            '/admin/invoice/detail/' . $invoice->getId(),
        ] as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            $I->assertSame(
                '2.5',
                $this->lineCell($I, 'PARITY-QTY-FRACTIONAL', 5),
                sprintf('%s must print the fractional quantity it stores, not a rounded one.', $url),
            );
            $I->assertSame(
                '3',
                $this->lineCell($I, 'PARITY-QTY-WHOLE', 5),
                sprintf('%s must print a whole quantity with no decimals at all.', $url),
            );
        }

        // ── Read the columns back ────────────────────────────────────────────────────
        // What the screens printed against what the three line tables actually hold. The scale is
        // the column's (NUMERIC(14,4)); the rendering is the rule's.
        foreach ([
            ['sales_order_line', 'order_id', $order->getId()],
            ['estimate_line', 'estimate_id', $estimate->getId()],
            ['invoice_line', 'invoice_id', $invoice->getId()],
        ] as [$table, $fk, $id]) {
            $stored = $connection->fetchAllKeyValue(
                sprintf('SELECT sku, quantity FROM %s WHERE %s = ?', $table, $fk),
                [$id],
            );
            $I->assertSame(2.5, (float) $stored['PARITY-QTY-FRACTIONAL'], $table . ' stores the fraction.');
            $I->assertSame(3.0, (float) $stored['PARITY-QTY-WHOLE'], $table . ' stores the whole number.');
        }
    }

    // ── D7 · the invoice's activity log hides a column it has data for ────────────────

    /**
     * All three timeline entities carry `customer_notified`. The order and the quote print a Yes/No
     * column; the invoice's table was four columns and dropped it, so the one record that says
     * whether the buyer has already been told was invisible on the document that is the demand for
     * payment.
     *
     * Conducted: the invoice is actually emailed to the customer through the send endpoint, which is
     * the only thing in the app that writes `customer_notified = 1` on an invoice log — so the Yes
     * on the screen is a real send, and the No beside it is a real internal entry.
     */
    public function theInvoiceActivityLogSaysWhetherTheCustomerWasNotified(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, 'Notified Invoice Co');
        [, $invoice] = $this->invoicedOrder($I, $company);

        $I->amOnPage('/admin/invoice/print/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom(
            '//form[.//input[@name="type" and @value="customer"]]//input[@name="_token"]',
            'value',
        );
        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/admin/invoice/send/' . $invoice->getId(), ['_token' => $token, 'type' => 'customer']);
        $I->seeEmailIsSent();
        $I->startFollowingRedirects();

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->assertContains(
            'Customer Notified',
            $this->activityLogHeadings($I),
            'The column the order and the quote both carry.',
        );

        $notified = $this->activityLogNotifiedByComment($I);
        // Positive control and the absence it is paired with, on the same column: the send says Yes,
        // and the internal entry beside it says No rather than the column being blank or absent.
        $I->assertContains('Yes', $notified, 'The emailed copy is recorded as one the buyer was told about.');
        $I->assertContains('No', $notified, 'And an internal entry is recorded as one they were not.');

        // ── Read the column back ─────────────────────────────────────────────────────
        $stored = $this->connection($I)->fetchAllKeyValue(
            "SELECT summary, recipient_notified FROM audit_log WHERE entity_type = 'Invoice' AND entity_id = ? AND actor_type = 'document'",
            [$invoice->getId()],
        );
        foreach ($stored as $comment => $flag) {
            $I->assertSame(
                ((int) $flag) === 1 ? 'Yes' : 'No',
                $notified[$comment] ?? null,
                sprintf('The screen must agree with audit_log.recipient_notified for "%s".', $comment),
            );
        }
        $I->assertContains(1, array_map('intval', array_values($stored)));
        $I->assertContains(0, array_map('intval', array_values($stored)));
    }

    // ── D8 · "Edit Tracking Info" on the order screen is dead ─────────────────────────

    /**
     * The order's Info card offered a "Shipping Tracking Info" row whose value was the words "Edit
     * Tracking Info", styled blue with a pointer cursor. Nothing was behind it: no column, no route,
     * no handler. The invoice screen's own comment already recorded that it deliberately did not
     * copy the row.
     *
     * A control that looks clickable and does nothing is a defect, which is why this is the one
     * place the order is not the standard to conform to.
     */
    public function theOrderScreenOffersNoDeadEditTrackingInfoControl(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, 'Tracking Row Co');
        $order = $this->makeOrder($I, $company);

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        // Positive controls on the same panel: it rendered, and its neighbouring rows are there.
        $I->assertSame('Net 15', $this->panelRow($I, 'Order Info', 'Payment Terms:'));
        $I->assertSame('Pay Upon Delivery', $this->panelRow($I, 'Order Info', 'Payment Method:'));

        $I->dontSeeElement(
            '//section[.//h2[normalize-space()="Order Info"]]'
            . '//div[contains(@class,"detail-row")][label[normalize-space()="Shipping Tracking Info:"]]',
        );
        $I->dontSee('Edit Tracking Info');

        // Nothing backs it, which is why it goes rather than gets wired up: the document holds no
        // tracking field of its own, on any of the three sell-side entities.
        foreach ([SalesOrder::class, Invoice::class, Estimate::class] as $class) {
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                $I->assertStringNotContainsStringIgnoringCase(
                    'tracking',
                    $property->getName(),
                    $class . ' holds no shipment tracking field for such a control to edit.',
                );
            }
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────────

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('SSDP-' . uniqid())
            ->setPrimaryEmail('ap-' . uniqid() . '@sell-side-parity.example')
            ->setPhoneNumber('+1 604 555 0199');
        $I->haveInRepository($company);

        return $company;
    }

    /**
     * Two products whose SKUs are the anchors every line assertion addresses a row by. Created once
     * per run and reused, so a Cest method never depends on another having run first.
     */
    private function product(FunctionalTester $I, string $sku): ProductCore
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $existing = $entityManager->getRepository(ProductCore::class)->findOneBy(['sku' => $sku]);
        if ($existing instanceof ProductCore) {
            return $existing;
        }

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Parity ' . $sku)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    /**
     * The two line quantities every document fixture here carries, so one shape of data reaches all
     * three screens. 2.5 and 3 at $10.00: $25.00 + $30.00 = $55.00, 5% GST $2.75, total $57.75 —
     * and no figure on any of these pages is a substring of another.
     *
     * @param list<string> $quantities
     */
    private static function lineSpec(array $quantities): array
    {
        return [
            ['PARITY-QTY-FRACTIONAL', $quantities[0], '10.00', '25.00'],
            ['PARITY-QTY-WHOLE', $quantities[1], '10.00', '30.00'],
        ];
    }

    private function makeEstimate(FunctionalTester $I, Company $company, array $overrides = []): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('SSDP-EST-' . uniqid())
            ->setSource('Admin')
            ->setSubtotal('55.00')
            ->setTax('2.75')
            ->setTotal('57.75')
            ->setFeeLines(\array_key_exists('feeLines', $overrides) ? $overrides['feeLines'] : null);
        $estimate->setStatus('Priced', DocumentActor::system());

        foreach (self::lineSpec($overrides['quantities'] ?? ['2.5000', '3.0000']) as [$sku, $quantity, $price, $subtotal]) {
            $estimate->addLine(
                (new EstimateLine())
                    ->setProduct($this->product($I, $sku))
                    ->setName('Parity ' . $sku)
                    ->setSku($sku)
                    ->setQuantity($quantity)
                    ->setPrice($price)
                    ->setSubtotal($subtotal)
            );
        }

        $I->haveInRepository($estimate);

        return $estimate;
    }

    private function makeOrder(FunctionalTester $I, Company $company, array $overrides = []): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('SSDP-SO-' . uniqid())
            ->setPaymentMethod('Pay Upon Delivery')
            ->setPaymentTerm('Net 15')
            ->setSubtotal('55.00')
            ->setTax('2.75')
            ->setTotal('57.75')
            ->setFeeLines(\array_key_exists('feeLines', $overrides) ? $overrides['feeLines'] : null);

        foreach (self::lineSpec($overrides['quantities'] ?? ['2.5000', '3.0000']) as [$sku, $quantity, $price, $subtotal]) {
            $order->addLine(
                (new SalesOrderLine())
                    ->setProduct($this->product($I, $sku))
                    ->setName('Parity ' . $sku)
                    ->setSku($sku)
                    ->setQuantity($quantity)
                    ->setPrice($price)
                    ->setSubtotal($subtotal)
            );
        }

        $I->haveInRepository($order);
        // #539 stage 2: a live order is a persisted Draft that has been approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    /**
     * An invoice with no order behind it. Built directly, because the whole point of the standalone
     * case is that no order was involved — and because every money assertion here is about the
     * invoice's own snapshot.
     */
    private function makeStandaloneInvoice(FunctionalTester $I, Company $company, array $overrides = []): Invoice
    {
        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('SSDP-INV-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setInvoiceDate('2026-09-01')
            ->setSubtotal('55.00')
            ->setTax('2.75')
            ->setTotal('57.75')
            ->setFeeLines(\array_key_exists('feeLines', $overrides) ? $overrides['feeLines'] : null);
        $invoice->setTaxLines(json_encode([
            'lines' => [['label' => 'GST', 'rate' => 0.05, 'amount' => 2.75]],
            'total' => 2.75,
        ]));

        foreach (self::lineSpec($overrides['quantities'] ?? ['2.5000', '3.0000']) as [$sku, $quantity, $price, $subtotal]) {
            $invoice->addLine(
                (new InvoiceLine())
                    ->setProduct($this->product($I, $sku))
                    ->setName('Parity ' . $sku)
                    ->setSku($sku)
                    ->setQuantity($quantity)
                    ->setPrice($price)
                    ->setSubtotal($subtotal)
            );
        }

        $I->haveInRepository($invoice);

        return $invoice;
    }

    /**
     * An approved order invoiced in full through the real service — the path every invoice raised
     * against an order is born on, and the one the send endpoint needs an order behind.
     *
     * @return array{0: SalesOrder, 1: Invoice}
     */
    private function invoicedOrder(FunctionalTester $I, Company $company): array
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $order = $this->makeOrder($I, $company);

        $invoice = $I->grabService(OrderInvoicingService::class)->invoiceInFull(
            $order,
            $entityManager,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
        );
        $entityManager->flush();

        return [$order, $invoice];
    }

    /** One charge row of the fee_lines snapshot, in the shape FeeLineSnapshot::encode() writes. */
    private function feeRow(
        string $slug,
        string $label,
        float $amount,
        string $placement,
        string $type = 'fee',
    ): array {
        return [
            'slug' => $slug,
            'label' => $label,
            'taxClass' => 'E',
            'amount' => $amount,
            'placement' => $placement,
            'type' => $type,
            'source' => 'manual',
        ];
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }

    /**
     * Moves a timeline row's stored timestamp, so two messages posted inside one second order
     * deterministically. Written straight to the column because no entity exposes a setter for it —
     * the row itself was created by the real endpoint.
     */
    private function stampLog(Connection $connection, string $comment, string $when): void
    {
        $connection->executeStatement(
            'UPDATE audit_log SET occurred_at = ? WHERE summary = ?',
            [$when, $comment],
        );
    }

    // ── Cell readers ─────────────────────────────────────────────────────────────────
    //
    // Each addresses ONE cell by the class of the table or panel it sits in plus the label beside
    // it, so an assertion can only pass against the field it names (#627).

    private function panelRow(FunctionalTester $I, string $panel, string $label): string
    {
        return self::collapse($I->grabTextFrom(sprintf(
            '//div[contains(@class,"order-detail-grid")]/section[.//h2[normalize-space()="%s"]]'
            . '//div[contains(@class,"detail-row")][label[normalize-space()="%s"]]/strong',
            $panel,
            $label,
        )));
    }

    /** $column is 1-based across Name, U/M, SKU, Weight, Quantity, Price, Subtotal, Tax Code, Tax $. */
    private function lineCell(FunctionalTester $I, string $rowLabel, int $column): string
    {
        return self::collapse($I->grabTextFrom(sprintf(
            '//table[contains(@class,"order-products-table")]/tbody/tr[td[normalize-space()="%s"]]/td[%d]',
            $rowLabel,
            $column,
        )));
    }

    /** The money against a named row in the shared totals box. */
    private function footRow(FunctionalTester $I, string $label): string
    {
        return self::collapse($I->grabTextFrom(sprintf('//div[span[normalize-space()="%s:"]]/strong', $label)));
    }

    /**
     * @return list<string> every totals-row label in the shared totals box, in order.
     *
     * The invoice used to itemise its money in the line table's own <tfoot>; it uses the same box
     * as the order and the quote now, so these read there. Labels carry a trailing colon in the
     * box and did not in the tfoot, so it is stripped here rather than at every call site.
     */
    private function footRowLabels(FunctionalTester $I): array
    {
        return array_map(
            static fn (string $text): string => rtrim(self::collapse($text), ':'),
            $I->grabMultiple('//main[@id="main-content"]//div[starts-with(normalize-space(@style),"display:flex; justify-content:flex-end")]//div/span'),
        );
    }

    /**
     * The shipping rows of a detail screen's or a document's totals box, label => figure.
     *
     * `shipping-fee-row` is the hook the quote PDF has carried since #254; the three detail screens
     * now carry it too, so one reader addresses the same row wherever it is rendered.
     *
     * @return array<string, string>
     */
    private function screenShippingRows(FunctionalTester $I): array
    {
        return self::pairs(
            $I->grabMultiple('//*[contains(@class,"shipping-fee-row")]/*[1]'),
            $I->grabMultiple('//*[contains(@class,"shipping-fee-row")]/*[2]'),
        );
    }

    /** @return array<string, string> */
    private function pdfShippingRows(FunctionalTester $I): array
    {
        return self::pairs(
            $I->grabMultiple('//table[contains(@class,"totals-inner")]//tr[contains(@class,"shipping-fee-row")]/td[1]'),
            $I->grabMultiple('//table[contains(@class,"totals-inner")]//tr[contains(@class,"shipping-fee-row")]/td[2]'),
        );
    }

    /** @return list<string> every totals-row label in a document PDF's totals table, in order. */
    private function pdfTotalsLabels(FunctionalTester $I): array
    {
        return array_map(
            static fn (string $text): string => self::collapse($text),
            $I->grabMultiple('//table[contains(@class,"totals-inner")]//tr/td[1]'),
        );
    }

    /** @return list<string> the Activity Log table's column headings. */
    private function activityLogHeadings(FunctionalTester $I): array
    {
        return array_map(
            static fn (string $text): string => self::collapse($text),
            $I->grabMultiple('//section[.//h2[normalize-space()="Activity Log"]]//table/thead/tr/th'),
        );
    }

    /** @return list<string> one column of the Activity Log table, top row first. */
    private function activityLogColumn(FunctionalTester $I, int $column): array
    {
        return array_map(
            static fn (string $text): string => self::collapse($text),
            $I->grabMultiple(sprintf(
                '//section[.//h2[normalize-space()="Activity Log"]]//table/tbody/tr/td[%d]',
                $column,
            )),
        );
    }

    /** @return array<string, string> the Activity Log's Customer Notified cell, keyed by comment. */
    private function activityLogNotifiedByComment(FunctionalTester $I): array
    {
        return self::pairs($this->activityLogColumn($I, 3), $this->activityLogColumn($I, 5));
    }

    /**
     * @param list<string> $keys
     * @param list<string> $values
     *
     * @return array<string, string>
     */
    private static function pairs(array $keys, array $values): array
    {
        $pairs = [];
        foreach ($keys as $index => $key) {
            $pairs[self::collapse($key)] = self::collapse($values[$index] ?? '');
        }

        return $pairs;
    }

    /** One space between words, nothing at the ends — a cell may hold a <br> and a second line. */
    private static function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
