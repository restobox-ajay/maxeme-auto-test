<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AbstractDocumentAddress;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoiceLineStockOverride;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\SalesOrderLineStockOverride;
use App\Enum\InvoiceIssueIntent;
use App\Service\DocumentActor;
use App\Service\OrderInvoicingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The shared components the three sell-side DETAIL screens are built from, and the differences
 * between the three that are PARAMETERS of those components rather than accidents.
 *
 * The extraction itself was proved by rendering ten branch-covering documents before and after
 * and comparing the two as DOM trees. That proof is a one-off and cannot be committed. What CAN
 * be committed, and is what this file is, is the set of claims a future edit to one partial would
 * break silently on the OTHER two screens — which is exactly the hazard a shared component adds.
 *
 * Every difference asserted here was a real difference between the three screens before the
 * partials existed. None of them is a `{% if document == ... %}`; each is a named parameter, and
 * each case below pins what one caller passes AGAINST what another caller passes, so a parameter
 * that stops being honoured shows up as a disagreement rather than as a page that still renders.
 *
 * ## How these are written (#624, #627)
 *
 * No assertion is a bare number or a bare word: every figure and every label is grabbed from the
 * cell addressed by the table it sits in plus the label beside it, and compared exactly.
 *
 * Every absence is paired with a positive control on the SAME element — the invoice's Activity
 * Log has no Actions column, and the case that says so also counts the columns it DOES have, so
 * "the table did not render" cannot pass as "the column is correctly gone".
 *
 * No state on $this: Codeception reuses one Cest instance across every method in this file.
 */
