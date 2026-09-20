<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AbstractDocumentAddress;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\Invoice;
use App\Entity\InvoiceAddress;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceIssueIntent;
use App\Service\DocumentActor;
use App\Service\OrderInvoicingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * /admin/invoice/detail/{id} — the invoice's view screen, brought up to the order's.
 *
 * The screen used to be a six-field strip: Status, Sales Order, Company, Invoice Date, Payment,
 * Total. It carried NO billing address and NO shipping address, on the one document in the system
 * that is a demand for payment; no contact, no customer PO number, no payment terms; and a
 * five-column line table that dropped U/M, weight, tax code, per-line tax and batch, so the single
 * Tax figure at the foot had nothing behind it.
 *
 * Every one of those fields was already on the entity. The defect was that the page never asked.
 *
 * ## How these are written (#624, #627)
 *
 * Conducted: the fixtures are built here, the real screens are driven, and what the screen claims is
 * checked back against the database rather than trusted from the response.
 *
 * No assertion is a bare number. This screen is nothing but numbers — a price, a quantity, a postal
 * code, a phone number and a document total all live within a few hundred bytes of each other — so
 * `see('20')` would match a price of 1820, a quantity of 200 and the postal code V8W 1T3's
 * neighbours alike. Every figure here is grabbed from a named cell by XPath and compared exactly, so
 * an assertion that passes can only be passing against the cell it names.
 *
 * Every absence is paired with a positive control on the same page, so "the page did not render at
 * all" cannot masquerade as "the thing is correctly absent".
 */
final class AdminInvoiceDetailCest
{
    /** The billing snapshot rows, by their label on the screen. */
    private const BILLING = [
        'Company Name:' => 'Parity Buyer Co',
        'First Name:' => 'Belinda',
        'Last Name:' => 'Billings',
        'Phone number:' => '+1 250 555 0111',
        'Street Address:' => '4187 Billing Crescent',
        'Address 2:' => 'Suite 9',
        'City:' => 'Victoria',
        'Province:' => 'BC',
        'Country:' => 'CA',
        'Postal Code:' => 'V8W 1T3',
        'Fax Number:' => '+1 250 555 0112',
        'Primary Email:' => 'ap@parity-buyer.example',
        'Secondary Email:' => 'ap2@parity-buyer.example',
    ];

