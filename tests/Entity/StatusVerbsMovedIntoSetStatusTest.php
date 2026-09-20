<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Exception\StatusTransitionRefused;
use App\Service\DocumentActor;
use App\Status\CoreStatusVocabularyProvider;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every refusal the deleted status verbs carried, re-asked at the one gate.
 *
 * ## What this file is for, and why it is not the same as the tests already here
 *
 * `SalesOrder::approve()` and `SalesOrder::void()` are gone (owner rulings R1 and R2), and the
 * transitions table in the vocabulary is gone with them (R4). Two deletions in one change is exactly
 * the shape that loses a rule quietly: the verb that refused something stops existing, the map that
 * refused something else stops existing, and whether either refusal survived is invisible unless
 * somebody wrote it down BEFORE.
 *
 * So this is the "before" list, enumerated from the verb bodies at `2c0a1f78` and from the map that
 * shipped beside them, each one re-asked through `setStatus()`:
 *
 * | # | what refused it then | the refusal |
 * |---|---|---|
 * | 1 | `approve()`'s `!isStatus('Draft')` | `DomainException` "Only a Draft order can be approved; SO-1 is Approved." |
 * | 2 | the same, from every other live status | the same sentence, naming that status |
 * | 3 | the same, from a value the vocabulary never knew | the same sentence, naming the legacy value |
 * | 4 | `void()`'s `isStatus('Void')` | `DomainException` "Order SO-1 is already void." |
 * | 5 | `sales_order`'s `'Void' => []` | `StatusTransitionRefused` "Void is a final status — nothing moves out of it." |
 * | 6 | ~~`estimate`'s `'Accepted' => []`~~ | **Withdrawn.** Never a decision — a speculative map entry. An accepted quote moves anywhere, including back to Rejected. |
 * | 7 | `setStatus()`'s typo guard | `LogicException` "There is no status \"Draftt\" …" |
 * | 8 | `applyDerivedStatus()`'s `isDerived()` check | `DomainException` "Void is not a derived status on …" |
 *
 * Rows 1–4 were a VERB's and are now the gate's. Rows 5 and 6 were the MAP's and are now the
 * document's own, stated in `assertStatusChangeAllowed()`. Rows 7 and 8 were never either and are
 * here as the control: a change that broke them would not be a lost refusal, it would be a broken
 * gate, and this file would rather say which.
 *
 * ## And the things that must NOT have started refusing
 *
 * A consolidation can fail in the other direction just as quietly, by folding a human's rule onto a
 * path that never had it. Three cases carry that weight:
 *
 *  - the DERIVER still writes `Approved` from a live state, which is the one move `approve()`'s
 *    guard would forbid and the deriver has always made (cancel every invoice off an order and it
 *    goes back to Approved with its full quantity owed);
 *  - an order stranded on a value the vocabulary never knew is still voidable, because neither verb
 *    ever enumerated legal predecessors;
 *  - a quote still moves everywhere it moved before, and a move to the status it already holds is
 *    still a silent no-op.
 *
 * No database: every assertion here is about an object's own rules, which is where they have to hold
 * before anything is persisted. `setUp()` primes the registry with the real shipped provider, so the
 * vocabulary these pin is the one that ships rather than a fixture that could drift from it.
 */
final class StatusVerbsMovedIntoSetStatusTest extends TestCase
{
    private const LEGACY = 'Waiting for Quote';

