<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Contract\Status\HasStatus;
use App\Entity\Cart;
use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\Estimate;
use App\Entity\Invoice;
use App\Entity\SalesOrder;
use App\Enum\EstimateStatus;
use App\Exception\StatusTransitionRefused;
use App\Service\DocumentActor;
use App\Status\CoreStatusVocabularyProvider;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Estimate's half of the status seam (queue item 64, stage 3), and the visibility line the shared
 * `setStatus()` had to be moved up behind.
 *
 * Estimate is the document that motivated the whole seam: it had a bare
 * `setStatus(EstimateStatus $status): self` with NO guard on the entity at all, so what was legal on
 * a quote was whatever each of its six call sites happened to enforce — and they did not agree. The
 * cases below pin what replaced it.
 *
 * No database: every assertion here is about an object's own rules, and the timeline row is visible
 * on the collection the moment `setStatus()` returns because both sides are linked in memory. The
 * one thing a kernel would normally supply is the vocabulary, so setUp() primes the registry with
 * the real shipped core provider — the vocabulary these tests pin is the one that ships, not a
 * fixture that could drift from it.
 */
final class EstimateStatusSeamTest extends TestCase
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

    public function testANewQuoteIsADraftAndReadsItAsAString(): void
    {
        $estimate = $this->estimate();

        self::assertSame('Draft', $estimate->getStatus());
        self::assertTrue($estimate->isStatus('Draft'));
        self::assertTrue($estimate->statusIsRecognised());
        self::assertSame('Draft', $estimate->statusLabel());
        self::assertSame(EstimateStatus::Draft, $estimate->getStatusEnum(), 'the enum is still the core contract');
    }

    /** The door returns the resulting status and signs the timeline row with the caller's actor. */
    public function testTheDoorWritesTheStatusAndTheTimelineRowTogether(): void
    {
        $estimate = $this->estimate();

        self::assertSame('Submitted', $estimate->setStatus('Submitted', DocumentActor::named('Jane Buyer'), 'Quote requested.'));
        self::assertSame('Submitted', $estimate->getStatus());

        self::assertCount(1, $estimate->getLogs());
        $entry = $estimate->getLogs()->last();
        self::assertSame('Jane Buyer', $entry->getUserName());
        self::assertSame('Quote requested.', $entry->getComment());
        self::assertSame('System', $entry->getType());
    }

    /**
     * The typo guard, and the exact thing the task asked to be proved: the refusal names the
     * VOCABULARY and every slug in it, so the person reading it can see what they should have typed.
     *
     * A raw `getStatus() === 'Pricedd'` would silently be false and take the wrong branch. This is
     * where the loudness the enum used to provide at compile time comes back at runtime.
     */
    public function testAnUnknownStatusIsRefusedByNameAndNothingIsWritten(): void
    {
        $estimate = $this->estimate();

        try {
            $estimate->setStatus('Pricedd', DocumentActor::system());
            self::fail('a status the vocabulary does not know must be refused, not written');
        } catch (\LogicException $refusal) {
            self::assertSame(
                'There is no status "Pricedd" in the "estimate" vocabulary.'
                    . ' It has: Draft, Submitted, Priced, Accepted, Rejected.',
                $refusal->getMessage(),
            );
        }

        self::assertSame('Draft', $estimate->getStatus(), 'and nothing was written');
        self::assertCount(0, $estimate->getLogs(), 'and no timeline row was left behind');
    }

    /** Accepted is not terminal. Owner: *"accepted can be changed to decline after by admin."* */
    public function testAnAcceptedQuoteCanStillBeDeclined(): void
    {
        $estimate = $this->estimate()->setDocumentNumber('EST-9001');
        $estimate->setStatus('Accepted', DocumentActor::system());

        self::assertSame('Rejected', $estimate->setStatus('Rejected', DocumentActor::system()));
        self::assertSame('Rejected', $estimate->getStatus());
    }

    /**
     * The other half of the wording: a refusal out of a non-terminal status lists what it WOULD
     * accept, because "cannot do that" without the third part sends the person back to guess.
     *
     * There is no such move on a quote today — every live status may reach every other one, which
     * `CoreStatusVocabularyProvider` transcribed from the controllers rather than tidied — so this
     * pins the sentence on the one document family that does have a partial map, through the same
     * shared body on `AbstractSalesDocument`.
     */
    public function testARefusedMoveNamesWhatWasAllowed(): void
    {
        $order = (new SalesOrder())->setOrderNumber('SO-9001');
        $order->setStatus('Void', DocumentActor::system());

        $this->expectException(StatusTransitionRefused::class);
        $this->expectExceptionMessage('Order SO-9001 is Void and cannot become Approved. Void is a final status — nothing moves out of it.');

        $order->setStatus('Approved', DocumentActor::system());
    }

    /** Moving to the status already held is legal, silent, and writes no second row. */
    public function testAMoveToTheStatusAlreadyHeldIsASilentNoOp(): void
    {
        $estimate = $this->estimate();

        self::assertSame('Draft', $estimate->setStatus('Draft', DocumentActor::system()));
        self::assertCount(0, $estimate->getLogs());
    }

    /** What a filter bar and a picker read, both halves, straight off the vocabulary. */
    public function testTheQuoteListsItsVocabularyAndItsLegalMoves(): void
    {
        self::assertSame(
            ['Draft' => 'Draft', 'Submitted' => 'Submitted', 'Priced' => 'Priced', 'Accepted' => 'Accepted', 'Rejected' => 'Rejected'],
            Estimate::listStatuses(),
        );

        $estimate = $this->estimate();
        self::assertSame(['Submitted', 'Priced', 'Accepted', 'Rejected'], array_keys($estimate->allowedTransitions()));
        self::assertTrue($estimate->canTransitionTo('Priced'));
        self::assertFalse($estimate->canTransitionTo('Nonsense'), 'the boolean question never throws');
    }

    /**
     * Report, never refuse (handoff section 7). A quote holding a value the vocabulary no longer
     * knows still loads, says so on screen, and can be moved OFF it — which is what keeps a stranded
     * document repairable without SQL.
     */
    public function testAStrandedValueLoadsIsMarkedAndCanBeLeft(): void
    {
        $estimate = $this->estimate();
        (new \ReflectionProperty(Estimate::class, 'status'))->setValue($estimate, 'Waiting for Quote');

        self::assertSame('Waiting for Quote', $estimate->getStatus());
        self::assertFalse($estimate->statusIsRecognised());
        self::assertSame('Waiting for Quote (unrecognised)', $estimate->statusLabel());
        self::assertSame(['Draft', 'Submitted', 'Priced', 'Accepted', 'Rejected'], array_keys($estimate->allowedTransitions()));

        self::assertSame('Priced', $estimate->setStatus('Priced', DocumentActor::system()));
    }

    /**
     * The visibility line, asserted rather than described.
     *
     * The shared `setStatus()` body lives on `AbstractSalesDocument` and is PROTECTED. Five classes
     * extend that base, and each decides in its OWN file whether to open the door — one public
     * one-liner calling `parent::`. That is what this case pins, in both directions.
     *
     * ## Invoice and CreditMemo moved sides, and it was a ruling rather than a drift
     *
     * They used to be on the closed list, and the reason given here was that each carried guards
     * inside named actions — `Invoice::cancel()` refusing an invoice with payments against it,
     * `CreditMemo::void()` refusing a note with applications against it — which a public setter
     * would let a caller walk around.
     *
     * The premise was right and the conclusion was backwards, and the owner reversed it:
     *
     * > *"Invoice why is it off seam. it gets to keep those non-status name verbs but those verbs
     * > must call setStatus - i dont think our instructions where vague. invoice like anything else
     * > should not have status name as verbs as public methods. I don't think there's anything 'off
     * > seam'."*
     *
     * The answer to "a public setter would bypass the guard" is to move the guard ONTO the gate, not
     * to keep the gate shut — which is ruling R1's one door read forwards. `cancel()` and
     * `complete()` and `void()` are deleted; their guards are in `assertStatusChangeAllowed()` on
     * each document, where every caller meets them whichever way it came in. The payments guard is
     * re-asked at the gate by `InvoiceAndCreditMemoStatusSeamTest`.
     *
     * `Cart` stays closed and is now the whole of that side: it has no status column, no status
     * property and not one reference to one, so a write door would be a door onto nothing.
     */
    public function testTheSharedDoorIsOnlyPublicWhereADocumentOpensItDeliberately(): void
    {
        foreach ([SalesOrder::class, Estimate::class, Invoice::class, CreditMemo::class] as $open) {
            self::assertTrue(
                (new \ReflectionMethod($open, 'setStatus'))->isPublic(),
                $open . ' declares HasStatus, which requires a public setStatus()',
            );
            self::assertTrue(is_a($open, HasStatus::class, true), $open . ' is on the seam');
        }

        foreach ([Cart::class] as $closed) {
            self::assertFalse(
                (new \ReflectionMethod($closed, 'setStatus'))->isPublic(),
                $closed . ' has no status at all; inheriting a public setter from'
                    . ' AbstractSalesDocument would open a write door onto nothing',
            );
            self::assertFalse(is_a($closed, HasStatus::class, true), $closed . ' is not on the seam');
        }
    }

    private function estimate(): Estimate
    {
        return (new Estimate())->setCompany(new Company());
    }
}
