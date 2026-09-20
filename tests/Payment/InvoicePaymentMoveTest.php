<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoicePayment;
use App\Enum\InvoicePaymentStatus;
use App\Payment\PaymentApplicationGuard;
use App\Service\DocumentActor;
use App\Service\InvoicePaymentStatusDeriver;
use App\Status\CoreStatusVocabularyProvider;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules a move has to pass, and what a move does to the two documents (queue item 34, #708) —
 * the sell-side mirror of `ProcurementBundle\Tests\Payment\PaymentMoveTest`.
 *
 * A pure unit test — no database — for the same reason the buy side's is: every rule here is a
 * decision made in memory before anything is written. Recalculation is exercised directly through
 * {@see InvoicePaymentStatusDeriver}, the same engine `InvoicePaymentStatusSubscriber` calls at
 * flush, rather than through a kernel test — `moveApplication()` itself does not call it, on purpose
 * (see its docblock), so a test of the move alone would otherwise assert nothing about status at all.
 */
final class InvoicePaymentMoveTest extends TestCase
{
    protected function setUp(): void
    {
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new CoreStatusVocabularyProvider()]));
    }

    protected function tearDown(): void
    {
        StatusVocabularyRegistry::reset();
    }

    private function company(string $name = 'Acme Wholesale'): Company
    {
        return (new Company())->setName($name);
    }

    private function invoice(Company $company, string $number, string $total = '900.00'): Invoice
    {
        $invoice = (new Invoice())
            ->setDocumentNumber($number)
            ->setTotal($total);
        $invoice->setCompany($company);
        $invoice->issue(DocumentActor::system());

        return $invoice;
    }

    private function payment(string $amount = '900.00'): InvoicePayment
    {
        return (new InvoicePayment())
            ->setReceivedAt(new \DateTimeImmutable('2026-09-15'))
            ->setMethod('Cheque')
            ->setAmount($amount)
            ->setComment('Cheque 8801');
    }

    /**
     * The move itself: same underlying payment, both documents' derived payment status moves in
     * opposite directions, two timeline entries.
     */
    public function testAMovePreservesThePaymentAndBothStatusesDeriveCorrectlyAfterwards(): void
    {
        $deriver = new InvoicePaymentStatusDeriver();
        $company = $this->company();
        $losing = $this->invoice($company, 'INV-1');
        $gaining = $this->invoice($company, 'INV-2');

        $payment = $this->payment();
        $losing->recordPayment(DocumentActor::system(), $payment);
        $application = $losing->getApplications()->first();
        $deriver->recalculate($losing);
        self::assertSame(InvoicePaymentStatus::Paid, $losing->getPaymentStatus());
        self::assertSame(InvoicePaymentStatus::NotPaid, $gaining->getPaymentStatus());

        $losing->moveApplication(DocumentActor::named('Priya'), $application, $gaining, 'Wrong invoice');
        $deriver->recalculate($losing);
        $deriver->recalculate($gaining);

        // The SAME underlying payment, not a copy of it. Identity is the whole feature — the claim
        // row itself is free to be a new one, since queue item 34 never promised that row's id, only
        // the payment's date, method, reference and actor.
        self::assertCount(0, $losing->getApplications());
        self::assertCount(1, $gaining->getApplications());
        $newApplication = $gaining->getApplications()->first();
        self::assertSame($payment, $newApplication->getPayment());
        self::assertSame($gaining, $newApplication->getDocument());
        self::assertSame('2026-09-15', $newApplication->getAppliedAt()->format('Y-m-d'));
        self::assertSame('Cheque', $payment->getMethod());
        self::assertSame('Cheque 8801', $payment->getComment());

        self::assertSame(InvoicePaymentStatus::NotPaid, $losing->getPaymentStatus(), 'the invoice that lost the money is still Paid');
        self::assertSame(InvoicePaymentStatus::Paid, $gaining->getPaymentStatus(), 'the invoice that gained it did not become Paid');
        self::assertSame('900.00', $losing->getBalance());
        self::assertSame('0.00', $gaining->getBalance());

        $lost = $this->logNamed($losing, 'moved to invoice INV-2');
        self::assertNotNull($lost, "the losing invoice's timeline does not say where the payment went");
        self::assertStringContainsString('Wrong invoice', $lost);
        self::assertSame('Priya', $this->logUserFor($losing, 'moved to invoice INV-2'));

        $gained = $this->logNamed($gaining, 'moved in from invoice INV-1');
        self::assertNotNull($gained, "the gaining invoice's timeline does not say where the payment came from");
        self::assertStringContainsString('2026-09-15', $gained, 'the receiving timeline does not record the original date the money arrived');
    }

    /** The first timeline comment on $invoice containing $needle, or null. */
    private function logNamed(Invoice $invoice, string $needle): ?string
    {
        foreach ($invoice->getLogs() as $log) {
            if (str_contains((string) $log->getComment(), $needle)) {
                return (string) $log->getComment();
            }
        }

        return null;
    }

    private function logUserFor(Invoice $invoice, string $needle): ?string
    {
        foreach ($invoice->getLogs() as $log) {
            if (str_contains((string) $log->getComment(), $needle)) {
                return $log->getUserName();
            }
        }

        return null;
    }

    /** @return array<string, array{string, \Closure(Company): Invoice}> */
    public static function refusedTargets(): array
    {
        return [
            'a cancelled invoice' => ['is cancelled; it is owed nothing', static function (Company $company): Invoice {
                $invoice = (new Invoice())->setDocumentNumber('INV-CANCELLED')->setTotal('900.00');
                $invoice->setCompany($company);
                $invoice->issue(DocumentActor::system());
                $invoice->setStatus('Cancelled', DocumentActor::system());

                return $invoice;
            }],
            'a draft invoice' => ['still a draft; it has not been issued', static function (Company $company): Invoice {
                $invoice = (new Invoice())->setDocumentNumber('INV-DRAFT')->setTotal('900.00');
                $invoice->setCompany($company);

                return $invoice;
            }],
            'another company' => ["would leave both parties' balances wrong", static function (): Invoice {
                $stranger = (new Company())->setName('Other Wholesale');
                $invoice = (new Invoice())->setDocumentNumber('INV-OTHER')->setTotal('900.00');
                $invoice->setCompany($stranger);
                $invoice->issue(DocumentActor::system());

                return $invoice;
            }],
        ];
    }

    /**
     * The attribute and not the `@dataProvider` annotation — see the buy side's identical note:
     * PHPUnit 12 no longer reads metadata out of doc comments.
     *
     * @param \Closure(Company): Invoice $makeTarget
     */
    #[DataProvider('refusedTargets')]
    public function testAnIllegalTargetIsRefusedAndNothingMoves(string $expected, \Closure $makeTarget): void
    {
        $company = $this->company();
        $source = $this->invoice($company, 'INV-1');
        $target = $makeTarget($company);

        $payment = $this->payment();
        $source->recordPayment(DocumentActor::system(), $payment);
        $application = $source->getApplications()->first();
        $logsBefore = $source->getLogs()->count();

        try {
            $source->moveApplication(DocumentActor::system(), $application, $target);
            self::fail('the move onto ' . $target->getDocumentLabel() . ' was allowed');
        } catch (\DomainException $e) {
            self::assertStringContainsString($expected, $e->getMessage());
            self::assertStringContainsString($target->getDocumentLabel(), $e->getMessage());
        }

        self::assertCount(1, $source->getApplications(), 'the claim left the source despite the refusal');
        self::assertSame($source, $application->getDocument(), 'the claim was re-pointed despite the refusal');
        self::assertCount(0, $target->getApplications(), 'the target gained a claim despite the refusal');
        self::assertSame($logsBefore, $source->getLogs()->count(), 'a refused move still wrote a timeline entry');
    }

    public function testAMoveOntoTheSameInvoiceIsRefusedRatherThanSilentlyDoingNothing(): void
    {
        $company = $this->company();
        $invoice = $this->invoice($company, 'INV-1');
        $payment = $this->payment();
        $invoice->recordPayment(DocumentActor::system(), $payment);
        $application = $invoice->getApplications()->first();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('That payment is already on INV-1.');

        $invoice->moveApplication(DocumentActor::system(), $application, $invoice);
    }

    public function testAPaymentOnAnotherInvoiceIsRefusedBeforeAnyRuleReadsTheTarget(): void
    {
        $company = $this->company();
        $mine = $this->invoice($company, 'INV-1');
        $theirs = $this->invoice($company, 'INV-2');
        $target = $this->invoice($company, 'INV-3');

        $payment = $this->payment();
        $theirs->recordPayment(DocumentActor::system(), $payment);
        $application = $theirs->getApplications()->first();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('That payment does not belong to invoice INV-1.');

        $mine->moveApplication(DocumentActor::system(), $application, $target);
    }

    /**
     * Overpayment is allowed and announced, rather than refused — same argument as the buy side's,
     * same guard.
     */
    public function testOverpayingTheTargetIsAllowedAndAnnouncedInMoney(): void
    {
        $deriver = new InvoicePaymentStatusDeriver();
        $company = $this->company();
        $source = $this->invoice($company, 'INV-1');
        $small = $this->invoice($company, 'INV-2', '100.00');

        $payment = $this->payment('900.00');
        $source->recordPayment(DocumentActor::system(), $payment);
        $application = $source->getApplications()->first();
        $source->moveApplication(DocumentActor::system(), $application, $small);
        $deriver->recalculate($small);

        self::assertSame(InvoicePaymentStatus::Paid, $small->getPaymentStatus());
        self::assertSame('-800.00', $small->getBalance());

        $notice = PaymentApplicationGuard::overpaymentNotice($small);
        self::assertNotNull($notice);
        self::assertStringContainsString('INV-2 is now OVERPAID by the base currency 800.00', $notice);
        self::assertStringContainsString('the base currency 900.00 applied against a total of the base currency 100.00', $notice);

        self::assertNull(PaymentApplicationGuard::overpaymentNotice($source), 'an invoice with nothing paid against it is not overpaid');
    }

    /**
     * Two unsaved companies are two counterparties — the sell-side reading of the buy side's
     * identical test, and for the identical reason: `getPaymentCounterpartyKey()` reads an id that
     * is null until the row is persisted.
     */
    public function testTwoUnsavedCompaniesAreNotTheSameCounterparty(): void
    {
        $one = $this->invoice($this->company('A'), 'INV-1');
        $two = $this->invoice($this->company('B'), 'INV-2');

        self::assertNotSame($one->getPaymentCounterpartyKey(), $two->getPaymentCounterpartyKey());
    }
}