    protected function setUp(): void
    {
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new CoreStatusVocabularyProvider()]));
    }

    protected function tearDown(): void
    {
        StatusVocabularyRegistry::reset();
    }

    // -------------------------------------------------------------------------------------------
    // The verbs are gone, and the gate is the only way in
    // -------------------------------------------------------------------------------------------

    /**
     * Rulings R1 and R2 in the smallest possible form.
     *
     * `NoStatusVerbIsReachableCest` is the derived, un-evadable version of this and is what actually
     * holds the line; this is the same claim as a unit assertion, so a developer running PHPUnit
     * alone still sees it go red rather than discovering it in the functional suite.
     */
    #[DataProvider('deletedVerbs')]
    public function testTheStatusVerbsNoLongerExistAtAll(string $verb): void
    {
        self::assertFalse(
            method_exists(SalesOrder::class, $verb),
            sprintf('SalesOrder::%s() is a status verb and there is to be one gate — setStatus().', $verb),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function deletedVerbs(): iterable
    {
        yield 'approve' => ['approve'];
        yield 'void' => ['void'];
    }

    public function testTheGateIsPublicAndIsWhatApprovingAnOrderNowMeans(): void
    {
        $order = $this->order();

        self::assertTrue((new \ReflectionMethod(SalesOrder::class, 'setStatus'))->isPublic());
        self::assertSame('Approved', $order->setStatus('Approved', DocumentActor::named('Priya Admin')));

        // The wording approve() wrote, still written, without the caller having to know it. That is
        // what keeps a reorganisation from rewording a customer's history.
        self::assertCount(1, $order->getLogs());
        self::assertSame('Order approved.', $order->getLogs()->last()->getComment());
        self::assertSame('Priya Admin', $order->getLogs()->last()->getUserName());
    }

    // -------------------------------------------------------------------------------------------
    // Rows 1-3: approve()'s guard
    // -------------------------------------------------------------------------------------------

    /**
     * Every non-Draft status an order can be sitting on, refused with the sentence `approve()` wrote.
     *
     * Void is deliberately NOT in this provider. It refuses too, and harder — it is row 5, and the
     * refusal it gives is the terminal one rather than this one, which is a difference the two cases
     * below state explicitly rather than blur.
     */
    #[DataProvider('statusesThatAreNotADraft')]
    public function testOnlyADraftOrderCanBeApproved(string $from): void
    {
        $order = $this->orderOn($from);

        try {
            $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
            self::fail(sprintf('approving an order that is %s must be refused', $from));
        } catch (\DomainException $refusal) {
            self::assertSame(
                sprintf('Only a Draft order can be approved; SO-1 is %s.', $from),
                $refusal->getMessage(),
            );
        }

        self::assertSame($from, $order->getStatus(), 'a refusal writes nothing');
        self::assertCount(0, $order->getLogs(), 'and leaves no timeline row');
    }

    /** @return iterable<string, array{string}> */
    public static function statusesThatAreNotADraft(): iterable
    {
        foreach (['Approved', 'Partially Invoiced', 'Invoiced', 'Closed', self::LEGACY] as $status) {
            yield $status => [$status];
        }
    }

    /**
     * The same guard on the document `approve()` could never have been called on safely.
     *
     * An order holding a quote-era string is not a Draft, so `approve()` refused it, and the status
     * control still offers Approve because the vocabulary has no opinion about it. Both halves are
     * here because the pairing is the point: offered, and refused when pressed.
     */
    public function testAStrandedOrderIsOfferedApproveAndStillRefusedIt(): void
    {
        $order = $this->orderOn(self::LEGACY);

        self::assertTrue($order->canTransitionTo('Approved'), 'the picker still offers it');
        self::assertArrayHasKey('Approved', $order->allowedTransitions());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only a Draft order can be approved; SO-1 is ' . self::LEGACY . '.');

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
    }

    // -------------------------------------------------------------------------------------------
    // Rows 4 and 5: void()'s guard, and Void being terminal
    // -------------------------------------------------------------------------------------------

    public function testVoidingAnAlreadyVoidOrderIsStillTheLoudMistakeItWas(): void
    {
        $order = $this->orderOn('Void');

        try {
            $order->setStatus('Void', DocumentActor::system());
            self::fail('voiding a void order must be refused, not swallowed as a no-op');
        } catch (\DomainException $refusal) {
            self::assertSame('Order SO-1 is already void.', $refusal->getMessage());
            self::assertNotInstanceOf(
                StatusTransitionRefused::class,
                $refusal,
                'this is a mistake in the request, not the document saying it cannot get there',
            );
        }

        self::assertCount(0, $order->getLogs());
    }

    /**
     * Void is the end of the road, and it says so in the sentence the vocabulary used to produce.
     *
     * The type matters as much as the words: `StatusTransitionRefused` is what takes a target out of
     * `canTransitionTo()` and out of a picker, which is why a void order offers nothing at all.
     */
    #[DataProvider('everyTargetOtherThanVoid')]
    public function testNothingMovesOutOfVoid(string $to): void
    {
        $order = $this->orderOn('Void');

        self::assertFalse($order->canTransitionTo($to));

        try {
            $order->setStatus($to, DocumentActor::system());
            self::fail(sprintf('Void is final; moving to %s must be refused', $to));
        } catch (StatusTransitionRefused $refusal) {
            self::assertSame(
                sprintf('Order SO-1 is Void and cannot become %s. Void is a final status — nothing moves out of it.', $to),
                $refusal->getMessage(),
            );
            self::assertSame('Void', $refusal->from);
            self::assertSame($to, $refusal->to);
        }

        self::assertSame('Void', $order->getStatus());
        self::assertCount(0, $order->getLogs());
    }

    /** @return iterable<string, array{string}> */
    public static function everyTargetOtherThanVoid(): iterable
    {
        foreach (['Draft', 'Approved', 'Partially Invoiced', 'Invoiced', 'Closed'] as $status) {
            yield $status => [$status];
        }
    }

    public function testAVoidOrderOffersNoMovesAtAll(): void
    {
        self::assertSame([], $this->orderOn('Void')->allowedTransitions());
    }

    // -------------------------------------------------------------------------------------------
    // Row 6: the one rule the estimate map really carried
    // -------------------------------------------------------------------------------------------

    /**
     * Accepted is not terminal. Owner, 2026-09-12: *"accepted can be changed to decline after by
     * admin."* It was terminal only because a speculative `transitions` map held `'Accepted' => []`.
     */
    #[DataProvider('everyEstimateStatusOtherThanAccepted')]
    public function testAnAcceptedQuoteMovesAnywhereTheVocabularyKnows(string $to): void
    {
        $estimate = $this->estimateOn('Accepted');

        self::assertTrue($estimate->canTransitionTo($to));
        self::assertSame($to, $estimate->setStatus($to, DocumentActor::system()));
        self::assertSame($to, $estimate->getStatus());
    }

    /** The move the owner named: an admin declining a quote the customer had accepted. */
    public function testAnAcceptedQuoteCanBeDeclined(): void
    {
        $estimate = $this->estimateOn('Accepted');

        self::assertSame('Rejected', $estimate->setStatus('Rejected', DocumentActor::system(), 'Customer withdrew.'));
        self::assertSame('Rejected', $estimate->getStatus());
    }

    /** @return iterable<string, array{string}> */
    public static function everyEstimateStatusOtherThanAccepted(): iterable
    {
        foreach (['Draft', 'Submitted', 'Priced', 'Rejected'] as $status) {
            yield $status => [$status];
        }
    }

    /**
     * The control on the same document: a quote that is not Accepted still goes anywhere it went
     * before, including the moves the map used to enumerate one by one.
     */
    #[DataProvider('everyLiveEstimateMove')]
    public function testEveryOtherQuoteMoveStillWorks(string $from, string $to): void
    {
        $estimate = $this->estimateOn($from);

        self::assertTrue($estimate->canTransitionTo($to));
        self::assertSame($to, $estimate->setStatus($to, DocumentActor::system()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function everyLiveEstimateMove(): iterable
    {
        $statuses = ['Draft', 'Submitted', 'Priced', 'Rejected'];

        foreach ($statuses as $from) {
            foreach (['Draft', 'Submitted', 'Priced', 'Accepted', 'Rejected'] as $to) {
                if ($from !== $to) {
                    yield $from . ' -> ' . $to => [$from, $to];
                }
            }
        }
    }

    public function testAQuoteMovingToWhereItAlreadyIsIsStillASilentNoOp(): void
    {
        $estimate = $this->estimateOn('Accepted');

        // Even out of the terminal status: every self-move is a no-op and always was, which is what
        // keeps a recalculation that lands a document where it already is from being a refusal.
        self::assertSame('Accepted', $estimate->setStatus('Accepted', DocumentActor::system()));
        self::assertCount(0, $estimate->getLogs());
    }

    // -------------------------------------------------------------------------------------------
    // Rows 7 and 8: the gate's own guards, which were never a verb's
    // -------------------------------------------------------------------------------------------

    public function testAStatusTheVocabularyDoesNotKnowIsStillTheTypoGuardsThrow(): void
    {
        $order = $this->order();

        try {
            $order->setStatus('Draftt', DocumentActor::system());
            self::fail('a status the vocabulary does not know must be refused, not written');
        } catch (\LogicException $refusal) {
            self::assertStringContainsString('There is no status "Draftt"', $refusal->getMessage());
            self::assertStringContainsString('sales_order', $refusal->getMessage());
            self::assertNotInstanceOf(\DomainException::class, $refusal, 'the typo guard is not a refusal');
        }

        self::assertSame('Draft', $order->getStatus());
    }

    public function testTheDeriverStillRefusesAStatusTheVocabularyDoesNotMarkDerived(): void
    {
        $order = new class extends SalesOrder {
            public function deriveStatus(): ?string
            {
                return 'Void';
            }
        };
        $order->setCompany((new Company())->setName('Acme Co')->setCode('ACME'))->setOrderNumber('SO-1');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Void is not a derived status on Order SO-1; it is set by an action.');

        $order->applyDerivedStatus(DocumentActor::system());
    }

    // -------------------------------------------------------------------------------------------
    // The other direction: what must NOT have started refusing
    // -------------------------------------------------------------------------------------------

    /**
     * The move that proves the moved guard did not leak onto the other door.
     *
     * `approve()`'s rule is "only from Draft". The DERIVER writes `Approved` from a live state every
     * time the last counting invoice is cancelled off an order — so if the rule had been folded onto
     * `applyDerivedStatus()` as well, this exact sequence would have started throwing. It is built
     * the long way round, through real invoices, because that is the only way to be sure the status
     * under test was reached the way production reaches it.
     */
    public function testTheDeriverStillWritesApprovedFromALiveStatus(): void
    {
        $order = $this->order();
        $order->setStatus('Approved', DocumentActor::system());

        $line = (new SalesOrderLine())->setName('Widget')->setQuantity('10.00')->setPrice('5.00');
        $order->addLine($line);

        $invoice = new Invoice();
        $order->addInvoice($invoice);
        $invoice->addLine(
            (new InvoiceLine())->setSalesOrderLine($line)->setName('Widget')->setQuantity('4.00')->setPrice('5.00'),
        );
        $invoice->issue(DocumentActor::system());

        self::assertTrue($order->applyDerivedStatus(DocumentActor::system()));
        self::assertSame('Partially Invoiced', $order->getStatus(), 'guard: the order really left Approved');

        $invoice->setStatus('Cancelled', DocumentActor::system());

        self::assertSame('Approved', $order->deriveStatus());
        self::assertTrue(
            $order->applyDerivedStatus(DocumentActor::system()),
            'the deriver may return a live order to Approved — the gate\'s Draft-only rule is not its rule',
        );
        self::assertSame('Approved', $order->getStatus());
        self::assertSame(
            'Order status changed from Partially Invoiced to Approved.',
            $order->getLogs()->last()->getComment(),
            'and it is still signed in the deriver\'s own words, not the gate\'s default',
        );
    }

    /**
     * A stranded order can still be voided, and the timeline still names the value it was on.
     *
     * Neither verb ever enumerated legal predecessors — `void()` asked only "am I already Void" —
     * so a row holding a value nobody recognises has always had this way out. It is the one escape
     * such a document has, and losing it would make it repairable only in SQL.
     */
    public function testAStrandedOrderCanStillBeVoided(): void
    {
        $order = $this->orderOn(self::LEGACY);

        self::assertTrue($order->canTransitionTo('Void'));
        self::assertSame('Void', $order->setStatus('Void', DocumentActor::system()));
        self::assertSame(
            sprintf('Order voided (was %s).', self::LEGACY),
            $order->getLogs()->last()->getComment(),
        );
    }

    /**
     * The wording `void()` composed, still composed, from both of its branches.
     *
     * The reason half is the caller's now — the gate takes a comment, not a reason — so what is
     * pinned here is that the no-reason default still comes from the document rather than from the
     * generic "Status changed from X to Y." every other target gets.
     */
    public function testVoidingWithoutACommentStillSaysWhatTheOrderWas(): void
    {
        $order = $this->orderOn('Approved');

        $order->setStatus('Void', DocumentActor::automation('Stale unpaid order sweep'));

        self::assertSame('Order voided (was Approved).', $order->getLogs()->last()->getComment());
        self::assertSame('Stale unpaid order sweep (automated)', $order->getLogs()->last()->getUserName());
    }

    /**
     * A move with no bespoke wording still gets the generic sentence, which is the control on the
     * two cases above: the defaults are per-target, not "every SalesOrder move now says something
     * bespoke".
     */
    public function testATargetWithNoBespokeWordingStillGetsTheGenericSentence(): void
    {
        $order = $this->orderOn('Approved');

        $order->setStatus('Closed', DocumentActor::system());

        self::assertSame('Status changed from Approved to Closed.', $order->getLogs()->last()->getComment());
    }

    /**
     * The picker answers exactly what the deleted grid answered, status for status.
     *
     * This is the regression that would otherwise be invisible: `allowedTransitions()` stopped
     * reading a table and started asking the document, and the two must agree. The expectations
     * below are the `sales_order` table from `CoreStatusVocabularyProvider` at `2c0a1f78`, written
     * out by hand from the deleted code rather than computed the same way the new implementation
     * computes them.
     */
    #[DataProvider('whatTheDeletedGridAnswered')]
    public function testThePickerAnswersWhatTheDeletedGridAnswered(string $from, array $expected): void
    {
        self::assertSame($expected, array_keys($this->orderOn($from)->allowedTransitions()));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function whatTheDeletedGridAnswered(): iterable
    {
        yield 'Draft' => ['Draft', ['Approved', 'Partially Invoiced', 'Invoiced', 'Closed', 'Void']];
        yield 'Approved' => ['Approved', ['Draft', 'Partially Invoiced', 'Invoiced', 'Closed', 'Void']];
        yield 'Partially Invoiced' => ['Partially Invoiced', ['Draft', 'Approved', 'Invoiced', 'Closed', 'Void']];
        yield 'Invoiced' => ['Invoiced', ['Draft', 'Approved', 'Partially Invoiced', 'Closed', 'Void']];
        yield 'Closed' => ['Closed', ['Draft', 'Approved', 'Partially Invoiced', 'Invoiced', 'Void']];
        yield 'Void' => ['Void', []];
        // Not a row the grid had: an unrecognised value was handled by transitionsFrom()'s own
        // fallback, and the answer — every status the vocabulary knows — is unchanged.
        yield self::LEGACY => [self::LEGACY, ['Draft', 'Approved', 'Partially Invoiced', 'Invoiced', 'Closed', 'Void']];
    }

    // -------------------------------------------------------------------------------------------

    private function order(): SalesOrder
    {
        return (new SalesOrder())
            ->setCompany((new Company())->setName('Acme Co')->setCode('ACME'))
            ->setOrderNumber('SO-1');
    }

    /**
     * An order sitting on $status, written straight into the column.
     *
     * Reflection rather than the gate, deliberately: half the statuses below cannot be reached
     * through it — `Closed` is the deriver's, and `Waiting for Quote` is a value nothing in the
     * application can write at all — and a fixture that used the gate would also be using the thing
     * under test to set up the thing under test.
     */
    private function orderOn(string $status): SalesOrder
    {
        $order = $this->order();
        (new \ReflectionProperty(SalesOrder::class, 'status'))->setValue($order, $status);

        return $order;
    }

    private function estimateOn(string $status): Estimate
    {
        $estimate = (new Estimate())->setCompany(new Company())->setDocumentNumber('EST-1');
        (new \ReflectionProperty(Estimate::class, 'status'))->setValue($estimate, $status);

        return $estimate;
    }
}
