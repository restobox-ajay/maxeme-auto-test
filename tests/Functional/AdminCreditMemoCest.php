<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The credit note's admin screens (#586), driven through real GETs and POSTs.
 *
 * What these cover that the entity tests cannot: that the draft editor's POST body actually builds
 * the lines it claims to, that issuing goes through the controller's named action, and — the two
 * cases most likely to be got wrong — that ONE NOTE ACROSS TWO INVOICES and ONE INVOICE CREDITED BY
 * TWO NOTES both behave when driven a screen at a time rather than by calling methods in order.
 *
 * Tokens are scraped from the rendered page and posted, so every request here goes through the
 * app's CSRF check rather than around it. Relative-path POSTs rather than submitForm(), for the
 * reason AdminInvoicePaymentsCest documents: with a custom Host header the browser module resolves
 * a crawled form action as an absolute URL and trips its external-URL guard.
 */
final class AdminCreditMemoCest
{
    public function raisingANoteFromAnInvoiceAndIssuingIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$company, $invoice] = $this->invoicedOrder($I, '4.00', '100.00');

        $memo = $this->draftFromInvoice($I, $invoice, quantity: '2.00', rate: '25.00');

        $I->assertSame('Draft', $memo->getStatus(), 'a new note starts as a draft, holding and crediting nothing');
        // Through getBalance() rather than getTotal(): a decimal column comes back off SQLite as
        // '50', not '50.00', and the balance is the figure every screen actually shows — formatted
        // in whole cents by the document itself.
        $I->assertSame('50.00', $memo->getBalance(), 'two units at twenty-five is a fifty-dollar credit, built from the posted lines');
        $I->assertSame($invoice->getId(), $memo->getInvoice()?->getId(), 'and it records which invoice it was raised from');

        $this->action($I, $memo, 'issue');

        $memo = $this->reload($I, $memo);
        $I->assertSame('Open', $memo->getStatus(), 'issuing opens it');
        $I->assertSame('50.00', $memo->getBalance(), 'with its whole total as balance');

        // And it is on the grid, with the balance the document computed rather than one the list
        // added up for itself.
        $I->amOnPage('/admin/credit-memo/index');
        $I->seeResponseCodeIsSuccessful();
        $I->see($memo->getDocumentNumber());
        $I->see($company->getName());
    }

    /**
     * ONE NOTE, TWO INVOICES — the half of the many-to-many a foreign key cannot express.
     */
    public function oneNoteAppliedAcrossTwoInvoices(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$company, $first] = $this->invoicedOrder($I, '4.00', '100.00');
        $second = $this->extraInvoiceFor($I, $company, '80.00');

        $memo = $this->draftFromInvoice($I, $first, quantity: '4.00', rate: '25.00');
        $this->action($I, $memo, 'issue');

        $this->apply($I, $memo, $first, '60.00');
        $memo = $this->reload($I, $memo);
        $I->assertSame('40.00', $memo->getBalance(), 'sixty of the hundred went to the invoice it was raised from');
        $I->assertSame('Open', $memo->getStatus(), 'and forty is still credit, so it stays Open');

        // The second invoice is not the one the note was raised from. That is the point: invoice_id
        // on the header is provenance, and the balance may land wherever the customer's credit is
        // useful.
        $this->apply($I, $memo, $second, '40.00');
        $memo = $this->reload($I, $memo);

        $I->assertSame('0.00', $memo->getBalance(), 'the rest went to a different invoice entirely');
        $I->assertSame('Closed', $memo->getStatus(), 'and a note with nothing left is Closed');
        $I->assertCount(2, $memo->getApplications(), 'two allocations, one per invoice');

        $numbers = [];
        foreach ($memo->getApplications() as $application) {
            $numbers[] = $application->getInvoice()->getDocumentNumber();
        }
        sort($numbers);
        $expected = [$first->getDocumentNumber(), $second->getDocumentNumber()];
        sort($expected);
        $I->assertSame($expected, $numbers, 'and they name the two different invoices, not the same one twice');
    }

    /**
     * #603: an invoice credited in full reads Paid with a $0.00 balance on its own real detail
     * screen — not just in the entity's own arithmetic. Before this, `Invoice::getBalance()` and
     * the derived payment status only looked at payment rows, so a fully-credited invoice still
     * read Not Paid, owing its whole total.
     */
    public function anInvoiceCreditedInFullReadsPaidOnItsOwnDetailScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I, '4.00', '100.00');

        $memo = $this->draftFromInvoice($I, $invoice, quantity: '4.00', rate: '25.00');
        $this->action($I, $memo, 'issue');
        $this->apply($I, $memo, $invoice, '100.00');

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('$0.00', '.detail-row');
        $I->see('Paid', '.badge.success');
    }

    /**
     * #770: the note's own remaining balance is not the only cap — an invoice can have less left to
     * receive than the note has left to give, and applying more than that must be refused, exactly
     * as over-applying against the note's own balance already is.
     */
    public function applyingMoreThanTheInvoiceBalanceIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$company, $first] = $this->invoicedOrder($I, '4.00', '100.00');
        $second = $this->extraInvoiceFor($I, $company, '30.00');

        $memo = $this->draftFromInvoice($I, $first, quantity: '4.00', rate: '25.00');
        $this->action($I, $memo, 'issue');
        $this->apply($I, $memo, $first, '20.00');

        $memo = $this->reload($I, $memo);
        $I->assertSame('80.00', $memo->getBalance(), 'twenty of the hundred spent, eighty left to give');

        $this->apply($I, $memo, $second, '50.00');

        $memo = $this->reload($I, $memo);
        $I->assertSame('80.00', $memo->getBalance(), 'the refused application left the note untouched');
        $I->assertCount(1, $memo->getApplications(), 'still only the one allocation, against the first invoice');

        $applied = (int) $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM credit_memo_application WHERE invoice_id = ?',
            [$second->getId()],
        );
        $I->assertSame(0, $applied, 'the second invoice took nothing');
    }

    /**
     * ONE INVOICE, TWO NOTES — the other half. Neither note knows about the other; the allocation
     * rows are what relate them.
     */
    public function oneInvoiceCreditedByTwoNotes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I, '4.00', '100.00');

        $firstNote = $this->draftFromInvoice($I, $invoice, quantity: '1.00', rate: '25.00');
        $this->action($I, $firstNote, 'issue');
        $this->apply($I, $firstNote, $invoice, '25.00');

        $secondNote = $this->draftFromInvoice($I, $invoice, quantity: '1.00', rate: '15.00');
        $this->action($I, $secondNote, 'issue');
        $this->apply($I, $secondNote, $invoice, '15.00');

        $firstNote = $this->reload($I, $firstNote);
        $secondNote = $this->reload($I, $secondNote);

        $I->assertSame('Closed', $firstNote->getStatus(), 'the first note spent everything it had');
        $I->assertSame('Closed', $secondNote->getStatus(), 'and so did the second');

        $applied = (int) $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM credit_memo_application WHERE invoice_id = ?',
            [$invoice->getId()],
        );
        $I->assertSame(2, $applied, 'one invoice carries two allocations, from two different notes');
    }

    /** A refund empties the balance the other way, and the status follows just the same. */
    public function loggingARefundClosesTheNote(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I, '4.00', '100.00');

        $memo = $this->draftFromInvoice($I, $invoice, quantity: '2.00', rate: '25.00');
        $this->action($I, $memo, 'issue');

        $I->amOnPage('/admin/credit-memo/' . $memo->getId());
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('#refund-form input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/credit-memo/' . $memo->getId() . '/refund', [
            '_token' => $token,
            'refunded_at' => '2026-08-23',
            'method' => 'Cheque',
            'amount' => '50.00',
            'comment' => 'cheque posted to the buyer',
        ]);

        $memo = $this->reload($I, $memo);

        $I->assertSame('0.00', $memo->getBalance(), 'the whole note was refunded');
        $I->assertSame('50.00', $memo->getAmountRefunded(), 'and the refund row carries the figure');
        $I->assertSame('Closed', $memo->getStatus(), 'Closed is Closed however the balance left the note');
    }

    /**
     * A note with an allocation against it refuses to void — the same rule an invoice with payments
     * against it follows, and the refusal is REPORTED rather than swallowed.
     */
    public function aNoteWithCreditAppliedRefusesToVoid(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I, '4.00', '100.00');

        $memo = $this->draftFromInvoice($I, $invoice, quantity: '2.00', rate: '25.00');
        $this->action($I, $memo, 'issue');
        $this->apply($I, $memo, $invoice, '20.00');

        $this->action($I, $memo, 'void');

        $I->see('cannot be voided');
        $I->assertNotSame(
            'Void',
            $this->reload($I, $memo)->getStatus(),
            'a note with money against it must survive a Void',
        );
    }

    /** An untouched note voids cleanly and then holds nothing at all. */
    public function anUnspentNoteVoidsCleanly(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I, '4.00', '100.00');

        $memo = $this->draftFromInvoice($I, $invoice, quantity: '2.00', rate: '25.00');
        $this->action($I, $memo, 'issue');
        $this->action($I, $memo, 'void');

        $memo = $this->reload($I, $memo);
        $I->assertSame('Void', $memo->getStatus(), 'nothing had been spent, so it voids');
        $I->assertSame('0.00', $memo->getBalance(), 'and a voided note holds nothing whatever its total says');
        $I->assertFalse($memo->countsTowardCreditedQuantity(), 'nor does it credit any quantity back');
    }

    /** An issued note is not editable: the draft editor sends the admin back with a reason. */
    public function anIssuedNoteCannotBeEdited(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I, '4.00', '100.00');

        $memo = $this->draftFromInvoice($I, $invoice, quantity: '2.00', rate: '25.00');
        $this->action($I, $memo, 'issue');

        $I->amOnPage('/admin/credit-memo/' . $memo->getId() . '/edit');
        $I->see('Only a draft can be edited');
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Driving the screens
     * ------------------------------------------------------------------------------------------
     */

    /** Creates a draft through the real editor: open it against an invoice, fill one line, POST. */
    private function draftFromInvoice(FunctionalTester $I, Invoice $invoice, string $quantity, string $rate): CreditMemo
    {
        $I->amOnPage('/admin/credit-memo/new?invoice=' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('#credit-memo-form input[name="_token"]', 'value');

        $invoiceLine = $invoice->getLines()->first();

        $I->sendAjaxPostRequest('/admin/credit-memo/new?invoice=' . $invoice->getId(), [
            '_token' => $token,
            'document_date' => '2026-08-23',
            'credit_memo_type_id' => '0',
            'tax' => '0.00',
            'reason' => 'damaged in transit',
            'lines' => [
                [
                    'invoice_line_id' => (string) $invoiceLine->getId(),
                    'name' => $invoiceLine->getName(),
                    'sku' => (string) $invoiceLine->getSku(),
                    'location' => (string) $invoiceLine->getLocation(),
                    'quantity' => $quantity,
                    'price' => $rate,
                ],
            ],
        ]);

        $memo = $I->grabService(EntityManagerInterface::class)
            ->getRepository(CreditMemo::class)
            ->findOneBy(['invoice' => $invoice->getId()], ['id' => 'DESC']);

        $I->assertInstanceOf(CreditMemo::class, $memo, 'the editor POST created a credit note');

        return $memo;
    }

    private function action(FunctionalTester $I, CreditMemo $memo, string $action): void
    {
        $I->amOnPage('/admin/credit-memo/' . $memo->getId());
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/credit-memo/' . $memo->getId() . '/action/' . $action, ['_token' => $token]);
    }

    private function apply(FunctionalTester $I, CreditMemo $memo, Invoice $invoice, string $amount): void
    {
        $I->amOnPage('/admin/credit-memo/' . $memo->getId());
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('#apply-form input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/credit-memo/' . $memo->getId() . '/apply', [
            '_token' => $token,
            'invoice_id' => (string) $invoice->getId(),
            'applied_at' => '2026-08-23',
            'amount' => $amount,
        ]);
    }

    /**
     * The note as the DATABASE now holds it.
     *
     * find() rather than refresh(): the request under test wrote through its own entity manager and
     * this one has been cleared since, so the fixture object is detached and refresh() refuses it.
     */
    private function reload(FunctionalTester $I, CreditMemo $memo): CreditMemo
    {
        return $I->grabService(EntityManagerInterface::class)
            ->getRepository(CreditMemo::class)
            ->find($memo->getId());
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Fixtures
     * ------------------------------------------------------------------------------------------
     */

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-credit-memo-test-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * An approved order with one issued invoice for its whole value.
     *
     * @return array{0: Company, 1: Invoice}
     */
    private function invoicedOrder(FunctionalTester $I, string $quantity, string $total): array
    {
        $company = (new Company())
            ->setName('Credit Note Test Co')
            ->setCode('CN-' . uniqid())
            ->setPrimaryEmail('buyer@creditnote.example');
        $I->haveInRepository($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('CNSO-' . uniqid())
            ->setDocumentDate('2026-08-23')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $order->addLine(
            (new SalesOrderLine())->setName('Widget')->setQuantity($quantity)->setPrice('25.00')->setSubtotal($total),
        );
        $I->haveInRepository($order);

        $em = $I->grabService(EntityManagerInterface::class);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('CNINV-' . uniqid())
            ->setDocumentDate('2026-08-23')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $order->addInvoice($invoice);
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($order->getLines()->first())
                ->setName('Widget')
                ->setSku('WIDGET-1')
                ->setQuantity($quantity)
                ->setPrice('25.00')
                ->setSubtotal($total),
        );
        $em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $em->flush();

        return [$company, $invoice];
    }

    /** A second, unrelated invoice for the same customer — the one a note is applied to across. */
    private function extraInvoiceFor(FunctionalTester $I, Company $company, string $total): Invoice
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('CNINV2-' . uniqid())
            ->setDocumentDate('2026-08-23')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $invoice->addLine(
            (new InvoiceLine())->setName('Other widget')->setQuantity('2.00')->setPrice('40.00')->setSubtotal($total),
        );
        $em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $em->flush();

        return $invoice;
    }
}
