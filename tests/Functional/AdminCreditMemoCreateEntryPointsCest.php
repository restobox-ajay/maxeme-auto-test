<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Can a credit note be RAISED — from the navigation, not only from a document that already knows
 * the customer?
 *
 * Until this change the answer was no. `admin_credit_memo_new` needed `?invoice=`, `?sales_return=`
 * or `?company=` and bounced back to the grid without one, so the only doors were an invoice's
 * Credit Note tab, a received RMA's "Raise credit note", and the grid's New credit note button —
 * which the grid template wrapped in `{% if company %}`, i.e. rendered nothing at all on
 * `/admin/credit-memo/index`, which is where the sidebar lands. There was no sidebar entry either.
 * A document with four screens and no way to start one.
 *
 * Every test here drives the REAL screens: a plain form POST (no X-Requested-With — this app's
 * baseline is that it works with JavaScript off), with the CSRF token scraped off the rendered
 * page, and POSTing to the ACTION THE PAGE ITSELF PRINTED rather than to a URL the test built.
 * That last part is the whole difference between this file and the #586/#596 Cests beside it: they
 * post to a hand-assembled URL, which is why nobody noticed the draft editor's form was dropping
 * `sales_return` out of its own action — see theSalesReturnButtonStillFilesTheNoteAgainstThatReturn.
 *
 * #624: the assertions are on `credit_memo.<column>` re-read out of the database after the request,
 * not on an entity fetched beforehand, and each one also asserts THE ROW THAT SHOULD NOT HAVE
 * CHANGED — a second customer's note, snapshotted before and compared column by column after.
 * #627: no figure is asserted with see(); money is compared in integer cents read from the row.
 */
final class AdminCreditMemoCreateEntryPointsCest
{
    /*
     * ------------------------------------------------------------------------------------------
     * The two doors that were shut
     * ------------------------------------------------------------------------------------------
     */

    /**
     * The sidebar has a Create entry for the credit note, beside the list of them — as an icon
     * affordance now (queue item 35's later revision), not a full sidebar row of its own, so it
     * announces itself via aria-label/title rather than visible text.
     */
    public function theSidebarOffersAWayToCreateACreditNote(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();

        // The positive control for the assertion below it: the list entry has always been there, so
        // a selector that finds neither is a broken selector rather than a missing menu entry.
        $I->seeElement('#primary-navigation a[href="/admin/credit-memo/index"]');
        $I->seeElement('#primary-navigation a.nav-affordance[href="/admin/credit-memo/new"][aria-label="Create Credit Note"]');
    }

