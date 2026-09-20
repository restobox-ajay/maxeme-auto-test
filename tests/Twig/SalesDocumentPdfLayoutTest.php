<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Regression cover for issue #35: the invoice and packing-slip PDFs wasted so much of page 1
 * on the title bar and header blocks that the line-items table did not start until roughly
 * three quarters down, and dompdf silently mixed a serif face into the sans document.
 *
 * These assertions are made against a real dompdf render, not the HTML, because both defects
 * only exist at layout time. Positions come from `pdftotext -bbox`; where that binary is not
 * installed the geometry tests skip rather than fail, but the font-consistency test is pure
 * PHP (font names are literal strings inside the PDF) and always runs.
 */
final class SalesDocumentPdfLayoutTest extends DoctrineIntegrationTestCase
{
    /**
     * The items table must begin within the top half of page 1 — the issue asks for 50% as
     * the hard ceiling and 30% as the goal. Kept at 50% so ordinary content growth (a long
     * company name, a second address line) doesn't turn this into a flapping test.
     */
    private const MAX_TABLE_START_FRACTION = 0.50;

    /**
     * The three printed sales documents. The invoice and its packing slip moved onto the Invoice
     * entity in #539 stage 6; the SALES ORDER is that stage's new, deliberately different document,
     * and it is held to the same geometry, font and no-leaked-brand rules as the other two.
     */
    private const INVOICE = 'admin/invoice/invoice.html.twig';
    private const PACKING_SLIP = 'admin/invoice/packing_slip.html.twig';
    private const SALES_ORDER = 'admin/order/sales_order.html.twig';

    private SalesOrder $order;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer()->get(RequestStack::class)
            ->push(Request::create('https://admin.example.test/admin/invoice/print/1'));

        $company = (new Company())
            ->setName('Northfield Restaurant Supply Ltd.')
            ->setCode('NRS')
            ->setPhoneNumber('+1 604 555 0142')
            ->setPrimaryEmail('accounts@northfield.example');
        $this->em->persist($company);

        foreach ([['billing', true, false], ['shipping', false, true]] as [$label, $isBilling, $isShipping]) {
            $address = (new CompanyAddress())
                ->setCompany($company)
                ->setLabel($label)
                ->setCompanyName('Northfield Restaurant Supply Ltd.')
                ->setFirstName('Dana')
                ->setLastName('Whitfield')
                ->setAddressLine1('4820 Grandview Highway')
                ->setAddressLine2('Suite 300')
                ->setCity('Burnaby')
                ->setProvince('BC')
                ->setPostalCode('V5C 6C4')
                ->setCountry('Canada')
                ->setPhone('+1 604 555 0142')
                ->setEmailPrimary('accounts@northfield.example')
                ->setIsDefaultBilling($isBilling)
                ->setIsDefaultShipping($isShipping);
            $this->em->persist($address);
            $company->getAddresses()->add($address);
        }

        $this->order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORD-20260728-001')
            ->setPoNumber('PO-77120')
            ->setPaymentMethod('E-Transfer')
            ->setPaymentTerm('Net 30')
            ->setSpecialInstructions('Deliver to the rear loading dock before 11am.')
            ->setBillingName('Dana Whitfield')
            ->setShippingName('Dana Whitfield')
            ->setSubtotal('180.00')
            ->setFeeLines(FeeLineSnapshot::encode([
                new FeeLine(null, 'shipping', 'Shipping (Ground)', 'G', 20.0, 'main_line', FeeLine::TYPE_SHIPPING, FeeLine::SOURCE_MANUAL),
            ]))
            ->setTax('10.00')
            ->setTotal('210.00');

        for ($i = 1; $i <= 4; $i++) {
            $product = (new ProductCore())->setSku('NRS-000' . $i)->setName('Stainless Prep Tray ' . $i)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
            $this->em->persist($product);
            $this->order->addLine(
                (new SalesOrderLine())
                    ->setProduct($product)
                    ->setName('Stainless Prep Tray ' . $i)
                    ->setSku('NRS-000' . $i)
                    ->setUnit('CS')
                    ->setQuantity('3')
                    ->setPrice('15.00')
                    ->setSubtotal('45.00')
            );
        }

        // A live order, which since #539 stage 2 means an approved one — there is no status setter.
        $this->order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $this->em->persist($this->order);
        $this->em->flush();

