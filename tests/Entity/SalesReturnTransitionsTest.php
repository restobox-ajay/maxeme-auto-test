<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\ProductCore;
use App\Entity\SalesReturn;
use App\Entity\SalesReturnLine;
use App\Entity\Warehouse;
use App\Enum\SalesReturnStatus;
use PHPUnit\Framework\TestCase;

/**
 * The RMA's state machine, and the credit note's refusal to restock against one (#596).
 *
 * No database. Every rule asserted here is enforced by the entity itself, which is the property this
 * repo judges a transition by: an illegal move is refused where the caller's intent still exists,
 * and the side effects live INSIDE the method rather than beside its call sites.
 *
 * The transitions are the part of #596 that is easiest to get subtly wrong, because four of the five
 * states look interchangeable from a list screen. What separates them is which of them the goods
 * have physically moved in, and there is exactly one: Received.
 */
final class SalesReturnTransitionsTest extends TestCase
{
    /**
     * Requested → Authorised → Received → Closed, with the stamps landing as it goes.
     *
     * The stamps are asserted as they are written and not only at the end. A method that stamped
     * everything at Closed would satisfy a final-state assertion and would have lost the fact #596
     * exists to record: that the goods arrived on one date and the paperwork finished on another.
     */
    public function testTheOrdinaryPathStampsEachStepAsItHappens(): void
    {
        $return = $this->returnFor(3);

        self::assertSame(SalesReturnStatus::Requested, $return->getStatus(), 'a new return is only a request');
        self::assertNull($return->getAuthorisedAt(), 'nobody has agreed to anything yet');
        self::assertNull($return->getReceivedAt(), 'and nothing has arrived');
        self::assertTrue($return->isDraft(), 'so its lines are still editable');

        $return->authorise();
        self::assertSame(SalesReturnStatus::Authorised, $return->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $return->getAuthorisedAt(), 'authorising stamps when we agreed');
        self::assertNull($return->getReceivedAt(), 'agreeing is not receiving — the box has not been sent yet');
        self::assertFalse(
            $return->isDraft(),
            'and the lines stop being editable: they are now a promise to the customer about what they may send',
        );

        $warehouse = new Warehouse();
        $return->receive($warehouse);
        self::assertSame(SalesReturnStatus::Received, $return->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $return->getReceivedAt(), 'receiving stamps when the goods arrived');
        self::assertSame($warehouse, $return->getWarehouse(), 'and records WHERE they arrived, in the same act');

        $return->close();
        self::assertSame(SalesReturnStatus::Closed, $return->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $return->getReceivedAt(), 'closing does not erase the receipt');
    }

    /**
     * The warehouse is a parameter of receive(), not a setter called beforehand.
     *
     * There is no setWarehouse() at all, and its absence is the assertion: "the goods arrived" and
     * "they arrived here" are one fact, so a caller that could record the first without the second —
     * or change the second afterwards — could record a receipt of a parcel that landed nowhere.
     */
    public function testThereIsNoWayToRecordAReceiptWithoutSayingWhereItLanded(): void
    {
        self::assertFalse(
            method_exists(SalesReturn::class, 'setWarehouse'),
            'the warehouse is receive()\'s parameter. A public setter would let a receipt be recorded with no '
            . 'destination, or its destination changed after the fact, neither of which is a thing that can happen '
            . 'to a parcel',
        );

        $reflection = new \ReflectionMethod(SalesReturn::class, 'receive');
        self::assertSame(
            Warehouse::class,
            (string) ($reflection->getParameters()[0]->getType()?->getName()),
            'and it is required, not nullable — the transition refuses rather than guessing a building',
        );
    }

    /**
     * A return that authorises nothing is refused.
     *
     * The list of lines is what the receiving clerk checks the parcel against. An authorisation that
     * names nothing authorises everything, and the parcel then arrives with nothing to check it
     * against — which is the state this document exists to end.
     */
    public function testAnEmptyReturnCannotBeAuthorised(): void
    {
        $return = (new SalesReturn())->setCompany(new Company())->setDocumentNumber('RMA-EMPTY');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('authorises nothing');

        $return->authorise();
    }

