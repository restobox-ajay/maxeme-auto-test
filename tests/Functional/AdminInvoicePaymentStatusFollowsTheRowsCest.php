<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * `invoice.payment_status` follows the `invoice_payment_application` rows through the real screens —
 * conducted per #624, anchored per #627 (item 58).
 *
 * ## What item 58 is about, and what part of it a screen can reach
 *
 * `InvoicePaymentStatusSubscriber` used to queue only `$payment->getInvoice()`, which after a payment
 * is re-pointed names the DESTINATION. The invoice the payment LEFT was never queued, so it went on
 * calling itself Paid while holding nothing.
 *
 * **There is no sell-side move screen.** `admin_invoice_payments` records and amends,
 * `admin_invoice_payment_delete` removes, and `Invoice::amendApplication()` calls `assertHolds()`, so a
 * forged `payment_id` belonging to another invoice is refused rather than moved — the case at the
 * bottom of this file proves that, and it is why the move itself is exercised against the entity
 * surface in `InvoicePaymentStatusSubscriberTest` instead of here.
 *
 * What this file conducts is the pair of neighbours that ARE reachable from a screen and that the
 * fix had to keep working — a payment DELETED off an invoice, and a payment AMENDED down — because
 * the argument for fixing the subscriber rather than a call site is that all three cases are the
 * same rule and not three remembered call sites. Every case reads `invoice.payment_status` back by
 * column after the POST, asserts the same value on screen anchored to `#payment-status-derived`,
 * and asserts a second, untouched invoice did not move.
 */
final class AdminInvoicePaymentStatusFollowsTheRowsCest
{
    private ?Company $company = null;

    /**
     * Codeception reuses one Cest instance for every method in the file, so anything held on $this
     * outlives the transaction that created it. Both properties are rebuilt here, per test.
     */
    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('payment-derivation-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->company = (new Company())
            ->setName('Derivation Wholesale')
            ->setCode('DRV-' . uniqid());
        $I->haveInRepository($this->company);
    }

    /**
     * Deleting the only payment takes the invoice back to Not Paid.
     *
     * The positive control for the move case: a deletion leaves `getInvoice()` naming the document
     * that is actually affected, which is why this half was never broken. Conducted so that the
     * claim is a measurement rather than an argument.
     */
    public function deletingThePaymentTakesTheInvoiceBackToNotPaid(FunctionalTester $I): void
    {
        $paid = $this->issuedInvoice($I, '100.00');
        $bystander = $this->issuedInvoice($I, '100.00');

        $this->recordPayment($I, $paid, '100.00');
        $I->assertSame('Paid', $this->paymentStatusColumn($I, $paid));
        $I->assertSame(1, $this->paymentCount($I, $paid));

        $paymentId = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM invoice_payment_application WHERE invoice_id = ?',
            [$paid],
        );

        $I->amOnPage('/admin/invoice/' . $paid . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('#document-payment-form input[name="_token"]', 'value');
        $I->sendFormPostRequest(
            '/admin/invoice/' . $paid . '/payments/delete/' . $paymentId,
            ['_token' => $token],
        );

        $I->assertSame('Not Paid', $this->paymentStatusColumn($I, $paid));
        $I->assertSame(0, $this->paymentCount($I, $paid));

        // The same answer on screen, anchored to the element rather than matched anywhere on the page.
        $I->amOnPage('/admin/invoice/' . $paid . '/payments');
        $I->see('Not Paid', '#payment-status-derived');
        $I->dontSee('Partially Paid', '#payment-status-derived');

        // The row that must not change.
        $I->assertSame('Not Paid', $this->paymentStatusColumn($I, $bystander));
        $I->assertSame(0, $this->paymentCount($I, $bystander));
    }

    /**
     * Amending a payment down from the full total to part of it moves the invoice to Partially Paid.
     *
     * The other reachable neighbour. An amendment is an UPDATE to the payment whose invoice did not
     * change, so the change set carries no 'invoice' key at all and the existing pass covers it —
     * which is the second half of why the fix belongs in the subscriber and not at a call site.
     */
    public function amendingThePaymentDownMovesTheInvoiceToPartiallyPaid(FunctionalTester $I): void
    {
        $invoice = $this->issuedInvoice($I, '100.00');
        $bystander = $this->issuedInvoice($I, '100.00');

        $this->recordPayment($I, $invoice, '100.00');
        $I->assertSame('Paid', $this->paymentStatusColumn($I, $invoice));

        $paymentId = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM invoice_payment_application WHERE invoice_id = ?',
            [$invoice],
        );

        $this->postPaymentForm($I, $invoice, ['payment_id' => (string) $paymentId, 'amount' => '40.00']);

        $I->assertSame('Partially Paid', $this->paymentStatusColumn($I, $invoice));
        // Compared as a number: SQLite stores this DECIMAL column without trailing zeros, so the
        // string it hands back is '40' and asserting '40.00' would be asserting the storage format
        // rather than the money.
        $I->assertSame(40.0, (float) $this->connection($I)->fetchOne(
            'SELECT amount FROM invoice_payment_application WHERE id = ?',
            [$paymentId],
        ));
        // Still one row: an amendment corrects the payment, it does not delete and re-key it.
        $I->assertSame(1, $this->paymentCount($I, $invoice));

