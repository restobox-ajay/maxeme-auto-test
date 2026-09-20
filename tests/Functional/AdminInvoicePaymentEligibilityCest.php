<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Which invoices may take money, and what that costs cancellation — conducted per #624 (#31).
 *
 * ## What was wrong
 *
 * A DRAFT invoice accepted payments. There was no status check anywhere: not in the payments
 * controller, not in Invoice::recordPayment(), not in the template. A draft has been sent to nobody,
 * so nobody could have paid it — and because the payment status is DERIVED from the payment rows at
 * flush by InvoicePaymentStatusSubscriber, a draft could display itself as **Paid** while still being
 * a working document no customer had ever seen: two statuses on one row contradicting each other.
 *
 * ## One rule, guarded from both ends
 *
 * InvoiceStatus::acceptsPayment() refuses Draft and Cancelled, and the second of those is the other
 * half of a pair rather than a second decision: Invoice::cancel() refuses an invoice holding payments,
 * so a cancelled invoice holds none and there is nothing for a further payment to join. Pay it and you
 * cannot cancel it; cancel it and you cannot pay it. Both halves are exercised here, in that order.
 *
 * ## How these tests are built
 *
 * The screen being hidden is not the guard — this app works with JavaScript off and anyone can post
 * to a route — so every hiding assertion is paired with a POST that skips the screen entirely and an
 * assertion that `invoice_payment_application` gained no row. Rows are read back out of the database by
 * `table.column` after each POST rather than from a flash message or an entity fetched beforehand,
 * and every refusal is paired with an assertion that the rows which should NOT have changed did not:
 * the issued invoice's own payment, and a second, unrelated invoice.
 */
final class AdminInvoicePaymentEligibilityCest
{
    private Company $company;

    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('payment-eligibility-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->company = (new Company())
            ->setName('Draft Payments Wholesale')
            ->setCode('DPW-' . uniqid());
        $I->haveInRepository($this->company);
    }

    // ------------------------------------------------------------------ the screen is not offered

    /**
     * A draft offers no way through to payments, and the screen itself says why.
     *
     * Three doors, all closed on a draft and all open on an issued invoice — the tab bar, the balance
     * link on the invoice, and the per-invoice link on its order — with the issued invoice as the
     * positive control in each case, because a test that only asserted absence would pass against a
     * template that had lost the link altogether.
     *
     * The page itself still renders. Somebody arriving here followed a bookmark or an old link, and
     * "this invoice has not been issued yet" is the answer they need; a 404 would read as the invoice
     * being gone, and an empty grid as the screen being broken.
     */
    public function aDraftOffersNoPaymentsScreenAndTheScreenExplainsWhy(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);
        $draft = $this->invoiceFor($I, $order, '40.00', issued: false);
        $issued = $this->invoiceFor($I, $order, '60.00', issued: true);