    /**
     * Receiving is allowed only from Authorised.
     *
     * "Goods arrived against a return nobody authorised" is a case #596 requires to be EXPRESSIBLE,
     * and it is: raise the RMA after the fact, authorise it, receive it. That is two clicks with a
     * document at the end. Letting receive() jump from Requested would be the same act with no
     * record that anybody ever agreed to it — the audit hole rather than the fix for one.
     */
    public function testGoodsCannotBeReceivedAgainstAReturnNobodyAuthorised(): void
    {
        $return = $this->returnFor(2);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('only an authorised return can be received');

        $return->receive(new Warehouse());
    }

    /** Authorising twice is a mistake in the caller, not a no-op — SalesOrder::approve()'s rule. */
    public function testAuthorisingIsDeliberatelyNotIdempotent(): void
    {
        $return = $this->returnFor(2);
        $return->authorise();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('only a requested return can be authorised');

        $return->authorise();
    }

    /**
     * Declining is reachable from Authorised AND from Received, and the two are different facts.
     *
     * From Authorised: refused before anything shipped, so no goods ever moved. From Received: the
     * box was opened and it is the wrong item or plainly customer damage. Only the second strands
     * stock, and strandedUnits() is what tells the screen which case it is looking at.
     */
    public function testDecliningBeforeTheGoodsArriveStrandsNothing(): void
    {
        $return = $this->returnFor(4);
        $return->authorise();
        $return->decline('Not covered by warranty.');

        self::assertSame(SalesReturnStatus::Declined, $return->getStatus());
        self::assertNull($return->getReceivedAt(), 'nothing arrived');
        self::assertSame(
            '0.0000',
            $return->strandedUnits(),
            'and nothing is stranded: there is no stock to be stuck anywhere, so the screen shows no warning',
        );
    }

    public function testDecliningAfterTheGoodsArriveReportsThemAsStranded(): void
    {
        $return = $this->returnFor(4);
        $return->authorise();
        $return->receive(new Warehouse());
        $return->decline('Wrong item — this was never ours.');

        self::assertSame(SalesReturnStatus::Declined, $return->getStatus());
        self::assertSame(
            '4.0000',
            $return->strandedUnits(),
            'four units are in the building against a return we refused. They are ours to store and not ours to '
            . 'sell, and the detail screen surfaces exactly this figure so they are not merely correct and invisible',
        );

        foreach ($return->getLines() as $line) {
            self::assertNull(
                $line->getDisposition(),
                'and no disposition was invented for them — writing one would claim somebody had inspected the goods',
            );
        }
    }

    /** The refusal reason is APPENDED, so it cannot erase why the return was authorised. */
    public function testDecliningAppendsToTheNotesRatherThanReplacingThem(): void
    {
        $return = $this->returnFor(1)->setNotes('Authorised by Priya on the telephone, 2026-08-30.');
        $return->authorise();
        $return->decline('Customer damage.');

        $notes = (string) $return->getNotes();
        self::assertStringContainsString('Authorised by Priya', $notes, 'the context somebody reading the refusal needs most');
        self::assertStringContainsString('Customer damage.', $notes, 'beside the refusal itself');
    }

    /**
     * Closing is only from Received, and it does not require a credit note.
     *
     * A return closed with no credit is an ordinary outcome — an exchange, a warranty replacement
     * shipped on a new order, a goodwill write-off handled elsewhere. Coupling the two documents'
     * lifecycles would mean this one could not be tidied up without inventing money.
     */
    public function testOnlyAReceivedReturnCanBeClosed(): void
    {
        $return = $this->returnFor(1);
        $return->authorise();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('only a received return can be closed');

        $return->close();
    }

    public function testATerminalReturnGoesNoFurther(): void
    {
        $return = $this->returnFor(1);
        $return->authorise();
        $return->receive(new Warehouse());
        $return->close();

        self::assertTrue($return->getStatus()->isTerminal());

        $this->expectException(\DomainException::class);
        $return->decline('too late');
    }

    /**
     * There is no public setStatus(). The register is SalesOrder and CreditMemo, and the reason is
     * the same: a string setter would accept Closed → Requested and would let a caller write a state
     * the transitions could never have produced.
     */
    public function testThereIsNoPublicStatusSetter(): void
    {
        self::assertFalse(
            method_exists(SalesReturn::class, 'setStatus'),
            'transitions are named actions. A public setStatus() would accept Closed -> Requested and would let a '
            . 'caller reach a state no transition can produce',
        );
    }