        // The invoice raised from it, covering the whole order. Since #539 stage 6 the invoice
        // document and the packing slip render an Invoice, not a SalesOrder, so the geometry and
        // font assertions below need one — the sales order document keeps its own.
        $this->invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('INV-20260728-001')
            ->setDocumentDate('2026-07-28')
            ->setInvoiceDate('2026-07-28')
            ->setPoNumber($this->order->getPoNumber())
            ->setPaymentMethod($this->order->getPaymentMethod())
            ->setPaymentTerm($this->order->getPaymentTerm())
            ->setSpecialInstructions($this->order->getSpecialInstructions())
            ->setBillingName($this->order->getBillingName())
            ->setShippingName($this->order->getShippingName())
            ->setSubtotal($this->order->getSubtotal())
            ->setFeeLines($this->order->getFeeLines())
            ->setTax($this->order->getTax())
            ->setTotal($this->order->getTotal());
        $this->order->addInvoice($this->invoice);

        foreach ($this->order->getLines() as $line) {
            $this->invoice->addLine(
                (new InvoiceLine())
                    ->setSalesOrderLine($line)
                    ->setProduct($line->getProduct())
                    ->setName($line->getName())
                    ->setSku($line->getSku())
                    ->setUnit($line->getUnit())
                    ->setQuantity($line->getQuantity())
                    ->setPrice($line->getPrice())
                    ->setSubtotal($line->getSubtotal()),
            );
        }