    /** The shipping snapshot rows. Deliberately different from BILLING in every single field. */
    private const SHIPPING = [
        'Company Name:' => 'Parity Buyer Receiving Depot',
        'First Name:' => 'Sheldon',
        'Last Name:' => 'Shipman',
        'Phone number:' => '+1 604 555 0113',
        'Street Address:' => '2260 Shipping Road',
        'Address 2:' => 'Dock 4',
        'City:' => 'Burnaby',
        'Province:' => 'BC',
        'Country:' => 'CA',
        'Postal Code:' => 'V5C 1A1',
        'Fax Number:' => '+1 604 555 0114',
        'Primary Email:' => 'receiving@parity-buyer.example',
        'Secondary Email:' => 'dock@parity-buyer.example',
    ];

    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('invoice-detail-parity@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * The screen an invoice raised from an order renders: both address panels populated from the
     * invoice's own frozen snapshots, the header fields the order screen carries, and a ten-column
     * line table with the tax worked out on the row rather than asserted at the foot.
     */
    public function anInvoiceRaisedFromAnOrderShowsItsBillingAndShippingPanels(FunctionalTester $I): void
    {
        [$order, $invoice] = $this->invoicedOrder($I, 'PARITY-A');

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();

        // Positive control for everything below: this is the invoice whose screen we are reading.
        $I->see($invoice->getDocumentNumber(), 'h1');

        // ── The two panels that were missing outright ────────────────────────────────
        foreach (self::BILLING as $label => $expected) {
            $I->assertSame(
                $expected,
                $this->panelRow($I, 'Billing Detail', $label),
                sprintf('Billing Detail row "%s" should print the invoice\'s frozen snapshot.', $label),
            );
        }

        foreach (self::SHIPPING as $label => $expected) {
            $I->assertSame(
                $expected,
                $this->panelRow($I, 'Shipping Detail', $label),
                sprintf('Shipping Detail row "%s" should print the invoice\'s frozen snapshot.', $label),
            );
        }

        // The two panels are genuinely two: a template that rendered the billing snapshot into both
        // would satisfy every assertion above taken one panel at a time.
        $I->assertNotSame(
            $this->panelRow($I, 'Billing Detail', 'Street Address:'),
            $this->panelRow($I, 'Shipping Detail', 'Street Address:'),
            'Billing and shipping must be read from their own snapshots, not the same one twice.',
        );
        $I->assertSame(
            'Leave with the depot supervisor.',
            $this->panelRow($I, 'Shipping Detail', 'Delivery instructions:'),
        );

        // ── The header fields the strip dropped ──────────────────────────────────────
        $I->assertSame('PO-PARITY-A-4471', $this->panelRow($I, 'Invoice Info', 'PO #:'));
        $I->assertSame('Net 30', $this->panelRow($I, 'Invoice Info', 'Payment Terms:'));
        $I->assertSame('Pay Upon Delivery', $this->panelRow($I, 'Invoice Info', 'Payment Method:'));
        $I->assertSame(
            'Prudence Placer buyer@parity-buyer.example',
            $this->panelRow($I, 'Invoice Info', 'Invoice Placed By:'),
            'Who raised it, above the frozen contact address it was raised against.',
        );
        $I->assertSame('Main', $this->panelRow($I, 'Invoice Info', 'Fulfillment Region:'));
        $I->assertSame('Deliver before noon.', $this->panelRow($I, 'Invoice Info', 'Special Instructions:'));
        $I->assertSame('+1 604 555 0101', $this->panelRow($I, 'Invoice Info', 'Customer Phone:'));

        // ── The line table's five restored columns ───────────────────────────────────
        // Read cell by cell out of the row that carries this SKU. Nothing here is a page-wide
        // substring match, which on a screen holding "V8W 1T3", "$1,820.00" and "2260" is the only
        // way an assertion about a quantity can be trusted (#627).
        $sku = 'PARITY-A-SKU-1';
        $I->assertSame('Each', $this->lineCell($I, $sku, 2), 'U/M is its own column again.');
        $I->assertSame($sku, $this->lineCell($I, $sku, 3));
        $I->assertSame('12.500', $this->lineCell($I, $sku, 4), 'Weight is what the carrier bills against.');
        $I->assertSame('91', $this->lineCell($I, $sku, 5));
        $I->assertSame('$20.00', $this->lineCell($I, $sku, 6));
        $I->assertSame('$1,820.00', $this->lineCell($I, $sku, 7));
        $I->assertSame('GST', $this->lineCell($I, $sku, 8), 'The tax that was charged, named.');
        $I->assertSame('$91.00', $this->lineCell($I, $sku, 9), 'Per-line tax: 5% of $1,820.00.');
        $I->assertSame('LOT-PARITY-A-77', $this->lineCell($I, $sku, 10));

        // And the itemised tax at the foot, so the single Tax figure is no longer unexplained.
        $I->assertSame('$91.00', $this->footRow($I, 'GST 5%'));
        $I->assertSame('$91.00', $this->footRow($I, 'Total Tax'));
        $I->assertSame('$1,820.00', $this->footRow($I, 'Subtotal'));
        $I->assertSame('$1,911.00', $this->footRow($I, 'Total Amount'));

        // ── Re-read from the database, not from the entity built above ───────────────
        // The screen is only correct if it printed what is actually stored. The invoice carries its
        // OWN invoice_address rows, copied from the order's snapshot at issue time — that is the
        // whole reason a document can be reprinted years later and still say what it said.
        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        /** @var Invoice $stored */
        $stored = $em->find(Invoice::class, $invoice->getId());

        $storedBilling = $stored->getBillingAddress();
        $storedShipping = $stored->getShippingAddress();
        $I->assertNotNull($storedBilling, 'The invoice must hold its own billing snapshot row.');
        $I->assertNotNull($storedShipping, 'The invoice must hold its own shipping snapshot row.');
        $I->assertSame('4187 Billing Crescent', $storedBilling->getAddressLine1());
        $I->assertSame('2260 Shipping Road', $storedShipping->getAddressLine1());
        $I->assertSame('V8W 1T3', $storedBilling->getPostalCode());
        $I->assertFalse(
            $storedBilling->isLinkOnly(),
            'The invoice froze values, so nothing on this screen can resolve through the address book.',
        );
        $I->assertSame('PO-PARITY-A-4471', $stored->getPoNumber());
        $I->assertSame('Net 30', $stored->getPaymentTerm());
        $I->assertSame($order->getId(), $stored->getSalesOrder()?->getId());

        // The invoice's snapshot is its OWN row in its OWN table — invoice_address, not a
        // foreign key into the order's sales_order_address. Ids are not comparable across the two
        // (each table numbers from 1), so the class is what says which row this is.
        $I->assertInstanceOf(
            InvoiceAddress::class,
            $storedBilling,
            'The invoice copies the order address into a row of its own rather than pointing at it.',
        );
    }

    /**
     * The frozen-snapshot rule, conducted: correct the customer's address book AFTER the invoice is
     * raised, then reload the invoice screen. A live read would silently restate a historical
     * invoice; a snapshot cannot.
     */
    public function editingTheCustomersAddressBookDoesNotRewriteAnInvoiceAlreadyRaised(FunctionalTester $I): void
    {
        [, $invoice] = $this->invoicedOrder($I, 'PARITY-FROZEN');

        $em = $I->grabService(EntityManagerInterface::class);

        /** @var CompanyAddress $bookEntry */
        $bookEntry = $em->getRepository(CompanyAddress::class)->findOneBy(['addressLine1' => '4187 Billing Crescent']);
        $I->assertNotNull($bookEntry, 'The address-book row the invoice was raised from should exist.');
        $bookEntry->setAddressLine1('9900 Moved Away Boulevard')->setCity('Kamloops');
        $em->flush();
        $em->clear();

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(
            '4187 Billing Crescent',
            $this->panelRow($I, 'Billing Detail', 'Street Address:'),
            'An invoice states where it was billed, not where the customer lives today.',
        );
        $I->assertSame('Victoria', $this->panelRow($I, 'Billing Detail', 'City:'));
        // Paired with the positive control above: the page did render the billing panel, so the
        // new street genuinely is not on it.
        $I->dontSee('9900 Moved Away Boulevard');
        $I->dontSee('Kamloops');

        // The other half of the same rule, conducted: the invoice does not read through the ORDER
        // either. Move the order's own snapshot and the invoice keeps saying what it said — which
        // is what "copied at issue time" has to mean if it means anything.
        /** @var Invoice $reloaded */
        $reloaded = $em->find(Invoice::class, $invoice->getId());
        $orderSnapshot = $reloaded->getSalesOrder()?->getBillingAddress();
        $I->assertNotNull($orderSnapshot, 'The order should still hold its own billing snapshot.');
        $orderSnapshot->setAddressLine1('1 Amended Order Avenue');
        $em->flush();
        $em->clear();

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            '4187 Billing Crescent',
            $this->panelRow($I, 'Billing Detail', 'Street Address:'),
        );
        $I->dontSee('1 Amended Order Avenue');
    }