    /*
     * ------------------------------------------------------------------------------------------
     * The behavioural change to #586
     * ------------------------------------------------------------------------------------------
     */

    /**
     * A credit note carrying a sales return REFUSES to restock, in both orderings.
     *
     * Both, because both are real: a screen that raises a note FROM a return sets the return first,
     * while one that links an existing restocking note to a return sets it second. One assertion
     * inside the entity is called from both setters so neither ordering can slip past — and this
     * test would notice if one of them lost its guard.
     */
    public function testACreditNoteCarryingAReturnRefusesToRestockWhicheverOrderTheyAreSet(): void
    {
        $return = $this->returnFor(2);

        $first = (new CreditMemo())->setCompany(new Company())->setDocumentNumber('CN-1')->setSalesReturn($return);
        $refusedForward = false;
        try {
            $first->setRestock(true);
        } catch (\DomainException) {
            $refusedForward = true;
        }
        self::assertTrue(
            $refusedForward,
            'setRestock() on a note that already names a return must refuse: the return\'s receipt is what put the '
            . 'goods back, and doing it again enters the same units twice',
        );
        self::assertFalse($first->isRestock(), 'and the flag is not left half-set by the refusal');

        $second = (new CreditMemo())->setCompany(new Company())->setDocumentNumber('CN-2')->setRestock(true);
        $refusedBackward = false;
        try {
            $second->setSalesReturn($return);
        } catch (\DomainException) {
            $refusedBackward = true;
        }
        self::assertTrue(
            $refusedBackward,
            'and attaching a return to a note that already restocks must refuse too — otherwise the ordering of two '
            . 'setters decides whether the ledger double-counts',
        );
        self::assertNull($second->getSalesReturn(), 'the note is left as it was, restocking on its own');
    }

    /**
     * #586's behaviour survives: a note with NO return restocks exactly as it always did.
     *
     * #596 is explicit that `restock` is not removed — a standalone credit with no RMA behind it
     * still needs it, for goodwill credits and credits where the goods are not worth the freight to
     * ship back. A fix that quietly disabled the flag would break the ordinary standalone credit
     * while passing every test about returns.
     */
    public function testAStandaloneCreditNoteStillRestocks(): void
    {
        $memo = (new CreditMemo())->setCompany(new Company())->setDocumentNumber('CN-3')->setRestock(true);

        self::assertTrue($memo->isRestock(), 'no return, no refusal — this is the #586 note, unchanged');
        self::assertNull($memo->getSalesReturn());

        // And detaching a return is what makes a refused note usable again, rather than leaving the
        // admin with a document they can neither restock nor unlink.
        $withReturn = (new CreditMemo())->setCompany(new Company())->setDocumentNumber('CN-4')->setSalesReturn($this->returnFor(1));
        $withReturn->setSalesReturn(null);
        $withReturn->setRestock(true);
        self::assertTrue($withReturn->isRestock(), 'detach the return and the note may own the stock itself again');
    }

    /** A disposition outside the four is refused rather than silently coerced. */
    public function testAnUnknownDispositionIsRefusedRatherThanCoerced(): void
    {
        $line = (new SalesReturnLine())->setProduct(new ProductCore());

        $line->setDisposition(SalesReturnLine::DISPOSITION_DAMAGED);
        self::assertSame('damaged', $line->getDisposition());

        $line->setDisposition('  ');
        self::assertNull($line->getDisposition(), 'blank means nobody said, which is a NULL and not an empty string');

        $this->expectException(\DomainException::class);
        $line->setDisposition('probably fine');
    }

    private function returnFor(int $units): SalesReturn
    {
        $return = (new SalesReturn())
            ->setCompany(new Company())
            ->setDocumentNumber('RMA-' . $units);

        $return->addLine(
            (new SalesReturnLine())
                ->setProduct(new ProductCore())
                ->setName('Widget')
                ->setQuantity(number_format($units, 2, '.', '')),
        );

        return $return;
    }
}