        $I->amOnPage('/admin/invoice/' . $invoice . '/payments');
        $I->see('Partially Paid', '#payment-status-derived');
        $I->dontSee('Not Paid', '#payment-status-derived');

        $I->assertSame('Not Paid', $this->paymentStatusColumn($I, $bystander));
        $I->assertSame(0, $this->paymentCount($I, $bystander));
    }

    /**
     * Recording the money is the positive control for both cases above on the same element.
     *
     * Without it, "the column said Not Paid" would be indistinguishable from a screen and a
     * derivation that never do anything at all.
     */
    public function recordingThePaymentIsWhatMovesTheColumnInTheFirstPlace(FunctionalTester $I): void
    {
        $invoice = $this->issuedInvoice($I, '100.00');
        $bystander = $this->issuedInvoice($I, '100.00');

        $I->assertSame('Not Paid', $this->paymentStatusColumn($I, $invoice));
        $I->amOnPage('/admin/invoice/' . $invoice . '/payments');
        $I->see('Not Paid', '#payment-status-derived');

        $this->recordPayment($I, $invoice, '40.00');
        $I->assertSame('Partially Paid', $this->paymentStatusColumn($I, $invoice));

        $this->recordPayment($I, $invoice, '60.00');
        $I->assertSame('Paid', $this->paymentStatusColumn($I, $invoice));
        $I->assertSame(2, $this->paymentCount($I, $invoice));

        $I->amOnPage('/admin/invoice/' . $invoice . '/payments');
        $I->see('Paid', '#payment-status-derived');

        $I->assertSame('Not Paid', $this->paymentStatusColumn($I, $bystander));
    }

    /**
     * The screen cannot be used to move a payment: a `payment_id` belonging to another invoice is
     * refused by `Invoice::amendPayment()`'s `assertHolds()`, not quietly re-pointed.
     *
     * This is what makes "there is no sell-side move" a measurement rather than a reading of the
     * route list, and it is the reason the move case in `InvoicePaymentStatusSubscriberTest` is not
     * conducted through a screen.
     */
    public function thePaymentsScreenRefusesToAdoptAnotherInvoicesPayment(FunctionalTester $I): void
    {
        $holder = $this->issuedInvoice($I, '100.00');
        $thief = $this->issuedInvoice($I, '100.00');

        $this->recordPayment($I, $holder, '100.00');
        $paymentId = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM invoice_payment_application WHERE invoice_id = ?',
            [$holder],
        );

        $this->postPaymentForm($I, $thief, ['payment_id' => (string) $paymentId, 'amount' => '100.00']);

        // The row never moved.
        $I->assertSame($holder, (int) $this->connection($I)->fetchOne(
            'SELECT invoice_id FROM invoice_payment_application WHERE id = ?',
            [$paymentId],
        ));
        $I->assertSame('Paid', $this->paymentStatusColumn($I, $holder));
        $I->assertSame('Not Paid', $this->paymentStatusColumn($I, $thief));
        $I->assertSame(0, $this->paymentCount($I, $thief));

        $I->amOnPage('/admin/invoice/' . $thief . '/payments');
        $I->see('Not Paid', '#payment-status-derived');
        $I->dontSee('Partially Paid', '#payment-status-derived');
    }

    // ------------------------------------------------------------------------------- the plumbing

    /** Records money through the real form, with the token scraped off the page. */
    private function recordPayment(FunctionalTester $I, int $invoiceId, string $amount): void
    {
        $this->postPaymentForm($I, $invoiceId, ['payment_id' => '', 'amount' => $amount]);
    }

    /**
     * @param array{payment_id: string, amount: string} $fields
     */
    private function postPaymentForm(FunctionalTester $I, int $invoiceId, array $fields): void
    {
        $I->amOnPage('/admin/invoice/' . $invoiceId . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('#document-payment-form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/payments', [
            '_token' => $token,
            'payment_id' => $fields['payment_id'],
            'received_at' => '2026-08-20',
            'method' => 'Bank Transfer',
            'amount' => $fields['amount'],
            'comment' => '',
        ]);
    }

    private function paymentStatusColumn(FunctionalTester $I, int $invoiceId): string
    {
        return (string) $this->connection($I)->fetchOne(
            'SELECT payment_status FROM invoice WHERE id = ?',
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

    /** An approved order with one ISSUED invoice for its whole value. Returns the invoice id. */
    private function issuedInvoice(FunctionalTester $I, string $total): int
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('DRVSO-' . uniqid())
            ->setDocumentDate('2026-08-20')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $order->addLine(
            (new SalesOrderLine())->setName('Widget')->setQuantity('4.00')->setPrice('25.00')->setSubtotal($total),
        );
        $I->haveInRepository($order);

        $em = $I->grabService(EntityManagerInterface::class);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $invoice = (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber('DRVINV-' . uniqid())
            ->setDocumentDate('2026-08-20')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $order->addInvoice($invoice);
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($order->getLines()->first())
                ->setName('Widget')
                ->setQuantity('4.00')
                ->setPrice('25.00')
                ->setSubtotal($total),
        );
        $em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $em->flush();

        return (int) $invoice->getId();
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }
}