    /**
     * A standalone invoice — no sales order behind it at all. Every panel has to survive that, and
     * the two address panels must say why they are empty rather than printing a box of dashes where
     * the order's data would have been.
     */
    public function anInvoiceWithNoSalesOrderRendersWithoutErroring(FunctionalTester $I): void
    {
        $invoice = $this->standaloneInvoice($I, 'PARITY-SOLO', withAddresses: false);

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();

        // Positive controls: the page is fully rendered, not a stub or an error template.
        $I->see($invoice->getDocumentNumber(), 'h1');
        $I->see('Invoice Info');
        $I->see('Billing Detail');
        $I->see('Shipping Detail');
        $I->see('Not raised against an order.');
        $I->seeLink('Link to a sales order', '/admin/invoice/' . $invoice->getId() . '/link');

        // The panels explain themselves instead of rendering thirteen empty rows.
        $I->see('This invoice recorded no billing address.');
        $I->see('This invoice recorded no shipping address.');
        $I->assertSame(
            0,
            $this->rowCount($I, 'Billing Detail'),
            'An address panel with nothing to print must not render an empty grid.',
        );

        // The order-derived header rows read as absent, not as a crash.
        $I->assertSame('-', $this->panelRow($I, 'Invoice Info', 'PO #:'));
        $I->assertSame('No payment terms', $this->panelRow($I, 'Invoice Info', 'Payment Terms:'));

        // The money still adds up, read from the cells rather than from the page text.
        $I->assertSame('$310.00', $this->footRow($I, 'Subtotal'));
        $I->assertSame('$310.00', $this->footRow($I, 'Total Amount'));
        $I->assertSame('$310.00', $this->footRow($I, 'Balance Due'));
        $I->assertSame('SOLO-SKU', $this->lineCell($I, 'SOLO-SKU', 3));
        $I->assertSame('$310.00', $this->lineCell($I, 'SOLO-SKU', 7));

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        /** @var Invoice $stored */
        $stored = $em->find(Invoice::class, $invoice->getId());
        $I->assertNull($stored->getSalesOrder(), 'This invoice is standalone and must have stayed so.');
        $I->assertNull($stored->getBillingAddress());
    }