        $I->amOnPage('/admin/invoice/detail/' . $draft->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('a[href="/admin/invoice/' . $draft->getId() . '/payments"]');

        $I->amOnPage('/admin/invoice/detail/' . $issued->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('a[href="/admin/invoice/' . $issued->getId() . '/payments"]');

        // The order page lists both invoices, so one page shows the link present and absent at once.
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('a[href="/admin/invoice/' . $draft->getId() . '/payments"]');
        $I->seeElement('a[href="/admin/invoice/' . $issued->getId() . '/payments"]');

        // And the screen behind the withheld link renders, explains itself, and offers no form.
        $I->amOnPage('/admin/invoice/' . $draft->getId() . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $I->see('This invoice has not been issued yet.');
        $I->see('no payment can be recorded against it');
        $I->dontSeeElement('#document-payment-form');

        $I->amOnPage('/admin/invoice/' . $issued->getId() . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#document-payment-form');
    }

    // ------------------------------------------------------------------ the POST is refused

    /**
     * A payment posted straight at a draft is refused and `invoice_payment_application` gains no row — and the
     * same post succeeds the moment the invoice is issued.
     *
     * The POST deliberately skips the screen: the form is not rendered on a draft, so this is exactly
     * the scripted post the hiding does not stop. The token is real, scraped from the invoice's own
     * page, so the request goes through the CSRF check rather than being refused before the
     * controller body runs — which is how two security tests in this suite turned out to be testing
     * nothing at all.
     */
    public function postingAPaymentToADraftIsRefusedAndIssuingItLetsTheSamePostThrough(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);
        $draft = $this->invoiceFor($I, $order, '40.00', issued: false);
        $draftId = (int) $draft->getId();

        $this->postPayment($I, $draftId, 'Bank Transfer', '40.00');

        $I->assertSame(0, $this->paymentCount($I, $draftId), 'invoice_payment_application gained a row against a draft');
        $I->assertSame('Draft', $this->invoiceRow($I, $draftId)['status'], 'the refused payment moved the invoice status');
        $I->assertSame('Not Paid', $this->invoiceRow($I, $draftId)['payment_status'], 'invoice.payment_status after the refusal');
        $I->see('still a draft');
        $I->see('Issue the invoice first');

        // The positive control: issue it, and the identical post is accepted.
        $this->issue($I, $draftId);
        $I->assertSame('Pending', $this->invoiceRow($I, $draftId)['status'], 'the invoice did not issue');

        $this->postPayment($I, $draftId, 'Bank Transfer', '40.00');

        $I->assertSame(1, $this->paymentCount($I, $draftId), 'the same payment was refused on an ISSUED invoice');
        $rows = $this->paymentRows($I, $draftId);
        $I->assertSame(40.0, (float) $rows[0]['amount'], 'invoice_payment_application.amount of the accepted payment');
        $I->assertSame('Bank Transfer', $rows[0]['method'], 'invoice_payment.method of the accepted payment');
        $I->assertSame('Paid', $this->invoiceRow($I, $draftId)['payment_status'], 'invoice.payment_status after the accepted payment');
    }

    /**
     * A draft cannot read as Paid, however much is posted at it.
     *
     * The reason the guard exists at all rather than merely being tidy: payment status is derived
     * from the rows, so a draft that took one would contradict itself on its own screen. Posted at
     * the full total, which is the amount that would have derived Paid.
     */
    public function aDraftsPaymentStatusCannotBeDrivenToPaid(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);
        $draft = $this->invoiceFor($I, $order, '40.00', issued: false);
        $draftId = (int) $draft->getId();

        $this->postPayment($I, $draftId, 'Cash', '40.00');
        $this->postPayment($I, $draftId, 'Cheque', '40.00');

        $row = $this->invoiceRow($I, $draftId);
        $I->assertSame('Not Paid', $row['payment_status'], 'a draft derived a payment status from rows it should not hold');
        $I->assertSame('Draft', $row['status']);
        $I->assertSame(0, $this->paymentCount($I, $draftId), 'invoice_payment_application rows exist against a draft');

        // Nor may the delete route be used to imply such a row could have existed.
        $I->sendFormPostRequest('/admin/invoice/' . $draftId . '/payments/delete/0', ['_token' => $I->csrfToken()]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('still a draft');
    }

    /**
     * An issued invoice's payment is untouched by a refusal on a draft beside it.
     *
     * The row that should NOT change, on the document that should not be involved at all. Re-read
     * from the database rather than from an entity fetched before the POST, which would answer from
     * the identity map.
     */
    public function anIssuedInvoicesPaymentSurvivesARefusalOnADraft(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);
        $issued = $this->invoiceFor($I, $order, '60.00', issued: true);
        $draft = $this->invoiceFor($I, $order, '40.00', issued: false);
        $issuedId = (int) $issued->getId();
        $draftId = (int) $draft->getId();

        $this->postPayment($I, $issuedId, 'Cheque', '25.00');
        $before = $this->paymentRows($I, $issuedId);
        $I->assertCount(1, $before, 'the payment on the issued invoice was not written');
        $I->assertSame(25.0, (float) $before[0]['amount']);
        $I->assertSame('Partially Paid', $this->invoiceRow($I, $issuedId)['payment_status']);

        $this->postPayment($I, $draftId, 'Cheque', '25.00');
        $I->assertSame(0, $this->paymentCount($I, $draftId), 'the draft took a payment');

        $after = $this->paymentRows($I, $issuedId);
        $I->assertCount(1, $after, 'the issued invoice gained or lost a payment row during the refusal');
        $I->assertSame($before[0]['id'], $after[0]['id'], 'the issued invoice\'s payment is no longer the same row');
        $I->assertSame(25.0, (float) $after[0]['amount'], 'invoice_payment_application.amount on the ISSUED invoice changed during the refusal');
        $I->assertSame('Partially Paid', $this->invoiceRow($I, $issuedId)['payment_status'], 'invoice.payment_status on the ISSUED invoice changed during the refusal');
        $I->assertSame('Pending', $this->invoiceRow($I, $issuedId)['status'], 'invoice.status on the ISSUED invoice changed during the refusal');
    }

    // ------------------------------------------------------------------ the other end of the rule

    /**
     * An invoice holding a payment refuses to cancel, and nothing moves — then deleting the payment
     * lets the same cancel through.
     *
     * The second half is the positive control that proves the refusal is about the payment and not
     * about something else on the document. A second, unrelated invoice is carried through the whole
     * thing and asserted at the end.
     */
    public function anInvoiceHoldingAPaymentRefusesToCancelUntilThePaymentIsDeleted(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);
        $paid = $this->invoiceFor($I, $order, '60.00', issued: true);
        $bystander = $this->invoiceFor($I, $order, '40.00', issued: true);
        $paidId = (int) $paid->getId();
        $bystanderId = (int) $bystander->getId();

        $this->postPayment($I, $paidId, 'Cash', '25.00');
        $this->postPayment($I, $bystanderId, 'Cash', '15.00');
        $paymentId = (int) $this->paymentRows($I, $paidId)[0]['id'];

        $this->cancel($I, $paidId);

        // Refused, and the refusal names what is blocking, how many, and the one way out there is.
        $I->see('cannot be cancelled');
        $I->see('Payments screen');
        $I->dontSee('Refund');

        $I->assertSame('Pending', $this->invoiceRow($I, $paidId)['status'], 'an invoice holding a payment was cancelled');
        $rows = $this->paymentRows($I, $paidId);
        $I->assertCount(1, $rows, 'the payment rows changed during the refused cancel');
        $I->assertSame($paymentId, (int) $rows[0]['id'], 'the payment is no longer the same row');
        $I->assertSame(25.0, (float) $rows[0]['amount'], 'invoice_payment_application.amount changed during the refused cancel');

        // Delete the payment through the screen that holds it, then the same cancel succeeds. The
        // shared document_payments partial (#708) posts each row's delete through its own hidden
        // form rather than a `.js-delete`/`data-url` button, the same shape the bill payments screen
        // has always used.
        $I->amOnPage('/admin/invoice/' . $paidId . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $deleteToken = (string) $I->grabAttributeFrom('form#delete-document-payment-' . $paymentId . ' input[name="_token"]', 'value');
        $I->sendFormPostRequest(
            '/admin/invoice/' . $paidId . '/payments/delete/' . $paymentId,
            ['_token' => $deleteToken],
        );
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(0, $this->paymentCount($I, $paidId), 'invoice_payment_application after the delete');

        $this->cancel($I, $paidId);
        $I->assertSame('Cancelled', $this->invoiceRow($I, $paidId)['status'], 'the cancel was still refused after the payment was deleted');

        // The unrelated invoice is exactly where it was left, payment and all.
        $I->assertSame('Pending', $this->invoiceRow($I, $bystanderId)['status'], 'a second invoice changed status while its neighbour was cancelled');
        $bystanderRows = $this->paymentRows($I, $bystanderId);
        $I->assertCount(1, $bystanderRows, 'a second invoice gained or lost a payment row');
        $I->assertSame(15.0, (float) $bystanderRows[0]['amount'], 'invoice_payment_application.amount on the UNRELATED invoice changed');
    }

    /**
     * An invoice with no payments cancels exactly as it always did.
     *
     * A guard that refuses too much is a worse defect than the one being fixed, so the behaviour that
     * was already correct gets an assertion of its own.
     */
    public function anInvoiceWithNoPaymentsStillCancels(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I);
        $invoice = $this->invoiceFor($I, $order, '60.00', issued: true);
        $invoiceId = (int) $invoice->getId();

        $this->cancel($I, $invoiceId);

        $I->assertSame('Cancelled', $this->invoiceRow($I, $invoiceId)['status'], 'an invoice with no payments was refused a cancel');
        $I->assertSame(0, $this->paymentCount($I, $invoiceId));
    }

