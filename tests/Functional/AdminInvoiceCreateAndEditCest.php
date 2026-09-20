<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CreditMemo;
use App\Entity\CreditMemoLine;
use App\Entity\Invoice;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Two routes became one, and a Draft invoice gained a way to be changed after it is raised.
 *
 * `admin_order_invoice_new` / `admin_order_invoice_create` are gone: `order_id` is now a parameter
 * of `admin_invoice_create`, read the same way GET and POST already read `company_id` — see that
 * method's docblock. `admin_invoice_edit` is new; it is `create()`'s save reused against an
 * existing standalone Draft, refused once the invoice is issued or bills a sales order.
 *
 * ## Conducted (#624), minimal by request
 *
 * Real screens, plain form POSTs with a scraped CSRF token, fixtures built here, every figure
 * re-read from the database by column. This file is deliberately narrow — the four cases that
 * exercise what changed — because a wider sweep of the touched sibling Cests already ran green
 * against this same working tree, and the two-route merge and the invoice-status verbs are moving
 * in this repo right now.
 *
 * #627: no bare see() on a number or a word the page prints elsewhere; the one absence check pairs
 * with a positive control in the same test.
 */
final class AdminInvoiceCreateAndEditCest
{
    private Company $company;
    private ProductCore $product;

    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('invoice-create-edit@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->company = (new Company())
            ->setName('Consolidated Invoicing Co')
            ->setCode('CIC-' . uniqid())
            ->setPrimaryEmail('ap@consolidated.example');
        $I->haveInRepository($this->company);

        // A taxable line with nowhere to tax it is refused (taxProvinceRefusal()) — the province
        // comes from the customer's default shipping address, so a fixture with none cannot raise
        // an ordinary standalone invoice at all.
        $address = (new CompanyAddress())
            ->setCompany($this->company)
            ->setAddressLine1('1 Test Street')
            ->setCity('Vancouver')
            ->setProvince('BC')
            ->setCountry('CA')
            ->setPostalCode('V6B 1A1')
            ->setIsDefaultShipping(true)
            ->setIsDefaultBilling(true);
        $I->haveInRepository($address);

        $this->product = (new ProductCore())
            ->setSku('CIC-SKU-' . uniqid())
            ->setName('Consolidated Widget')
            ->setSalesTaxCode('G')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->product);
    }

    /**
     * The two retired routes are gone outright — not aliased, not redirected. A 404 here is the
     * proof this is a real consolidation and not a second door left unlocked beside the new one.
     */
    public function theTwoRetiredRoutesAre404(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);

        $I->amOnPage('/admin/order/' . $order->getId() . '/invoice/new');
        $I->seeResponseCodeIs(404);

        $I->sendFormPostRequest('/admin/order/' . $order->getId() . '/invoice/new', ['_token' => 'irrelevant']);
        $I->seeResponseCodeIs(404);
    }

    /**
     * The order-linked screen and save, reached through the ONE route now, still raise a real
     * order-rooted invoice — the regression the merge itself must not break.
     */
    public function orderLinkedInvoiceStillWorksThroughTheOneRoute(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $order = $this->approvedOrder($I);
        $line = $order->getLines()->first();

        $I->amOnPage('/admin/invoice/create?order_id=' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeInSource((string) $order->getOrderNumber());

        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'order_id' => (string) $order->getId(),
            'save_mode' => 'issue',
            'lines' => [['product_id' => (string) $line->getProduct()->getId(), 'sales_order_line_id' => (string) $line->getId(), 'qty' => '3', 'price' => '10.00']],
        ]);

        $connection = $em->getConnection();
        $invoiceRow = $connection->fetchAssociative(
            'SELECT * FROM invoice WHERE sales_order_id = ?',
            [$order->getId()],
        );
        $I->assertIsArray($invoiceRow, 'the order-linked save, reached through admin_invoice_create, wrote an invoice');
        $I->assertSame('30.00', $this->money($invoiceRow['subtotal']), 'invoice.subtotal is 3 x $10.00');
    }

    /**
     * Editing a standalone Draft changes its PO number, reprices an existing line and adds a new
     * one — read back BY COLUMN, never off the entity the create request built. A bystander Draft,
     * created alongside it, is asserted unchanged: the cheap half of #624 and the one most likely
     * to catch an edit that touched more than its own invoice.
     */
    public function editingADraftInvoiceChangesItsLinesAndFields(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $connection = $em->getConnection();

        $invoiceId = $this->rawDraftInvoice($I, 'PO-ORIGINAL');
        $bystanderId = $this->rawDraftInvoice($I, 'PO-BYSTANDER');

        $I->amOnPage('/admin/invoice/edit/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('po_number', 'PO-ORIGINAL');

        $I->sendFormPostRequest('/admin/invoice/edit/' . $invoiceId, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'draft',
            'po_number' => 'PO-CHANGED',
            'lines' => [
                ['product_id' => (string) $this->product->getId(), 'qty' => '2', 'price' => '12.00'],
                ['product_id' => '', 'name' => 'Second line, added on edit', 'qty' => '1', 'price' => '5.00'],
            ],
        ]);

        $invoiceRow = $connection->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$invoiceId]);
        $I->assertSame('PO-CHANGED', $invoiceRow['po_number'], 'po_number was updated by the edit save');
        $I->assertSame('29.00', $this->money($invoiceRow['subtotal']), '2 x $12.00 + 1 x $5.00 = $29.00');

        $lineRows = $connection->fetchAllAssociative(
            'SELECT * FROM invoice_line WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC',
            [$invoiceId],
        );
        $I->assertCount(2, $lineRows, 'the rebuild left exactly the two posted lines, not three');
        $I->assertSame('Second line, added on edit', $lineRows[1]['name']);

        // The bystander: same fixture shape, never touched by the other invoice's edit.
        $bystanderRow = $connection->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$bystanderId]);
        $I->assertSame('PO-BYSTANDER', $bystanderRow['po_number'], 'a different Draft invoice was not touched by this edit');
        $bystanderLines = $connection->fetchAllAssociative('SELECT * FROM invoice_line WHERE invoice_id = ?', [$bystanderId]);
        $I->assertCount(1, $bystanderLines, 'the bystander keeps its own single original line');
    }

    /**
     * The exact case that was silently broken before InvoiceController moved onto
     * SellSideLineReconciler: an untouched line's own id — and anything else attributed to that
     * id — used to go stale on every single edit, because the old save deleted and rebuilt the
     * whole line set regardless of which rows actually changed. Posting the line's real id now
     * upserts it in place; a CreditMemoLine pointing at it stays valid across the same edit,
     * which used to SET NULL it via InvoiceLine's own cascade-on-delete every single time.
     */
    public function editingAnUntouchedLineKeepsItsIdAndCreditMemoAttribution(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $connection = $em->getConnection();

        $invoiceId = $this->rawDraftInvoice($I, 'PO-KEEP-ID');
        $originalLineId = (int) $connection->fetchOne('SELECT id FROM invoice_line WHERE invoice_id = ?', [$invoiceId]);
        $I->assertGreaterThan(0, $originalLineId);

        $invoice = $em->find(Invoice::class, $invoiceId);
        $invoiceLine = $invoice->getLines()->first();

        $creditMemo = (new CreditMemo())->setCompany($invoice->getCompany())->setDocumentNumber('CM-KEEP-ID')->setInvoice($invoice);
        $em->persist($creditMemo);
        $creditMemoLine = (new CreditMemoLine())->setCreditMemo($creditMemo)->setInvoiceLine($invoiceLine)->setName('Credited back')->setQuantity('1.00');
        $em->persist($creditMemoLine);
        $em->flush();
        $creditMemoLineId = $creditMemoLine->getId();

        // Same line id posted back with only the po_number changed elsewhere on the document —
        // the ordinary "I edited something else on this invoice" save.
        $I->sendFormPostRequest('/admin/invoice/edit/' . $invoiceId, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'draft',
            'po_number' => 'PO-KEEP-ID-EDITED',
            'lines' => [
                ['id' => (string) $originalLineId, 'product_id' => (string) $this->product->getId(), 'qty' => '1', 'price' => '10.00'],
            ],
        ]);

        $lineRows = $connection->fetchAllAssociative('SELECT id FROM invoice_line WHERE invoice_id = ?', [$invoiceId]);
        $I->assertCount(1, $lineRows, 'still exactly one line');
        $I->assertSame($originalLineId, (int) $lineRows[0]['id'], 'the untouched line kept its own id rather than being deleted and re-minted');

        $creditMemoLineRow = $connection->fetchAssociative('SELECT invoice_line_id FROM credit_memo_line WHERE id = ?', [$creditMemoLineId]);
        $I->assertSame($originalLineId, (int) $creditMemoLineRow['invoice_line_id'], 'the credit memo line still points at the same invoice line after the edit');
    }

    /**
     * The edit screen's totals footer shows this invoice's REAL stored figures
     * (#full-parity, 2026-09-13) — not the create screen's "Computed on save" placeholder, which
     * made no sense once there is an actual invoice with an actual subtotal to show.
     */
    public function editingADraftInvoiceShowsItsRealTotalsNotAPlaceholder(FunctionalTester $I): void
    {
        $invoiceId = $this->rawDraftInvoice($I, 'PO-TOTALS');

        $em = $I->grabService(EntityManagerInterface::class);
        $invoiceRow = $em->getConnection()->fetchAssociative('SELECT subtotal, total FROM invoice WHERE id = ?', [$invoiceId]);

        $I->amOnPage('/admin/invoice/edit/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('Computed on save');
        $I->see('$' . $this->money($invoiceRow['subtotal']));
        $I->see('$' . $this->money($invoiceRow['total']));
    }

    /**
     * Once issued, an invoice refuses this screen outright. Asserted both ways: the redirect away
     * from edit, and — the assertion that would catch a refusal that redirects AFTER writing
     * something — that the line row is byte-for-byte what it was before the attempt.
     */
    /**
     * Widened from Draft-only (#full-parity, 2026-09-12): a Pending invoice is still editable, and
     * the change actually lands — read back from invoice_line, not the redirect alone.
     */
    public function aPendingInvoiceAcceptsEdit(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $connection = $em->getConnection();

        $invoiceId = $this->rawDraftInvoice($I, 'PO-PENDING', issue: true);
        $invoiceRow = $connection->fetchAssociative('SELECT status FROM invoice WHERE id = ?', [$invoiceId]);
        $I->assertSame('Pending', $invoiceRow['status'], 'the fixture actually issued');

        $I->amOnPage('/admin/invoice/edit/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest('/admin/invoice/edit/' . $invoiceId, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'draft',
            'po_number' => 'PO-PENDING-CHANGED',
            'lines' => [
                ['product_id' => (string) $this->product->getId(), 'qty' => '3', 'price' => '10.00'],
            ],
        ]);

        $after = $connection->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$invoiceId]);
        $I->assertSame('PO-PENDING-CHANGED', $after['po_number']);
        $I->assertSame('Pending', $after['status'], 'a plain save does not demote an already-issued invoice');
    }

    /**
     * Completed and Cancelled are the two genuine exceptions — see Invoice::isEditable()'s own
     * docblock. Cancelled is used here because it is reachable from Pending with no other
     * preconditions (Completed needs a fulfilment step this fixture does not model).
     */
    public function aCancelledInvoiceRefusesEdit(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $connection = $em->getConnection();

        $invoiceId = $this->rawDraftInvoice($I, 'PO-CANCELLED', issue: true);
        $invoice = $em->find(Invoice::class, $invoiceId);
        $invoice->setStatus('Cancelled', DocumentActor::system(), 'Cancelled for this test.');
        $em->flush();

        $before = $connection->fetchAssociative('SELECT * FROM invoice_line WHERE invoice_id = ?', [$invoiceId]);

        $I->amOnPage('/admin/invoice/edit/' . $invoiceId);
        $I->dontSeeInCurrentUrl('/admin/invoice/edit/');
        $I->seeInCurrentUrl('/admin/invoice/detail/' . $invoiceId);

        $after = $connection->fetchAssociative('SELECT * FROM invoice_line WHERE invoice_id = ?', [$invoiceId]);
        $I->assertSame($before, $after, 'the refused edit attempt left the cancelled invoice line exactly as it was');
    }

    /**
     * An approved order with one line, ready to invoice — the fixture `orderLinkedInvoiceStill...`
     * needs. Mirrors AdminStandaloneInvoiceRevivalCest::orderRootedInvoice()'s own approval step.
     */
    private function approvedOrder(FunctionalTester $I): SalesOrder
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $company = $em->find(Company::class, (int) $this->company->getId());
        $product = $em->find(ProductCore::class, (int) $this->product->getId());

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('CIC-ORD-' . uniqid())
            ->setSubtotal('100.00')
            ->setTax('0.00')
            ->setTotal('100.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setTaxCode('G')
                ->setQuantity('10')
                ->setPrice('10.00')
                ->setSubtotal('100.00'),
        );
        foreach ($company->getAddresses() as $address) {
            if ($address instanceof CompanyAddress && $address->isDefaultShipping()) {
                $order->setShippingAddressFrom($address);
                $order->setBillingAddressFrom($address);
            }
        }
        $I->haveInRepository($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $em->flush();

        return $order;
    }

    /**
     * Zero lines is a valid invoice (#full-parity, 2026-09-12) — but a save that omits the `lines`
     * key ENTIRELY (never posted, not merely empty) must not wipe an existing Draft's real line.
     * The real screen always posts at least one row, so an absent key means a malformed/incomplete
     * request, not an admin choosing zero lines.
     */
    public function editingWithNoLinesKeyAtAllRefusesRatherThanWipingTheInvoice(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $connection = $em->getConnection();

        $invoiceId = $this->rawDraftInvoice($I, 'PO-NOWIPE');

        $I->amOnPage('/admin/invoice/edit/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest('/admin/invoice/edit/' . $invoiceId, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'draft',
            'po_number' => 'PO-NOWIPE',
        ]);

        $lineRows = $connection->fetchAllAssociative('SELECT * FROM invoice_line WHERE invoice_id = ?', [$invoiceId]);
        $I->assertCount(1, $lineRows, 'the original line survived a request that never posted lines at all');
    }

    /**
     * A standalone invoice raised through the real create screen — one line, one product, a named
     * PO number so each fixture in this file is distinguishable by more than its id.
     */
    private function rawDraftInvoice(FunctionalTester $I, string $poNumber, bool $issue = false): int
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $before = (int) $em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM invoice');

        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->company->getId(),
            'save_mode' => $issue ? 'issue' : 'draft',
            'po_number' => $poNumber,
            'lines' => [['product_id' => (string) $this->product->getId(), 'qty' => '1', 'price' => '10.00']],
        ]);

        $id = (int) $em->getConnection()->fetchOne('SELECT id FROM invoice WHERE id > ? ORDER BY id DESC LIMIT 1', [$before]);
        $I->assertGreaterThan($before, $id, 'the fixture invoice was actually raised');

        return $id;
    }

    /** Money as a two-place string, so '29' and '29.00' compare equal. */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
