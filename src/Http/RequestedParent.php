<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Which parent document a CREATE (or edit-in-place) screen was opened against, when the URL names
 * one by id (queue item 51; moved here from ProcurementBundle 2026-09-17 — a namespace change and
 * nothing else, now that the sell side's payment-move screen needs the same three-state read for
 * `?payment=` that the bill payments screen already had).
 *
 * ## The defect this exists to close
 *
 * Every purchase-side create screen is reachable two ways: bare — `/bills/new`, raise a standalone
 * bill — and against a parent — `/bills/new?po=42`, bill that order. The id arrived through
 * `$request->query->getInt('po', 0)`, and that one call produced BOTH of the failures the owner's
 * standing rule forbids:
 *
 *   - `?po=abc` and `?po=` threw `BadRequestException` out of Symfony's `InputBag::filter()`,
 *     because `FILTER_VALIDATE_INT` rejects them and the flag that would return null is not set.
 *     The admin got a raw 400 error page — failing OBSCURELY.
 *   - `?po=99999` cast cleanly to 99999, `find()` returned null, and the screen rendered the BARE
 *     form: the same blank standalone screen you get from `/bills/new` with no parameter at all.
 *     Somebody who clicked "Enter a bill" from a purchase order was shown a form that would raise
 *     a bill against NOBODY, and nothing on it said so — failing SILENTLY, and then failing
 *     QUIETLY WRONG if they typed into it.
 *
 * Both come from the same collapse `App\Service\CompanyListScope` names on the sell side: a
 * nullable parent cannot tell "the URL named no parent" apart from "the URL named a parent and
 * there is no such thing". Those are different questions with different answers, and merging them
 * means the second one silently gets the first one's.
 *
 * ## Three states, and the third is the reason this is a type
 *
 *   - NOT REQUESTED   the URL named no parent. The bare create screen, which is a real feature.
 *   - RESOLVED        the URL named one and it exists. The screen is opened against it.
 *   - UNRESOLVED      the URL named one and it does not exist, or is not an id at all.
 *
 * UNRESOLVED renders the screen — the owner's ruling is that the screen CARRIES ON — with a loud,
 * specific notice naming what was asked for and what was not found. It deliberately does NOT fall
 * back to the bare form's behaviour, because the bare form is an answer to a different question.
 *
 * ## Why this is not `CompanyListScope`
 *
 * It is the same three states and it uses that class's own `isIdShaped()` rather than inventing a
 * fourth spelling of "is this an id" — `^\d+$` plus `> 0`, so `12abc` is junk rather than row 12.
 * What differs is the answer each gives its caller. A LIST scoped to an unresolvable customer must
 * show NOTHING, because showing everything answers a question nobody asked. A CREATE screen has
 * nothing to withhold: there are no rows, the form is the same form, and refusing to render it
 * would replace one unhelpful screen with another. So the list fails closed and the create screen
 * fails LOUD, and the two are not the same policy over the same type.
 *
 * That difference is why the shared part here is `isIdShaped()` and the three-state SHAPE, not the
 * class. See the report on queue item 51 for what a genuinely shared abstraction needed from core —
 * this move is that abstraction, once the freeze that kept it in ProcurementBundle lifted.
 *
 * @template T of object
 */
final class RequestedParent
{
    /**
     * @param T|null $entity
     */
    private function __construct(
        private readonly ?object $entity,
        private readonly ?string $requestedId,
        private readonly string $noun,
        /**
         * The set this screen searched, when it searched one rather than the whole table —
         * "one of this bill's payments". Null when the question genuinely was "does this exist at
         * all". See {@see notOneOf()} for why the difference is a different sentence.
         */
        private readonly ?string $collection = null,
    ) {
    }

    /**
     * The URL named no parent at all. The bare create screen.
     *
     * @return self<T>
     */
    public static function none(string $noun): self
    {
        return new self(null, null, $noun);
    }

    /**
     * The URL named this parent and it exists.
     *
     * @param T $entity
     *
     * @return self<T>
     */
    public static function of(object $entity, string $requestedId, string $noun): self
    {
        return new self($entity, $requestedId, $noun);
    }

    /**
     * The URL named a parent that could not be found, or a value that is not an id.
     *
     * @return self<T>
     */
    public static function unresolved(string $requestedId, string $noun): self
    {
        return new self(null, $requestedId, $noun);
    }

    /**
     * The URL named something this screen looked for inside ONE document's own rows and did not
     * find there — `?payment=42` on bill 7 when payment 42 is bill 9's, `?bin=3` when bin 3 belongs
     * to another warehouse.
     *
     * A separate state because "there is no such payment" would be a LIE on exactly the case that
     * brings somebody here: a stale link from the other bill, where the row does exist. Collapsing
     * the two would be the same mistake one level down as collapsing "nothing was asked for" into
     * "what was asked for is missing" — a sentence that reads as authoritative and is false.
     *
     * It also answers the missing-entirely case, deliberately: from this screen's side, an id that
     * names nothing and an id that names somebody else's row are the same fact — it is not one of
     * these — and one true sentence beats two that have to be told apart first.
     *
     * @param string $collection what this screen searched, as it reads mid-sentence:
     *                           "which is not one of this bill's payments"
     *
     * @return self<T>
     */
    public static function notOneOf(string $requestedId, string $noun, string $collection): self
    {
        return new self(null, $requestedId, $noun, $collection);
    }