    /**
     * A standalone invoice that DOES carry its own typed address snapshot prints it. Without this,
     * the test above would be satisfied by a screen that never renders an address panel at all.
     */
    public function aStandaloneInvoiceWithItsOwnAddressSnapshotPrintsIt(FunctionalTester $I): void
    {
        $invoice = $this->standaloneInvoice($I, 'PARITY-SOLO-ADDR', withAddresses: true);

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->see($invoice->getDocumentNumber(), 'h1');
        $I->assertSame('77 Standalone Street', $this->panelRow($I, 'Billing Detail', 'Street Address:'));
        $I->assertSame('Nanaimo', $this->panelRow($I, 'Billing Detail', 'City:'));
        $I->assertSame('88 Standalone Dock', $this->panelRow($I, 'Shipping Detail', 'Street Address:'));
        $I->dontSee('This invoice recorded no billing address.');

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        /** @var Invoice $stored */
        $stored = $em->find(Invoice::class, $invoice->getId());
        $I->assertNull($stored->getSalesOrder());
        $I->assertSame('77 Standalone Street', $stored->getBillingAddress()?->getAddressLine1());
    }

    /**
     * The row that should NOT have changed.
     *
     * A second, unrelated invoice for a second company is built alongside the first, and nothing
     * asserted about the first may be true of it. Its own screen renders its own figures, and its
     * stored snapshot is re-read from the database afterwards to prove that visiting the first
     * invoice neither rewrote nor re-resolved it.
     */
    public function asecondUnrelatedInvoiceIsUntouchedByAnythingOnTheFirst(FunctionalTester $I): void
    {
        [, $first] = $this->invoicedOrder($I, 'PARITY-ONE');
        $second = $this->standaloneInvoice($I, 'PARITY-TWO', withAddresses: true);

        // The first invoice's screen says nothing about the second.
        $I->amOnPage('/admin/invoice/detail/' . $first->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($first->getDocumentNumber(), 'h1');
        $I->assertSame('4187 Billing Crescent', $this->panelRow($I, 'Billing Detail', 'Street Address:'));
        $I->dontSee('77 Standalone Street');
        $I->dontSee($second->getDocumentNumber());

        // The second's screen says nothing about the first.
        $I->amOnPage('/admin/invoice/detail/' . $second->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($second->getDocumentNumber(), 'h1');
        $I->assertSame('77 Standalone Street', $this->panelRow($I, 'Billing Detail', 'Street Address:'));
        $I->assertSame('$310.00', $this->footRow($I, 'Total Amount'));
        $I->dontSee('4187 Billing Crescent');
        $I->dontSee($first->getDocumentNumber());

        // Re-read both from the database. Rendering is a GET and must have written nothing.
        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        /** @var Invoice $storedFirst */
        $storedFirst = $em->find(Invoice::class, $first->getId());
        /** @var Invoice $storedSecond */
        $storedSecond = $em->find(Invoice::class, $second->getId());

        $I->assertSame('4187 Billing Crescent', $storedFirst->getBillingAddress()?->getAddressLine1());
        // Compared as a value, not as a string: SQLite's NUMERIC affinity hands '1911.00' back as
        // '1911', which is a fact about the column's storage and not about this invoice.
        $I->assertSame(1911.0, (float) $storedFirst->getTotal());
        $I->assertNotNull($storedFirst->getSalesOrder());

        $I->assertSame('77 Standalone Street', $storedSecond->getBillingAddress()?->getAddressLine1());
        $I->assertSame(310.0, (float) $storedSecond->getTotal());
        $I->assertNull($storedSecond->getSalesOrder(), 'The second invoice was never linked to an order.');
        $I->assertNotSame($storedFirst->getDocumentNumber(), $storedSecond->getDocumentNumber());
    }

    /**
     * `Invoice::canEditOnStatus()` is the one and only authoritative "can this be edited" gate —
     * order-linked or not, and regardless of anything else about the invoice. This is the positive
     * half: an invoice raised from an order, still in an editable status (Pending, here), gets a
     * real Edit tab pointing at a real route.
     */
    public function theInvoiceTabBarOffersAnEditScreenWhileEditableOnStatus(FunctionalTester $I): void
    {
        [, $invoice] = $this->invoicedOrder($I, 'PARITY-TABS');
        $I->assertTrue($invoice->canEditOnStatus(), 'guard: the fixture must land on an editable status or this proves nothing.');
        $I->assertNotNull($invoice->getSalesOrder(), 'guard: this is the order-linked case — canEditOnStatus must be the gate, not salesOrder being null.');

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();

        // Positive control: the nav rendered, and these are the tabs it really has. No standalone
        // "Invoice PDF" tab any more — the PDF/Download disclosure's own "Print Invoice" already
        // reaches the identical route, so the bar no longer offers the same PDF from two buttons.
        $I->seeLink('View', '/admin/invoice/detail/' . $invoice->getId());
        $I->seeLink('Packing Slip', '/admin/invoice/packing-slip/' . $invoice->getId());
        $I->seeLink('Payments', '/admin/invoice/' . $invoice->getId() . '/payments');
        $I->seeLink('Edit', '/admin/invoice/edit/' . $invoice->getId());

        $router = $I->grabService('router');
        $I->assertNotNull(
            $router->getRouteCollection()->get('admin_invoice_edit'),
            'admin_invoice_edit exists — an order-linked invoice may be corrected the same way a standalone one is.',
        );
    }

    /**
     * The negative half of the same gate: a Completed invoice has no Edit tab, because
     * `InvoiceStatus::allowsEditing()` refuses Completed and Cancelled regardless of how the
     * invoice was raised. This is the only reason an Edit tab is ever withheld now.
     */
    public function theInvoiceTabBarWithholdsEditOnceCompletedRegardlessOfSalesOrder(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        [, $invoice] = $this->invoicedOrder($I, 'PARITY-TABS-DONE');
        $invoice->setStatus('Processing', DocumentActor::system(), 'Payment settled.');
        $invoice->setStatus('Completed', DocumentActor::system(), 'Shipped.');
        $em->flush();
        $I->assertFalse($invoice->canEditOnStatus());

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('//nav[@aria-label="Invoice detail actions"]//a[normalize-space()="Edit"]');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────────

    /**
     * An approved order with both address snapshots, invoiced in full through the real service —
     * which is the path every invoice in the application is born on, and the path that copies the
     * order's frozen addresses onto the invoice.
     *
     * @return array{0: SalesOrder, 1: Invoice}
     */
    private function invoicedOrder(FunctionalTester $I, string $prefix): array
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $company = (new Company())
            ->setName('Parity Buyer Co')
            ->setCode($prefix . '-' . uniqid())
            ->setPrimaryEmail('buyer@parity-buyer.example')
            ->setPhoneNumber('+1 604 555 0101');
        $I->haveInRepository($company);

        $billing = (new CompanyAddress())->setCompany($company)->setIsDefaultBilling(true);
        $billing
            ->setFirstName(self::BILLING['First Name:'])
            ->setLastName(self::BILLING['Last Name:'])
            ->setCompanyName(self::BILLING['Company Name:'])
            ->setAddressLine1(self::BILLING['Street Address:'])
            ->setAddressLine2(self::BILLING['Address 2:'])
            ->setCity(self::BILLING['City:'])
            ->setProvince(self::BILLING['Province:'])
            ->setCountry(self::BILLING['Country:'])
            ->setPostalCode(self::BILLING['Postal Code:'])
            ->setPhone(self::BILLING['Phone number:'])
            ->setFax(self::BILLING['Fax Number:'])
            ->setEmailPrimary(self::BILLING['Primary Email:'])
            ->setEmailSecondary(self::BILLING['Secondary Email:']);
        $I->haveInRepository($billing);

        $shipping = (new CompanyAddress())->setCompany($company)->setIsDefaultShipping(true);
        $shipping
            ->setFirstName(self::SHIPPING['First Name:'])
            ->setLastName(self::SHIPPING['Last Name:'])
            ->setCompanyName(self::SHIPPING['Company Name:'])
            ->setAddressLine1(self::SHIPPING['Street Address:'])
            ->setAddressLine2(self::SHIPPING['Address 2:'])
            ->setCity(self::SHIPPING['City:'])
            ->setProvince(self::SHIPPING['Province:'])
            ->setCountry(self::SHIPPING['Country:'])
            ->setPostalCode(self::SHIPPING['Postal Code:'])
            ->setPhone(self::SHIPPING['Phone number:'])
            ->setFax(self::SHIPPING['Fax Number:'])
            ->setEmailPrimary(self::SHIPPING['Primary Email:'])
            ->setEmailSecondary(self::SHIPPING['Secondary Email:'])
            ->setDeliveryInstructions('Leave with the depot supervisor.');
        $I->haveInRepository($shipping);

        $sku = $prefix . '-SKU-1';
        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Parity Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        // 91 x $20.00 = $1,820.00, plus 5% GST = $91.00, total $1,911.00. Chosen so that no figure
        // on the page is a substring of another: 91, 1820, 1911 and the postal codes share no run
        // of digits, which is what makes a mis-scoped assertion fail instead of passing by accident.
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($prefix . '-SO-' . uniqid())
            ->setPoNumber('PO-' . $prefix . '-4471')
            ->setUserName('Prudence Placer')
            ->setPaymentMethod('Pay Upon Delivery')
            ->setPaymentTerm('Net 30')
            ->setSpecialInstructions('Deliver before noon.')
            ->setFulfillmentRegion('Main')
            ->setSubtotal('1820.00')
            ->setTax('91.00')
            ->setTotal('1911.00');
        $order->setBillingAddressFrom($billing);
        $order->setShippingAddressFrom($shipping);
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName('Parity Widget')
                ->setSku($sku)
                ->setUnit('Each')
                ->setWeight('12.500')
                ->setTaxCode('G')
                ->setBatch('LOT-' . $prefix . '-77')
                ->setQuantity('91')
                ->setPrice('20.00')
                ->setSubtotal('1820.00')
        );
        $I->haveInRepository($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $em->flush();

        $invoice = $I->grabService(OrderInvoicingService::class)->invoiceInFull(
            $order,
            $em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
        );
        $em->flush();

        return [$order, $invoice];
    }

    /**
     * An invoice with no sales order behind it — the standalone case the app is heading towards.
     * Built directly rather than through OrderInvoicingService, because the whole point is that no
     * order was involved.
     */
    private function standaloneInvoice(FunctionalTester $I, string $prefix, bool $withAddresses): Invoice
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $company = (new Company())
            ->setName('Standalone Buyer Ltd')
            ->setCode($prefix . '-' . uniqid())
            ->setPrimaryEmail('ap@standalone-buyer.example');
        $I->haveInRepository($company);

        $product = (new ProductCore())
            ->setSku('SOLO-SKU')
            ->setName('Standalone Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);

        // 31 x $10.00 = $310.00. No overlap with the invoiced order's 91 / 1820 / 1911.
        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber($prefix . '-INV-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setInvoiceDate('2026-09-01')
            ->setSubtotal('310.00')
            ->setTax('0.00')
            ->setTotal('310.00');
        $invoice->addLine(
            (new InvoiceLine())
                ->setProduct($product)
                ->setName('Standalone Widget')
                ->setSku('SOLO-SKU')
                ->setUnit('Each')
                ->setQuantity('31')
                ->setPrice('10.00')
                ->setSubtotal('310.00')
        );

        if ($withAddresses) {
            // Typed straight onto the document: a standalone invoice has no order to inherit from,
            // so its snapshot is its own from the start.
            $invoice->addressForWriting(AbstractDocumentAddress::TYPE_BILLING)
                ->setCompanyName('Standalone Buyer Ltd')
                ->setFirstName('Sandra')
                ->setLastName('Standalone')
                ->setAddressLine1('77 Standalone Street')
                ->setCity('Nanaimo')
                ->setProvince('BC')
                ->setCountry('CA')
                ->setPostalCode('V9R 5K6');
            $invoice->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING)
                ->setCompanyName('Standalone Buyer Ltd')
                ->setAddressLine1('88 Standalone Dock')
                ->setCity('Nanaimo')
                ->setProvince('BC')
                ->setCountry('CA')
                ->setPostalCode('V9R 5K7');
        }

        $em->persist($invoice);
        $em->flush();

        return $invoice;
    }

    // ── Cell readers ─────────────────────────────────────────────────────────────────
    //
    // Each of these addresses ONE cell by the label or heading beside it, so an assertion can only
    // pass against the field it names. That is the whole of #627 on a screen like this one.

    private function panelRow(FunctionalTester $I, string $panel, string $label): string
    {
        return self::collapse($I->grabTextFrom(sprintf(
            '//div[contains(@class,"order-detail-grid")]/section[.//h2[normalize-space()="%s"]]'
            . '//div[contains(@class,"detail-row")][label[normalize-space()="%s"]]/strong',
            $panel,
            $label,
        )));
    }

    private function rowCount(FunctionalTester $I, string $panel): int
    {
        return count($I->grabMultiple(sprintf(
            '//div[contains(@class,"order-detail-grid")]/section[.//h2[normalize-space()="%s"]]'
            . '//div[contains(@class,"detail-row")]',
            $panel,
        )));
    }

    /** One space between words, nothing at the ends — a <strong> may hold a <br> and a second line. */
    private static function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /** $column is 1-based across Name, U/M, SKU, Weight, Quantity, Price, Subtotal, Tax Code, Tax $, Batch. */
    private function lineCell(FunctionalTester $I, string $sku, int $column): string
    {
        return self::collapse($I->grabTextFrom(sprintf(
            '//table[contains(@class,"order-products-table")]/tbody/tr[td[normalize-space()="%s"]]/td[%d]',
            $sku,
            $column,
        )));
    }

    /**
     * The money against a named row in the shared totals box.
     *
     * It used to read the line table's own <tfoot>; the invoice now uses the same box as the order
     * and the quote, so the selector follows it there.
     */
    private function footRow(FunctionalTester $I, string $label): string
    {
        return self::collapse($I->grabTextFrom(sprintf(
            '//div[span[normalize-space()="%s:"]]/strong',
            $label,
        )));
    }
}
