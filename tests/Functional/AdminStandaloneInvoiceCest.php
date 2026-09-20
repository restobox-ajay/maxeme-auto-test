<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use App\Entity\Invoice;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * An invoice raised with no sales order behind it — the create screen, and everything downstream
 * of the document it produces (queue item 1).
 *
 * ## Conducted, not asserted at (#624)
 *
 * Every invoice here is made the way a person makes one: a plain form POST to the real route,
 * carrying a scraped CSRF token, with the fixtures this file creates itself. The figures are then
 * read back out of `invoice` and `invoice_line` BY COLUMN through a second connection read, not off
 * the entity the request left behind — an in-memory object proves what the request computed, not
 * what was stored.
 *
 * ## What this actually protects
 *
 * The create screen is the small half. Everything downstream of an invoice was written when an
 * invoice could only come from an order — payments, credit notes, sales returns, the packing slip,
 * the PDF on both the admin and the customer side, the status derivation — so the test that matters
 * is the one that opens each of those for an invoice whose `sales_order_id` is NULL and proves it
 * renders. A create page whose invoice 500s the moment somebody prints it is worse than no create
 * page.
 *
 * ## Numbers are never asserted with see()
 *
 * `see('50')` matches '1050' (#627), so no figure in this file is asserted against the page. Money
 * and quantities are compared as stored columns; where a page is asserted on at all it is asserted
 * on a label or an element, always with a positive control proving the page rendered.
 */
final class AdminStandaloneInvoiceCest
{
    private Company $company;
    private ProductCore $product;
    private CompanyAddress $shippingAddress;

    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('standalone-invoice@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->company = (new Company())
            ->setName('Standalone Invoice Wholesale')
            ->setCode('SIW-' . uniqid())
            ->setPrimaryEmail('ap@standalone-invoice.example');
        $I->haveInRepository($this->company);

        // Ontario, so the shared tax calculator actually charges something: the figures below are
        // the ones the tax contract produces, not zeros that would make every comparison vacuous.
        $I->haveInRepository(
            (new CompanyAddress())
                ->setCompany($this->company)
                ->setLabel('Head office')
                ->setCompanyName('Standalone Invoice Wholesale')
                ->setFirstName('Bill')
                ->setLastName('Toomey')
                ->setAddressLine1('1 Billing Road')
                ->setCity('Toronto')
                ->setProvince('ON')
                ->setCountry('CA')
                ->setPostalCode('M4B1B3')
                ->setIsDefaultBilling(true)
        );

        $this->shippingAddress = (new CompanyAddress())
            ->setCompany($this->company)
            ->setLabel('Warehouse door')
            ->setCompanyName('Standalone Invoice Wholesale')
            ->setFirstName('Ship')
            ->setLastName('Tomey')
            ->setAddressLine1('2 Receiving Lane')
            ->setCity('Toronto')
            ->setProvince('ON')
            ->setCountry('CA')
            ->setPostalCode('M4B1B4')
            ->setIsDefaultShipping(true);
        $I->haveInRepository($this->shippingAddress);

        $this->product = (new ProductCore())
            ->setSku('SIW-SKU-' . uniqid())
            ->setName('Standalone Invoice Widget')
            ->setSalesTaxCode('G')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->product);
    }

    /**
     * The entry point. A route with no button is not a feature, so the Invoices grid carries the
     * way in beside its existing actions.
     */
    public function theInvoiceListOffersTheCreateScreen(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/invoice');
        $I->seeResponseCodeIsSuccessful();
        $I->seeLink('Create Invoice', '/admin/invoice/create');
    }

    /**
     * And it survives the id-based company filter the grid gained while this branch was in flight.
     *
     * Scoped to one customer the link carries that customer, so the screen opens on the company the
     * admin was already looking at rather than asking again. The unscoped assertion above is the
     * positive control for this one: both selectors are `Create Invoice`, and only the href differs.
     */
    public function theCompanyScopedListOffersTheCreateScreenForThatCompany(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/invoice?InvoiceSearch[company_id]=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeLink('Create Invoice', '/admin/invoice/create?company_id=' . $this->company->getId());
    }

    /**
     * Step one is the customer, exactly as on the order and quote create screens, and step two
     * offers the shared searchable picker rather than a numeric product id box.
     */
    public function theScreenPicksACustomerThenOffersTheSearchableProductPicker(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/invoice/create');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Choose Customer');
        // Nothing to fill in until a customer is chosen — the line table is not on this step.
        $I->dontSeeElement('select[name$="[product_id]"]');

        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->company->getId(),
        ]);

        $I->seeCurrentUrlEquals('/admin/invoice/create?company_id=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();
        // Positive control for the two absence assertions below: the workspace really did render.
        $I->see('Line Items');
        $I->seeElement('select.js-searchable-select[name$="[product_id]"]');
        // The thing the issue asks for: the picker, not a raw id field. A <noscript> id box exists
        // only past the catalogue's inline limit, which this fixture is nowhere near.
        $I->dontSeeElement('input[type="number"][name$="[product_id_manual]"]');
    }

    /**
     * The invoice is created, and its lines, totals and tax are what the database holds afterwards.
     *
     * One product line and one blank line, because both have to work: a standalone invoice is
     * frequently a rebill with a typed description on it and no catalogue row behind it.
     */
    public function aStandaloneInvoiceStoresItsLinesTotalsAndTax(FunctionalTester $I): void
    {
        $highWaterMark = $this->highestInvoiceId($I);

        $this->postInvoice($I, [
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-11',
            'due_date' => '2026-10-11',
            'po_number' => 'PO-STANDALONE-1',
            'payment_term' => 'Net 30',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '3', 'price' => '10.00', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
                1 => ['product_id' => '', 'name' => 'Pallet deposit', 'qty' => '1', 'price' => '25.00', 'sku' => '', 'unit' => '', 'tax_code' => 'E', 'cost' => '', 'location' => 'Main'],
            ],
        ]);

        $invoiceRow = $this->newestInvoiceRow($I, $highWaterMark);

        $I->assertNull($invoiceRow['sales_order_id'], 'the whole point: no order stands behind it');
        $I->assertSame('55.00', $this->money($invoiceRow['subtotal']), '3 x $10.00 of goods plus a $25.00 blank line');
        // Ontario HST at 13%, charged on the taxable line only: the blank line is coded E.
        $I->assertSame('3.90', $this->money($invoiceRow['tax']), 'tax is 13% of the taxable line alone');
        $I->assertSame('58.90', $this->money($invoiceRow['total']), 'total is subtotal plus tax');
        $I->assertSame('Pending', $invoiceRow['status'], 'Raise invoice issues it through the named action');
        $I->assertSame('2026-09-11', $invoiceRow['invoice_date']);
        $I->assertSame('2026-10-11', $invoiceRow['due_date']);
        $I->assertSame('PO-STANDALONE-1', $invoiceRow['po_number']);

        // The tax figure is the sum of the document's own tax lines, so the stored column and the
        // breakdown behind it cannot disagree — the shape #589/#590/#591 are all about.
        $taxLines = json_decode((string) $invoiceRow['tax_lines'], true);
        $I->assertIsArray($taxLines);
        $I->assertNotEmpty($taxLines['lines'] ?? [], 'a real calculator ran; this is not an untaxed document');
        $I->assertSame(
            3.90,
            round(array_sum(array_map(static fn (array $line): float => (float) $line['amount'], $taxLines['lines'])), 2),
            'the tax column is the sum of the tax lines beside it',
        );

        $lineRows = $this->lineRowsFor($I, (int) $invoiceRow['id']);
        $I->assertCount(2, $lineRows);

        $I->assertSame((string) $this->product->getId(), (string) $lineRows[0]['product_id']);
        $I->assertSame('Standalone Invoice Widget', $lineRows[0]['name'], 'a product row is a snapshot of the catalogue row');
        $I->assertSame('3.00', $this->quantity($lineRows[0]['quantity']));
        $I->assertSame('10.00', $this->money($lineRows[0]['price']));
        $I->assertSame('30.00', $this->money($lineRows[0]['subtotal']));
        $I->assertSame('G', $lineRows[0]['tax_code']);
        $I->assertNull($lineRows[0]['sales_order_line_id'], 'no order line to attribute it to');

        $I->assertNull($lineRows[1]['product_id'], 'the blank line names no product');
        $I->assertSame('Pallet deposit', $lineRows[1]['name']);
        $I->assertSame('25.00', $this->money($lineRows[1]['subtotal']));
        $I->assertSame('E', $lineRows[1]['tax_code']);
    }

    /**
     * The cheap half of #624, and the one that catches a save writing through the wrong row: an
     * invoice that has nothing to do with this one is byte-identical afterwards.
     */
    public function anUnrelatedInvoiceIsUntouchedByTheNewOne(FunctionalTester $I): void
    {
        $bystander = $this->orderDerivedInvoice($I, '4.00', '2.00', '15.00');
        $before = $this->invoiceRow($I, (int) $bystander->getId());
        $beforeLines = $this->lineRowsFor($I, (int) $bystander->getId());

        $this->postInvoice($I, [
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-11',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '7', 'price' => '4.00', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ]);

        $after = $this->invoiceRow($I, (int) $bystander->getId());
        $afterLines = $this->lineRowsFor($I, (int) $bystander->getId());

        $I->assertSame($before, $after, 'the invoice nobody touched is exactly as it was');
        $I->assertSame($beforeLines, $afterLines, 'and so are its lines');
    }

    /**
     * Tax comes out of the same place on both paths.
     *
     * The same goods, at the same price, for the same customer: one invoice raised from an order
     * through Convert to Invoice, one raised standalone. The stored tax and total columns match,
     * and they are not zero — a second tax implementation on the standalone path would show up
     * here as a difference, and an untaxed fixture would make the comparison meaningless.
     */
    public function theTaxFigureMatchesTheOrderDerivedPath(FunctionalTester $I): void
    {
        $orderDerived = $this->orderDerivedInvoice($I, '10.00', '4.00', '12.50');
        $orderDerivedRow = $this->invoiceRow($I, (int) $orderDerived->getId());

        $highWaterMark = $this->highestInvoiceId($I);
        $this->postInvoice($I, [
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-11',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '4', 'price' => '12.50', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ]);
        $standaloneRow = $this->newestInvoiceRow($I, $highWaterMark);

        $I->assertSame('50.00', $this->money($standaloneRow['subtotal']), '4 x $12.50');
        $I->assertNotSame('0.00', $this->money($standaloneRow['tax']), 'a calculator really ran');
        $I->assertSame(
            $this->money($orderDerivedRow['tax']),
            $this->money($standaloneRow['tax']),
            'the same goods are taxed the same whether or not an order stands behind them',
        );
        $I->assertSame($this->money($orderDerivedRow['total']), $this->money($standaloneRow['total']));
    }

    /**
     * The addresses are a snapshot taken at creation, not a live read of the customer.
     *
     * Settled for orders and the same for invoices: a document records who was billed and where the
     * goods went. Editing the customer's address book afterwards must not restate an invoice that
     * has already gone out.
     */
    public function theAddressesAreFrozenAtCreationAndDoNotFollowTheCustomer(FunctionalTester $I): void
    {
        $highWaterMark = $this->highestInvoiceId($I);
        $this->postInvoice($I, [
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-11',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '1', 'price' => '10.00', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ]);
        $invoiceId = (int) $this->newestInvoiceRow($I, $highWaterMark)['id'];

        $addresses = $this->connection($I)->fetchAllAssociative(
            'SELECT type, address_line1, city, province, postal_code FROM invoice_address WHERE invoice_id = ? ORDER BY type',
            [$invoiceId],
        );
        $byType = [];
        foreach ($addresses as $address) {
            $byType[(string) $address['type']] = $address;
        }

        $I->assertArrayHasKey('billing', $byType, 'the customer default was copied onto the document');
        $I->assertArrayHasKey('shipping', $byType);
        $I->assertSame('1 Billing Road', $byType['billing']['address_line1']);
        $I->assertSame('2 Receiving Lane', $byType['shipping']['address_line1']);

        // Now move the customer, the way an admin would, and re-read the invoice's own rows.
        $em = $I->grabService(EntityManagerInterface::class);
        $this->shippingAddress->setAddressLine1('999 Somewhere Else')->setCity('Ottawa');
        $em->flush();
        $em->clear();

        $frozen = $this->connection($I)->fetchAssociative(
            "SELECT address_line1, city FROM invoice_address WHERE invoice_id = ? AND type = 'shipping'",
            [$invoiceId],
        );
        $I->assertSame('2 Receiving Lane', $frozen['address_line1'], 'the invoice kept the address it was raised against');
        $I->assertSame('Toronto', $frozen['city']);
    }

    /**
     * The test this feature actually needs: every screen downstream of an invoice, opened against
     * one whose `sales_order_id` is NULL.
     *
     * Each of these was written when an invoice could only come from an order. They are driven
     * here, in one test, because what matters is that the whole set survives — a feature that
     * creates a document nobody can print, pay, credit or return is not a feature.
     */
    public function everyDownstreamScreenRendersForAnInvoiceWithNoOrder(FunctionalTester $I): void
    {
        $highWaterMark = $this->highestInvoiceId($I);
        $this->postInvoice($I, [
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-11',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '2', 'price' => '10.00', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ]);
        $row = $this->newestInvoiceRow($I, $highWaterMark);
        $invoiceId = (int) $row['id'];
        $number = (string) $row['document_number'];
        $I->assertNull($row['sales_order_id']);

        // The grid, where the Order # cell has nothing to point at.
        $I->amOnPage('/admin/invoice');
        $I->seeResponseCodeIsSuccessful();
        $I->see($number);

        // The detail screen.
        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $I->see($number);

        // The document, on screen and as the PDF an admin downloads.
        $I->amOnPage('/admin/invoice/print/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $I->see($number);

        $I->amOnPage('/admin/invoice/print/' . $invoiceId . '?download=1');
        $I->seeResponseCodeIsSuccessful();
        $I->assertStringStartsWith('%PDF', substr($I->grabPageSource(), 0, 4), 'the PDF really rendered');

        // The packing slip, both ways.
        $I->amOnPage('/admin/invoice/packing-slip/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $I->see($number);

        $I->amOnPage('/admin/invoice/packing-slip/' . $invoiceId . '?pdf=1');
        $I->seeResponseCodeIsSuccessful();
        $I->assertStringStartsWith('%PDF', substr($I->grabPageSource(), 0, 4));

        // Payments: the screen, and a payment actually recorded against it.
        $I->amOnPage('/admin/invoice/' . $invoiceId . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Payments');

        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/payments', [
            '_token' => $I->csrfToken(),
            'received_at' => '2026-09-11',
            'method' => 'Cheque',
            'amount' => '10.00',
            'comment' => 'Part payment on a standalone invoice.',
        ]);
        $paid = $this->connection($I)->fetchOne(
            'SELECT amount FROM invoice_payment_application WHERE invoice_id = ?',
            [$invoiceId],
        );
        $I->assertSame('10.00', $this->money($paid), 'the payment landed on the invoice with no order');

        // The link screen — the one place the missing order is the subject rather than a field.
        $I->amOnPage('/admin/invoice/' . $invoiceId . '/link');
        $I->seeResponseCodeIsSuccessful();
        $I->see($number);

        // A credit note raised against it, which copies the invoice's own frozen addresses.
        $I->amOnPage('/admin/credit-memo/new?invoice=' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Standalone Invoice Widget');

        $I->sendFormPostRequest('/admin/credit-memo/new?invoice=' . $invoiceId, [
            '_token' => $I->csrfToken(),
            // The editor renders this pre-filled and required; posted here for the same reason —
            // CreditMemo's document_date is NOT NULL and nothing stamps it at persist.
            'document_date' => '2026-09-11',
            'reason' => 'One unit came back damaged.',
            'lines' => [[
                'invoice_line_id' => (string) $this->lineRowsFor($I, $invoiceId)[0]['id'],
                'name' => 'Standalone Invoice Widget',
                'quantity' => '1',
                'price' => '10.00',
            ]],
        ]);
        $memoCount = (int) $this->connection($I)->fetchOne(
            'SELECT COUNT(*) FROM credit_memo WHERE invoice_id = ?',
            [$invoiceId],
        );
        $I->assertSame(1, $memoCount, 'a credit note can be raised against an invoice with no order');

        // A sales return raised against it — SalesReturn::$salesOrder simply stays null.
        $I->amOnPage('/admin/sales-return/new?invoice=' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Standalone Invoice Widget');

        $I->sendFormPostRequest('/admin/sales-return/new?invoice=' . $invoiceId, [
            '_token' => $I->csrfToken(),
            'reason' => 'Customer over-ordered.',
            'lines' => [[
                'invoice_line_id' => (string) $this->lineRowsFor($I, $invoiceId)[0]['id'],
                'product_id' => (string) $this->product->getId(),
                'name' => 'Standalone Invoice Widget',
                'quantity' => '1',
            ]],
        ]);
        $returned = $this->connection($I)->fetchAssociative(
            'SELECT invoice_id, sales_order_id FROM sales_return WHERE invoice_id = ?',
            [$invoiceId],
        );
        $I->assertIsArray($returned, 'a return can be raised against an invoice with no order');
        $I->assertNull($returned['sales_order_id'], 'and it points at no order, because there is none');

        // The status actions, through the real buttons.
        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/action/start-processing', ['_token' => $I->csrfToken()]);
        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/action/complete', ['_token' => $I->csrfToken()]);
        $I->assertSame(
            'Completed',
            $this->invoiceRow($I, $invoiceId)['status'],
            'the status derivation copes with an invoice that belongs to no order',
        );

        // The customer's own copy of the document, which renders the same template.
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        // Re-read through the Doctrine module's OWN entity manager, not through $this->company and
        // not through grabService(): the kernel is rebooted between requests, so the object this
        // Cest has held since _before() belongs to an entity manager that is gone, and the one
        // haveInRepository() writes with would take it for a brand-new Company to cascade-persist.
        $company = $I->grabEntityFromRepository(Company::class, ['id' => $this->company->getId()]);
        $customer = (new CustomerUser())
            ->setEmail('standalone-invoice-buyer-' . uniqid() . '@example.test')
            ->setFirstName('Buy')
            ->setLastName('Er')
            ->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);
        $I->amLoggedInAs($customer, 'main');
        $I->haveHttpHeader('Host', 'localhost');
        $I->amOnPage('/orders/invoice/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $I->assertStringStartsWith('%PDF', substr($I->grabPageSource(), 0, 4), "the customer's copy renders too");
    }

    /**
     * Emailing a standalone invoice actually sends — through the SHIPPED invoice_customer /
     * invoice_self bodies, not a test double, which is the whole point: those two rows used to be
     * written against an `order` variable that a standalone invoice does not have, and
     * InvoiceController::send() refused the send outright rather than let Twig crash on it. Both are
     * migrated to read the invoice's own fields now (Version20260919180000), with `order` as
     * optional enhancement, so this is no longer a refusal to test — it is a send to prove.
     */
    public function emailingAStandaloneInvoiceActuallySends(FunctionalTester $I): void
    {
        $highWaterMark = $this->highestInvoiceId($I);
        $this->postInvoice($I, [
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-11',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '1', 'price' => '10.00', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ]);
        $invoiceRow = $this->newestInvoiceRow($I, $highWaterMark);
        $invoiceId = (int) $invoiceRow['id'];

        $I->amOnPage('/admin/invoice/print/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        // The positive control: both buttons render, and the old refusal message is gone.
        $I->seeElement('#invoice-action-send-customer');
        $I->seeElement('#invoice-action-send-self');
        $I->dontSee('Emailing needs an order behind the invoice');

        $token = $I->grabAttributeFrom(
            '//form[.//input[@name="type" and @value="customer"]]//input[@name="_token"]',
            'value',
        );

        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/admin/invoice/send/' . $invoiceId, ['_token' => $token, 'type' => 'customer']);

        $I->seeEmailIsSent();
        $I->assertEmailSubjectContains($invoiceRow['document_number']);
        $I->assertEmailHtmlBodyContains($invoiceRow['document_number']);
        // What the old body could never have shown for this invoice: no order to name.
        $I->assertEmailHtmlBodyNotContains('href="#"');
    }

    /**
     * Save as draft leaves an inert document: a draft holds no stock, which is the whole difference
     * between it and an issued invoice.
     */
    public function aDraftInvoiceHoldsNoStock(FunctionalTester $I): void
    {
        $highWaterMark = $this->highestInvoiceId($I);
        $this->postInvoice($I, [
            'save_mode' => 'draft',
            'invoice_date' => '2026-09-11',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '2', 'price' => '20.00', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ]);

        $row = $this->newestInvoiceRow($I, $highWaterMark);
        $I->assertSame('Draft', $row['status']);
        $I->assertSame('40.00', $this->money($row['subtotal']));
        $I->assertSame(
            0,
            (int) $this->connection($I)->fetchOne(
                'SELECT COUNT(*) FROM invoice_inventory_reservation WHERE invoice_id = ?',
                [(int) $row['id']],
            ),
            'a draft is inert: it holds nothing',
        );
    }

    /**
     * #326's ceiling, on this document: an ISSUED standalone invoice may not bill more than exists.
     *
     * Invoicing moves no stock, but an issued invoice HOLDS it — InvoiceReservationSubject puts
     * what each line bills into `pending_quantity`, and stockedQuantityFor() returns the FULL billed
     * quantity for a line with no order line behind it, which is every line on this screen. So
     * without a ceiling this page can drive a SKU's availability negative and make it unbuyable for
     * every customer, which is #326 verbatim on the other document.
     *
     * Conducted against real stock this test creates itself, one unit over the line so the refusal
     * cannot be a rounding artefact, and the bystander product sits in the SAME warehouse so a
     * check that looked up "the" inventory row rather than the line's own would be caught.
     */
    public function anIssuedInvoiceIsRefusedWhenStockCannotCoverIt(FunctionalTester $I): void
    {
        [$stockedId, $bystanderId] = $this->stockTheMainRegion($I, 40, 40);

        $invoicesBefore = (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM invoice');
        $counterBefore = $this->connection($I)->fetchOne("SELECT last_value FROM document_number_counter WHERE kind = 'invoice'");
        $pendingBefore = (int) $this->inventoryColumn($I, $stockedId, 'pending_quantity');
        $bystanderPendingBefore = (int) $this->inventoryColumn($I, $bystanderId, 'pending_quantity');
        $I->assertSame(0, $pendingBefore, 'guard: nothing is held before the invoice is attempted');

        $this->postInvoice($I, [
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-11',
            'fulfillment_region' => 'Main',
            // 41 against 40 in stock.
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '41', 'price' => '10.00', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ]);

        $I->seeResponseCodeIs(422);
        $I->see('Save as a draft, or reduce the quantity.');

        $I->assertSame(
            $invoicesBefore,
            (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM invoice'),
            'nothing was written',
        );
        $I->assertSame(
            $counterBefore,
            $this->connection($I)->fetchOne("SELECT last_value FROM document_number_counter WHERE kind = 'invoice'"),
            'and no invoice number was drawn on the way to the refusal',
        );
        $I->assertSame(
            $pendingBefore,
            (int) $this->inventoryColumn($I, $stockedId, 'pending_quantity'),
            'product_inventory.pending_quantity is exactly where it was',
        );
        $I->assertSame(
            $bystanderPendingBefore,
            (int) $this->inventoryColumn($I, $bystanderId, 'pending_quantity'),
            'and so is the other product stocked in the same warehouse',
        );

        // The refused form comes back carrying what was typed, so the admin can correct it rather
        // than retype it. The positive control is the value itself: a wiped form would render the
        // blank rows with no quantity of 41 in any of them.
        $I->seeElement('input[name$="[qty]"][value="41"]');
    }

    /**
     * The other half of the same rule: a DRAFT may bill more than exists, because a draft holds
     * nothing. Without this, building an invoice before deciding what is really on the shelf would
     * be impossible — and it is the ordinary way an admin works.
     */
    public function aDraftMayBillMoreStockThanExists(FunctionalTester $I): void
    {
        [$stockedId] = $this->stockTheMainRegion($I, 40, 40);
        $pendingBefore = (int) $this->inventoryColumn($I, $stockedId, 'pending_quantity');
        $highWaterMark = $this->highestInvoiceId($I);

        $this->postInvoice($I, [
            'save_mode' => 'draft',
            'invoice_date' => '2026-09-11',
            'fulfillment_region' => 'Main',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '41', 'price' => '10.00', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ]);

        $row = $this->newestInvoiceRow($I, $highWaterMark);
        $I->assertSame('Draft', $row['status'], 'invoice.status');
        $I->assertSame(
            '41.00',
            $this->quantity($this->lineRowsFor($I, (int) $row['id'])[0]['quantity']),
            'invoice_line.quantity — a draft takes the whole figure',
        );
        $I->assertSame(
            $pendingBefore,
            (int) $this->inventoryColumn($I, $stockedId, 'pending_quantity'),
            'and holds none of it: product_inventory.pending_quantity is untouched',
        );
    }

    /**
     * And what an issued invoice within the ceiling actually does to the ledger: it holds exactly
     * what it bills, on its own product's row and on no other.
     */
    public function anIssuedInvoiceHoldsWhatItBillsAndLeavesTheOtherProductAlone(FunctionalTester $I): void
    {
        [$stockedId, $bystanderId] = $this->stockTheMainRegion($I, 40, 40);
        $bystanderPendingBefore = (int) $this->inventoryColumn($I, $bystanderId, 'pending_quantity');
        $I->assertSame(0, (int) $this->inventoryColumn($I, $stockedId, 'pending_quantity'), 'guard: nothing held yet');

        $highWaterMark = $this->highestInvoiceId($I);
        $this->postInvoice($I, [
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-11',
            'fulfillment_region' => 'Main',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '7', 'price' => '10.00', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ]);

        $row = $this->newestInvoiceRow($I, $highWaterMark);
        $I->assertSame('Pending', $row['status'], 'invoice.status — Save issues it');
        $I->assertNull($row['sales_order_id'], 'and it still stands behind no order');
        $I->assertSame(
            7,
            (int) $this->inventoryColumn($I, $stockedId, 'pending_quantity'),
            'product_inventory.pending_quantity rose by exactly what the invoice bills',
        );
        $I->assertSame(
            $bystanderPendingBefore,
            (int) $this->inventoryColumn($I, $bystanderId, 'pending_quantity'),
            'the product this invoice does not bill was left alone, in the same warehouse',
        );
    }

    /**
     * A form with nothing on it is refused, writes no invoice — and burns no invoice number.
     *
     * The number comes from a counter row DocumentNumberAllocator commits there and then, so a
     * refusal that had already drawn one would leave a hole in the accounting sequence for
     * somebody to explain later.
     */
    /**
     * Zero line items is a valid invoice (#full-parity, 2026-09-12) — a placeholder/soft-note
     * document, the same reasoning that already made a zero Qty legal on any one line. An entirely
     * blank line row (no product, no name) contributes nothing to the line collection, same as
     * posting no `lines` key at all.
     */
    public function anEmptyFormIsAcceptedWithZeroLines(FunctionalTester $I): void
    {
        $highWaterMark = $this->highestInvoiceId($I);
        $counterBefore = $this->connection($I)->fetchOne("SELECT last_value FROM document_number_counter WHERE kind = 'invoice'");

        $this->postInvoice($I, [
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-11',
            'lines' => [
                0 => ['product_id' => '', 'name' => '', 'qty' => '1', 'price' => '', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ]);

        $I->seeResponseCodeIsSuccessful();

        $invoiceRow = $this->newestInvoiceRow($I, $highWaterMark);
        $I->assertNotNull($invoiceRow['document_number'], 'a real invoice was raised, so it drew a number');
        $I->assertSame('Pending', $invoiceRow['status'], 'issue mode still issues it, with or without lines');
        $I->assertSame(
            0,
            (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM invoice_line WHERE invoice_id = ?', [$invoiceRow['id']]),
            'the blank row contributed no line',
        );
        $I->assertNotSame(
            $counterBefore,
            $this->connection($I)->fetchOne("SELECT last_value FROM document_number_counter WHERE kind = 'invoice'"),
            'the counter advanced for the invoice actually raised',
        );
    }

    /**
     * Shipping, fees and manual tax rows reach the stored snapshots, and the total is built from
     * them.
     *
     * The charge bar is the shared one (SalesDocumentChargeLines), so what this proves is the
     * wiring: a shipping row takes the document's highest tax class, a manual tax row stands as its
     * own line, and the tax column is the sum of the tax lines rather than a figure beside them.
     */
    public function chargeRowsReachTheStoredFeeAndTaxLines(FunctionalTester $I): void
    {
        $highWaterMark = $this->highestInvoiceId($I);
        $this->postInvoice($I, [
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-11',
            'charge_lines' => [
                ['label' => 'Custom Shipping', 'amount' => '15.00', 'type' => 'shipping'],
                ['label' => 'Custom GST', 'amount' => '1.00', 'type' => 'tax', 'slug' => 'custom-gst'],
            ],
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '4', 'price' => '10.00', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ]);

        $row = $this->newestInvoiceRow($I, $highWaterMark);

        $feeLines = json_decode((string) $row['fee_lines'], true);
        $I->assertIsArray($feeLines);
        $I->assertCount(1, $feeLines, 'the shipping row is the only charge on the fee snapshot');
        $I->assertSame('shipping', $feeLines[0]['type']);
        $I->assertSame(15.0, (float) $feeLines[0]['amount']);
        // Taxed at the document's highest class rather than at a class the row invented.
        $I->assertSame('G', $feeLines[0]['taxClass']);
        $I->assertSame('Custom Shipping', $row['shipping_method']);

        $taxLines = json_decode((string) $row['tax_lines'], true);
        $labels = array_map(static fn (array $line): string => (string) $line['label'], $taxLines['lines']);
        $I->assertContains('Custom GST', $labels, 'the typed tax row is a line of its own');

        // 40 of goods and 15 of shipping at 13%, plus the $1.00 typed by hand.
        $I->assertSame('7.15', $this->money(array_sum(array_map(
            static fn (array $line): float => (float) $line['amount'],
            array_filter($taxLines['lines'], static fn (array $line): bool => $line['label'] === 'HST'),
        ))));
        $I->assertSame('8.15', $this->money($row['tax']), 'the tax column is the sum of its lines');
        $I->assertSame('40.00', $this->money($row['subtotal']));
        $I->assertSame('63.15', $this->money($row['total']), 'goods plus shipping plus tax');
    }

    /**
     * The no-JS way to get another row: a real submit, exactly like order's own no-JS row buttons
     * (#full-parity, 2026-09-13 — invoice must not special-case this). It saves — staying Draft,
     * since no save_mode names 'issue' — the same way order's saveModeFromRequest() already treats
     * its own add/remove-charge and remove-line submits as a real 'recalc' save, not a no-op. An
     * invoice already gets a number on its very first Draft save regardless of which button asked
     * for it, so there was never a "must not mint" case here to protect.
     */
    public function addingALineWithoutJavascriptSavesADraft(FunctionalTester $I): void
    {
        $before = (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM invoice');

        $I->amOnPage('/admin/invoice/create?company_id=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();
        // A bare create page: two spare rows only, scoped to the real table — the shared JS-clone
        // <template>s in page_end carry the same row markup, and PHP's DOMDocument (unlike a real
        // browser) does not treat <template> content as inert, so an unscoped selector would double count.
        $I->seeNumberOfElements('table.invoice-line-table tbody tr.invoice-line-row', 2);

        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->company->getId(),
            'add_line' => '1',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '5', 'price' => '10.00', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
                1 => ['product_id' => '', 'name' => '', 'qty' => '1', 'price' => '', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
                2 => ['product_id' => '', 'name' => '', 'qty' => '1', 'price' => '', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ]);

        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            $before + 1,
            (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM invoice'),
            'pressing Add Line saves a real Draft invoice, exactly like order\'s own no-JS submits do',
        );
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $invoiceId = (int) $this->connection($I)->fetchOne('SELECT id FROM invoice ORDER BY id DESC LIMIT 1');
        $invoice = $entityManager->find(Invoice::class, $invoiceId);
        $I->assertSame('Draft', $invoice->getStatus(), 'add_line never promotes past Draft');
        $I->assertCount(1, $invoice->getLines(), 'the one real line saved, the two blanks dropped');
        // The blank row add_line appended is there for the next round of typing, same as order's
        // and quote's own fresh spare rows after any save.
        $I->seeNumberOfElements('table.invoice-line-table tbody tr.invoice-line-row', 3);
    }

    /**
     * Loads the create screen for this company, then posts the form to it — the two requests a
     * person makes, so the CSRF token is the one the page actually rendered.
     */
    private function postInvoice(FunctionalTester $I, array $params): void
    {
        $I->amOnPage('/admin/invoice/create?company_id=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest('/admin/invoice/create', array_merge(
            ['_token' => $I->csrfToken(), 'company_id' => (string) $this->company->getId()],
            $params,
        ));
    }

    /**
     * An ordinary order-derived invoice for the same customer and product, raised through the real
     * Convert to Invoice screen so the comparison is against what that path actually produces.
     *
     * It bills PART of the order deliberately. An invoice covering the whole of one is handed the
     * order's own frozen tax snapshot verbatim by OrderInvoicingService — which for a fixture order
     * is whatever the fixture typed — whereas a part-invoice computes its own tax from its own lines
     * through OrderTaxBreakdownService. The part-invoice is therefore the case that actually
     * exercises the tax path this feature had to reuse.
     */
    private function orderDerivedInvoice(FunctionalTester $I, string $orderQuantity, string $billedQuantity, string $price): Invoice
    {
        $quantity = $orderQuantity;
        $em = $I->grabService(EntityManagerInterface::class);

        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('SIW-ORD-' . uniqid())
            ->setSubtotal(number_format((float) $quantity * (float) $price, 2, '.', ''))
            ->setTax('0.00')
            ->setTotal(number_format((float) $quantity * (float) $price, 2, '.', ''));
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($this->product)
                ->setName($this->product->getName())
                ->setSku($this->product->getSku())
                ->setTaxCode('G')
                ->setQuantity($quantity)
                ->setPrice($price)
                ->setSubtotal(number_format((float) $quantity * (float) $price, 2, '.', ''))
        );
        // The order's own frozen addresses, so both documents are taxed on the same province.
        foreach (['billing', 'shipping'] as $type) {
            foreach ($this->company->getAddresses() as $address) {
                if (!$address instanceof CompanyAddress) {
                    continue;
                }
                if ($type === 'billing' && $address->isDefaultBilling()) {
                    $order->setBillingAddressFrom($address);
                }
                if ($type === 'shipping' && $address->isDefaultShipping()) {
                    $order->setShippingAddressFrom($address);
                }
            }
        }
        $I->haveInRepository($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $em->flush();

        $line = $order->getLines()->first();
        $I->amOnPage('/admin/invoice/create?order_id=' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [['product_id' => (string) $this->product->getId(), 'sales_order_line_id' => (string) $line->getId(), 'qty' => $billedQuantity, 'price' => $price]],
        ]);

        $em->clear();
        /** @var SalesOrder $reloaded */
        $reloaded = $em->find(SalesOrder::class, $order->getId());
        $invoice = $reloaded->getInvoices()->first();
        $I->assertInstanceOf(Invoice::class, $invoice, 'the order-derived invoice was raised');

        return $invoice;
    }

    /**
     * Stock for the Main region, with a BYSTANDER product stocked FIRST in the same warehouse.
     *
     * The order matters. Stocking the billed product first would make "the first row in this
     * warehouse" and "this line's own row" the same row, and every inventory assertion above would
     * pass for code that looked up the wrong one.
     *
     * @return array{0: int, 1: int} the billed product's product_inventory id, then the bystander's
     */
    private function stockTheMainRegion(FunctionalTester $I, int $billedQuantity, int $bystanderQuantity): array
    {
        $I->haveActiveFulfillmentRegionFor($this->company, 'Main');

        $bystander = (new ProductCore())
            ->setSku('SIW-BYSTANDER-' . uniqid())
            ->setName('Standalone Invoice Bystander')
            ->setSalesTaxCode('G')
            ->setOriginalPrice('9.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($bystander);

        $I->haveStockFor($bystander, $bystanderQuantity, 'Main');
        $I->haveStockFor($this->product, $billedQuantity, 'Main');

        return [
            (int) $this->connection($I)->fetchOne('SELECT id FROM product_inventory WHERE product_id = ?', [$this->product->getId()]),
            (int) $this->connection($I)->fetchOne('SELECT id FROM product_inventory WHERE product_id = ?', [$bystander->getId()]),
        ];
    }

    /** One column of one product_inventory row, re-read from the database. */
    private function inventoryColumn(FunctionalTester $I, int $inventoryId, string $column): mixed
    {
        return $this->connection($I)->fetchOne(
            sprintf('SELECT %s FROM product_inventory WHERE id = ?', $column),
            [$inventoryId],
        );
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }

    private function highestInvoiceId(FunctionalTester $I): int
    {
        return (int) $this->connection($I)->fetchOne('SELECT COALESCE(MAX(id), 0) FROM invoice');
    }

    /** @return array<string, mixed> */
    private function newestInvoiceRow(FunctionalTester $I, int $above): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT * FROM invoice WHERE id > ? ORDER BY id DESC LIMIT 1',
            [$above],
        );
        $I->assertIsArray($row, 'the save wrote an invoice row');

        return $row;
    }

    /** @return array<string, mixed> */
    private function invoiceRow(FunctionalTester $I, int $id): array
    {
        $row = $this->connection($I)->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$id]);
        $I->assertIsArray($row);

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function lineRowsFor(FunctionalTester $I, int $invoiceId): array
    {
        return $this->connection($I)->fetchAllAssociative(
            'SELECT * FROM invoice_line WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC',
            [$invoiceId],
        );
    }

    /** Money as the columns hold it, so '10' and '10.00' compare as the same figure. */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function quantity(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
