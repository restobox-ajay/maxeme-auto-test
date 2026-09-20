<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What became of the customer's returned goods, asked when a credit note that restocked them is
 * voided (item 38).
 *
 * ## Why the app must ask instead of working it out
 *
 * Issuing a restocking credit note records goods ARRIVING from the customer, into
 * `InventoryDetail::STATUS_RETURNED` — present, counted in `quarantine_quantity`, and deliberately
 * not sellable until somebody has looked at them. Voiding that note withdraws the paperwork; it
 * says nothing by itself about where the physical units are, and there are three real answers with
 * three different correct ledger entries. Guessing is wrong in two cases out of three whichever way
 * it guesses, so the void screen asks and this enum is the vocabulary of the question.
 *
 * ## The mirror of the buy side, and where it stops being a mirror
 *
 * The debit memo's own stock service on the buy side asks the same question about a debit memo,
 * and this is the sell-side twin of it: three answers, each writing its own
 * `InventoryMovementGroup` with the reason, the actor and the note's number, all three appearing on
 * the movement timeline, and the note's own detail screen stating what was chosen.
 *
 * What does NOT carry across is the DIRECTION, and it inverts every case:
 *
 * | | buy side (debit memo)                | sell side (credit note)                     |
 * |-|--------------------------------------|---------------------------------------------|
 * | issuing moved   | stock OUT: `available → returned_to_vendor` | stock IN: `null → returned`      |
 * | undoing it      | puts stock BACK: inventory UP        | takes stock OFF the books: inventory DOWN   |
 *
 * So the buy side's `back_in_stock` — reverse the movement, units become sellable again — has no
 * sell-side counterpart: reversing a receipt takes the units out of the building, which is
 * {@see self::NotHere}. That is the answer this defect exists for.
 *
 * ## Why "back on the shelf" is deliberately NOT one of the answers
 *
 * There is no `returned → available` option here, and its absence is a decision rather than an
 * omission. Deciding that a returned unit is fit to sell is an INSPECTION ruling:
 *
 *  - `CreditMemoRestockSubscriber` lands the units in `returned` rather than `available` precisely
 *    because "the note cannot know whether the goods are faulty or perfect" — putting them straight
 *    back on the shelf would offer stock for sale that nobody has looked at;
 *  - `InventoryDetail::documentBackedStatuses()` includes `returned`, so even the adjustment screen
 *    refuses to move such a row, on the same reasoning (#596);
 *  - `SalesReturn::decline()` reaches the identical state — units received and then refused — and
 *    deliberately invents no disposition for them either.
 *
 * A void confirmation that could make them sellable would be the one screen in the application
 * where unexamined returned goods become sellable, which is exactly the hole those three decisions
 * were made to close. {@see self::StillHere} is what a note voided pending inspection chooses.
 */
enum CreditMemoVoidDisposition: string
{
    /**
     * The goods are not in the building: they never arrived, or they have gone back to the customer.
     *
     * The receipt is undone — the units come off the books out of `returned`, so
     * `quarantine_quantity` falls by exactly what issuing raised it by. This is the answer that
     * stops a voided note leaving inventory nobody has.
     */
    case NotHere = 'not_here';

    /**
     * The goods are here and they are scrap: `returned → damaged`.
     *
     * The units stay in the building and stay unsellable; they stop being described as awaiting a
     * ruling and start being described as written off. `quarantine_quantity` falls,
     * `write_off_quantity` rises by the same figure, and `quantity` — the sellable count — never
     * moves. The owner's words for why this is the common case: "something's wrong, the shit's bad,
     * u have to write it off".
     */
    case WrittenOff = 'written_off';

    /**
     * The goods are here, in quarantine, and nothing about them changes.
     *
     * The note was voided for a financial reason — wrong figure, wrong customer, to be re-raised —
     * and the physical return stands. Nothing moves, and ONE ZERO-QUANTITY movement per product
     * records that nothing moved: the ledger is the chronological record of everything, and if the
     * one outcome where nothing happened were the one outcome with no entry, it would be the case
     * hardest to explain later that had no trace.
     *
     * The units are then in the same place a declined-after-receipt sales return leaves its units,
     * and carry the same known cost: `returned` is document-backed, so no adjustment screen will
     * move them. Whoever inspects them rules on them, exactly as they would there.
     */
    case StillHere = 'still_here';

    /** The heading on the void screen's radio, and the phrase the timeline uses. */
    public function label(): string
    {
        return match ($this) {
            self::NotHere => 'Not here',
            self::WrittenOff => 'Here, but written off',
            self::StillHere => 'Still here, awaiting a ruling',
        };
    }

    /** The sentence stored on the movement group, which is what a person reading the ledger gets. */
    public function reasonFor(string $documentNumber): string
    {
        return match ($this) {
            self::NotHere => sprintf(
                'Credit note %s voided; the goods are not here, so the units it brought in have been taken back off the books.',
                $documentNumber,
            ),
            self::WrittenOff => sprintf(
                'Credit note %s voided; the goods came back damaged and were written off, not returned to sellable stock.',
                $documentNumber,
            ),
            self::StillHere => sprintf(
                'Credit note %s voided; the goods are still here awaiting a ruling. Nothing moved — this entry records that decision.',
                $documentNumber,
            ),
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return [self::NotHere, self::WrittenOff, self::StillHere];
    }
}
