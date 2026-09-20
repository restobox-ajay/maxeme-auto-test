<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePayment;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\SalesOrderStatus;
use App\Exception\StatusTransitionRefused;
use App\Service\DocumentActor;
use App\Status\CoreStatusVocabularyProvider;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SalesOrder's two named actions, the one door they write through, and the invoiced-quantity
 * arithmetic the derived statuses read (#539 stage 2, amended by the status seam).
 *
 * **This file used to say "there is no setStatus() to test. That is the point of this file."** The
 * status seam reversed that on purpose, so the sentence is gone and what replaces it is this:
 * `setStatus()` exists and is the ONE door. It refuses a status the vocabulary does not know, it
 * refuses a move the vocabulary does not allow, and it writes the timeline row itself — which is
 * what makes a status change that records nobody impossible to write rather than merely discouraged.
 * The invariant the old assertion was really protecting is untouched: nothing may put a status on an
 * order that the order's own rules did not sanction. Only the mechanism that enforces it changed,
 * from "there is no setter" to "the setter answers to the vocabulary".
 *
 * `approve()` and `void()` are still named actions rather than calls to that door, because each
 * carries a from-state guard STRICTER than the map can express — only a Draft order may be approved
 * by a human, and an already-void order may not be voided again.
 *
 * **`applyDerivedStatus()` no longer takes a target.** The caller does not decide what the status
 * should be; the order does, in `deriveStatus()`, from its own invoices. So every derived case below
 * builds the STATE that implies the status under test and then asserts what the order made of it.
 * Handing it an answer is no longer something the API permits.
 *
 * No database: every assertion here is about an object's own rules, and the log entries are visible
 * on the collection the moment the action returns because both sides are linked in memory. The one
 * thing a kernel would normally supply is the status vocabulary, so setUp() primes the registry with
 * the real shipped core provider — the vocabulary these tests pin is the one that ships, not a
 * fixture that could drift from it.
 */
final class SalesOrderActionsTest extends TestCase
{
    protected function setUp(): void
    {
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new CoreStatusVocabularyProvider()]));
    }

    protected function tearDown(): void
    {
        // Static state outlives a test. Leaking a loader into the next case is the hazard
        // StatusVocabularyRegistryTest names, and it applies here for the same reason.
        StatusVocabularyRegistry::reset();
    }

    /**
     * The replacement for `testSetStatusIsNotPartOfTheOrdersSurface`.
     *
     * That case asserted `method_exists(SalesOrder::class, 'setStatus')` was FALSE, on the grounds
     * that a public string setter could accept Closed -> Draft and let any caller write a derived
     * status by hand. The seam's answer to the same worry is not absence but the vocabulary: the
     * door exists, and it is exactly as narrow as the map. So this asserts the narrowness, which is
     * the thing that was ever worth pinning.
     */
    public function testEveryTransitionGoesThroughTheVocabularyAndAnIllegalOneIsRefused(): void
    {
        $order = $this->order();

        // The door returns the resulting status and writes the timeline row itself, signed by the
        // actor the CALLER supplied. That is what stops a status change recording nobody.
        self::assertSame('Approved', $order->setStatus('Approved', DocumentActor::named('Priya Admin'), 'Order approved.'));
        self::assertSame(SalesOrderStatus::Approved, $order->getStatusEnum());
        self::assertSame('Priya Admin', $order->getLogs()->last()->getUserName());

        // A status the vocabulary does not know is refused BEFORE anything is written. This is the
        // loudness the enum used to provide: a raw comparison against 'Draftt' would silently be
        // false and take the wrong branch.
        try {
            $order->setStatus('Draftt', DocumentActor::system());
            self::fail('a status the vocabulary does not know must be refused, not written');
        } catch (\LogicException $refusal) {
            self::assertStringContainsString('There is no status "Draftt"', $refusal->getMessage());
            self::assertSame(SalesOrderStatus::Approved, $order->getStatusEnum(), 'and nothing was written');
        }

        $order->setStatus('Void', DocumentActor::system());

        // Void is final in the sales_order vocabulary, so the door is shut from here — and the
        // refusal says what the order WOULD accept rather than only "no".
        $this->expectException(StatusTransitionRefused::class);
        $this->expectExceptionMessage('is a final status — nothing moves out of it');

        $order->setStatus('Approved', DocumentActor::system());
    }

    public function testANewOrderIsADraft(): void
    {
        self::assertSame(SalesOrderStatus::Draft, $this->order()->getStatusEnum());
    }

    public function testApprovingADraftMovesItAndWritesItsOwnTimelineEntry(): void
    {
        $order = $this->order();

        $order->setStatus('Approved', DocumentActor::named('Priya Admin, priya@example.test (4)'), 'Order approved.');

        self::assertSame(SalesOrderStatus::Approved, $order->getStatusEnum());
        self::assertCount(1, $order->getLogs());

        $log = $order->getLogs()->first();
        self::assertSame('Order approved.', $log->getComment());
        // The actor the CALLER supplied, not an ambient one — which is what lets a console command
        // sign its own work.
        self::assertSame('Priya Admin, priya@example.test (4)', $log->getUserName());
    }

    public function testAnApprovedOrderCannotBeApprovedAgain(): void
    {
        $order = $this->order();
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only a Draft order can be approved');

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
    }

    public function testVoidingRecordsWhatItWasAndWhy(): void
    {
        $order = $this->order();
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $order->setStatus(
            'Void',
            DocumentActor::automation('Stale unpaid order sweep'),
            sprintf('Order voided (was %s): %s', $order->getStatus(), 'payment was not completed.'),
        );

        self::assertSame(SalesOrderStatus::Void, $order->getStatusEnum());

        $log = $order->getLogs()->last();
        self::assertSame('Order voided (was Approved): payment was not completed.', $log->getComment());
        // Attributed to the job, not to a user and not to "System" — the audit requirement in #539.
        self::assertSame('Stale unpaid order sweep (automated)', $log->getUserName());
    }

    public function testVoidingWithoutAReasonStillSaysWhatItWas(): void
    {
        $order = $this->order();

        $order->setStatus('Void', DocumentActor::system());

        self::assertSame('Order voided (was Draft).', $order->getLogs()->last()->getComment());
    }

    public function testAVoidedOrderCannotBeVoidedAgain(): void
    {
        $order = $this->order();
        $order->setStatus('Void', DocumentActor::system());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already void');

        $order->setStatus('Void', DocumentActor::system());
    }

    public function testAVoidedOrderCannotBeApproved(): void
    {
        $order = $this->order();
        $order->setStatus('Void', DocumentActor::system());

        $this->expectException(\DomainException::class);

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
    }

    /**
     * Every status the deriver is allowed to write, reached the way the deriver reaches it.
     *
     * This case used to hand `applyDerivedStatus()` the target and assert it was written. It cannot
     * any more, and the rewrite is the point rather than a concession: the order is put into the
     * state that IMPLIES each status, and what is asserted is that it worked the status out and
     * wrote it. `deriveStatus()` is asserted first so that a builder which drifted shows up as a
     * wrong SETUP rather than as a wrong outcome.
     *
     * The claim about Draft is carried over unchanged, and it is the interesting one: Draft is the
     * only derivable status that is never a CHANGE. An order derives Draft precisely when it has not
     * been approved, which is where a new one already is — so the write is a no-op and the method
     * answers false, exactly as the old `=== ($status !== Draft)` said.
     */
    #[DataProvider('derivedStatuses')]
    public function testTheDeriverMayWriteAnyDerivedStatus(SalesOrderStatus $status): void
    {
        $order = $this->orderThatDerives($status);

        self::assertSame($status->value, $order->deriveStatus(), 'the state under test must imply the status under test');

        self::assertTrue($order->applyDerivedStatus(DocumentActor::system()) === ($status !== SalesOrderStatus::Draft));
        self::assertSame($status, $order->getStatusEnum());
    }

    /** @return iterable<string, array{SalesOrderStatus}> */
    public static function derivedStatuses(): iterable
    {
        foreach (SalesOrderStatus::derivable() as $status) {
            yield $status->value => [$status];
        }
    }

    /**
     * The `isDerived()` half of `applyDerivedStatus()`'s guard, pinned where it is reachable.
     *
     * The old case handed the method `SalesOrderStatus::Void` and expected the refusal. The new
     * signature takes no target, so that route is gone — and `SalesOrder::deriveStatus()` never
     * returns Void, so no state of a sales order reaches the guard either. It is NOT dead code: the
     * guard protects the extension point, not the caller. `deriveStatus()` is the overridable hook
     * every computing document implements, and a document that named a status the vocabulary does
     * not mark `derived` would otherwise have the deriver write a status only an action may set.
     *
     * So the subject here is a document whose `deriveStatus()` says Void. That is the only thing
     * that can still make this mistake, and it is refused.
     */
    public function testTheDeriverMayNotWriteVoid(): void
    {
        $order = $this->orderComputing('Void');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Void is not a derived status');

        $order->applyDerivedStatus(DocumentActor::system());
    }

    /**
     * A voided order is out of the deriver's reach, and its invoices do not get a vote.
     *
     * The refusal this used to expect ("is void; its status is never recomputed") is gone, because
     * the check moved INTO the document: `deriveStatus()` returns null for a void order and a null
     * derives nothing. The CLAIM is unchanged and is asserted harder than before — the order is
     * left in a state whose invoices plainly say Invoiced, and it stays Void with no new row on its
     * timeline. A refusal would also have satisfied the old assertion; only the outcome matters, and
     * the outcome is that nothing moved.
     */
    public function testAVoidedOrdersStatusIsNeverRecomputed(): void
    {
        $order = $this->orderThatDerives(SalesOrderStatus::Invoiced);
        $order->setStatus('Void', DocumentActor::system());
        $logsAfterVoiding = $order->getLogs()->count();

        self::assertNull($order->deriveStatus(), 'a void order computes nothing at all');
        self::assertFalse($order->applyDerivedStatus(DocumentActor::system()));
        self::assertSame(SalesOrderStatus::Void, $order->getStatusEnum());
        self::assertCount($logsAfterVoiding, $order->getLogs(), 'and wrote nothing to the timeline either');
    }

    public function testApplyingTheStatusItAlreadyHoldsIsNotAChange(): void
    {
        $order = $this->order();
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        self::assertSame('Approved', $order->deriveStatus());

        // False is what stops the deriver writing a timeline entry on every flush that merely
        // touched the order.
        self::assertFalse($order->applyDerivedStatus(DocumentActor::system()));
        self::assertCount(1, $order->getLogs(), 'the approval, and no second row saying nothing changed');
    }

    public function testUninvoicedQuantityIsOrderedMinusWhatCountingInvoicesBill(): void
    {
        $order = $this->order();
        $line = $this->line($order, '10.0000');

        self::assertSame('0.0000', $order->invoicedQuantityFor($line));
        self::assertSame('10.0000', $order->uninvoicedQuantityFor($line));
        self::assertFalse($order->isFullyInvoiced());

        $this->invoiceFor($order, $line, '4.0000')->issue(DocumentActor::system());

        self::assertSame('4.0000', $order->invoicedQuantityFor($line));
        self::assertSame('6.0000', $order->uninvoicedQuantityFor($line));
        self::assertFalse($order->isFullyInvoiced());

        $this->invoiceFor($order, $line, '6.00')->issue(DocumentActor::system());

        self::assertSame('10.0000', $order->invoicedQuantityFor($line));
        self::assertSame('0.0000', $order->uninvoicedQuantityFor($line));
        self::assertTrue($order->isFullyInvoiced());
    }

    public function testADraftInvoiceDrawsDownNothing(): void
    {
        $order = $this->order();
        $line = $this->line($order, '10.0000');
        $this->invoiceFor($order, $line, '10.0000');

        // Left Draft deliberately. A draft is inert: it holds no stock and does not consume the
        // order's uninvoiced quantity, so the quantity stays where it is rather than escaping into
        // neither document.
        self::assertSame('10.0000', $order->uninvoicedQuantityFor($line));
        self::assertFalse($order->hasCountingInvoices());
    }

    public function testCancellingAnInvoiceReturnsItsQuantityToTheOrder(): void
    {
        $order = $this->order();
        $line = $this->line($order, '10.00');
        $invoice = $this->invoiceFor($order, $line, '10.0000');
        $invoice->issue(DocumentActor::system());

        self::assertSame('0.0000', $order->uninvoicedQuantityFor($line));

        $invoice->setStatus('Cancelled', DocumentActor::system());

        self::assertSame('10.0000', $order->uninvoicedQuantityFor($line));
        self::assertFalse($order->hasCountingInvoices());
    }

    public function testAnInvoiceLineNotAttributedToAnOrderLineDrawsDownNothing(): void
    {
        $order = $this->order();
        $line = $this->line($order, '10.00');

        $invoice = new Invoice();
        $order->addInvoice($invoice);
        // A fee, or an extra added at ship time: #539 allows an invoice to carry a SKU the order does
        // not. It bills something, but there is no order row for it to draw down — and matching on
        // SKU instead would credit it against whichever order row happened to share the code.
        $invoice->addLine((new InvoiceLine())->setName('Pallet charge')->setQuantity('3.00')->setPrice('25.00'));
        $invoice->issue(DocumentActor::system());

        self::assertSame('0.0000', $order->invoicedQuantityFor($line));
        self::assertSame('10.0000', $order->uninvoicedQuantityFor($line));
        self::assertTrue($order->hasCountingInvoices());
    }

    public function testEveryLineMustBeInvoicedBeforeTheOrderIs(): void
    {
        $order = $this->order();
        $first = $this->line($order, '2.00');
        $second = $this->line($order, '5.00');

        $this->invoiceFor($order, $first, '2.00')->issue(DocumentActor::system());

        self::assertFalse($order->isFullyInvoiced(), 'One line billed in full is not the whole order.');

        $this->invoiceFor($order, $second, '5.00')->issue(DocumentActor::system());

        self::assertTrue($order->isFullyInvoiced());
    }


    private function order(): SalesOrder
    {
        return (new SalesOrder())
            ->setCompany((new Company())->setName('Acme Co')->setCode('ACME'))
            ->setOrderNumber('SO-1');
    }

    /**
     * An order standing in the state that makes `deriveStatus()` answer $status.
     *
     * The table these build is the one on `SalesOrder::deriveStatus()`, and each case is set up so
     * the status under test is a real MOVE rather than the status the order already held — otherwise
     * `applyDerivedStatus()`'s early "already there" return would make every case pass for the wrong
     * reason. Draft is the exception and cannot be anything else; see the case that uses it.
     */
    private function orderThatDerives(SalesOrderStatus $status): SalesOrder
    {
        $order = $this->order();

        if ($status === SalesOrderStatus::Draft) {
            // Not approved, whatever its invoices say — which is where a new order already stands.
            return $order;
        }

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        if ($status === SalesOrderStatus::Approved) {
            // Approved is DERIVED as well as set by approve(): cancelling the last counting invoice
            // off an order returns it to Approved with its full quantity owed again. Built that way
            // so the order is somewhere else when the call under test happens.
            $line = $this->line($order, '10.00');
            $invoice = $this->invoiceFor($order, $line, '4.00');
            $invoice->issue(DocumentActor::system());
            $order->applyDerivedStatus(DocumentActor::system());
            self::assertSame(SalesOrderStatus::PartiallyInvoiced, $order->getStatusEnum());

            $invoice->setStatus('Cancelled', DocumentActor::system());

            return $order;
        }

        $line = $this->line($order, '10.00');

        if ($status === SalesOrderStatus::PartiallyInvoiced) {
            $this->invoiceFor($order, $line, '4.00')->issue(DocumentActor::system());

            return $order;
        }

        // Invoiced and Closed differ only in whether the money arrived, so both bill the line in
        // full and only the payment separates them.
        $invoice = $this->invoiceFor($order, $line, '10.00')->setTotal('50.00');
        $invoice->issue(DocumentActor::system());

        if ($status === SalesOrderStatus::Closed) {
            $invoice->recordPayment(
                DocumentActor::system(),
                (new InvoicePayment())->setMethod('Cheque')->setAmount('50.00'),
            );
        }

        return $order;
    }

    /**
     * A sales order whose computed status is whatever the test says, for the guard cases.
     *
     * `deriveStatus()` is the hook a computing document overrides, so overriding it is how a test
     * reaches `applyDerivedStatus()`'s guard now that no caller can hand it a target. Everything
     * else about the order — its vocabulary, its timeline, its one door — is SalesOrder's.
     */
    private function orderComputing(?string $derived): SalesOrder
    {
        $order = new class extends SalesOrder {
            public ?string $derived = null;

            public function deriveStatus(): ?string
            {
                return $this->derived;
            }
        };

        $order->derived = $derived;
        $order->setCompany((new Company())->setName('Acme Co')->setCode('ACME'))->setOrderNumber('SO-1');

        return $order;
    }

    private function line(SalesOrder $order, string $quantity): SalesOrderLine
    {
        $line = (new SalesOrderLine())->setName('Widget')->setQuantity($quantity)->setPrice('5.00');
        $order->addLine($line);

        return $line;
    }

    private function invoiceFor(SalesOrder $order, SalesOrderLine $line, string $quantity): Invoice
    {
        $invoice = (new Invoice())->setCompany($order->getCompany());
        $order->addInvoice($invoice);
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($line)
                ->setName($line->getName())
                ->setQuantity($quantity)
                ->setPrice($line->getPrice()),
        );

        return $invoice;
    }
}
