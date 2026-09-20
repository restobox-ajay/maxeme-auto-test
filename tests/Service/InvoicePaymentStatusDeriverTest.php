<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoicePayment;
use App\Enum\InvoicePaymentStatus;
use App\Service\DocumentActor;
use App\Service\InvoicePaymentStatusDeriver;
use App\Status\CoreStatusVocabularyProvider;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\TestCase;

/**
 * What "payment status is derived" means, one rule per test (#539 stage 4).
 *
 * A plain unit test, for the reason SalesOrderStatusDeriverTest is one: the rules are arithmetic
 * over an invoice's own payment rows, and a database would add nothing but a way for the rules and
 * the fixtures to disagree about what was flushed.
 */
final class InvoicePaymentStatusDeriverTest extends TestCase
{
    private InvoicePaymentStatusDeriver $deriver;

    protected function setUp(): void
    {
        // No kernel here, and the fixtures below move an invoice's OTHER status — the seam one —
        // through the gate, which asks the vocabulary what exists. Primed with the real shipped
        // provider, as StatusVocabularyRegistry::loader()'s own message instructs.
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new CoreStatusVocabularyProvider()]));

        $this->deriver = new InvoicePaymentStatusDeriver();
    }

    protected function tearDown(): void
    {
        StatusVocabularyRegistry::reset();
    }

    public function testAnInvoiceWithNoPaymentsIsNotPaid(): void
    {
        $invoice = $this->invoice('100.00');

        self::assertSame(InvoicePaymentStatus::NotPaid, $this->deriver->statusFor($invoice));
    }

    public function testSomeOfTheTotalIsPartiallyPaid(): void
    {
        $invoice = $this->invoice('100.00');
        $this->pay($invoice, '40.00');

        self::assertSame(InvoicePaymentStatus::PartiallyPaid, $this->deriver->statusFor($invoice));
    }

    public function testSeveralPaymentsAddUp(): void
    {
        $invoice = $this->invoice('100.00');
        $this->pay($invoice, '40.00');
        $this->pay($invoice, '60.00');

        self::assertSame(InvoicePaymentStatus::Paid, $this->deriver->statusFor($invoice));
    }

    public function testAnOverpaymentIsStillPaid(): void
    {
        $invoice = $this->invoice('100.00');
        $this->pay($invoice, '120.00');

        self::assertSame(InvoicePaymentStatus::Paid, $this->deriver->statusFor($invoice));
        self::assertSame('-20.00', $invoice->getBalance());
    }

    public function testAnInvoiceOwingNothingIsBornPaid(): void
    {
        // Nothing is owed, so nothing can be outstanding. The alternative — Not Paid forever —
        // would hold its order open at Invoiced on a balance nobody can settle.
        self::assertSame(InvoicePaymentStatus::Paid, $this->deriver->statusFor($this->invoice('0.00')));
    }

    public function testCentsAreComparedAsCentsRatherThanFloats(): void
    {
        $invoice = $this->invoice('0.30');
        $this->pay($invoice, '0.10');
        $this->pay($invoice, '0.20');

        // 0.1 + 0.2 !== 0.3 in binary floating point, which is exactly why the comparison is done in
        // whole cents. Read as floats this invoice is a cent short forever.
        self::assertSame(InvoicePaymentStatus::Paid, $this->deriver->statusFor($invoice));
    }

    public function testACancelledInvoiceStillReportsWhatActuallyHappenedToItsMoney(): void
    {
        $invoice = $this->invoice('100.00');
        $invoice->setStatus('Cancelled', DocumentActor::system());

        // The status is about money and nobody paid, so it says so. isFullyPaid() answers the other
        // question — is anything still owed — and a cancelled invoice is owed nothing.
        self::assertSame(InvoicePaymentStatus::NotPaid, $this->deriver->statusFor($invoice));
        self::assertTrue($invoice->isFullyPaid());
    }

    public function testRecalculateWritesTheTransitionOntoTheTimeline(): void
    {
        $invoice = $this->invoice('100.00');
        $this->pay($invoice, '100.00');
        $before = $invoice->getLogs()->count();

        self::assertTrue($this->deriver->recalculate($invoice));
        self::assertSame(InvoicePaymentStatus::Paid, $invoice->getPaymentStatus());
        self::assertCount($before + 1, $invoice->getLogs());
        self::assertSame(
            'Payment status changed from Not Paid to Paid.',
            $invoice->getLogs()->last()->getComment(),
        );
    }

    public function testRecalculateIsSilentWhenNothingMoved(): void
    {
        $invoice = $this->invoice('100.00');
        $before = $invoice->getLogs()->count();

        self::assertFalse($this->deriver->recalculate($invoice));
        self::assertCount($before, $invoice->getLogs(), 'A no-op recalculation must not write a timeline entry.');
    }

    private function invoice(string $total): Invoice
    {
        // Issued, because since #31 only an issued invoice takes a payment: a draft has been sent
        // to nobody, so nobody could have paid it. What is derived from the rows is the same either
        // way — this fixture predates that rule rather than depending on it.
        return (new Invoice())
            ->setCompany((new Company())->setName('Acme Co')->setCode('ACME'))
            ->setDocumentNumber('INV-1')
            ->setTotal($total)
            ->issue(DocumentActor::system());
    }

    private function pay(Invoice $invoice, string $amount): InvoicePayment
    {
        $payment = (new InvoicePayment())->setMethod('Bank Transfer')->setAmount($amount);
        $invoice->recordPayment(DocumentActor::system(), $payment);

        return $payment;
    }
}
