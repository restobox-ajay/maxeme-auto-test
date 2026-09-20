<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePayment;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\CreditMemoStatus;
use App\Enum\InvoicePaymentStatus;
use App\Enum\InvoiceStatus;
use App\Service\DocumentActor;
use App\Status\CoreStatusVocabularyProvider;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every refusal `Invoice::cancel()`, `Invoice::complete()` and `CreditMemo::void()` carried,
 * re-asked at the one gate.
 *
 * Three verbs were deleted in one change, which is the shape that loses a rule quietly: the method
 * that refused something stops existing, and whether the refusal survived is invisible unless
 * somebody wrote it down. So this is the "before" list, enumerated from the verb bodies, each one
 * re-asked through `setStatus()`.
 *
 * | # | what refused it then | the refusal |
 * |---|---|---|
 * | 1 | `cancel()`'s `!$this->payments->isEmpty()` | "Invoice INV-1 has 1 payment(s) … cannot be cancelled." |
 * | 2 | `cancel()`'s `...$allowedFrom` (no Cancelled) | "An invoice cannot go from Cancelled to Cancelled …" |
 * | 3 | `complete()`'s `...$allowedFrom` (Processing) | "An invoice cannot go from Draft to Completed …" |
 * | 4 | `assertRoomToIssue()` inside `issue()` | "Invoice INV-2 cannot be issued. Widget: 10 requested …" |
 * | 5 | `void()`'s `isStatus(Void)` | "Credit note CN-1 is already void." |
 * | 6 | `void()`'s applied/refunded check | "Credit note CN-1 has $40.00 applied … cannot be voided." |
 * | 7 | `issue()`'s `!isStatus(Draft)` | "Credit note CN-1 is Open; only a draft can be issued." |
 * | 8 | `issue()`'s zero-total check | "Credit note CN-1 has a total of $0.00 …" |
 *
 * And the things that must NOT have started refusing: the four surviving verbs still work and still
 * answer with their OWN narrower from-lists, the credit note's balance still drives Open and Closed
 * through the second door, and `$paymentStatus` is untouched.
 */