        $this->em->persist($this->invoice);
        $this->invoice->issue(DocumentActor::system());
        $this->em->flush();
    }

    public function testInvoiceLineItemsTableStartsInTheTopHalfOfPageOne(): void
    {
        $this->assertTableStartsHighOnPageOne($this->renderPdf(self::INVOICE), 'Quantity');
    }

    public function testPackingSlipLineItemsTableStartsInTheTopHalfOfPageOne(): void
    {
        $this->assertTableStartsHighOnPageOne($this->renderPdf(self::PACKING_SLIP), 'QTY');
    }

    /** The new document inherits the constraint rather than starting again below it. */
    public function testSalesOrderLineItemsTableStartsInTheTopHalfOfPageOne(): void
    {
        $this->assertTableStartsHighOnPageOne($this->renderPdf(self::SALES_ORDER), 'Ordered');
    }

    /**
     * The whole reason the Sales Order is a separate template (#539 stage 6): an order is not owed
     * anything, so it must never print an amount due, a payment status, or the "how to pay this"
     * note. Asserted on the rendered document rather than trusted to the template, because the two
     * files share a layout and the cheapest wrong change is copying a block across.
     */
    public function testTheSalesOrderDocumentNeverAsksForMoney(): void
    {
        $this->setSetting('invoice_payment_note', 'Remit to: BMO 001-234, transit 00010.');

        $salesOrder = $this->renderHtml(self::SALES_ORDER);
        self::assertStringNotContainsStringIgnoringCase('amount due', $salesOrder);
        self::assertStringNotContainsStringIgnoringCase('amount received', $salesOrder);
        self::assertStringNotContainsString('Not Paid', $salesOrder);
        self::assertStringNotContainsString('Remit to:', $salesOrder);
        self::assertStringNotContainsString('INVOICE</td>', $salesOrder);

        // It says what it IS, in place of what it is not.
        self::assertStringContainsString('SALES ORDER', $salesOrder);
        self::assertStringContainsString('ORDER VALUE:', $salesOrder);
        self::assertStringContainsString('This is a sales order, not an invoice.', $salesOrder);
        // And the two things it has that the invoice does not.
        self::assertStringContainsString('<th>Invoiced</th>', $salesOrder);
        self::assertStringContainsString('<th>Remaining</th>', $salesOrder);
        self::assertStringContainsString('Invoices Raised', $salesOrder);

        // The same three figures are exactly what the invoice DOES carry, which is what makes the
        // absences above a decision rather than an omission.
        $invoice = $this->renderHtml(self::INVOICE);
        self::assertStringContainsString('AMOUNT DUE:', $invoice);
        self::assertStringContainsString('Amount received:', $invoice);
        self::assertStringContainsString('Not Paid', $invoice);
        self::assertStringContainsString('Remit to:', $invoice);
    }

    /**
     * dompdf only resolves the font subtypes a family actually declares. `font-weight: 800`
     * or `900` resolves to the subtype "800"/"900", which no bundled family has, so dompdf
     * quietly falls back to its default serif — that was the serif/sans mix on these
     * documents. Every heavy weight is now plain `bold`, and the whole document is one family.
     */
    public function testDocumentsUseASingleSansFamilyWithNoSerifFallback(): void
    {
        foreach ([self::INVOICE, self::PACKING_SLIP, self::SALES_ORDER] as $template) {
            $pdf = $this->renderPdf($template);

            self::assertStringContainsString('DejaVuSans', $pdf, $template . ' should embed the sans family');
            self::assertStringNotContainsString('Times', $pdf, $template . ' must not fall back to a serif face');
            self::assertStringNotContainsString('Courier', $pdf, $template . ' must not mix in a monospace face');
        }
    }

    /** Item 5: the note the customer typed at checkout has to reach the invoice. */
    public function testInvoiceShowsTheCustomerNoteCapturedAtCheckout(): void
    {
        $html = $this->renderHtml(self::INVOICE);

        self::assertStringContainsString('Customer Note:', $html);
        self::assertStringContainsString('Deliver to the rear loading dock before 11am.', $html);
    }

    /** Items 2, 3 and 7: seller identity comes from config, never from a hardcoded brand. */
    public function testSellerIdentityAndGstNumberComeFromSettings(): void
    {
        $this->setSetting('company_name', 'Northfield Wholesale Inc.');
        $this->setSetting('company_gst_number', '81234 5678 RT0001');

        $html = $this->renderHtml(self::INVOICE);

        self::assertStringContainsString('Northfield Wholesale Inc.', $html);
        self::assertStringContainsString('81234 5678 RT0001', $html);
    }

    public function testNoBrandSpecificFallbackLeaksWhenTheSettingsAreEmpty(): void
    {
        foreach ([self::INVOICE, self::PACKING_SLIP, self::SALES_ORDER] as $template) {
            $html = $this->renderHtml($template);

            self::assertStringNotContainsString('Happy Days', $html);
            self::assertStringNotContainsString('Salmon Arm', $html);
            self::assertStringNotContainsString('happydaysdairy', $html);
        }
    }

    /** Neither document header should imply the customer-facing PDF is the internal admin tool. */
    public function testNoAdminConsoleTextOnInvoiceOrPackingSlip(): void
    {
        foreach ([self::INVOICE, self::PACKING_SLIP, self::SALES_ORDER] as $template) {
            $html = $this->renderHtml($template);

            self::assertStringNotContainsStringIgnoringCase('admin console', $html, $template . ' must not mention "Admin Console"');
        }
    }

    /**
     * A `main_line` fee is part of the pre-tax subtotal and must never itself be taxed; an
     * `after_tax` fee is added to the grand total after tax and must never appear before it.
     * admin/order/detail.html.twig already gets this right — this pins invoice.html.twig to
     * the same two-bucket placement.
     */
    public function testFeeLinesAreOrderedByPlacementRelativeToTax(): void
    {
        $this->invoice->setFeeLines(json_encode([
            ['feeId' => 1, 'slug' => 'handling', 'label' => 'Handling Fee', 'taxClass' => 'none', 'amount' => 5.0, 'placement' => 'main_line'],
            ['feeId' => 2, 'slug' => 'eco', 'label' => 'Eco Fee', 'taxClass' => 'none', 'amount' => 2.5, 'placement' => 'after_tax_line'],
        ]));
        $this->em->flush();

        $html = self::getContainer()->get('twig')->render(self::INVOICE, [
            'invoice' => $this->invoice,
            'billingAddress' => $this->invoice->getEffectiveBillingAddress(),
            'shippingAddress' => $this->invoice->getEffectiveShippingAddress(),
            'taxLines' => [['label' => 'GST', 'rate' => 0.05, 'amount' => 9.00]],
            'taxLinesTotal' => 9.00,
            'is_pdf' => true,
        ]);

        $handlingPos = strpos($html, 'Handling Fee');
        $ecoPos = strpos($html, 'Eco Fee');
        $totalTaxPos = strpos($html, 'Total Tax');

        self::assertNotFalse($handlingPos, 'main_line fee should be rendered');
        self::assertNotFalse($ecoPos, 'after_tax fee should be rendered');
        self::assertNotFalse($totalTaxPos, 'Total Tax row should be rendered');
        self::assertLessThan($totalTaxPos, $handlingPos, 'A main_line fee must appear before the Total Tax row.');
        self::assertGreaterThan($totalTaxPos, $ecoPos, 'An after_tax fee must appear after the Total Tax row.');
    }

    /**
     * admin/order/detail.html.twig already renders a Products-table row per `main_line` fee
     * (Name = the fee's label, every other column an em dash) and folds the fee's amount
     * silently into the sidebar's "Subtotal" rather than naming it a second time. This pins
     * invoice.html.twig to that same behavior: a `main_line` fee gets its own table row and
     * is no longer separately named in the totals sidebar, while an `after_tax` fee stays
     * sidebar-only exactly as before.
     */
    public function testMainLineFeeGetsAProductsTableRowAndIsNoLongerNamedInTheSidebar(): void
    {
        $this->invoice->setFeeLines(json_encode([
            ['feeId' => 1, 'slug' => 'handling', 'label' => 'Handling Fee', 'taxClass' => 'none', 'amount' => 5.0, 'placement' => 'main_line'],
            ['feeId' => 2, 'slug' => 'eco', 'label' => 'Eco Fee', 'taxClass' => 'none', 'amount' => 2.5, 'placement' => 'after_tax_line'],
        ]));
        $this->em->flush();

        $html = $this->renderHtml(self::INVOICE);

        $tableStart = strpos($html, '<table class="invoice-table"');
        $tableEnd = strpos($html, '</table>', $tableStart);
        self::assertNotFalse($tableStart);
        self::assertNotFalse($tableEnd);

        self::assertSame(1, substr_count($html, 'Handling Fee'), 'The main_line fee must not be named a second time in the totals sidebar.');
        $handlingPos = strpos($html, 'Handling Fee');
        self::assertGreaterThan($tableStart, $handlingPos, 'The main_line fee must be rendered inside the Products table.');
        self::assertLessThan($tableEnd, $handlingPos, 'The main_line fee must be rendered inside the Products table.');
        self::assertStringContainsString('$5.00', substr($html, $handlingPos, 400), 'The fee row must show its amount.');

        self::assertSame(1, substr_count($html, 'Eco Fee'), 'The after_tax fee is unaffected by this change.');
        $ecoPos = strpos($html, 'Eco Fee');
        self::assertGreaterThan($tableEnd, $ecoPos, 'The after_tax fee must stay sidebar-only, not appear in the Products table.');
    }

    private function setSetting(string $key, string $value): void
    {
        $setting = (new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($value);
        $this->em->persist($setting);
        $this->em->flush();
        self::getContainer()->get(AppSettings::class)->clearCache();
    }

    private function renderHtml(string $template): string
    {
        // Both documents in scope, because the three templates read different ones: the invoice
        // and the packing slip take `invoice`, the sales order takes `order`. A variable a template
        // does not use costs nothing, and passing one set keeps every assertion below comparing the
        // same fixture across all three.
        return self::getContainer()->get('twig')->render($template, [
            'invoice' => $this->invoice,
            'order' => $this->order,
            'billingAddress' => $this->order->getEffectiveBillingAddress(),
            'shippingAddress' => $this->order->getEffectiveShippingAddress(),
            'taxLines' => [],
            'taxLinesTotal' => 0,
            'is_pdf' => true,
        ]);
    }

    private function renderPdf(string $template): string
    {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($this->renderHtml($template));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function assertTableStartsHighOnPageOne(string $pdf, string $columnHeading): void
    {
        $bbox = $this->pdfTextBoundingBoxes($pdf);

        if (!preg_match('/<page[^>]*height="([\d.]+)"/', $bbox, $page)) {
            self::fail('Could not read the page height out of the rendered PDF.');
        }
        $pageHeight = (float) $page[1];

        $pattern = '/<word xMin="[\d.]+" yMin="([\d.]+)"[^>]*>' . preg_quote($columnHeading, '/') . '<\/word>/i';
        if (!preg_match($pattern, $bbox, $word)) {
            self::fail(sprintf('Could not find the "%s" column heading on page 1 of the rendered PDF.', $columnHeading));
        }
        $fraction = ((float) $word[1]) / $pageHeight;

        self::assertLessThanOrEqual(
            self::MAX_TABLE_START_FRACTION,
            $fraction,
            sprintf(
                'The line-items table starts %.1f%% down page 1; issue #35 requires it to start within the top %.0f%%.',
                $fraction * 100,
                self::MAX_TABLE_START_FRACTION * 100
            )
        );
    }

    /** @return string pdftotext's `-bbox` XML for page 1 */
    private function pdfTextBoundingBoxes(string $pdf): string
    {
        exec('pdftotext -v 2>&1', $probe, $probeStatus);
        if ($probeStatus !== 0) {
            self::markTestSkipped('pdftotext (poppler-utils) is not installed, so PDF geometry cannot be measured.');
        }

        $file = tempnam(sys_get_temp_dir(), 'pdflayout') . '.pdf';
        file_put_contents($file, $pdf);

        try {
            exec(sprintf('pdftotext -bbox -f 1 -l 1 %s - 2>/dev/null', escapeshellarg($file)), $output, $status);
            self::assertSame(0, $status, 'pdftotext failed to read the rendered PDF.');

            return implode("\n", $output);
        } finally {
            @unlink($file);
        }
    }
}
