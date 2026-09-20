<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * An invoice payment can be MOVED to another invoice, keeping its identity — the sell-side mirror of
 * `VendorBillPaymentMoveCest` (#708, queue item 34).
 *
 * ## What this proves that the unit tests do not
 *
 * `InvoicePaymentMoveTest` (PHPUnit) already proves `Invoice::moveApplication()`'s rules in memory.
 * This proves the wiring around it: the route exists and is reachable, the shared
 * `document_payments.html.twig` partial renders the move dropdown scoped correctly for THIS side,
 * the controller reads `target_id` and reports through the flash, and —
 * the one thing no unit test can — that `InvoicePaymentStatusSubscriber` actually recalculates BOTH
 * invoices from the database after a real HTTP request, not just from an in-memory call.
 *
 * ## Two ids, not one, since #708
 *
 * Before #708 one row, `invoice_payment`, was both the money and the one document it settled, so
 * "the payment" and "the row" were the same id. #708 split that row into a POOL (`invoice_payment` —
 * the money, the date, the method, the reference, no longer pointed at any invoice) and a CLAIM
 * against one invoice (`invoice_payment_application` — an amount and an invoice, pointed at the
 * pool). A move withdraws the losing claim and creates a brand new one on the gaining invoice, so the
 * claim's own id is NOT what survives a move — the pool's is. This file checks both ids separately
 * for exactly that reason: the route and the dropdown work in APPLICATION ids (what a claim is
 * addressed by), and "kept its id" in the flash message and the assertions below is a claim about the
 * POOL underneath.
 *
 * ## Built on invoice fixtures, not a raised-through-the-screen bill
 *
 * Every other invoice payment Cest in this file's neighbourhood (`AdminInvoicePaymentsCest`,
 * `AdminInvoicePaymentEligibilityCest`) builds its invoices by persisting the entity directly rather
 * than posting the create screen — unlike the buy side's bill fixtures, which #624 asks to be raised
 * through the real POST. This follows the sell side's own established idiom rather than importing the
 * buy side's, for the same reason `InvoicePaymentMover` is thinner than `VendorBillPaymentMover`: the
 * two sides are allowed to differ in how they are built, only the shared contract is forced.
 */
final class AdminInvoicePaymentMoveCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('invoicemove-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function company(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name . ' ' . strtoupper(substr(uniqid(), -6)))
            ->setCode('IM-' . strtoupper(substr(uniqid(), -8)));
        $I->haveInRepository($company);

        return $company;
    }

    /** An issued invoice for $total against $company, with a marker in its own PO number for lookup. */
    private function issuedInvoice(FunctionalTester $I, Company $company, string $total, string $marker): int
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('IM-INV-' . strtoupper(substr(uniqid(), -8)))
            ->setDocumentDate('2026-09-15')
            ->setInvoiceDate('2026-09-15')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total)
            ->setPoNumber($marker);
        $invoice->addLine(
            (new InvoiceLine())->setName('Pallets ' . $marker)->setQuantity('1.00')->setPrice($total)->setSubtotal($total),
        );
        $invoice->issue(DocumentActor::system());
        $em->persist($invoice);
        $em->flush();

        return (int) $invoice->getId();
    }

    private function recordPayment(FunctionalTester $I, int $invoiceId, string $amount, string $method, string $comment, string $receivedAt): void
    {
        $I->amOnPage('/admin/invoice/' . $invoiceId . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form#document-payment-form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/payments', [
            '_token' => $token,
            'payment_id' => '0',
            'received_at' => $receivedAt,
            'method' => $method,
            'amount' => $amount,
            'comment' => $comment,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    /** The pool row itself — the money, the date, the method, the reference, who recorded it. */
    private function poolRow(FunctionalTester $I, int $poolId): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT id, user_id, received_at, method, amount, comment FROM invoice_payment WHERE id = ?',
            [$poolId],
        );

        return \is_array($row) ? $row : [];
    }

    /** One claim — which invoice it is against, which pool it draws from, its own amount and date. */
    private function applicationRow(FunctionalTester $I, int $applicationId): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT id, invoice_id, invoice_payment_id, amount, applied_at FROM invoice_payment_application WHERE id = ?',
            [$applicationId],
        );

        return \is_array($row) ? $row : [];
    }

    /** @return list<int> the claim ids sitting on $invoiceId, in id order */
    private function applicationIdsOn(FunctionalTester $I, int $invoiceId): array
    {
        return array_map('intval', $this->connection($I)->fetchFirstColumn(
            'SELECT id FROM invoice_payment_application WHERE invoice_id = ? ORDER BY id ASC',
            [$invoiceId],
        ));
    }

    private function paymentStatus(FunctionalTester $I, int $invoiceId): string
    {
        return (string) $this->connection($I)->fetchOne('SELECT payment_status FROM invoice WHERE id = ?', [$invoiceId]);
    }

    private function documentNumber(FunctionalTester $I, int $invoiceId): string
    {
        return (string) $this->connection($I)->fetchOne('SELECT document_number FROM invoice WHERE id = ?', [$invoiceId]);
    }

    private function connection(FunctionalTester $I): \Doctrine\DBAL\Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }

    /**
     * POST the move form, with the CSRF token scraped off the payments screen it is served from.
     * $applicationId is the claim's own id — what the route and the dropdown both address a payment by.
     */
    private function postMove(FunctionalTester $I, int $invoiceId, int $applicationId, string $targetId, string $reason = ''): void
    {
        $I->amOnPage('/admin/invoice/' . $invoiceId . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form#document-payment-form input[name="_token"]', 'value');

        $I->sendFormPostRequest(
            '/admin/invoice/' . $invoiceId . '/payments/move/' . $applicationId,
            ['_token' => $token, 'target_id' => $targetId, 'reason' => $reason],
        );
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * The load-bearing test: the payment moves, both invoices' derived payment statuses follow the
     * rows, the underlying pool keeps its id and everything else on it, the claim lands as a new row
     * on the gaining invoice, and a third invoice is untouched.
     */
    public function aPaymentMovesAndBothInvoicesFollowWhileAThirdDoesNot(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $company = $this->company($I, 'Moving Co');
        $tag = strtoupper(substr(uniqid(), -6));

        $losing = $this->issuedInvoice($I, $company, '900.00', 'MOVE-FROM-' . $tag);
        $gaining = $this->issuedInvoice($I, $company, '900.00', 'MOVE-TO-' . $tag);
        $bystander = $this->issuedInvoice($I, $company, '300.00', 'MOVE-BYSTANDER-' . $tag);

        $this->recordPayment($I, $losing, '900.00', 'Cheque', 'Cheque 8801', '2026-09-15');
        $this->recordPayment($I, $bystander, '300.00', 'Cash', 'Petty cash', '2026-09-17');

        $before = $this->applicationIdsOn($I, $losing);
        $I->assertCount(1, $before, 'the payment under test was not recorded');
        $applicationId = $before[0];
        $bystanderApplications = $this->applicationIdsOn($I, $bystander);
        $I->assertCount(1, $bystanderApplications, 'the bystander payment was not recorded');
        $bystanderApplicationId = $bystanderApplications[0];

        $I->assertSame('Paid', $this->paymentStatus($I, $losing), 'invoice.payment_status of the invoice about to lose the payment');
        $I->assertSame('Not Paid', $this->paymentStatus($I, $gaining), 'invoice.payment_status of the invoice about to gain it');
        $I->assertSame('Paid', $this->paymentStatus($I, $bystander), 'invoice.payment_status of the bystander before the move');

        $originalApplication = $this->applicationRow($I, $applicationId);
        $I->assertSame($losing, (int) $originalApplication['invoice_id'], 'invoice_payment_application.invoice_id before the move');
        $poolId = (int) $originalApplication['invoice_payment_id'];
        $originalPool = $this->poolRow($I, $poolId);

        // The dropdown offers the legal target and nothing else about it is guessed at.
        $I->amOnPage('/admin/invoice/' . $losing . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name="target_id"] option[value="' . $gaining . '"]');
        $I->seeElement('select[name="target_id"] option[value="' . $bystander . '"]');
        $I->dontSeeElement('select[name="target_id"] option[value="' . $losing . '"]');

        $this->postMove($I, $losing, $applicationId, (string) $gaining, 'Recorded against the wrong invoice');

        // The underlying pool kept its identity — same row, same date, method, reference and actor,
        // re-read from the table by the id that never changed.
        $afterPool = $this->poolRow($I, $poolId);
        $I->assertNotSame([], $afterPool, 'the underlying payment was deleted by the move — a move is not a delete and recreate');
        $I->assertSame($originalPool['received_at'], $afterPool['received_at'], 'invoice_payment.received_at was not preserved');
        $I->assertSame('Cheque', $afterPool['method'], 'invoice_payment.method was not preserved');
        $I->assertSame('Cheque 8801', $afterPool['comment'], 'invoice_payment.comment (the reference) was not preserved');
        $I->assertSame($originalPool['user_id'], $afterPool['user_id'], 'invoice_payment.user_id — who recorded it — was not preserved');
        $I->assertSame(900.0, (float) $afterPool['amount'], 'invoice_payment.amount was not preserved');

        // The claim itself is a NEW row on the gaining invoice, pointed at that same pool.
        $I->assertSame([], $this->applicationIdsOn($I, $losing), 'the losing invoice still holds a claim');
        $gainingApplications = $this->applicationIdsOn($I, $gaining);
        $I->assertCount(1, $gainingApplications, 'the gaining invoice does not hold exactly one claim');
        $newApplicationId = $gainingApplications[0];
        $newApplication = $this->applicationRow($I, $newApplicationId);
        $I->assertSame($poolId, (int) $newApplication['invoice_payment_id'], 'the moved claim draws from a different underlying payment');
        $I->assertSame($gaining, (int) $newApplication['invoice_id'], 'invoice_payment_application.invoice_id after the move');
        $I->assertSame($originalApplication['applied_at'], $newApplication['applied_at'], 'invoice_payment_application.applied_at was not preserved');
        $I->assertSame(900.0, (float) $newApplication['amount'], 'invoice_payment_application.amount was not preserved');

        // Both derived statuses moved, in opposite directions — the sell-side proof that
        // InvoicePaymentStatusSubscriber caught the invoice the payment LEFT and not only the one it
        // landed on.
        $I->assertSame('Not Paid', $this->paymentStatus($I, $losing), 'invoice.payment_status of the losing invoice did not come back down from Paid');
        $I->assertSame('Paid', $this->paymentStatus($I, $gaining), 'invoice.payment_status of the gaining invoice did not become Paid');

        // The bystander did not move at all.
        $I->assertSame('Paid', $this->paymentStatus($I, $bystander), 'the bystander invoice changed payment status');
        $I->assertSame([$bystanderApplicationId], $this->applicationIdsOn($I, $bystander), 'the bystander invoice lost or gained a claim');

        $I->see('moved from invoice ' . $this->documentNumber($I, $losing) . ' to invoice ' . $this->documentNumber($I, $gaining));
        $I->see('It kept its date, its reference and its id.');
        $I->see('balance of $900.00');
        $I->see('balance of $0.00');

        $I->amOnPage('/admin/invoice/detail/' . $losing);
        $I->seeResponseCodeIsSuccessful();
        $I->see('moved to invoice ' . $this->documentNumber($I, $gaining));
        $I->amOnPage('/admin/invoice/detail/' . $gaining);
        $I->seeResponseCodeIsSuccessful();
        $I->see('moved in from invoice ' . $this->documentNumber($I, $losing));
    }

    /**
     * Moving a payment onto an invoice that is already covered OVERPAYS it, allowed and said out
     * loud rather than refused — the same argument the buy side's identical test makes.
     */
    public function aMoveThatOverpaysTheTargetIsAllowedAndSaysSo(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $company = $this->company($I, 'Overpay Co');
        $tag = strtoupper(substr(uniqid(), -6));

        $losing = $this->issuedInvoice($I, $company, '900.00', 'OVER-FROM-' . $tag);
        $gaining = $this->issuedInvoice($I, $company, '100.00', 'OVER-TO-' . $tag);

        $this->recordPayment($I, $losing, '900.00', 'Bank Transfer', 'EFT 22', '2026-09-18');
        $applicationId = $this->applicationIdsOn($I, $losing)[0];

        $this->postMove($I, $losing, $applicationId, (string) $gaining);

        $newApplicationId = $this->applicationIdsOn($I, $gaining)[0];
        $I->assertSame($gaining, (int) $this->applicationRow($I, $newApplicationId)['invoice_id'], 'invoice_payment_application.invoice_id after an overpaying move');
        $I->assertSame('Not Paid', $this->paymentStatus($I, $losing), 'invoice.payment_status of the losing invoice');
        $I->assertSame('Paid', $this->paymentStatus($I, $gaining), 'invoice.payment_status of the overpaid invoice');

        $I->see('is now OVERPAID by the base currency 800.00');
        $I->see('the base currency 900.00 applied against a total of the base currency 100.00');
    }

    /**
     * Every illegal move is refused with the reason, and NOTHING is written — one test rather than
     * several, because the assertion after each refusal is the important half: the payment is still
     * exactly where it was, and neither invoice's status moved.
     */
    public function anIllegalMoveIsRefusedLoudlyAndChangesNothing(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = strtoupper(substr(uniqid(), -6));

        $company = $this->company($I, 'Refusing Co');
        $stranger = $this->company($I, 'Other Co');

        $source = $this->issuedInvoice($I, $company, '900.00', 'REF-FROM-' . $tag);
        $legal = $this->issuedInvoice($I, $company, '900.00', 'REF-LEGAL-' . $tag);
        $strangers = $this->issuedInvoice($I, $stranger, '900.00', 'REF-STRANGER-' . $tag);

        // A cancelled invoice: owed nothing, and it can be cancelled here because it holds no
        // payments.
        $em = $I->grabService(EntityManagerInterface::class);
        $cancelled = $this->issuedInvoice($I, $company, '900.00', 'REF-CANCELLED-' . $tag);
        $cancelledEntity = $em->find(Invoice::class, $cancelled);
        $cancelledEntity->setStatus('Cancelled', DocumentActor::system());
        $em->flush();
        $I->assertSame('Cancelled', (string) $this->connection($I)->fetchOne('SELECT status FROM invoice WHERE id = ?', [$cancelled]), 'the invoice under test was not cancelled');

        // A DRAFT invoice of the same company: never issued.
        $draft = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('IM-INV-' . strtoupper(substr(uniqid(), -8)))
            ->setDocumentDate('2026-09-15')
            ->setInvoiceDate('2026-09-15')
            ->setSubtotal('900.00')
            ->setTax('0.00')
            ->setTotal('900.00')
            ->setPoNumber('REF-DRAFT-' . $tag);
        $draft->addLine((new InvoiceLine())->setName('Pallets')->setQuantity('1.00')->setPrice('900.00')->setSubtotal('900.00'));
        $em->persist($draft);
        $em->flush();
        $draftId = (int) $draft->getId();

        $this->recordPayment($I, $source, '900.00', 'Cheque', 'Cheque 9001', '2026-09-19');
        $applicationId = $this->applicationIdsOn($I, $source)[0];
        $poolId = (int) $this->applicationRow($I, $applicationId)['invoice_payment_id'];
        $I->assertSame('Paid', $this->paymentStatus($I, $source), 'invoice.payment_status of the source invoice before any refusal');

        // The dropdown does not offer any of the illegal targets — and the positive control sits
        // right beside it, because "no options at all" would satisfy every dontSee below.
        $I->amOnPage('/admin/invoice/' . $source . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name="target_id"] option[value="' . $legal . '"]');
        $I->dontSeeElement('select[name="target_id"] option[value="' . $cancelled . '"]');
        $I->dontSeeElement('select[name="target_id"] option[value="' . $draftId . '"]');
        $I->dontSeeElement('select[name="target_id"] option[value="' . $strangers . '"]');
        $I->dontSeeElement('select[name="target_id"] option[value="' . $source . '"]');

        $refusals = [
            'onto itself' => [(string) $source, 'That payment is already on ' . $this->documentNumber($I, $source)],
            'onto a cancelled invoice' => [(string) $cancelled, 'is cancelled; it is owed nothing'],
            'onto a draft invoice' => [(string) $draftId, 'still a draft; it has not been issued'],
            'onto another company' => [(string) $strangers, "would leave both parties' balances wrong"],
            'with nothing chosen' => ['0', 'Choose the invoice to move this payment to.'],
        ];

        foreach ($refusals as $what => [$target, $expected]) {
            $this->postMove($I, $source, $applicationId, $target);
            $I->see($expected);

            $row = $this->applicationRow($I, $applicationId);
            $I->assertSame($source, (int) $row['invoice_id'], 'the payment MOVED despite the refusal: ' . $what);
            $I->assertSame(900.0, (float) $row['amount'], 'the payment amount changed on a refused move: ' . $what);
            $pool = $this->poolRow($I, $poolId);
            $I->assertSame('Cheque 9001', $pool['comment'], 'the payment comment changed on a refused move: ' . $what);
            $I->assertSame('Paid', $this->paymentStatus($I, $source), 'the source invoice status changed on a refused move: ' . $what);
            $I->assertSame([$applicationId], $this->applicationIdsOn($I, $source), 'the source invoice claims changed on a refused move: ' . $what);
        }

        foreach ([$legal, $cancelled, $draftId, $strangers] as $untouched) {
            $I->assertSame([], $this->applicationIdsOn($I, $untouched), 'a refused target gained a claim');
        }

        // The positive control: the SAME POST, to the legal target, works.
        $this->postMove($I, $source, $applicationId, (string) $legal);
        $legalApplicationId = $this->applicationIdsOn($I, $legal)[0];
        $I->assertSame($legal, (int) $this->applicationRow($I, $legalApplicationId)['invoice_id'], 'the legal move was refused too, so the refusals above prove nothing');
        $I->assertSame('Not Paid', $this->paymentStatus($I, $source), 'invoice.payment_status of the source after the legal move');
        $I->assertSame('Paid', $this->paymentStatus($I, $legal), 'invoice.payment_status of the legal target after the move');
    }

    /**
     * A forged claim id in the URL cannot move somebody else's row.
     *
     * The route takes an invoice and a claim id independently, so a hand-made POST can name a claim
     * belonging to a different invoice. `moveApplication()` asserts the claim is on THIS invoice
     * before reading anything else — the same protection `deletePayment()` gets from
     * `withdrawApplication()`.
     */
    public function aPaymentOnAnotherInvoiceCannotBeMovedThroughThisInvoicesRoute(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $company = $this->company($I, 'Forging Co');
        $tag = strtoupper(substr(uniqid(), -6));

        $mine = $this->issuedInvoice($I, $company, '900.00', 'FORGE-MINE-' . $tag);
        $theirs = $this->issuedInvoice($I, $company, '900.00', 'FORGE-THEIRS-' . $tag);
        $target = $this->issuedInvoice($I, $company, '900.00', 'FORGE-TARGET-' . $tag);

        $this->recordPayment($I, $theirs, '450.00', 'E-Transfer', 'Not yours', '2026-09-20');
        $foreignApplicationId = $this->applicationIdsOn($I, $theirs)[0];

        // Posted through MINE's route, naming THEIRS' claim.
        $this->postMove($I, $mine, $foreignApplicationId, (string) $target);
        $I->see('That payment does not belong to invoice ' . $this->documentNumber($I, $mine));

        $I->assertSame($theirs, (int) $this->applicationRow($I, $foreignApplicationId)['invoice_id'], 'a forged claim id moved another invoice\'s row');
        $I->assertSame([$foreignApplicationId], $this->applicationIdsOn($I, $theirs), 'the other invoice lost its claim to a forged move');
        $I->assertSame([], $this->applicationIdsOn($I, $target), 'the target gained a claim it was never legally sent');
        $I->assertSame('Partially Paid', $this->paymentStatus($I, $theirs), 'the other invoice status changed on a forged move');
        $I->assertSame('Not Paid', $this->paymentStatus($I, $target), 'the target invoice status changed on a forged move');
    }
}
