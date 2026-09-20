<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoicePayment;
use App\Enum\InvoiceStatus;
use App\Service\DocumentActor;
use App\Status\CoreStatusVocabularyProvider;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Invoice's state machine: which moves are legal, and what each refusal says.
 *
 * Written when every transition was a named action of its own (#539). The actions named after a
 * status — `cancel()`, `complete()` — are gone, and the moves they made are asked of `setStatus()`
 * here instead; the rules and the sentences are unchanged, which is what these cases pin. What has
 * not changed at all is WHY the rules are on the entity: a bare string setter accepts Cancelled →
 * Completed and nothing objects, and a Doctrine subscriber cannot object either, since by onFlush
 * the change is already made and the caller's intent is gone.
 *
 * No database: these are in-memory rules about one object, and the whole point of putting them on
 * the entity is that they hold before anything is persisted.
 */
final class InvoiceTransitionsTest extends TestCase
{
    /**
     * No kernel here, so the vocabulary registry is primed by hand with the real shipped provider —
     * the same thing `EstimateStatusSeamTest` does, for the reason
     * `StatusVocabularyRegistry::loader()`'s own message gives. It is needed now because this
     * document's status moves go through the seam's gate, which asks the vocabulary what exists.
     */
    protected function setUp(): void
    {
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new CoreStatusVocabularyProvider()]));
    }

    protected function tearDown(): void
    {
        // Static state outlives a test; leaking a loader into the next case is the hazard
        // StatusVocabularyRegistryTest names.
        StatusVocabularyRegistry::reset();
    }

    /**
     * Every action takes the actor to credit its timeline entry to (#539 stage 2). Which actor is
     * irrelevant to a state-machine rule, so these use the unattributed one — the entries
     * themselves are covered elsewhere.
     */
    private static function actor(): DocumentActor
    {
        return DocumentActor::system();
    }

    public function testAnInvoiceStartsAsADraft(): void
    {
        self::assertSame('Draft', (new Invoice())->getStatus());
    }

    public function testTheHappyPathRunsDraftToCompleted(): void
    {
        $invoice = new Invoice();

        self::assertSame('Pending', $invoice->issue(self::actor())->getStatus());
        self::assertSame('Processing', $invoice->startProcessing(self::actor())->getStatus());
        $invoice->setStatus('Completed', self::actor());
        self::assertSame('Completed', $invoice->getStatus());
    }

    /**
     * The abandoned-card-checkout path. On Hold is issued — the customer placed the order — but the
     * money has not arrived, so it holds no stock and is the only status the stale-unpaid sweep
     * looks for. paymentReceived() is how it rejoins the happy path.
     */
    public function testAnInvoiceAwaitingPaymentIssuesOnHoldAndJoinsTheHappyPathWhenPaid(): void
    {
        $invoice = new Invoice();

        self::assertSame('On Hold', $invoice->issueAwaitingPayment(self::actor())->getStatus());
        self::assertSame('Pending', $invoice->paymentReceived(self::actor())->getStatus());
        self::assertSame('Processing', $invoice->startProcessing(self::actor())->getStatus());
    }

    /** Every live state can be withdrawn, including a draft nobody ever issued. */
    #[DataProvider('everyLiveStatus')]
    public function testAnyLiveInvoiceCanBeCancelled(\Closure $reach, string $expected): void
    {
        $invoice = new Invoice();
        $reach($invoice);
        self::assertSame($expected, $invoice->getStatus(), 'guard: the fixture reached the state it claims to test');

        $invoice->setStatus('Cancelled', self::actor());
        self::assertSame('Cancelled', $invoice->getStatus());
    }

    /** @return iterable<string, array{0: \Closure, 1: string}> */
    public static function everyLiveStatus(): iterable
    {
        yield 'Draft' => [static fn (Invoice $i) => null, 'Draft'];
        yield 'On Hold' => [static fn (Invoice $i) => $i->issueAwaitingPayment(self::actor()), 'On Hold'];
        yield 'Pending' => [static fn (Invoice $i) => $i->issue(self::actor()), 'Pending'];
        yield 'Processing' => [static fn (Invoice $i) => $i->issue(self::actor())->startProcessing(self::actor()), 'Processing'];
        yield 'Completed' => [
            static fn (Invoice $i) => $i->issue(self::actor())->startProcessing(self::actor())
                ->setStatus('Completed', self::actor()),
            'Completed',
        ];
    }

    /**
     * Cancelling is terminal. An invoice keeps its number forever and is never deleted, so there is
     * no way back out — this is the transition the plan names outright as the one a string setter
     * would wave through.
     */
    public function testACancelledInvoiceCannotBeCompleted(): void
    {
        $invoice = (new Invoice())->issue(self::actor())->startProcessing(self::actor());
        $invoice->setStatus('Cancelled', self::actor());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('An invoice cannot go from Cancelled to Completed');

        $invoice->setStatus('Completed', self::actor());
    }

    public function testACancelledInvoiceCannotBeCancelledAgain(): void
    {
        $invoice = new Invoice();
        $invoice->setStatus('Cancelled', self::actor());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('An invoice cannot go from Cancelled to Cancelled');

        $invoice->setStatus('Cancelled', self::actor());
    }

    public function testADraftCannotBeCompletedWithoutBeingIssued(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('An invoice cannot go from Draft to Completed');

        (new Invoice())->setStatus('Completed', self::actor());
    }

    public function testAPendingInvoiceCannotBeCompletedWithoutBeingProcessed(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('An invoice cannot go from Pending to Completed');

        (new Invoice())->issue(self::actor())->setStatus('Completed', self::actor());
    }

    public function testAnIssuedInvoiceCannotBeIssuedAgain(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('An invoice cannot go from Pending to Pending');

        (new Invoice())->issue(self::actor())->issue(self::actor());
    }

    /** issue() and issueAwaitingPayment() are two claims about the same document, not a sequence. */
    public function testAnInvoiceOnHoldCannotBeIssuedOutright(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('An invoice cannot go from On Hold to Pending (allowed from: Draft)');

        (new Invoice())->issueAwaitingPayment(self::actor())->issue(self::actor());
    }

    public function testPaymentReceivedOnlyAppliesToAnInvoiceOnHold(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('An invoice cannot go from Draft to Pending (allowed from: On Hold)');

        (new Invoice())->paymentReceived(self::actor());
    }

    public function testProcessingCannotStartFromADraft(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('An invoice cannot go from Draft to Processing');

        (new Invoice())->startProcessing(self::actor());
    }

    /**
     * The other half of the plan's status→bucket table: which statuses draw down the order's
     * uninvoiced quantity.
     *
     * Draft does not, because a draft is entirely inert — the quantity stays in the order's sales
     * hold rather than escaping into neither. Cancelled does not, because the goods are still owed.
     * On Hold DOES, which is what makes an abandoned checkout hold nothing anywhere without a
     * special case: the order's hold is already zero and the invoice itself holds no bucket.
     */
    #[DataProvider('countingStatuses')]
    public function testCountsTowardInvoicedQuantity(\Closure $reach, string $expected, bool $counts): void
    {
        $invoice = new Invoice();
        $reach($invoice);

        self::assertSame($expected, $invoice->getStatus(), 'guard: the fixture reached the state it claims to test');
        self::assertSame($counts, $invoice->countsTowardInvoicedQuantity());
        self::assertSame($expected === 'Cancelled', $invoice->isCancelled());
    }

    /** @return iterable<string, array{0: \Closure, 1: string, 2: bool}> */
    public static function countingStatuses(): iterable
    {
        yield 'Draft does not count' => [static fn (Invoice $i) => null, 'Draft', false];
        yield 'Cancelled does not count' => [
            static fn (Invoice $i) => $i->setStatus('Cancelled', self::actor()),
            'Cancelled',
            false,
        ];
        yield 'On Hold counts' => [static fn (Invoice $i) => $i->issueAwaitingPayment(self::actor()), 'On Hold', true];
        yield 'Pending counts' => [static fn (Invoice $i) => $i->issue(self::actor()), 'Pending', true];
        yield 'Processing counts' => [static fn (Invoice $i) => $i->issue(self::actor())->startProcessing(self::actor()), 'Processing', true];
        yield 'Completed counts' => [
            static fn (Invoice $i) => $i->issue(self::actor())->startProcessing(self::actor())
                ->setStatus('Completed', self::actor()),
            'Completed',
            true,
        ];
    }

    /**
     * Every case of the enum is covered above. Without this the table quietly stops being
     * exhaustive the moment a status is added, which is the failure mode a "status → bucket"
     * mapping has.
     */
    public function testTheCountingTableCoversEveryStatus(): void
    {
        $covered = [];
        foreach (self::countingStatuses() as [, $status]) {
            $covered[] = $status;
        }

        self::assertEqualsCanonicalizing(array_column(InvoiceStatus::cases(), 'value'), $covered);
    }

    /**
     * Which statuses may take money (#31), enumerated from the enum itself rather than from a list
     * somebody remembers to extend.
     *
     * Two are out, and they are one rule seen from both ends: a draft has been sent to nobody, so
     * nobody could have paid it; and a cancelled invoice cannot be holding payments in the first
     * place, because cancel() refuses one that does. The other four are invoices a customer has been
     * given — Completed included, since goods shipped on credit terms are paid after fulfilment.
     */
    public function testOnlyIssuedInvoicesAcceptAPayment(): void
    {
        $refused = [InvoiceStatus::Draft, InvoiceStatus::Cancelled];

        foreach (InvoiceStatus::cases() as $status) {
            self::assertSame(
                !in_array($status, $refused, true),
                $status->acceptsPayment(),
                sprintf('%s answered the wrong way about accepting a payment', $status->value),
            );
        }
    }

    public function testADraftRefusesAPaymentAtTheEntity(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('still a draft');

        (new Invoice())->setCompany((new Company())->setName('Acme Wholesale'))
            ->recordPayment(self::actor(), (new InvoicePayment())->setAmount('10.00')->setMethod('Cash'));
    }
}