final class InvoiceAndCreditMemoStatusSeamTest extends TestCase
{
    protected function setUp(): void
    {
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new CoreStatusVocabularyProvider()]));
    }

    protected function tearDown(): void
    {
        StatusVocabularyRegistry::reset();
    }

    private static function actor(): DocumentActor
    {
        return DocumentActor::system();
    }

    /*
     * ----------------------------------------------------------------------------------------
     * 1. The headline: an invoice holding money cannot be cancelled THROUGH THE GATE
     * ----------------------------------------------------------------------------------------
     */

    /**
     * This is the case that kept Invoice off the seam, and the one the gate had to absorb.
     *
     * A public setter beside a guarded `cancel()` is two doors and the unguarded one wins — which is
     * exactly what a trial wiring produced: `cancel()` refused, `setStatus()` wrote "Cancelled", and
     * the payment rows stayed attached. Invoice's own docblock says that state cannot exist.
     */
    public function testAnInvoiceHoldingAPaymentCannotBeCancelledThroughTheGate(): void
    {
        $invoice = $this->paidInvoice('INV-1', '10.00');

        try {
            $invoice->setStatus('Cancelled', self::actor());
            self::fail('an invoice with a payment against it must not be cancellable by any route');
        } catch (\DomainException $refusal) {
            self::assertSame(
                'Invoice INV-1 has 1 payment(s) against it totalling $10.00 and cannot be cancelled.'
                . ' Delete them on this invoice\'s Payments screen first.',
                $refusal->getMessage(),
            );
        }

        self::assertSame('Pending', $invoice->getStatus(), 'and nothing was written');
        self::assertCount(1, $invoice->getApplications(), 'and the payment is still attached');
    }

    /** The refusal counts and sums the rows rather than reporting the first one. */
    public function testTheRefusalNamesEveryPaymentAndTheirTotal(): void
    {
        $invoice = $this->paidInvoice('INV-1', '10.00');
        $invoice->recordPayment(self::actor(), (new InvoicePayment())->setAmount('15.50')->setMethod('EFT'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Invoice INV-1 has 2 payment(s) against it totalling $25.50');

        $invoice->setStatus('Cancelled', self::actor());
    }

    /** The other half of the pair: a cancelled invoice cannot then take a payment. */
    public function testTheGuardStillExistsOnBothSides(): void
    {
        $invoice = (new Invoice())->setDocumentNumber('INV-1')->setCompany((new Company())->setName('Acme Wholesale'));
        $invoice->issue(self::actor());
        $invoice->setStatus('Cancelled', self::actor());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('is cancelled; it is owed nothing and cannot take a payment');

        $invoice->recordPayment(self::actor(), (new InvoicePayment())->setAmount('10.00')->setMethod('Cash'));
    }

    /*
     * ----------------------------------------------------------------------------------------
     * 2-4. The rest of the invoice "before" list
     * ----------------------------------------------------------------------------------------
     */

    /** An invoice with no money against it is still withdrawable from every live state. */
    #[DataProvider('everyLiveStatus')]
    public function testAnInvoiceWithoutPaymentsIsStillCancellableFromEveryLiveState(\Closure $reach): void
    {
        $invoice = new Invoice();
        $reach($invoice);

        self::assertSame('Cancelled', $invoice->setStatus('Cancelled', self::actor()));
    }

    /** @return iterable<string, array{0: \Closure}> */
    public static function everyLiveStatus(): iterable
    {
        yield 'Draft' => [static fn (Invoice $i) => null];
        yield 'On Hold' => [static fn (Invoice $i) => $i->issueAwaitingPayment(self::actor())];
        yield 'Pending' => [static fn (Invoice $i) => $i->issue(self::actor())];
        yield 'Processing' => [static fn (Invoice $i) => $i->issue(self::actor())->startProcessing(self::actor())];
        yield 'Completed' => [
            static fn (Invoice $i) => $i->issue(self::actor())->startProcessing(self::actor())
                ->setStatus('Completed', self::actor()),
        ];
    }

    /**
     * Cancelling twice is a mistake, not a no-op — and that is why the gate runs a document's own
     * rules BEFORE its no-op short-circuit.
     */
    public function testCancellingATwiceIsLoudRatherThanSilent(): void
    {
        $invoice = new Invoice();
        $invoice->setStatus('Cancelled', self::actor());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('An invoice cannot go from Cancelled to Cancelled');

        $invoice->setStatus('Cancelled', self::actor());
    }

    /** `complete()`'s from-rule, unchanged: only a Processing invoice is completed. */
    #[DataProvider('statusesThatCannotComplete')]
    public function testOnlyAProcessingInvoiceCompletes(\Closure $reach, string $expectedFrom): void
    {
        $invoice = new Invoice();
        $reach($invoice);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(sprintf('An invoice cannot go from %s to Completed', $expectedFrom));

        $invoice->setStatus('Completed', self::actor());
    }

    /** @return iterable<string, array{0: \Closure, 1: string}> */
    public static function statusesThatCannotComplete(): iterable
    {
        yield 'Draft' => [static fn (Invoice $i) => null, 'Draft'];
        yield 'On Hold' => [static fn (Invoice $i) => $i->issueAwaitingPayment(self::actor()), 'On Hold'];
        yield 'Pending' => [static fn (Invoice $i) => $i->issue(self::actor()), 'Pending'];
        yield 'Cancelled' => [static fn (Invoice $i) => $i->setStatus('Cancelled', self::actor()), 'Cancelled'];
    }

    /**
     * The over-invoicing guard runs on the GATE, not only inside `issue()`.
     *
     * It used to be safe inside `issue()` because `transitionTo()` was private and there was no
     * setter. There is one now, and `setStatus('Pending', ...)` on a draft IS issuing.
     */
    public function testTheOverInvoicingGuardCannotBeDodgedByUsingTheGate(): void
    {
        $order = (new SalesOrder())->setOrderNumber('SO-1');
        $line = (new SalesOrderLine())->setName('Widget')->setQuantity('10.00')->setPrice('5.00');
        $order->addLine($line);

        $first = $this->invoiceFor($order, $line, 'INV-1', '10.00');
        $first->issue(self::actor());

        $second = $this->invoiceFor($order, $line, 'INV-2', '10.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Invoice INV-2 cannot be issued. Widget: 10 requested but only 0 left to invoice',
        );

        $second->setStatus('Pending', self::actor());
    }

    /** Nothing ever wrote Draft, so the gate does not become the first thing that can. */
    public function testAnIssuedInvoiceCannotBePutBackToDraft(): void
    {
        $invoice = (new Invoice())->setDocumentNumber('INV-1');
        $invoice->issue(self::actor());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Invoice INV-1 cannot be put back to Draft');

        $invoice->setStatus('Draft', self::actor());
    }

    /*
     * ----------------------------------------------------------------------------------------
     * What must NOT have started refusing, and what must still be SAID
     * ----------------------------------------------------------------------------------------
     */

    /**
     * The surviving verbs keep their OWN from-lists, which are narrower than the gate's union.
     *
     * Pending is reachable from Draft (`issue()`) and from On Hold (`paymentReceived()`), so the
     * gate has to accept both. Each verb still accepts only its own, and still says which.
     */
    public function testTheSurvivingVerbsStillAnswerWithTheirOwnAllowedFrom(): void
    {
        $onHold = new Invoice();
        $onHold->issueAwaitingPayment(self::actor());

        try {
            $onHold->issue(self::actor());
            self::fail('issue() takes a Draft and nothing else');
        } catch (\DomainException $refusal) {
            self::assertStringContainsString('from On Hold to Pending (allowed from: Draft)', $refusal->getMessage());
        }

        try {
            (new Invoice())->paymentReceived(self::actor());
            self::fail('paymentReceived() takes an On Hold invoice and nothing else');
        } catch (\DomainException $refusal) {
            self::assertStringContainsString('from Draft to Pending (allowed from: On Hold)', $refusal->getMessage());
        }

        // And the gate itself accepts both, because it is asked for a target rather than a verb.
        self::assertSame('Pending', $onHold->setStatus('Pending', self::actor()));
    }

    /** The timeline still says what the verbs said, for a caller that names only the target. */
    public function testTheGateWritesTheSentencesTheVerbsWrote(): void
    {
        $invoice = new Invoice();
        $invoice->issue(self::actor());
        $invoice->startProcessing(self::actor());
        $invoice->setStatus('Completed', self::actor());

        self::assertSame('Fulfilment completed.', $invoice->getLogs()->last()->getComment());

        $invoice->setStatus('Cancelled', self::actor());
        self::assertSame('Invoice cancelled.', $invoice->getLogs()->last()->getComment());

        $onHold = new Invoice();
        $onHold->issueAwaitingPayment(self::actor());
        $onHold->setStatus('Pending', self::actor());
        self::assertSame(
            'Payment received; invoice released for fulfilment.',
            $onHold->getLogs()->last()->getComment(),
            'arriving at Pending from On Hold is paymentReceived(), and says so',
        );
    }

    /** A caller with a reason composes the whole comment, as SalesOrder's void reason already does. */
    public function testACallerWithAReasonPassesTheWholeComment(): void
    {
        $invoice = new Invoice();
        $invoice->setStatus(
            'Cancelled',
            DocumentActor::named('Priya'),
            'Invoice cancelled: customer changed their mind.',
        );

        $entry = $invoice->getLogs()->last();
        self::assertSame('Invoice cancelled: customer changed their mind.', $entry->getComment());
        self::assertSame('Priya', $entry->getUserName(), 'and the gate signs the row');
        self::assertSame('System', $entry->getType());
    }

    /**
     * An invoice derives nothing on this axis, and `$paymentStatus` is a different axis entirely.
     *
     * The invoice vocabulary flags no status `derived`, so there is nothing `applyDerivedStatus()`
     * would be allowed to write even if something computed it. The money question keeps its own
     * column and its own deriver.
     */
    public function testTheInvoiceDerivesNothingAndItsPaymentStatusIsSeparate(): void
    {
        $invoice = $this->paidInvoice('INV-1', '10.00');

        self::assertNull($invoice->deriveStatus());
        self::assertFalse($invoice->applyDerivedStatus(self::actor()));
        self::assertSame('Pending', $invoice->getStatus());

        self::assertSame([], array_filter(
            Invoice::loadStatusVocab()->slugs(),
            static fn (string $slug): bool => Invoice::loadStatusVocab()->isDerived($slug),
        ), 'no invoice status is derived, which is what makes the null above the only legal answer');

        self::assertTrue($invoice->applyDerivedPaymentStatus(InvoicePaymentStatus::Paid), 'the other axis still moves');
        self::assertSame('Pending', $invoice->getStatus(), 'and moving it does not touch this one');
    }

    /*
     * ----------------------------------------------------------------------------------------
     * The credit note
     * ----------------------------------------------------------------------------------------
     */

    /** `void()`'s applied/refunded refusal, at the gate, word for word. */
    public function testANoteWithCreditSpentAgainstItCannotBeVoidedThroughTheGate(): void
    {
        $company = new Company();
        $memo = $this->openNote($company, 'CN-1', '100.00');
        $invoice = (new Invoice())->setDocumentNumber('INV-1')->setCompany($company)->setTotal('100.00');
        $invoice->issue(self::actor());
        $memo->applyTo($invoice, '40.00');

        try {
            $memo->setStatus('Void', self::actor());
            self::fail('a note with an application against it must not be voidable by any route');
        } catch (\DomainException $refusal) {
            self::assertSame(
                'Credit note CN-1 has $40.00 applied and $0.00 refunded against it and cannot be voided.'
                . ' Withdraw its applications and delete its refunds first.',
                $refusal->getMessage(),
            );
        }

        self::assertSame('Open', $memo->getStatus(), 'and nothing was written');
    }

    /** Voiding a void note was a loud mistake when `void()` owned the rule, and still is. */
    public function testVoidingAVoidNoteIsLoudRatherThanSilent(): void
    {
        $memo = $this->openNote(new Company(), 'CN-1', '100.00');
        $memo->setStatus('Void', self::actor());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Credit note CN-1 is already void.');

        $memo->setStatus('Void', self::actor());
    }

    /** `issue()`'s two guards, in the order it asked them, now that both are on the gate. */
    public function testOnlyADraftWithSomethingToCreditIsIssued(): void
    {
        $empty = (new CreditMemo())->setCompany(new Company());
        $empty->setDocumentNumber('CN-1');

        try {
            $empty->issue();
            self::fail('a note with nothing in it has nothing to credit');
        } catch (\DomainException $refusal) {
            self::assertSame(
                'Credit note CN-1 has a total of $0.00. There is nothing to credit.',
                $refusal->getMessage(),
            );
        }

        $open = $this->openNote(new Company(), 'CN-2', '100.00');

        try {
            $open->issue();
            self::fail('only a draft is issued');
        } catch (\DomainException $refusal) {
            self::assertSame('Credit note CN-2 is Open; only a draft can be issued.', $refusal->getMessage());
        }

        // And the status check comes FIRST, so an open note with a zero total is told it is open.
        $openAndEmpty = $this->openNote(new Company(), 'CN-3', '100.00');
        $openAndEmpty->setTotal('0.00');
        $this->expectExceptionMessage('Credit note CN-3 is Open; only a draft can be issued.');
        $openAndEmpty->issue();
    }

    /** Open and Closed are derived from the balance; the gate refuses to have them typed in. */
    public function testTheBalanceDecidesOpenAndClosedAndNobodyElseDoes(): void
    {
        $company = new Company();
        $memo = $this->openNote($company, 'CN-1', '100.00');
        $invoice = (new Invoice())->setDocumentNumber('INV-1')->setCompany($company)->setTotal('100.00');
        $invoice->issue(self::actor());

        self::assertSame('Open', $memo->deriveStatus(), 'nothing spent, so there is still credit here');

        $memo->applyTo($invoice, '100.00');
        self::assertSame('Closed', $memo->getStatus(), 'spending it all closes it, through settle()');
        self::assertSame('Closed', $memo->deriveStatus());

        $memo->withdrawApplication($memo->getApplications()->first());
        self::assertSame('Open', $memo->getStatus(), 'and taking it back re-opens it');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Credit note CN-1 cannot be set to Closed by hand');

        $memo->setStatus('Closed', self::actor());
    }

    /**
     * A voided note is not resurrected by a recalculation.
     *
     * Its balance equals its total, so a naive "balance > 0 means Open" would reopen it. `settle()`
     * refused that with an early return; `deriveStatus()` refuses it by answering null.
     */
    public function testADraftAndAVoidNoteDeriveNothing(): void
    {
        $draft = (new CreditMemo())->setCompany(new Company());
        $draft->setDocumentNumber('CN-1')->setTotal('100.00');
        self::assertNull($draft->deriveStatus());
        self::assertFalse($draft->applyDerivedStatus(self::actor()));
        self::assertSame('Draft', $draft->getStatus());

        $void = $this->openNote(new Company(), 'CN-2', '100.00');
        $void->setStatus('Void', self::actor());
        self::assertNull($void->deriveStatus());
        self::assertFalse($void->applyDerivedStatus(self::actor()));
        self::assertSame('Void', $void->getStatus());
    }

    /*
     * ----------------------------------------------------------------------------------------
     * The verbs are GONE, not hidden
     * ----------------------------------------------------------------------------------------
     */

    /**
     * Deleted outright rather than made private.
     *
     * Private would satisfy the reachability rule, but `NoStatusVerbIsReachableCest` collects its
     * verb names with `method_exists()`, which sees private methods too — so a surviving private
     * `cancel()` would put "cancel" back into the set of names it scans every production file for,
     * and flag unrelated `->cancel()` calls on purchase orders and RFQs.
     */
    public function testTheThreeStatusNamedVerbsNoLongerExistAtAll(): void
    {
        self::assertFalse(method_exists(Invoice::class, 'cancel'), 'Invoice::cancel() is deleted');
        self::assertFalse(method_exists(Invoice::class, 'complete'), 'Invoice::complete() is deleted');
        self::assertFalse(method_exists(CreditMemo::class, 'void'), 'CreditMemo::void() is deleted');

        foreach (['issue', 'issueAwaitingPayment', 'paymentReceived', 'startProcessing'] as $kept) {
            self::assertTrue(
                (new \ReflectionMethod(Invoice::class, $kept))->isPublic(),
                $kept . '() is named after no invoice status and stays public',
            );
        }

        self::assertTrue((new \ReflectionMethod(CreditMemo::class, 'issue'))->isPublic());
    }

    /*
     * ----------------------------------------------------------------------------------------
     * Fixtures
     * ----------------------------------------------------------------------------------------
     */

    private function paidInvoice(string $number, string $amount): Invoice
    {
        $invoice = (new Invoice())->setDocumentNumber($number)->setCompany((new Company())->setName('Acme Wholesale'));
        $invoice->issue(self::actor());
        $invoice->recordPayment(self::actor(), (new InvoicePayment())->setAmount($amount)->setMethod('Cash'));

        return $invoice;
    }

    private function invoiceFor(SalesOrder $order, SalesOrderLine $line, string $number, string $quantity): Invoice
    {
        $invoice = (new Invoice())->setDocumentNumber($number);
        $order->addInvoice($invoice);
        $invoice->addLine(
            (new InvoiceLine())->setSalesOrderLine($line)->setName('Widget')->setQuantity($quantity)->setPrice('5.00'),
        );

        return $invoice;
    }

    private function openNote(Company $company, string $number, string $total): CreditMemo
    {
        $memo = (new CreditMemo())->setCompany($company);
        $memo->setDocumentNumber($number)->setTotal($total);
        $memo->issue();

        return $memo;
    }
}