    /**
     * The id this request asks a create screen to open against, exactly as it was typed, or null
     * when it asks for none.
     *
     * Read out of `$request->query->all()` and indexed, NEVER through `getInt()` or `getString()`.
     * That is not a stylistic preference: `InputBag::filter()` throws `BadRequestException` both
     * for a value that fails the filter (`?po=abc`, `?po=`) and for one that arrives as an array
     * (`?po[]=1`), and that exception IS the raw 400 this item is about. Reading the bag once and
     * indexing it cannot throw whatever shape the URL is, which is half of "must not 500" — the
     * same reasoning `CompanyListScope::requestedIdIn()` gives for reading the whole bag.
     *
     * An empty value is NOT a request for a parent. `?po=` is somebody having cleared the box or
     * hand-trimmed the URL, and on a create screen "no parent named" is a real, supported answer —
     * the standalone form. Null is that case. This matches `CompanyListScope` exactly rather than
     * differing from it for no reason.
     *
     * The empty STRING is the third answer and it is not the same one: something WAS asked for and
     * it cannot be shown as typed, because it never arrived as a value at all (`?po[]=1`). That
     * still counts as a request, so it still says so; only the wording of the notice differs.
     *
     * ## Zero is this application's OWN spelling of "none", and reporting it was a false alarm
     *
     * `0` is not a row id anywhere — `CompanyListScope::isIdShaped()` has always said so — but on
     * the buy side it is not junk either: it is the value the screens' own controls submit for
     * "none". The scan console's pickers are literally
     * `<option value="0">— receive with no purchase order —</option>`, `<option value="0">—</option>`
     * for the vendor and the warehouse, and `<option value="0">— no bin —</option>`, and its "Which
     * shelf" form posts `po=0` on every submit when no order is in play.
     *
     * So the first pass at item 51 made the console accuse itself: choosing "receive with no
     * purchase order" and pressing Set reloaded the screen with "That purchase order doesn't exist.
     * This link asks for purchase order 0" across the top of it. A notice that fires on the app's
     * own control is worse than no notice, because it is the one that teaches people to ignore the
     * red box — and the box is the whole of this item.
     *
     * Zero is therefore read as NOT REQUESTED, exactly like the empty value it means. A digits-only
     * value worth nothing is the test rather than the literal string, so `00` and `0000` — what a
     * hand-edited URL or a zero-padded export produces — answer the same way instead of falling
     * through to "there is no such purchase order 00".
     *
     * @param array<string, mixed> $query the whole of `$request->query->all()`
     */
    public static function requestedIdIn(array $query, string $key): ?string
    {
        $raw = $query[$key] ?? null;

        if ($raw === null) {
            return null;
        }

        if (!is_scalar($raw)) {
            return '';
        }

        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        // Digits that add up to nothing: the screens' own "— none —" option, not an id.
        return preg_match('/^0+$/', $raw) === 1 ? null : $raw;
    }

    /**
     * The parent, or null when there is not one — which covers BOTH "none was asked for" and "the
     * one asked for does not exist". Callers that must tell those apart ask {@see isUnresolved()};
     * every caller that only needs "is there a parent to pre-fill from" can use this and be right.
     *
     * @return T|null
     */
    public function entity(): ?object
    {
        return $this->entity;
    }

    /** The id as the URL gave it, for the notice that names it back to the reader. */
    public function requestedId(): ?string
    {
        return $this->requestedId;
    }

    /** What to call this thing on screen — 'purchase order', 'vendor', 'goods receipt'. */
    public function noun(): string
    {
        return $this->noun;
    }

    public function isRequested(): bool
    {
        return $this->requestedId !== null;
    }

    /** Asked for a parent, got nothing. The screen renders and says so. */
    public function isUnresolved(): bool
    {
        return $this->requestedId !== null && $this->entity === null;
    }

    /**
     * What the screen says when the id resolved to nothing — clear, loud, and specific about which
     * of the two ways it failed, because the remedies differ: a mistyped id is retyped, and an id
     * that is not an id at all usually means a broken link somebody should fix.
     *
     * Empty string when there is nothing wrong, so a template can render it unconditionally.
     */
    public function notice(): string
    {
        if (!$this->isUnresolved()) {
            return '';
        }

        // The empty string is `?po[]=1` and friends: a request arrived, but not as a value that can
        // be quoted back. Saying 'no such purchase order ""' would be worse than saying what
        // actually happened.
        if ($this->requestedId === '') {
            return sprintf(
                'This link asks for a %s but does not give an id that can be read. '
                    . 'Nothing has been pre-filled from it. Open the %s you meant and start from there, '
                    . 'or fill this form in as a standalone document.',
                $this->noun,
                $this->noun,
            );
        }

        // Looked for inside one document's own rows rather than in the table. See notOneOf().
        if ($this->collection !== null) {
            return sprintf(
                'This link asks for %s %s, which is not %s. Nothing has been pre-filled from it, '
                    . 'and this form is starting a new one instead. Check the id.',
                $this->noun,
                $this->requestedId,
                $this->collection,
            );
        }

        return sprintf(
            'That %s doesn\'t exist. This link asks for %s %s, and there is no such %s. '
                . 'Nothing has been pre-filled from it. Check the id, or fill this form in as a standalone document.',
            $this->noun,
            $this->noun,
            $this->requestedId,
            $this->noun,
        );
    }
}