final class AdminSellSideDetailComponentsCest
{
    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('detail-components@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    // ── sales_detail_address_panel: `emptyMessage` ────────────────────────────

    /**
     * An address-less ORDER keeps its thirteen rows; an address-less INVOICE prints a sentence.
     *
     * The first trap in sharing this panel. The order and the quote fall back PER FIELD to the
     * company snapshot, so the rows still say something; the invoice falls back WHOLE-PANEL,
     * because an invoice raised on its own has nothing to print there and a grid of empty rows
     * would read as data that failed to load. Passing one document's behaviour to the other would
     * change what an address-less document asserts, in silence.
     *
     * Both halves are asserted in one case, so what is pinned is that the two DISAGREE on purpose.
     */
    public function anAddressLessOrderKeepsItsRowsWhileAnAddressLessInvoicePrintsASentence(FunctionalTester $I): void
    {
        $company = $this->company($I, 'Address Fallback Co');
        $order = $this->order($I, $company, 'CMP-SO-NOADDR');

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->assertSame(
            'Address Fallback Co',
            $this->panelRow($I, 'Billing Detail', 'Company Name'),
            'an address-less order falls back per field to the company snapshot',
        );
        $I->assertSame(
            'Canada',
            $this->panelRow($I, 'Billing Detail', 'Country'),
            "the order's country fallback",
        );
        $I->assertSame(13, $this->panelRowCount($I, 'Billing Detail'), 'the order prints every row');
        $I->assertSame([], $this->panelSentences($I, 'Billing Detail'), 'and prints no sentence');

        $invoice = $this->standaloneInvoice($I, $company, 'CMP-INV-NOADDR');
        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->assertSame(0, $this->panelRowCount($I, 'Billing Detail'), 'the invoice prints no rows at all');
        $I->assertSame(
            ['This invoice recorded no billing address. It was not raised against a sales order, so there was none to inherit.'],
            $this->panelSentences($I, 'Billing Detail'),
            'it prints the sentence instead, with the clause a standalone invoice earns',
        );
        // Positive control on the same page: the panel rendered, it simply has no rows.
        $I->assertSame(
            'Address Fallback Co',
            $this->panelRow($I, 'Invoice Info', 'Customer Name'),
            'the page itself rendered — the absent rows are the panel, not the page',
        );
    }

    /**
     * The second clause of that sentence is the INVOICE's own question and belongs to the caller.
     *
     * An invoice raised AGAINST an order that itself recorded no address says only the first
     * sentence: there WAS an order, it simply had nothing to inherit. The clause is built in
     * invoice/detail.html.twig rather than inside the shared card, which could not know.
     */
    public function anInvoiceRaisedAgainstAnOrderDropsTheStandaloneClause(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $company = $this->company($I, 'Linked No Address Co');
        $order = $this->order($I, $company, 'CMP-SO-LINKED');
        $entityManager->flush();
        $invoice = $I->grabService(OrderInvoicingService::class)
            ->invoiceInFull($order, $entityManager, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $entityManager->flush();

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->assertSame(
            ['This invoice recorded no shipping address.'],
            $this->panelSentences($I, 'Shipping Detail'),
            'an invoice WITH an order behind it says only the first sentence',
        );
        $I->assertSame(0, $this->panelRowCount($I, 'Shipping Detail'), 'and still prints no rows');
    }

    // ── sales_detail_address_panel: `fieldFallback` ───────────────────────────

    /**
     * A blank field on an address the document DID record: empty on the order, a dash on the
     * invoice.
     *
     * The trap that was invisible and would have been the easiest to lose. Both screens print
     * thirteen rows here — the address exists — but they disagree about what a null column reads
     * as, and `{{ address.fax }}` versus `{{ address.fax|default('-') }}` produces markup that
     * looks the same in a diff of the two templates. On a shipping snapshot with no fax and no
     * second e-mail that is four cells differing between two documents describing one shipment.
     *
     * Conducted on ONE order and the invoice raised from it, so the two are describing the same
     * frozen address and the only thing that can differ is the rendering rule.
     */
    public function aBlankFieldOnARecordedAddressIsEmptyOnTheOrderAndADashOnTheInvoice(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $company = $this->company($I, 'Blank Field Co');
        $order = $this->order($I, $company, 'CMP-SO-BLANKS');
        // A real shipping snapshot with fax and both e-mails deliberately left null.
        $order->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING)
            ->setCompanyName('Blank Field Co')
            ->setAddressLine1('9 Blank Road')
            ->setCity('Delta')->setProvince('BC')->setCountry('Canada')
            ->setPostalCode('V4K 1A1');
        $entityManager->flush();

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->assertSame('', $this->panelRow($I, 'Shipping Detail', 'Fax Number'), 'the order leaves it empty');
        $I->assertSame('', $this->panelRow($I, 'Shipping Detail', 'Primary Email'), 'and the same for e-mail');
        // Positive control on the SAME panel: the rows that DO have a value carry it.
        $I->assertSame('9 Blank Road', $this->panelRow($I, 'Shipping Detail', 'Street Address'));

        $invoice = $I->grabService(OrderInvoicingService::class)
            ->invoiceInFull($order, $entityManager, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $entityManager->flush();

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->assertSame('-', $this->panelRow($I, 'Shipping Detail', 'Fax Number'), 'the invoice dashes it');
        $I->assertSame('-', $this->panelRow($I, 'Shipping Detail', 'Primary Email'), 'and the same for e-mail');
        $I->assertSame(
            '9 Blank Road',
            $this->panelRow($I, 'Shipping Detail', 'Street Address'),
            'reading the same frozen snapshot, so the rule is the only difference',
        );
    }

    // ── sales_detail_address_panel: `deliverable` and `trailingRows` ──────────

    /**
     * Delivery instructions belong to a card that is a delivery destination, and to no other.
     *
     * The shared card is included six times, and the snapshot class carries
     * `delivery_instructions` on BOTH addresses. Rendering the row wherever the column holds
     * something would have put it on the billing card, which no screen has ever done.
     */
    public function deliveryInstructionsShowOnTheShippingCardAndNeverOnTheBillingCard(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $company = $this->company($I, 'Delivery Note Co');
        $order = $this->order($I, $company, 'CMP-SO-DELIVERY');
        foreach ([AbstractDocumentAddress::TYPE_BILLING, AbstractDocumentAddress::TYPE_SHIPPING] as $type) {
            $order->addressForWriting($type)
                ->setCompanyName('Delivery Note Co')
                ->setAddressLine1('3 Bay Street')
                ->setCity('Richmond')->setProvince('BC')
                ->setDeliveryInstructions('Ring the bell at bay 3.');
        }
        $entityManager->flush();

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->assertSame(
            'Ring the bell at bay 3.',
            $this->panelRow($I, 'Shipping Detail', 'Delivery instructions'),
            'the shipping card shows it',
        );
        $I->assertSame(
            [],
            $I->grabMultiple($this->panelRowXpath('Billing Detail', 'Delivery instructions')),
            'the billing card never does, even holding the same value',
        );
        // Positive control on the SAME card: it rendered, and its other rows are there.
        $I->assertSame('3 Bay Street', $this->panelRow($I, 'Billing Detail', 'Street Address'));
    }

    /**
     * The quote's shipping card ends on a Method row that is a field of the QUOTE, not of the
     * address — and the order's card, reading the same partial, does not grow one.
     */
    public function onlyTheQuoteShippingCardCarriesTheTrailingMethodRow(FunctionalTester $I): void
    {
        $company = $this->company($I, 'Trailing Row Co');
        $quote = $this->estimate($I, $company, 'CMP-EST-METHOD', priced: true);
        $quote->setShippingMethod('Ground');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->amOnPage('/admin/estimate/detail/' . $quote->getId());
        $I->assertSame('Ground', $this->panelRow($I, 'Shipping Detail', 'Method'));
        $I->assertSame(
            'Secondary Email',
            $this->panelRowLabels($I, 'Shipping Detail')[count($this->panelRowLabels($I, 'Shipping Detail')) - 2],
            'and it is appended AFTER Secondary Email, where it has always been',
        );

        $order = $this->order($I, $company, 'CMP-SO-NOMETHOD');
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->assertNotContains('Method', $this->panelRowLabels($I, 'Shipping Detail'), 'the order grows no Method row');
        $I->assertContains('Secondary Email', $this->panelRowLabels($I, 'Shipping Detail'), 'but the card did render');
    }

    // ── sales_detail_activity_log: `deleteRoute` ──────────────────────────────

    /**
     * The order's timeline offers Delete; the invoice's has no Actions column at all.
     *
     * There is no admin_invoice_log_delete route, so a Delete button there would point at
     * nothing. The column count, the header labels and the empty row's colspan are all read from
     * one list inside the partial, so this case checks all three agree on each screen.
     */
    public function onlyTheDocumentsWithADeleteRouteCarryAnActionsColumn(FunctionalTester $I): void
    {
        $company = $this->company($I, 'Timeline Co');
        $order = $this->order($I, $company, 'CMP-SO-TIMELINE');

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->assertSame(
            ['Date', 'User', 'Comment', 'Type', 'Customer Notified', 'Actions'],
            $this->activityLogHeadings($I),
            'the order carries six columns',
        );
        $I->assertSame(6, $this->activityLogRowCellCount($I), 'and every row carries all six');

        $invoice = $this->standaloneInvoice($I, $company, 'CMP-INV-TIMELINE');
        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->assertSame(
            ['Date', 'User', 'Comment', 'Type', 'Customer Notified'],
            $this->activityLogHeadings($I),
            'the invoice carries five — no Actions, and Customer Notified is the positive control',
        );
        $I->assertSame(5, $this->activityLogRowCellCount($I), 'and every row carries exactly those five');
    }

    // ── sales_detail_payment_status_field: `value` ────────────────────────────

    /**
     * Paid is green and everything else is red, on the two documents that have the field.
     *
     * The colour IS the answer in this row, and the two screens derive the value differently —
     * the order rolls it up from its invoices, the invoice derives it from its own payment rows —
     * so what is pinned is that one vocabulary reaches one badge.
     */
    public function thePaymentStatusBadgeIsGreenOnlyWhenPaid(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $company = $this->company($I, 'Payment Badge Co');
        $order = $this->order($I, $company, 'CMP-SO-PAYMENT');
        $entityManager->flush();
        $invoice = $I->grabService(OrderInvoicingService::class)
            ->invoiceInFull($order, $entityManager, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $entityManager->flush();

        foreach ([
            '/admin/order/detail/' . $order->getId() => 'Order Info',
            '/admin/invoice/detail/' . $invoice->getId() => 'Invoice Info',
        ] as $url => $panel) {
            $I->amOnPage($url);
            $classes = $I->grabAttributeFrom($this->paymentBadgeXpath($panel), 'class');
            $I->assertStringContainsString('danger-soft', $classes, "an unpaid document is red on {$url}");
            $I->assertStringNotContainsString('success', $classes, "and never green on {$url}");
            $I->assertNotSame('Paid', $I->grabTextFrom($this->paymentBadgeXpath($panel)), 'the label agrees with the colour');
        }
    }

    // ── sales_detail_line_table: `showBatch`, `allowUnpriced` ─────────────────

    /**
     * The quote's line table is the order's minus the Batch column, and its empty row counts the
     * same list the header did.
     *
     * The 10-versus-9 column count ripples into the colspan of the empty row, which is the sort of
     * thing that goes wrong silently and shows up as a table one cell out of alignment rather than
     * as an error. Both are read from one list in the partial; this pins both ends of it.
     */
    public function theQuoteLineTableDropsBatchAndItsEmptyRowCountsTheSameColumns(FunctionalTester $I): void
    {
        $company = $this->company($I, 'Column Count Co');

        $order = $this->order($I, $company, 'CMP-SO-COLUMNS', withLines: false);
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->assertSame(
            ['Name', 'U/M', 'SKU', 'Weight', 'Quantity', 'Price', 'Subtotal', 'Tax Code', 'Tax $', 'Batch'],
            $this->lineTableHeadings($I),
        );
        $I->assertSame('10', $this->lineTableEmptyColspan($I));

        $quote = $this->estimate($I, $company, 'CMP-EST-COLUMNS', priced: true, withLines: false);
        $I->amOnPage('/admin/estimate/detail/' . $quote->getId());
        $I->assertSame(
            ['Name', 'U/M', 'SKU', 'Weight', 'Quantity', 'Price', 'Subtotal', 'Tax Code', 'Tax $'],
            $this->lineTableHeadings($I),
            'no Batch — a quote has picked nothing, so there is no lot to name',
        );
        $I->assertSame('9', $this->lineTableEmptyColspan($I), 'and the empty row spans exactly nine');
    }

    /**
     * An unpriced quote reads TBD where a priced order reads a figure — never $0.00 (#255).
     *
     * A stated $0.00 would tell the customer the line is free. `allowUnpriced` is what separates
     * the two, and it reaches three cells of every row: Price, Subtotal and Tax $.
     */
    public function anUnpricedQuoteLineReadsTbdInEveryMoneyCell(FunctionalTester $I): void
    {
        $company = $this->company($I, 'Unpriced Co');
        $quote = $this->estimate($I, $company, 'CMP-EST-TBD', priced: false);

        $I->amOnPage('/admin/estimate/detail/' . $quote->getId());
        $I->assertSame('TBD', $this->lineCell($I, 'CMP-A', 6), 'Price');
        $I->assertSame('TBD', $this->lineCell($I, 'CMP-A', 7), 'Subtotal');
        $I->assertSame('TBD', $this->lineCell($I, 'CMP-A', 9), 'Tax $');
        // Positive control on the SAME row: the non-money cells still carry their values.
        $I->assertSame('CMP-A', $this->lineCell($I, 'CMP-A', 3), 'the row rendered; only the money is unstated');

        $order = $this->order($I, $company, 'CMP-SO-PRICED');
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->assertSame('$10.00', $this->lineCell($I, 'CMP-A', 6), 'a priced order states the figure');
        $I->assertSame('$25.00', $this->lineCell($I, 'CMP-A', 7));
    }

    // ── sales_detail_stock_override_panel: heading, column, capacity ──────────

    /**
     * The override panel names what the document did — sold, or billed — and only the order
     * credits backorder capacity.
     *
     * An invoice line has no split to fall back on, so capacity never stood behind those units and
     * printing it would suggest something covered them. Both documents carry the same override
     * figures here, so the rendering rule is the only thing that can differ.
     */
    public function theOverridePanelSpeaksEachDocumentsOwnLanguage(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $company = $this->company($I, 'Override Language Co');

        $order = $this->order($I, $company, 'CMP-SO-OVERRIDE');
        $orderLine = $order->getLines()->first();
        $override = (new SalesOrderLineStockOverride())
            ->setOrderLine($orderLine)
            ->setRegionName('BC Lower Mainland')
            ->setRequestedQuantity('40')
            ->setAvailableQuantity('-5')
            ->setBackorderCapacity(6)
            ->setReason('Customer accepted a split shipment.')
            ->setOverriddenBy('dana@example.test');
        $orderLine->setStockOverride($override);
        $I->haveInRepository($override);
        $entityManager->flush();

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->assertSame('Sold beyond stock', $I->grabTextFrom('//section[@id="stock-overrides"]//h2'));
        $I->assertSame('Asked for', $this->overrideHeadings($I)[2]);
        $I->assertStringContainsString(
            '(after 6 backorder capacity)',
            $this->overrideShortfall($I),
            'the order credits the capacity it had',
        );

        $invoice = $I->grabService(OrderInvoicingService::class)
            ->invoiceInFull($order, $entityManager, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $invoiceLine = $invoice->getLines()->first();
        $invoiceOverride = (new InvoiceLineStockOverride())
            ->setInvoiceLine($invoiceLine)
            ->setRegionName('BC Lower Mainland')
            ->setRequestedQuantity('40')
            ->setAvailableQuantity('-5')
            ->setBackorderCapacity(6)
            ->setReason('Shipped short, billed in full by agreement.')
            ->setOverriddenBy(null);
        $invoiceLine->setStockOverride($invoiceOverride);
        $I->haveInRepository($invoiceOverride);
        $entityManager->flush();

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->assertSame('Billed beyond stock', $I->grabTextFrom('//section[@id="stock-overrides"]//h2'));
        $I->assertSame('Billed', $this->overrideHeadings($I)[2]);
        $I->assertStringNotContainsString(
            'backorder capacity',
            $this->overrideShortfall($I),
            'an invoice never credits capacity — nothing but stock stood behind those units',
        );
        // Positive control on the SAME cell: it rendered, carrying the shortfall it measured.
        $I->assertSame('34', $this->overrideShortfall($I), 'the figure itself is there — 40 asked, none available, 6 of capacity');
    }

    // ── sales_detail_totals_box: `allowUnstated`, `taxRowIds` ─────────────────

    /**
     * A shipping-free ORDER states $0.00; a shipping-free QUOTE states TBD.
     *
     * Shipping is the one figure a quote may leave unstated, and a stated $0.00 would tell the
     * customer it ships free (#254/#255). Neither document may simply omit the row, which is what
     * the stand-in is for, so this case also pins that both HAVE one.
     */
    public function theShippingStandInIsZeroOnAnOrderAndTbdOnAQuote(FunctionalTester $I): void
    {
        $company = $this->company($I, 'Shipping Stand In Co');

        $order = $this->order($I, $company, 'CMP-SO-SHIPPING');
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->assertSame(['Shipping Fee:' => '$0.00'], $this->shippingRows($I));

        $quote = $this->estimate($I, $company, 'CMP-EST-SHIPPING', priced: true);
        $I->amOnPage('/admin/estimate/detail/' . $quote->getId());
        $I->assertSame(['Shipping Fee:' => 'TBD'], $this->shippingRows($I));
    }

    /**
     * The order's tax rows are addressable by the calculator's own slug; the quote's are not.
     *
     * A pre-existing difference, kept exactly as it was: `tax-line-<slug>` is an id other things
     * point at on the order screen and the quote has never carried one. It is `taxRowIds`, so the
     * shared box cannot hand the quote ids it never had or take the order's away.
     */
    public function onlyTheOrderTotalsBoxKeysItsTaxRowsBySlug(FunctionalTester $I): void
    {
        $company = $this->company($I, 'Tax Row Id Co');

        $order = $this->order($I, $company, 'CMP-SO-TAXID');
        $order->setTaxLines(json_encode([
            'lines' => [['label' => 'GST', 'rate' => 0.05, 'amount' => 2.75, 'slug' => 'gst']],
            'total' => 2.75,
        ]));
        $I->grabService(EntityManagerInterface::class)->flush();
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->assertSame('$2.75', $I->grabTextFrom('//*[@id="tax-line-gst"]/strong'));

        $quote = $this->estimate($I, $company, 'CMP-EST-TAXID', priced: true);
        $quote->setTaxLines(json_encode([
            'lines' => [['label' => 'GST', 'rate' => 0.05, 'amount' => 2.75, 'slug' => 'gst']],
            'total' => 2.75,
            'perLineTax' => [],
            'perLineTaxLabel' => [],
        ]));
        $I->grabService(EntityManagerInterface::class)->flush();
        $I->amOnPage('/admin/estimate/detail/' . $quote->getId());
        $I->assertSame([], $I->grabMultiple('//*[@id="tax-line-gst"]'), 'the quote carries no such id');
        // Positive control on the SAME row: it is rendered, it simply has no id.
        $I->assertContains('GST 5%:', $this->totalsBoxLabels($I), 'and the tax row itself is there');
    }

    // ── readers ──────────────────────────────────────────────────────────────

    private function panelRowXpath(string $panel, string $label): string
    {
        return sprintf(
            '//div[contains(@class,"order-detail-grid")]/section[.//h2[normalize-space()="%s"]]'
            . '//div[contains(@class,"detail-row")][label[normalize-space()="%s:"]]/strong',
            $panel,
            $label,
        );
    }

    private function panelRow(FunctionalTester $I, string $panel, string $label): string
    {
        return self::collapse($I->grabTextFrom($this->panelRowXpath($panel, $label)));
    }

    /** @return list<string> */
    private function panelRowLabels(FunctionalTester $I, string $panel): array
    {
        return array_map(
            static fn (string $text): string => rtrim(self::collapse($text), ':'),
            $I->grabMultiple(sprintf(
                '//div[contains(@class,"order-detail-grid")]/section[.//h2[normalize-space()="%s"]]'
                . '//div[contains(@class,"detail-row")]/label',
                $panel,
            )),
        );
    }

    private function panelRowCount(FunctionalTester $I, string $panel): int
    {
        return count($this->panelRowLabels($I, $panel));
    }

    /** @return list<string> */
    private function panelSentences(FunctionalTester $I, string $panel): array
    {
        return array_map(
            static fn (string $text): string => self::collapse($text),
            $I->grabMultiple(sprintf(
                '//div[contains(@class,"order-detail-grid")]/section[.//h2[normalize-space()="%s"]]/p',
                $panel,
            )),
        );
    }

    private function paymentBadgeXpath(string $panel): string
    {
        return sprintf(
            '//div[contains(@class,"order-detail-grid")]/section[.//h2[normalize-space()="%s"]]'
            . '//div[contains(@class,"detail-row")][label[normalize-space()="Payment Status:"]]/span',
            $panel,
        );
    }

    /** @return list<string> */
    private function activityLogHeadings(FunctionalTester $I): array
    {
        return array_map(
            static fn (string $text): string => self::collapse($text),
            $I->grabMultiple('//section[.//h2[normalize-space()="Activity Log"]]//table/thead/tr/th'),
        );
    }

    /**
     * How many columns the timeline's BODY spans, however the body is filled: a real row's cell
     * count, or the empty row's colspan when there is nothing to list. Both are read from the
     * same list inside the partial as the header is, and this is the assertion that they agree.
     */
    private function activityLogRowCellCount(FunctionalTester $I): int
    {
        $root = '//section[.//h2[normalize-space()="Activity Log"]]//table/tbody/tr[1]';
        $emptyColspan = $I->grabMultiple($root . '/td[contains(@class,"empty-table-cell")]/@colspan');

        return $emptyColspan === []
            ? count($I->grabMultiple($root . '/td'))
            : (int) $emptyColspan[0];
    }

    /** @return list<string> */
    private function lineTableHeadings(FunctionalTester $I): array
    {
        return array_map(
            static fn (string $text): string => self::collapse($text),
            $I->grabMultiple('//table[contains(@class,"order-products-table")]/thead/tr/th'),
        );
    }

    private function lineTableEmptyColspan(FunctionalTester $I): string
    {
        return $I->grabAttributeFrom(
            '//table[contains(@class,"order-products-table")]/tbody/tr/td[contains(@class,"empty-table-cell")]',
            'colspan',
        );
    }

    /** $column is 1-based across Name, U/M, SKU, Weight, Quantity, Price, Subtotal, Tax Code, Tax $. */
    private function lineCell(FunctionalTester $I, string $sku, int $column): string
    {
        return self::collapse($I->grabTextFrom(sprintf(
            '//table[contains(@class,"order-products-table")]/tbody/tr[td[normalize-space()="%s"]]/td[%d]',
            $sku,
            $column,
        )));
    }

    /** @return list<string> */
    private function overrideHeadings(FunctionalTester $I): array
    {
        return array_map(
            static fn (string $text): string => self::collapse($text),
            $I->grabMultiple('//table[@id="stock-override-table"]/thead/tr/th'),
        );
    }

    private function overrideShortfall(FunctionalTester $I): string
    {
        return self::collapse($I->grabTextFrom(
            '//table[@id="stock-override-table"]/tbody/tr/td[@data-label="Short by"]'
        ));
    }

    /** @return array<string, string> the totals box's shipping rows, label => figure. */
    private function shippingRows(FunctionalTester $I): array
    {
        $labels = $I->grabMultiple('//*[contains(@class,"shipping-fee-row")]/*[1]');
        $amounts = $I->grabMultiple('//*[contains(@class,"shipping-fee-row")]/*[2]');
        $rows = [];
        foreach ($labels as $index => $label) {
            $rows[self::collapse($label)] = self::collapse($amounts[$index] ?? '');
        }

        return $rows;
    }

    /** @return list<string> every label down the order's or the quote's totals box. */
    private function totalsBoxLabels(FunctionalTester $I): array
    {
        return array_map(
            static fn (string $text): string => self::collapse($text),
            $I->grabMultiple('//div[strong[normalize-space()="Total Amount:"] or span[normalize-space()="Total Amount:"]]/../div/span'),
        );
    }

    private static function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function company(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('CMP-' . uniqid())
            ->setPrimaryEmail('ap-' . uniqid() . '@detail-components.example')
            ->setPhoneNumber('+1 604 555 0199');
        $I->haveInRepository($company);

        return $company;
    }

    private function product(FunctionalTester $I, string $sku): ProductCore
    {
        $existing = $I->grabService(EntityManagerInterface::class)
            ->getRepository(ProductCore::class)->findOneBy(['sku' => $sku]);
        if ($existing instanceof ProductCore) {
            return $existing;
        }
        $product = (new ProductCore())->setSku($sku)->setName('Components ' . $sku);
        $I->haveInRepository($product);

        return $product;
    }

    private function order(FunctionalTester $I, Company $company, string $number, bool $withLines = true): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($number)
            ->setDocumentDate('2026-09-01')
            ->setSubtotal('25.00')->setTax('0.00')->setTotal('25.00');
        if ($withLines) {
            $order->addLine(
                (new SalesOrderLine())
                    ->setProduct($this->product($I, 'CMP-A'))
                    ->setName('Components CMP-A')->setSku('CMP-A')
                    ->setQuantity('2.5000')->setPrice('10.000000')->setSubtotal('25.00')
            );
        }
        $I->haveInRepository($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    private function standaloneInvoice(FunctionalTester $I, Company $company, string $number): Invoice
    {
        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber($number)
            ->setDocumentDate('2026-09-01')
            ->setInvoiceDate('2026-09-01')
            ->setSubtotal('25.00')->setTax('0.00')->setTotal('25.00');
        $invoice->addLine(
            (new InvoiceLine())
                ->setProduct($this->product($I, 'CMP-A'))
                ->setName('Components CMP-A')->setSku('CMP-A')
                ->setQuantity('2.5000')->setPrice('10.000000')->setSubtotal('25.00')
        );
        $I->haveInRepository($invoice);
        $I->grabService(EntityManagerInterface::class)->flush();

        return $invoice;
    }

    private function estimate(FunctionalTester $I, Company $company, string $number, bool $priced, bool $withLines = true): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber($number)
            ->setDocumentDate('2026-09-01')
            ->setSource('Admin');
        $estimate->setStatus($priced ? 'Priced' : 'Draft', DocumentActor::system());
        if ($priced) {
            $estimate->setSubtotal('25.00')->setTax('0.00')->setTotal('25.00');
        }
        if ($withLines) {
            $line = (new EstimateLine())
                ->setProduct($this->product($I, 'CMP-A'))
                ->setName('Components CMP-A')->setSku('CMP-A')
                ->setQuantity('2.5000');
            if ($priced) {
                $line->setPrice('10.000000')->setSubtotal('25.00');
            }
            $estimate->addLine($line);
        }
        $I->haveInRepository($estimate);
        $I->grabService(EntityManagerInterface::class)->flush();

        return $estimate;
    }
}
