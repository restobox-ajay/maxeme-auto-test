<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\CreditMemoRefund;
use App\Entity\Invoice;
use App\Service\DocumentActor;
use App\Status\CoreStatusVocabularyProvider;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The credit note's balance, and the status that follows it (#586).
 *
 * A credit note is a document with a BALANCE, not a negative invoice. Its balance is
 *
 *     total − SUM(applications) − SUM(refunds)
 *
 * and it can reach zero three ways: entirely by applying it to invoices, entirely by refunding it,
 * or by a mix. All three are tested here, and each asserts the STATUS as well as the figure, because
 * the pair is the invariant — Open means "there is still credit here" and nothing is allowed to make
 * one of those true without the other.
 *
 * Plain entities, no database. Everything under test is arithmetic and a state machine, and both are
 * the entity's own; the persistence side is covered by the reservation and functional tests.
 */
final class CreditMemoBalanceTest extends TestCase
{
    private Company $company;

    /**
     * No kernel here, so the vocabulary registry is primed by hand with the real shipped provider —
     * the same thing `EstimateStatusSeamTest` does, for the reason
     * `StatusVocabularyRegistry::loader()`'s own message gives. It is needed now because this
     * document's status moves go through the seam's gate, which asks the vocabulary what exists.
     */
    protected function setUp(): void
    {
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new CoreStatusVocabularyProvider()]));

        $this->company = (new Company())->setName('Balance Test Co');
    }

    protected function tearDown(): void
    {
        // Static state outlives a test; leaking a loader into the next case is the hazard
        // StatusVocabularyRegistryTest names.
        StatusVocabularyRegistry::reset();
    }

    public function testAnIssuedNoteOpensWithItsWholeTotalAsBalance(): void
    {
        $memo = $this->openNote('100.00');

        self::assertSame('100.00', $memo->getBalance(), 'a note nobody has spent anything from holds its whole total');
        self::assertSame('Open', $memo->getStatus(), 'and is Open, because there is credit in it');
    }

    public function testADraftCannotBeAppliedOrRefunded(): void
    {
        $memo = $this->draftNote('50.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only an open note has a balance to apply');

        $memo->applyTo($this->invoice('INV-1'), '10.00');
    }

    public function testANoteWithNoTotalCannotBeIssued(): void
    {
        $memo = $this->draftNote('0.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('There is nothing to credit');

        $memo->issue();
    }

    /**
     * ONE NOTE ACROSS TWO INVOICES. Half of the many-to-many, and the half a design that hangs the
     * allocation off a foreign key cannot express at all.
     */
    public function testOneNoteAppliesAcrossTwoInvoicesAndClosesWhenItRunsOut(): void
    {
        $memo = $this->openNote('100.00');
        $first = $this->invoice('INV-1');
        $second = $this->invoice('INV-2');

        $memo->applyTo($first, '60.00');
        self::assertSame('40.00', $memo->getBalance(), 'sixty of a hundred spent leaves forty');
        self::assertSame('Open', $memo->getStatus(), 'and the note is still Open, because forty is still credit');

        $memo->applyTo($second, '40.00');
        self::assertSame('0.00', $memo->getBalance(), 'the remaining forty goes to the second invoice');
        self::assertSame('Closed', $memo->getStatus(), 'and a note with nothing left is Closed');

        self::assertCount(2, $memo->getApplications(), 'two allocations, because they went to two different documents');
        self::assertSame('100.00', $memo->getAmountApplied(), 'and together they are the whole note');
    }

    /**
     * THE OTHER HALF: two notes credit one invoice. Nothing about either note knows about the other,
     * which is the point — the allocation rows are what relate them, and they relate independently.
     */
    public function testTwoNotesCanBothCreditTheSameInvoice(): void
    {
        $invoice = $this->invoice('INV-SHARED');
        $first = $this->openNote('30.00');
        $second = $this->openNote('45.00');

        $first->applyTo($invoice, '30.00');
        $second->applyTo($invoice, '45.00');

        self::assertSame('Closed', $first->getStatus(), 'the first note spent everything it had');
        self::assertSame('Closed', $second->getStatus(), 'and so did the second');
        self::assertSame($invoice, $first->getApplications()->first()->getInvoice(), 'both allocations name the same invoice');
        self::assertSame($invoice, $second->getApplications()->first()->getInvoice(), 'both allocations name the same invoice');
    }

    public function testANoteCannotApplyMoreThanItHolds(): void
    {
        $memo = $this->openNote('20.00');
        $memo->applyTo($this->invoice('INV-1'), '15.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('It cannot apply $10.00');

        $memo->applyTo($this->invoice('INV-2'), '10.00');
    }

    /**
     * #770: the note's own remaining balance is not the only cap. An invoice that has already paid
     * down or been credited part of its total has that much less left to RECEIVE, whatever the note
     * still has to GIVE.
     */
    public function testANoteCannotApplyMoreThanTheInvoiceHasLeft(): void
    {
        $memo = $this->openNote('1000.00');
        $invoice = (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber('INV-SMALL')
            ->setTotal('50.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Invoice INV-SMALL has a balance of $50.00. It cannot take a $100.00 credit.');

        $memo->applyTo($invoice, '100.00');
    }

    /** The boundary case: exactly the invoice's balance is allowed, one cent more is not. */
    public function testANoteCanApplyExactlyTheInvoicesBalanceButNoMore(): void
    {
        $memo = $this->openNote('1000.00');
        $invoice = (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber('INV-EXACT')
            ->setTotal('50.00');

        $memo->applyTo($invoice, '50.00');
        self::assertSame('0.00', $invoice->getBalance(), 'the invoice is now fully credited');
        self::assertSame('950.00', $memo->getBalance(), 'the note has the rest left for other invoices');

        $second = $this->openNote('10.00');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('It cannot take a $0.01 credit');

        $second->applyTo($invoice, '0.01');
    }

    public function testANoteCannotCreditAnotherCustomer(): void
    {
        $memo = $this->openNote('20.00');
        $somebodyElse = (new Invoice())
            ->setCompany((new Company())->setName('Other Co'))
            ->setDocumentNumber('INV-OTHER')
            ->setTotal('500.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('A credit cannot cross customers');

        $memo->applyTo($somebodyElse, '20.00');
    }

    /** The balance reaches zero from refunds alone, with no invoice involved at all. */
    public function testTheBalanceReachesZeroFromRefundsAlone(): void
    {
        $memo = $this->openNote('75.00');

        $memo->recordRefund($this->refund('40.00'));
        self::assertSame('35.00', $memo->getBalance(), 'a partial refund leaves the rest as credit');
        self::assertSame('Open', $memo->getStatus(), 'so the note stays Open');

        $memo->recordRefund($this->refund('35.00'));
        self::assertSame('0.00', $memo->getBalance(), 'refunding the rest empties it');
        self::assertSame('Closed', $memo->getStatus(), 'and Closed is Closed however the balance left');
        self::assertSame('75.00', $memo->getAmountRefunded(), 'both refunds count');
        self::assertSame('0.00', $memo->getAmountApplied(), 'and nothing was applied to an invoice');
    }

    /** And from a MIX, which is the case a status-per-exit-route design would get wrong. */
    public function testTheBalanceReachesZeroFromAMixOfApplicationsAndRefunds(): void
    {
        $memo = $this->openNote('90.00');

        $memo->applyTo($this->invoice('INV-1'), '30.00');
        $memo->recordRefund($this->refund('25.00'));
        self::assertSame('35.00', $memo->getBalance(), 'ninety less thirty applied less twenty-five refunded');
        self::assertSame('Open', $memo->getStatus(), 'still Open with thirty-five left');

        $memo->recordRefund($this->refund('35.00'));
        self::assertSame('0.00', $memo->getBalance(), 'and the last of it refunded');
        self::assertSame('Closed', $memo->getStatus(), 'Closed, with the balance split between two exit routes');
    }

    /** Withdrawing an allocation puts the credit back and re-opens the note. */
    public function testWithdrawingAnApplicationReopensAClosedNote(): void
    {
        $memo = $this->openNote('50.00');
        $application = $memo->applyTo($this->invoice('INV-1'), '50.00');
        self::assertSame('Closed', $memo->getStatus(), 'guard: fully applied, so Closed');

        $memo->withdrawApplication($application);

        self::assertSame('50.00', $memo->getBalance(), 'the withdrawn credit is available again');
        self::assertSame('Open', $memo->getStatus(), 'so the note is Open again — the status follows the rows, not the other way round');
    }

    public function testAVoidedNoteHoldsNothingAndCreditsNothing(): void
    {
        $memo = $this->openNote('100.00');

        $memo->setStatus('Void', DocumentActor::system());

        self::assertSame('0.00', $memo->getBalance(), 'a voided note holds nothing, whatever its total says');
        self::assertFalse($memo->countsTowardCreditedQuantity(), 'and credits no quantity back to any invoice');
    }

    /**
     * A note with money against it cannot be voided — the same rule Invoice::cancel() applies to an
     * invoice with payments, and for the same reason.
     */
    public function testANoteWithAnApplicationAgainstItRefusesToVoid(): void
    {
        $memo = $this->openNote('100.00');
        $memo->applyTo($this->invoice('INV-1'), '25.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('cannot be voided');

        $memo->setStatus('Void', DocumentActor::system());
    }

    public function testADraftCreditsNothingAndAnIssuedNoteDoes(): void
    {
        $draft = $this->draftNote('10.00');
        self::assertFalse($draft->countsTowardCreditedQuantity(), 'a draft is inert: it is not a document yet');

        $draft->issue();
        self::assertTrue($draft->countsTowardCreditedQuantity(), 'issuing is what makes it credit');
    }

    private function draftNote(string $total): CreditMemo
    {
        return (new CreditMemo())
            ->setCompany($this->company)
            ->setDocumentNumber('CN-' . uniqid())
            ->setDocumentDate('2026-08-23')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
    }

    private function openNote(string $total): CreditMemo
    {
        $memo = $this->draftNote($total);
        $memo->issue();

        return $memo;
    }

    private function invoice(string $number): Invoice
    {
        return (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber($number)
            ->setTotal('500.00');
    }

    private function refund(string $amount): CreditMemoRefund
    {
        return (new CreditMemoRefund())->setMethod('Cheque')->setAmount($amount);
    }
}