    // ------------------------------------------------------------------ fixtures and readers

    /** An approved order for ten widgets at $10, which is room for every invoice raised below. */
    private function approvedOrder(FunctionalTester $I): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->currentCompany($I))
            ->setOrderNumber('DPW-SO-' . strtoupper(substr(uniqid(), -8)))
            ->setDocumentDate('2026-09-11')
            ->setSubtotal('100.00')
            ->setTax('0.00')
            ->setTotal('100.00');
        $order->addLine(
            (new SalesOrderLine())->setName('Widget')->setQuantity('10.00')->setPrice('10.00')->setSubtotal('100.00'),
        );
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->haveInRepository($order);

        return $order;
    }

    /**
     * An invoice against that order for $amount, issued or left as a draft.
     *
     * Its line is attributed to no order line on purpose: what is under test here is payment
     * eligibility, and an unattributed line draws nothing down, so these invoices cannot collide with
     * the over-invoicing guard (#31's other half) and turn a payment test into a quantity test.
     */
    private function invoiceFor(FunctionalTester $I, SalesOrder $order, string $amount, bool $issued): Invoice
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $order = $em->find(SalesOrder::class, $order->getId());

        $invoice = (new Invoice())
            ->setCompany($this->currentCompany($I))
            ->setDocumentNumber('DPW-INV-' . strtoupper(substr(uniqid(), -8)))
            ->setDocumentDate('2026-09-11')
            ->setInvoiceDate('2026-09-11')
            ->setSubtotal($amount)
            ->setTax('0.00')
            ->setTotal($amount);
        $invoice->addLine(
            (new InvoiceLine())->setName('Carriage')->setQuantity('1.00')->setPrice($amount)->setSubtotal($amount),
        );
        $order->addInvoice($invoice);

        if ($issued) {
            $invoice->issue(DocumentActor::system());
        }

        $em->persist($invoice);
        $em->flush();

        return $invoice;
    }

    private function currentCompany(FunctionalTester $I): Company
    {
        $em = $I->grabService(EntityManagerInterface::class);

        return $em->find(Company::class, $this->company->getId()) ?? $this->company;
    }

    /**
     * Posts a payment at the route, skipping the screen — which is the whole point on a draft, where
     * no form is rendered. The token is scraped from the invoice's own page, so the CSRF check passes
     * and the controller body actually runs.
     */
    private function postPayment(FunctionalTester $I, int $invoiceId, string $method, string $amount): void
    {
        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $token = $I->csrfToken();

        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/payments', [
            '_token' => $token,
            'payment_id' => '',
            'received_at' => '2026-09-11',
            'method' => $method,
            'amount' => $amount,
            'comment' => '',
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    /** Presses a named button on the invoice's own screen, with that form's own token. */
    private function performAction(FunctionalTester $I, int $invoiceId, string $action): void
    {
        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $url = '/admin/invoice/' . $invoiceId . '/action/' . $action;
        $token = (string) $I->grabAttributeFrom('form[action="' . $url . '"] input[name="_token"]', 'value');

        $I->sendFormPostRequest($url, ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();
    }

    private function issue(FunctionalTester $I, int $invoiceId): void
    {
        $this->performAction($I, $invoiceId, 'issue');
    }

    private function cancel(FunctionalTester $I, int $invoiceId): void
    {
        $this->performAction($I, $invoiceId, 'cancel');
    }

    /** @return array<string, mixed> */
    private function invoiceRow(FunctionalTester $I, int $invoiceId): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT id, status, payment_status, total FROM invoice WHERE id = ?',
            [$invoiceId],
        );

        return \is_array($row) ? $row : [];
    }

    /**
     * Each claim against the invoice, with the method it was via — a join, because #708 moved
     * `method` onto the pool (`invoice_payment`) and left the claim (`invoice_payment_application`,
     * `a` below) with only the id, the amount and which invoice it applies to.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentRows(FunctionalTester $I, int $invoiceId): array
    {
        return $this->connection($I)->fetchAllAssociative(
            'SELECT a.id, a.amount, p.method FROM invoice_payment_application a'
                . ' JOIN invoice_payment p ON p.id = a.invoice_payment_id'
                . ' WHERE a.invoice_id = ? ORDER BY a.id',
            [$invoiceId],
        );
    }

    private function paymentCount(FunctionalTester $I, int $invoiceId): int
    {
        return (int) $this->connection($I)->fetchOne(
            'SELECT COUNT(*) FROM invoice_payment_application WHERE invoice_id = ?',
            [$invoiceId],
        );
    }

    private function connection(FunctionalTester $I): \Doctrine\DBAL\Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }
}