    /**
     * The list row is what lights up on BOTH its own page and the create page now — there is no
     * separate Create row left to be the "other one" that lights up instead.
     *
     * Worth its own test because the highlight is data, not behaviour: `admin_credit_memo_new` used
     * to be off orders.credit_notes' own route list, back when Create Credit Note was still a
     * second, sibling ROW and a route in both lists would have lit them both at once. Now that
     * Create renders only as an icon with no is-current styling of its own, leaving the route off
     * would highlight nothing at all while creating one — worse than the collision it used to avoid.
     */
    public function theListEntryHighlightsOnBothItsOwnPageAndTheCreatePage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/credit-memo/index');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#primary-navigation a.is-current[href="/admin/credit-memo/index"]');

        $I->amOnPage('/admin/credit-memo/new');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#primary-navigation a.is-current[href="/admin/credit-memo/index"]');
        // The affordance icon itself never carries is-current — it is a bare link, not a row.
        $I->dontSeeElement('#primary-navigation a.is-current[href="/admin/credit-memo/new"]');
    }

    /**
     * The grid's own button, on the BARE grid — the one the sidebar's List Credit Notes reaches,
     * with no customer in scope.
     */
    public function theBareGridOffersTheCreateButton(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/credit-memo/index');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('.panel-actions a[href="/admin/credit-memo/new"]');
        $I->see('New credit note');
    }

    /**
     * And the company-scoped grid still passes the company through, so the customer does not have
     * to be chosen twice. The positive control for the test above: both grids offer the button,
     * and they differ only in what the link carries.
     */
    public function theCompanyScopedGridPassesTheCompanyToTheCreatePage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->company($I, 'Scoped Grid Co');

        $I->amOnPage('/admin/credit-memo/index?CreditMemoSearch[company_id]=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('.panel-actions a[href="/admin/credit-memo/new?company=' . $company->getId() . '"]');
        $I->dontSeeElement('.panel-actions a[href="/admin/credit-memo/new"]');
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Conducted: raising a note with nobody in scope (#624)
     * ------------------------------------------------------------------------------------------
     */

    /**
     * THE path that was broken: start on the bare grid, press the button, choose a customer, save.
     *
     * Conducted a screen at a time and never by a URL this test assembled — the button's href is
     * read off the grid and followed, the picker's form action is read off the picker and posted
     * to, the draft editor's form action is read off the editor and posted to. A test that built
     * those URLs itself would pass against a page offering no link at all.
     */
    public function aNoteIsRaisedFromTheBareGridByChoosingACustomer(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $buyer = $this->company($I, 'Chosen Customer Co');
        $bystander = $this->company($I, 'Untouched Customer Co');
        $bystanderNote = $this->existingNoteFor($I, $bystander, '31.50');

        $before = $this->noteRow($I, $bystanderNote);
        $countBefore = $this->noteCount($I);

        // 1. The bare grid, and the button it now prints.
        $I->amOnPage('/admin/credit-memo/index');
        $I->seeResponseCodeIsSuccessful();
        $createHref = $I->grabAttributeFrom('.panel-actions a.button.primary', 'href');
        $I->assertSame('/admin/credit-memo/new', $createHref, 'the bare grid links to the create page with no customer attached');

        // 2. Follow it. With nobody in scope the create page asks who the note is for, instead of
        //    bouncing back to the grid with "open one from an invoice, or from a company".
        $I->amOnPage($createHref);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#credit-memo-company-form select[name="company_id"]');
        $I->seeElement('#credit-memo-company-form option[value="' . $buyer->getId() . '"]');
        $I->dontSeeElement('#credit-memo-form');

        // 3. Choose the customer. A plain form POST to the action the page printed — no XHR header,
        //    because a browser with JavaScript off does not send one.
        $pickerAction = $I->grabAttributeFrom('#credit-memo-company-form', 'action');
        $I->sendFormPostRequest($pickerAction, [
            '_token' => $I->grabAttributeFrom('#credit-memo-company-form input[name="_token"]', 'value'),
            'company_id' => (string) $buyer->getId(),
        ]);

        // The redirect is followed, and we are in the draft editor for the customer that was picked.
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#credit-memo-form');
        $I->see($buyer->getName());
        $I->dontSee('Untouched Customer Co');

        // 4. Save the draft, again to the action the editor printed.
        $editorAction = $I->grabAttributeFrom('#credit-memo-form', 'action');
        $I->assertSame(
            '/admin/credit-memo/new?company=' . $buyer->getId(),
            $editorAction,
            'the editor posts back in the scope it was opened in, so the save knows the customer the picker chose',
        );
        $I->sendFormPostRequest($editorAction, [
            '_token' => $I->grabAttributeFrom('#credit-memo-form input[name="_token"]', 'value'),
            'document_date' => '2026-09-11',
            'credit_memo_type_id' => '0',
            'tax' => '0.00',
            'reason' => 'goodwill, no invoice behind it',
            'lines' => [[
                'name' => 'Goodwill credit',
                'sku' => '',
                'location' => '',
                'quantity' => '2.00',
                'price' => '30.00',
            ]],
        ]);

        // 5. The row, read back out of the database rather than off any entity this test held.
        $I->assertSame($countBefore + 1, $this->noteCount($I), 'exactly one note was filed');

        $stored = $this->latestNoteRowFor($I, $buyer);
        $I->assertNotNull($stored, 'the save filed a credit note against the customer the picker chose');
        $I->assertSame($buyer->getId(), (int) $stored['company_id'], 'credit_memo.company_id is the company that was picked');
        $I->assertNull($stored['invoice_id'], 'and no invoice, because none was named — a standalone credit is a first-class case');
        $I->assertNull($stored['sales_return_id'], 'and no return either');
        $I->assertSame(6000, $this->cents($stored['subtotal']), 'two at thirty is a sixty-dollar credit, built from the posted line');
        $I->assertSame(6000, $this->cents($stored['total']));
        $I->assertSame('Draft', (string) $stored['status'], 'and it starts as a draft, holding and crediting nothing');
        $I->assertNotSame('', (string) $stored['document_number'], 'with a number of its own');

        // 6. THE ROW THAT SHOULD NOT HAVE CHANGED.
        $I->assertSame($before, $this->noteRow($I, $bystanderNote), 'the other customer\'s note is untouched, column for column');
        $I->assertSame(1, $this->noteCountFor($I, $bystander), 'and they still hold exactly the one note they started with — nothing was filed against them');
    }

    /**
     * A save that names no customer is refused with the question still on screen, and files nothing.
     *
     * The negative half of the test above, with its own positive control: the same POST body with a
     * company_id on it does create a row (that is what aNoteIsRaisedFromTheBareGrid... proves), so
     * "nothing was created" here is about the missing customer and not about a POST that never
     * reached the controller.
     */
    public function aSaveWithNoCustomerChosenIsRefusedAndAsksAgain(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $countBefore = $this->noteCount($I);

        $I->amOnPage('/admin/credit-memo/new');
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest('/admin/credit-memo/new', [
            '_token' => $I->grabAttributeFrom('#credit-memo-company-form input[name="_token"]', 'value'),
            'document_date' => '2026-09-11',
            'credit_memo_type_id' => '0',
            'tax' => '0.00',
            'lines' => [['name' => 'Credit', 'quantity' => '1.00', 'price' => '10.00']],
        ]);

        $I->seeResponseCodeIs(422);
        $I->see('Choose a customer');
        // Still the picker, so nothing that was typed leads anywhere until a customer is named.
        $I->seeElement('#credit-memo-company-form select[name="company_id"]');
        $I->assertSame($countBefore, $this->noteCount($I), 'and no note was filed for nobody');
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Conducted: the entry points that already existed, proved rather than assumed
     * ------------------------------------------------------------------------------------------
     */

    /**
     * From an invoice: the Credit Note tab, followed and posted through.
     *
     * The tab's href is read off the invoice page, so this fails if the tab stops pointing at the
     * create page as well as if the create page stops accepting an invoice.
     */
    public function theInvoiceTabStillFilesTheNoteAgainstThatInvoice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$buyer, $invoice, $line] = $this->invoicedOrder($I);
        $invoiceId = (int) $invoice->getId();
        $bystander = $this->company($I, 'Untouched Customer Co');
        $bystanderNote = $this->existingNoteFor($I, $bystander, '12.25');
        $before = $this->noteRow($I, $bystanderNote);

        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $tabHref = $I->grabAttributeFrom('a[href^="/admin/credit-memo/new?invoice="]', 'href');
        $I->assertSame('/admin/credit-memo/new?invoice=' . $invoiceId, $tabHref);

        $I->amOnPage($tabHref);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#credit-memo-form');

        $action = $I->grabAttributeFrom('#credit-memo-form', 'action');
        $I->assertSame($tabHref, $action, 'the editor posts back in the scope the tab opened it in');
        $I->sendFormPostRequest($action, [
            '_token' => $I->grabAttributeFrom('#credit-memo-form input[name="_token"]', 'value'),
            'document_date' => '2026-09-11',
            'credit_memo_type_id' => '0',
            'tax' => '0.00',
            'lines' => [[
                'invoice_line_id' => (string) $line['id'],
                'name' => $line['name'],
                'sku' => $line['sku'],
                'quantity' => '1.00',
                'price' => '25.00',
            ]],
        ]);

        $stored = $this->latestNoteRowFor($I, $buyer);
        $I->assertNotNull($stored);
        $I->assertSame($invoiceId, (int) $stored['invoice_id'], 'credit_memo.invoice_id records the invoice it was raised from');
        $I->assertSame($buyer->getId(), (int) $stored['company_id'], 'and the customer comes off that invoice, not off a form field');
        $I->assertSame(2500, $this->cents($stored['total']));

        $I->assertSame($before, $this->noteRow($I, $bystanderNote), 'the other customer\'s note is untouched, column for column');
    }

    /**
     * From a received RMA: "Raise credit note", followed and posted through.
     *
     * This is the one that found something. The link carries `?invoice=&sales_return=`, but the
     * draft editor's own form action rebuilt the query from the invoice alone — so a note raised
     * through the button a human actually presses was stored with `sales_return_id` NULL, and with
     * it went the #596 rule that such a note may not ALSO restock (the return's receipt already put
     * the goods back; doing it twice enters the same units into the ledger twice). Invisible to the
     * #596 Cests because they post to a URL they build themselves rather than to the form.
     *
     * MEASURED, not asserted from memory — this test errored in its fixture for its whole life and
     * had never reached the assertion, so the paragraph above was a claim nobody had seen come
     * true. With the one-line `newScope` fix backed out of credit_memo/edit.html.twig and nothing
     * else changed:
     *
     *   - opened at /admin/credit-memo/new?invoice=1&sales_return=1
     *   - the form printed action="/admin/credit-memo/new?invoice=1"  ← sales_return dropped
     *   - posting to that action stored {company_id: 1, invoice_id: 1, sales_return_id: null}
     *
     * Restoring the fix turns both green. The `restock` half follows from the row rather than from
     * a separate assertion, and follows structurally: CreditMemoController::create() attaches the
     * return BEFORE applyPostedFields() precisely so setRestock()'s guard can refuse, and
     * applyPostedFields() reads `restock` off the POST unconditionally — with no return on the
     * object the guard has nothing to refuse against.
     */
    public function theSalesReturnButtonStillFilesTheNoteAgainstThatReturn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$buyer, $invoice, $line] = $this->invoicedOrder($I);
        $invoiceId = (int) $invoice->getId();
        $returnId = $this->receivedReturn($I, $invoiceId, $line);
        $bystander = $this->company($I, 'Untouched Customer Co');
        $bystanderNote = $this->existingNoteFor($I, $bystander, '7.00');
        $before = $this->noteRow($I, $bystanderNote);

        $I->amOnPage('/admin/sales-return/' . $returnId);
        $I->seeResponseCodeIsSuccessful();
        $raiseHref = $I->grabAttributeFrom('a[href^="/admin/credit-memo/new?invoice="]', 'href');
        $I->assertStringContainsString('sales_return=' . $returnId, $raiseHref, 'the RMA offers a link that names the return');

        $I->amOnPage($raiseHref);
        $I->seeResponseCodeIsSuccessful();
        // #627: the positive control for the absence assertion under it. The editor IS on screen
        // and IS rendering its inputs, so "no restock tick box" is a statement about that control
        // and not about a selector that matches nothing on a page that failed to load.
        $I->seeElement('#credit-memo-form input[name="document_date"]');
        // #596's courtesy: a note crediting an RMA is not offered the restock tick box at all.
        $I->dontSeeElement('#credit-memo-form input[name="restock"]');

        $action = $I->grabAttributeFrom('#credit-memo-form', 'action');
        $I->assertStringContainsString(
            'sales_return=' . $returnId,
            $action,
            'and the form posts back naming the return, or the note it creates forgets which RMA it credits',
        );

        $I->sendFormPostRequest($action, [
            '_token' => $I->grabAttributeFrom('#credit-memo-form input[name="_token"]', 'value'),
            'document_date' => '2026-09-11',
            'credit_memo_type_id' => '0',
            'tax' => '0.00',
            'lines' => [[
                'invoice_line_id' => (string) $line['id'],
                'name' => $line['name'],
                'sku' => $line['sku'],
                'quantity' => '1.00',
                'price' => '25.00',
            ]],
        ]);

        $stored = $this->latestNoteRowFor($I, $buyer);
        $I->assertNotNull($stored);
        $I->assertSame($returnId, (int) $stored['sales_return_id'], 'credit_memo.sales_return_id names the RMA the note credits');
        $I->assertSame($invoiceId, (int) $stored['invoice_id'], 'and the invoice behind it');
        $I->assertSame($buyer->getId(), (int) $stored['company_id']);
        $I->assertSame(0, (int) $stored['restock'], 'a note carrying a return never restocks: the receipt already did');

        $I->assertSame($before, $this->noteRow($I, $bystanderNote), 'the other customer\'s note is untouched, column for column');
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Reading the database
     * ------------------------------------------------------------------------------------------
     */

    /** @return array<string, mixed>|null */
    private function noteRow(FunctionalTester $I, CreditMemo $memo): ?array
    {
        $row = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT company_id, invoice_id, sales_return_id, document_number, subtotal, tax, total, status, restock '
            . 'FROM credit_memo WHERE id = ?',
            [$memo->getId()],
        );

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function latestNoteRowFor(FunctionalTester $I, Company $company): ?array
    {
        $row = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT company_id, invoice_id, sales_return_id, document_number, subtotal, tax, total, status, restock '
            . 'FROM credit_memo WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [$company->getId()],
        );

        return $row === false ? null : $row;
    }

    private function noteCount(FunctionalTester $I): int
    {
        return (int) $I->grabService(EntityManagerInterface::class)
            ->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM credit_memo');
    }

    private function noteCountFor(FunctionalTester $I, Company $company): int
    {
        return (int) $I->grabService(EntityManagerInterface::class)
            ->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM credit_memo WHERE company_id = ?', [$company->getId()]);
    }

    /**
     * Money as integer cents.
     *
     * #627: never a bare see() on a figure, and never a string compare on a DECIMAL either — SQLite
     * hands '60.00' back as '60', so an assertSame('60.00', ...) fails on a perfectly correct row
     * and an assertSame('60', ...) passes on a column that changed type under it.
     */
    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Fixtures — this file creates everything it asserts on
     * ------------------------------------------------------------------------------------------
     *
     * EVERY fixture below writes through $I->haveInRepository() / $I->flushToDatabase(), i.e.
     * through the Doctrine MODULE's entity manager. None of them writes through
     * grabService(EntityManagerInterface::class). That is not a style preference — there are two
     * entity managers in a functional test and mixing them breaks:
     *
     *   - The Doctrine module retrieves its EntityManager ONCE, in _beforeSuite, and keeps that
     *     instance for the whole run (Doctrine::retrieveEntityManager; _before only calls clear()).
     *   - grabService() reads the service out of whatever container the client currently holds, and
     *     the Symfony module reboots the kernel around requests (rebootable_client: true), which
     *     rebuilds the EntityManager. Only the DBAL *connection* is persisted permanently, not the
     *     EntityManager on top of it.
     *
     * So after the first amOnPage()/sendFormPostRequest() the two are different objects sharing one
     * connection, and an entity created through one is UNKNOWN to the other. Persisting a CreditMemo
     * through the grabbed manager while its Company was created through the module's produced
     *
     *     ORMInvalidArgumentException: A new entity was found through the relationship
     *     'App\Entity\CreditMemo#company'
     *
     * which is what took this Cest out of an integration batch. The bug was ORDERING — the same
     * helper worked when it happened to be called before the first request and blew up when it was
     * called after one — so "call the fixtures earlier" fixes one call site and leaves the trap
     * armed for the next. Writing through one manager removes the trap instead of dodging it.
     *
     * Reads are a different matter and stay as they are: the helpers above go through
     * grabService(...)->getConnection(), and the connection IS shared, so raw SQL sees every write
     * whichever manager made it. That is also why they read the DATABASE and not an entity (#624).
     */

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-cn-entry-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function company(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('CNE-' . uniqid())
            ->setPrimaryEmail('buyer-' . uniqid() . '@creditnote.example');
        $I->haveInRepository($company);

        return $company;
    }

    /** A note that already exists for somebody else — the row every test here asserts did NOT move. */
    private function existingNoteFor(FunctionalTester $I, Company $company, string $total): CreditMemo
    {
        $memo = (new CreditMemo())
            ->setCompany($company)
            ->setDocumentNumber('CNE-EXISTING-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        // haveInRepository(), never a grabbed EntityManager — see the note above.
        $I->haveInRepository($memo);

        return $memo;
    }

    private function product(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('CNE-SKU-' . uniqid())
            ->setName('Creditable Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    /**
     * An approved order with one issued invoice for its whole value.
     *
     * The invoice line's facts come back as scalars alongside it, read here while the entity is
     * certainly managed: each request in these tests boots its own kernel, so an entity held across
     * one can come back detached and a lazy association read off it afterwards is a gamble.
     *
     * @return array{0: Company, 1: Invoice, 2: array{id: int, name: string, sku: string, productId: int}}
     */
    private function invoicedOrder(FunctionalTester $I): array
    {
        $company = $this->company($I, 'Invoiced Customer Co');
        $product = $this->product($I);
        $total = '100.00';

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('CNESO-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setQuantity('4.00')
                ->setPrice('25.00')
                ->setSubtotal($total),
        );
        $I->haveInRepository($order);

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('CNEINV-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $order->addInvoice($invoice);
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($order->getLines()->first())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setQuantity('4.00')
                ->setPrice('25.00')
                ->setSubtotal($total),
        );
        $I->haveInRepository($invoice);
        $invoice->issue(DocumentActor::system());
        $I->flushToDatabase();

        $line = $invoice->getLines()->first();

        return [$company, $invoice, [
            'id' => (int) $line->getId(),
            'name' => (string) $line->getName(),
            'sku' => (string) $line->getSku(),
            'productId' => (int) $product->getId(),
        ]];
    }

    /**
     * An RMA against that invoice, authorised and received — the state that offers "Raise credit
     * note". Built through the RMA's own screens, so the fixture is the workflow rather than a row.
     *
     * @param array{id: int, name: string, sku: string, productId: int} $line
     */
    private function receivedReturn(FunctionalTester $I, int $invoiceId, array $line): int
    {
        $warehouse = (new Warehouse())->setName('Credit Note Dock ' . uniqid())->setStatus('Active');
        $I->haveInRepository($warehouse);
        $warehouseId = (int) $warehouse->getId();

        $url = '/admin/sales-return/new?invoice=' . $invoiceId;
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest($url, [
            '_token' => $I->grabAttributeFrom('#sales-return-form input[name="_token"]', 'value'),
            'reason' => 'Arrived damaged.',
            'lines' => [[
                'product_id' => (string) $line['productId'],
                'invoice_line_id' => (string) $line['id'],
                'name' => $line['name'],
                'sku' => $line['sku'],
                'quantity' => '1.00',
                'reason' => 'cracked casing',
            ]],
        ]);

        $returnId = (int) $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT id FROM sales_return WHERE invoice_id = ? ORDER BY id DESC LIMIT 1',
            [$invoiceId],
        );
        $I->assertGreaterThan(0, $returnId, 'the RMA fixture was created through its own screen');

        foreach (['authorise', 'receive'] as $action) {
            $I->amOnPage('/admin/sales-return/' . $returnId);
            $I->seeResponseCodeIsSuccessful();
            $I->sendFormPostRequest('/admin/sales-return/' . $returnId . '/action/' . $action, [
                '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
                'warehouse_id' => (string) $warehouseId,
            ]);
        }

        $status = (string) $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT status FROM sales_return WHERE id = ?',
            [$returnId],
        );
        $I->assertSame('Received', $status, 'guard: the goods are back, which is the state that offers "Raise credit note"');

        return $returnId;
    }
}
