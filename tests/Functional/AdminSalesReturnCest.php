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
use App\Entity\SalesReturn;
use App\Entity\Warehouse;
use App\Enum\SalesReturnStatus;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The sales return's admin screens (#596), driven through real GETs and POSTs.
 *
 * What these cover that the entity and movement tests cannot: that the draft editor's POST body
 * actually builds the lines it claims to, that each transition is reachable from the screen that
 * offers it, and — the two cases most likely to be got wrong — that a REFUSED transition is
 * REPORTED rather than swallowed, and that the receive form's warehouse actually lands on the
 * document.
 *
 * Tokens are scraped from the rendered page and posted, so every request here goes through the app's
 * CSRF check rather than around it. Relative-path POSTs rather than submitForm(), for the reason
 * AdminCreditMemoCest documents: with a custom Host header the browser module resolves a crawled
 * form action as an absolute URL and trips its external-URL guard.
 */
final class AdminSalesReturnCest
{
    /**
     * The ordinary path, one screen at a time: raise, authorise, receive, close.
     *
     * Asserted as it goes rather than only at the end, because four of the five states look
     * interchangeable from a list screen and what separates them is which of them the goods have
     * physically moved in.
     */
    public function raisingAuthorisingReceivingAndClosingAReturn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$company, $invoice] = $this->invoicedOrder($I, '4.00');
        $warehouse = $this->warehouse($I);

        $return = $this->draftFromInvoice($I, $invoice, quantity: '2.00');

        $I->assertSame(SalesReturnStatus::Requested, $return->getStatus(), 'a new return is only a request — nobody has agreed to anything');
        $I->assertSame('2.0000', $return->totalUnits(), 'two units, built from the posted lines');
        $I->assertSame($invoice->getId(), $return->getInvoice()?->getId(), 'and it records which delivery they came off');
        $I->assertNull($return->getWarehouse(), 'nothing has arrived anywhere');

        $this->action($I, $return, 'authorise');
        $return = $this->reload($I, $return);
        $I->assertSame(SalesReturnStatus::Authorised, $return->getStatus(), 'authorising gives the customer a number to write on the box');
        $I->assertNotNull($return->getAuthorisedAt(), 'and stamps when we agreed');
        $I->assertNull($return->getReceivedAt(), 'agreeing is not receiving');

        $this->receive($I, $return, $warehouse);
        $return = $this->reload($I, $return);
        $I->assertSame(SalesReturnStatus::Received, $return->getStatus(), 'the parcel arrived');
        $I->assertNotNull($return->getReceivedAt(), 'stamped separately from the authorisation, which is the gap #596 exists to record');
        $I->assertSame($warehouse->getId(), $return->getWarehouse()?->getId(), 'and the receive form\'s warehouse landed on the document');

        $this->action($I, $return, 'close');
        $I->assertSame(SalesReturnStatus::Closed, $this->reload($I, $return)->getStatus(), 'closing needs no credit note and does not check for one');

        // And it is on the grid.
        $I->amOnPage('/admin/sales-return/index');
        $I->seeResponseCodeIsSuccessful();
        $I->see($return->getDocumentNumber());
        $I->see($company->getName());
    }

    /**
     * Receiving without naming a warehouse is REFUSED, and the refusal reaches the admin.
     *
     * The half that matters is the second: a controller that swallowed this would leave a document
     * saying Authorised with an operator who believes they received it.
     */
    public function receivingWithoutAWarehouseIsRefusedAndSaysSo(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I, '4.00');

        $return = $this->draftFromInvoice($I, $invoice, quantity: '1.00');
        $this->action($I, $return, 'authorise');

        // A POST with no warehouse_id at all — the shape a hand-built request or a broken form sends.
        $I->amOnPage('/admin/sales-return/' . $return->getId());
        $token = $I->grabAttributeFrom('#receive-form input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/sales-return/' . $return->getId() . '/action/receive', ['_token' => $token]);

        $I->see('Say which warehouse the goods arrived at');
        $I->assertSame(
            SalesReturnStatus::Authorised,
            $this->reload($I, $return)->getStatus(),
            'the document is untouched: a receipt that does not record where the stock landed is not a receipt',
        );
    }

    /**
     * Goods cannot be received against a return nobody authorised, and the message says what to do
     * instead.
     *
     * "A parcel arrived against no authorisation" is a case #596 requires to be expressible, and it
     * is — by raising the RMA after the fact and authorising it, which is what the refusal tells the
     * operator to do.
     */
    public function goodsCannotBeReceivedAgainstAnUnauthorisedReturn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I, '4.00');
        $warehouse = $this->warehouse($I);

        $return = $this->draftFromInvoice($I, $invoice, quantity: '1.00');
        $this->receive($I, $return, $warehouse);

        $I->see('only an authorised return can be received');
        $I->assertSame(SalesReturnStatus::Requested, $this->reload($I, $return)->getStatus());
    }

    /**
     * Declining a received return says, on the screen, that the units are still here.
     *
     * This is the whole of #596's answer to stranded stock: it does not dispose of them and it does
     * not invent a disposition, so the only remaining obligation is that a human can find them.
     */
    public function decliningAfterReceiptTellsTheAdminTheUnitsAreStillInTheBuilding(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I, '4.00');
        $warehouse = $this->warehouse($I);

        $return = $this->draftFromInvoice($I, $invoice, quantity: '3.00');
        $this->action($I, $return, 'authorise');
        $this->receive($I, $return, $warehouse);

        $I->amOnPage('/admin/sales-return/' . $return->getId());
        $token = $I->grabAttributeFrom('#decline-form input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/sales-return/' . $return->getId() . '/action/decline', [
            '_token' => $token,
            'reason' => 'Out of warranty.',
        ]);

        $I->see('still in `returned`');

        $return = $this->reload($I, $return);
        $I->assertSame(SalesReturnStatus::Declined, $return->getStatus());
        $I->assertSame('3.0000', $return->strandedUnits(), 'three units arrived and were refused');
        foreach ($return->getLines() as $line) {
            $I->assertNull($line->getDisposition(), 'and declining invented no disposition for them');
        }

        // The detail screen surfaces the figure rather than leaving it correct and invisible.
        $I->amOnPage('/admin/sales-return/' . $return->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('arrived and were refused');
    }

    /**
     * A credit note raised from a received return does not offer the restock tick box, and refuses
     * it when a POST supplies one anyway.
     *
     * Both halves matter and only the second is the rule: the hidden control is a courtesy so that
     * nobody is refused for something they were invited to do, while the refusal is what stops the
     * units entering the ledger twice.
     */
    public function aCreditNoteRaisedFromAReturnCannotRestock(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I, '4.00');
        $warehouse = $this->warehouse($I);

        $return = $this->draftFromInvoice($I, $invoice, quantity: '2.00');
        $this->action($I, $return, 'authorise');
        $this->receive($I, $return, $warehouse);

        $url = '/admin/credit-memo/new?invoice=' . $invoice->getId() . '&sales_return=' . $return->getId();
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('input[name="restock"]');
        $I->see('already did');

        // Posted by hand with restock=1 anyway. The entity refuses, the controller reports it, and
        // no note is created.
        $token = $I->grabAttributeFrom('#credit-memo-form input[name="_token"]', 'value');
        $invoiceLine = $invoice->getLines()->first();
        $I->sendAjaxPostRequest($url, [
            '_token' => $token,
            'document_date' => '2026-09-01',
            'credit_memo_type_id' => '0',
            'tax' => '0.00',
            'restock' => '1',
            'lines' => [[
                'invoice_line_id' => (string) $invoiceLine->getId(),
                'name' => $invoiceLine->getName(),
                'sku' => (string) $invoiceLine->getSku(),
                'location' => (string) $invoiceLine->getLocation(),
                'quantity' => '2.00',
                'price' => '25.00',
            ]],
        ]);

        $I->see('may not also restock');

        $notes = $I->grabService(EntityManagerInterface::class)
            ->getRepository(CreditMemo::class)
            ->findBy(['salesReturn' => $return->getId()]);

        $I->assertSame(
            [],
            $notes,
            'the note was not created at all: the refusal happens while the caller\'s intent still exists, not after '
            . 'a half-built document has been persisted',
        );
    }

    /** A credit note raised WITHOUT a return still offers the tick box — #586's behaviour survives. */
    public function aCreditNoteWithNoReturnStillOffersTheRestockTickBox(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I, '4.00');

        $I->amOnPage('/admin/credit-memo/new?invoice=' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="restock"]');
        $I->see('The goods came back');
    }

    /** An authorised return is not editable: the draft editor sends the admin back with a reason. */
    public function anAuthorisedReturnCannotBeEdited(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I, '4.00');

        $return = $this->draftFromInvoice($I, $invoice, quantity: '1.00');
        $this->action($I, $return, 'authorise');

        $I->amOnPage('/admin/sales-return/' . $return->getId() . '/edit');
        $I->see('Only a requested return can be edited');
    }

    /**
     * A return raised against a CUSTOMER with no invoice named at all.
     *
     * The ordinary case #596 lists first and #586 could not express: a customer telephones about a
     * broken item without knowing which of four invoices billed it, and the document has to open
     * anyway.
     */
    public function aReturnCanBeRaisedWithNoInvoiceAtAll(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->company($I);
        $product = $this->product($I);

        $I->amOnPage('/admin/sales-return/new?company=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('no invoice named');

        $token = $I->grabAttributeFrom('#sales-return-form input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/sales-return/new?company=' . $company->getId(), [
            '_token' => $token,
            'reason' => 'Turned up on the dock with no paperwork.',
            'lines' => [[
                'product_id' => (string) $product->getId(),
                'name' => $product->getName(),
                'sku' => $product->getSku(),
                'quantity' => '5.00',
                'reason' => 'unknown',
            ]],
        ]);

        $return = $I->grabService(EntityManagerInterface::class)
            ->getRepository(SalesReturn::class)
            ->findOneBy(['company' => $company->getId()], ['id' => 'DESC']);

        $I->assertInstanceOf(SalesReturn::class, $return, 'the editor POST created a return with no invoice behind it');
        $I->assertNull($return->getInvoice(), 'and left invoice_id null rather than guessing one');
        $I->assertSame('5.0000', $return->totalUnits());
    }

    /** Authorising a return with nothing on it is refused and reported. */
    public function anEmptyReturnCannotBeRaised(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->company($I);

        $I->amOnPage('/admin/sales-return/new?company=' . $company->getId());
        $token = $I->grabAttributeFrom('#sales-return-form input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/sales-return/new?company=' . $company->getId(), [
            '_token' => $token,
            'lines' => [['product_id' => '', 'quantity' => '0.00']],
        ]);

        $I->see('needs at least one line');
        $I->assertNull(
            $I->grabService(EntityManagerInterface::class)->getRepository(SalesReturn::class)->findOneBy(['company' => $company->getId()]),
            'and nothing was created — an authorisation that names nothing authorises everything',
        );
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Driving the screens
     * ------------------------------------------------------------------------------------------
     */

    private function draftFromInvoice(FunctionalTester $I, Invoice $invoice, string $quantity): SalesReturn
    {
        $url = '/admin/sales-return/new?invoice=' . $invoice->getId();
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('#sales-return-form input[name="_token"]', 'value');

        $invoiceLine = $invoice->getLines()->first();

        $I->sendAjaxPostRequest($url, [
            '_token' => $token,
            'reason' => 'Arrived damaged.',
            'lines' => [[
                'product_id' => (string) $invoiceLine->getProduct()?->getId(),
                'invoice_line_id' => (string) $invoiceLine->getId(),
                'name' => $invoiceLine->getName(),
                'sku' => (string) $invoiceLine->getSku(),
                'quantity' => $quantity,
                'reason' => 'cracked casing',
            ]],
        ]);

        $return = $I->grabService(EntityManagerInterface::class)
            ->getRepository(SalesReturn::class)
            ->findOneBy(['invoice' => $invoice->getId()], ['id' => 'DESC']);

        $I->assertInstanceOf(SalesReturn::class, $return, 'the editor POST created a sales return');

        return $return;
    }

    private function action(FunctionalTester $I, SalesReturn $return, string $action): void
    {
        $I->amOnPage('/admin/sales-return/' . $return->getId());
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/sales-return/' . $return->getId() . '/action/' . $action, ['_token' => $token]);
    }

    private function receive(FunctionalTester $I, SalesReturn $return, Warehouse $warehouse): void
    {
        $I->amOnPage('/admin/sales-return/' . $return->getId());
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/sales-return/' . $return->getId() . '/action/receive', [
            '_token' => $token,
            'warehouse_id' => (string) $warehouse->getId(),
        ]);
    }

    /**
     * The return as the DATABASE now holds it.
     *
     * find() rather than refresh(): the request under test wrote through its own entity manager and
     * this one has been cleared since, so the fixture object is detached and refresh() refuses it.
     */
    private function reload(FunctionalTester $I, SalesReturn $return): SalesReturn
    {
        return $I->grabService(EntityManagerInterface::class)
            ->getRepository(SalesReturn::class)
            ->find($return->getId());
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Fixtures
     * ------------------------------------------------------------------------------------------
     */

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-sales-return-test-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function company(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Sales Return Test Co')
            ->setCode('RMA-' . uniqid())
            ->setPrimaryEmail('buyer@salesreturn.example');
        $I->haveInRepository($company);

        return $company;
    }

    private function product(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('RMA-SKU-' . uniqid())
            ->setName('Returnable Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    /** Somewhere for the goods to arrive. The receive form offers active warehouses only. */
    private function warehouse(FunctionalTester $I): Warehouse
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $warehouse = (new Warehouse())->setName('Returns Dock ' . uniqid())->setStatus('Active');
        $em->persist($warehouse);
        $em->flush();

        return $warehouse;
    }

    /**
     * An approved order with one issued invoice, and a real product on the line so the return can
     * name one.
     *
     * @return array{0: Company, 1: Invoice}
     */
    private function invoicedOrder(FunctionalTester $I, string $quantity): array
    {
        $company = $this->company($I);
        $product = $this->product($I);
        $total = '100.00';

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('RMASO-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $order->addLine(
            (new SalesOrderLine())->setProduct($product)->setName($product->getName())->setQuantity($quantity)->setPrice('25.00')->setSubtotal($total),
        );
        $I->haveInRepository($order);

        $em = $I->grabService(EntityManagerInterface::class);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('RMAINV-' . uniqid())
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
                ->setQuantity($quantity)
                ->setPrice('25.00')
                ->setSubtotal($total),
        );
        $em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $em->flush();

        return [$company, $invoice];
    }
}
