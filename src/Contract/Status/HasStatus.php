<?php

declare(strict_types=1);

namespace App\Contract\Status;

use App\Contract\Document\DocumentLog;
use App\Exception\StatusTransitionRefused;
use App\Service\DocumentActor;
use App\Status\StatusVocab;

/**
 * One way to get a status, set a status, ask whether a status is valid, and list the statuses a
 * thing can have (handoff section 1).
 *
 * **Its own interface, not methods bolted onto `CommercialDocument`.** Status is not a document
 * concept: a product has one, a warehouse has one, a user has one. Building it separately now costs
 * the same as extracting it later.
 *
 * ## Why `loadStatusVocab()` is STATIC, which was reached the hard way
 *
 * The obvious designs all fail, and the failures are recorded here so nobody re-derives them:
 *
 *  - **Pass the vocabulary in** — `setStatus(string $status, StatusVocab $vocab)`. Every caller then
 *    has to fetch the right vocabulary, and `$invoice->setStatus('Received', $purchaseOrderVocab)`
 *    sails straight through. That is precisely the hand-rolled knowledge this work exists to delete.
 *  - **A service as the only door** — `$statuses->setStatus($invoice, 'Approved')`. Works, but the
 *    entity still needs some way to write the column, and that becomes the new back door, guarded by
 *    nothing but a test.
 *  - **A per-instance property filled by a Doctrine `postLoad` listener** — works for hydrated
 *    entities, leaves a gap for `new Invoice()` before persist.
 *
 * Static resolves all three: no argument for a caller to get wrong, no instance property, no
 * hydration gap, and the service-locator call is confined to **one well-named method per class**
 * instead of leaking into every call site. {@see \App\Status\StatusVocabularyRegistry} is that one
 * call.
 *
 * **No trait.** With `loadStatusVocab()` static there is nothing per-instance to share, and the two
 * abstract base classes absorb the method bodies for the documents that extend them.
 *
 * ## `listStatuses()` and `allowedTransitions()` are deliberately separate
 *
 * A filter bar wants all six statuses; a detail screen wants "you may Issue or Cancel". Collapsing
 * them means every screen filters the list itself, which is the defect that put an Edit button on
 * all 37 purchase orders and had it succeed on 7. `listStatuses()` is static because a filter bar
 * has no row in hand; `allowedTransitions()` cannot be, because it reads current state.
 *
 * ## There is ONE gate, and the vocabulary carries no rules
 *
 * `setStatus()` is it. A document on this interface has no status verb a caller can reach instead —
 * owner rulings R1 and R2 — and the vocabulary behind `listStatuses()` is a LIST OF FACTS:
 * statuses, labels, and whether the deriver may write each one. The `transitions` table that used to
 * sit beside them is deleted (R4); what a document will and will not accept is stated in its own
 * `setStatus()`, and there is no second source.
 *
 * ## Where this interface departs from the handoff, and why
 *
 * **`newLogEntry()` returns `?DocumentLog`, not `DocumentLog`.** The handoff says the two abstracts
 * supply it "for nine of the ten documents". They cannot: only five of the eleven status-bearing
 * documents have a log entity at all. `CreditMemo`, `SalesReturn`, `DebitMemo`, `VendorReturn` and
 * `RfqVendorReply` have no `*Log` class and no `logs` collection — `CreditMemo` and `DebitMemo` are
 * covered by `AuditLogSubscriber` picking up the status column instead. Giving them one is five new
 * tables and a migration, which is a long way outside a shape-only change. So a document with no
 * timeline returns null and `setStatus()` writes no row for it, which is exactly what happens today.
 * Flagged rather than quietly changed: see the stage 1 report.
 */
interface HasStatus
{
    /** Which vocabulary governs me: 'invoice', 'purchase_order', 'sales_order'. */
    public function statusVocabulary(): string;

    /** Resolve this class's vocabulary. STATIC — see the class docblock, it matters. */
    public static function loadStatusVocab(): StatusVocab;

    /**
     * The raw stored value.
     *
     * **FORGIVING — returns values the vocabulary no longer knows.** A legacy or orphaned value
     * still comes back: the quote-era `'Waiting for Quote'` strings on `sales_order`, a pre-enum
     * `'APPROVED'`, a stray written straight into the column. A row you cannot load is a row you
     * cannot repair, and you would be fixing it in SQL. See {@see self::statusIsRecognised()}.
     *
     * ## A string on every document
     *
     * `Invoice` and `CreditMemo` used to store `enumType:` columns and return `InvoiceStatus` /
     * `CreditMemoStatus` here, so this was declared `string|\BackedEnum` with each document
     * narrowing it. Both columns are plain strings now and the union is gone.
     *
     * The two enums did not go with them: they are the frozen vocabulary — which slugs exist, and
     * the rules hung off them like `InvoiceStatus::acceptsPayment()` — not the storage. A document
     * that wants one asks `getStatusEnum()`, which returns null for a stored value the enum does not
     * know, exactly as `SalesOrder` and `ProductCore` already did. Section 7 stands: a row holding a
     * legacy string still loads, still displays, and can still be moved off it.
     *
     * **Nothing in the seam reads this method.** `statusLabel()`, `isStatus()`, `canTransitionTo()`,
     * `allowedTransitions()`, `statusIsRecognised()`, `applyDerivedStatus()` and the gate itself all
     * go through the protected `readStatus()`, which is a `string` on every document.
     */
    public function getStatus(): string;

    /**
     * THE GATE. Writes the status AND the timeline row, and it is the only thing that does.
     *
     * Owner ruling R1: *"there is only 1 gate where set status can happen, and any changes we do is
     * only at one place."* A document does not also carry a status verb somebody could call
     * instead — `approve()`, `void()`, `cancel()` are gone, and their guards and their wording are
     * inside this method or a PRIVATE method it calls.
     * `tests/Functional/NoStatusVerbIsReachableCest.php` re-asks that question on every run.
     *
     * The other door is {@see self::applyDerivedStatus()}, and which one a caller uses is decided by
     * what the caller KNOWS: name a target and you are here; want a recalculation whose rules and
     * outcome you neither know nor need and you are there.
     *
     * Returns the resulting status — not void, not bool. The owner: *"we have a duty to return
     * status at all times."* A bool can be ignored silently, which is the fail-silently the owner
     * has banned.
     *
     * **A move to the status already held is normally a silent no-op** — it writes nothing and logs
     * nothing, which is what keeps a recalculation on every flush that happened to touch the
     * document from being either a refusal or a second identical timeline row. A document may still
     * refuse one where its own rules always did: voiding an order that is already void was a loud
     * mistake when `void()` owned the rule and it is a loud mistake now.
     *
     * **`$actor` is REQUIRED and must not be made optional.** It was proposed and rejected in
     * discussion. Optional has two failure modes and both are silent: with no default the log row
     * carries an empty userName — a status change attributed to nobody, which is the hole this
     * closes, reopened at every call site that omits the argument; with a System default, a human
     * action that forgot the argument is recorded as the machine having done it, which is not a
     * missing record but a FALSE one, perfectly plausible on the screen, and nothing will ever flag
     * it. `DocumentActor` ships `forAdmin()`, `forCustomer()`, `system()`, `automation()` and
     * `named()`, so every call site can name one.
     *
     * `$comment` may be optional because its fallback is still true — "Status changed to Approved"
     * is honest. Every possible default for an actor is a claim about a person.
     *
     * **A document holding a value the vocabulary no longer knows may still be moved OFF it**, to
     * any status the vocabulary does know. The reverse never happens: $status is the caller's
     * argument and an unknown one is the typo guard's throw, so nothing writes an unrecognised
     * value in. A guard that refused both directions made such a document permanently unsaveable —
     * the save path reaches this method through the deriver — with no manual escape at all.
     *
     * @throws StatusTransitionRefused when the document does not go there from where it is
     * @throws \DomainException when the document's own rules refuse the request
     * @throws \LogicException on a status this vocabulary does not know — the typo guard
     */
    public function setStatus(string $status, DocumentActor $actor, ?string $comment = null): string;

    /**
     * Can this document GET there from where it is now?
     *
     * **A true answer is not permission.** It says the document is not at a dead end and the target
     * is one it knows; it does not promise the gate will accept the move, because a rule about the
     * caller's REQUEST can still refuse it. An order stranded on a legacy value answers true for
     * `Approved` and is still told "Only a Draft order can be approved" when somebody presses it —
     * which is exactly what happened while a transitions map answered this question, since the map
     * never knew what the verbs enforced. The split survived the map's deletion; only the source
     * moved, from a grid into the document's own code.
     *
     * False — never a throw — for a target the vocabulary does not know: this is the boolean
     * question, and nothing may transition INTO a status that does not exist. The other end is not
     * symmetric: an unrecognised CURRENT status is not a dead end, so it answers true for every
     * known target.
     */
    public function canTransitionTo(string $status): bool;

    /**
     * The whole vocabulary: slug => label. STATIC — a filter bar has no row in hand.
     *
     * Both halves, always. A dropdown carries the slug as the option value and the label as the
     * option text; a control built from a single string posts a label, which matches no row.
     *
     * @return array<string, string>
     */
    public static function listStatuses(): array;

    /**
     * The moves on offer from the CURRENT state, each carrying its `derived` flag.
     *
     * Every status the vocabulary knows, minus where the document already is and minus anything a
     * terminal state puts out of reach. There is no `from -> to` grid behind this any more (ruling
     * R4) and the answer is unchanged by that: for every document on the seam the grid said exactly
     * "everything except where you are", with the empty list for its one terminal status.
     *
     * Derived moves are IN here. They are reachable — they are just not a human's to make — and
     * leaving them out would make this method lie. A button bar filters on the flag. Note that the
     * flag means "the deriver may write this" and not "no human may": `Approved` on a `SalesOrder`
     * is both. See {@see StatusVocab}.
     *
     * **An unrecognised stored value answers with every status the vocabulary knows, not with the
     * empty list.** You can always leave a place that no longer exists; you just cannot go back to
     * it. The empty list reads as "final" to everything that consumes this — a picker built from it
     * renders no options — so it would leave a document stranded on a legacy value with nothing on
     * screen offering a way off it. This is the transition-side form of the rule
     * {@see self::statusIsRecognised()} states for a load: report, never refuse.
     *
     * @return array<string, array{label: string, derived: bool}> target-slug => definition
     */
    public function allowedTransitions(): array;

    /**
     * Is MY status this one?
     *
     * **The COMPARISON, not a validity check.** It replaces `$this->status === PurchaseOrderStatus::Draft`
     * at every call site, and it THROWS on a status the vocabulary does not know.
     *
     * That throw is the whole point of the method. Today a typo in `PurchaseOrderStatus::Draftt` is
     * a fatal error before the code ever runs; a raw `getStatus() === 'Draftt'` would **silently
     * return false** — no error, wrong branch taken, nobody told. Keeping the enums as the slug
     * contract does not fix that, because the comparison itself is still a string. This puts the
     * loudness back at runtime where the compiler used to put it.
     *
     * It was called `hasStatus()`. The owner renamed it — do not rename it back. The old name
     * described vocabulary membership rather than what the method does, and it misled its own author
     * inside the design document.
     *
     * "Is this a known status" needs no method here — it falls out of `listStatuses()`. Untrusted
     * input that needs a boolean rather than a throw uses {@see self::statusIsRecognised()}.
     *
     * @throws \LogicException on a status this vocabulary does not know
     */
    public function isStatus(string $status): bool;

    /**
     * Does my STORED value still exist in the vocabulary — report, never refuse (handoff section 7).
     *
     * The boolean form, for the one place a throw is wrong: a row that hydrated holding a value
     * nobody recognises. `ProductCore` already landed this shape and is the reference — the row
     * hydrates, `getStatus()` returns the stored value, the lookup finds nothing, and the grid
     * renders `Waiting for Stock (unrecognised)`.
     *
     * The lookup is against the VOCABULARY, not the enum. `ProductCore` checks its enum today;
     * under section 5 no production code reads an enum. The behaviour is identical.
     */
    public function statusIsRecognised(): bool;

    /**
     * What to PRINT — never the slug (handoff section 9).
     *
     * Today every screen prints the raw status value and looks correct, because the slug and the
     * label are the same string. The moment a customer relabels one, each of those screens shows the
     * wire value instead of their word — and it fails silently, because the page still renders
     * something plausible.
     *
     * On the entity rather than in a Twig filter because the entity already knows its vocabulary; a
     * filter would have to be handed the key again at every call site, which is the hand-rolled
     * knowledge this work exists to delete.
     *
     * An unrecognised stored value returns itself, marked — it never throws, for the same reason
     * {@see self::statusIsRecognised()} does not.
     */
    public function statusLabel(): string;

    /** What this document's own facts say its status should be. null = derives nothing. */
    public function deriveStatus(): ?string;

    /**
     * Applies {@see self::deriveStatus()}. Returns TRUE only when the status actually CHANGED.
     *
     * **Takes no status argument.** The caller does not decide what the status should be — the
     * document does. That removes the last place a caller could hand a document the wrong answer,
     * the same way `setStatus()` removed it for vocabularies.
     *
     * It takes an actor because it writes the status and therefore writes a log row like any other
     * write; the deriver passes `DocumentActor::system()`, which is what it already uses today. The
     * deriver must stop writing its own entry — under section 8 the row belongs to `setStatus()`,
     * and leaving both in place would put two rows on every derived transition.
     *
     * Three behaviours it must keep, all of which exist today:
     *
     *  1. **It refuses to write a status the vocabulary does not mark `derived`** — the list that
     *     was `SalesOrderStatus::derivable()` and `PurchaseOrderStatus::isDerivable()`.
     *  2. **It refuses to recompute a document its own rules say is out of the deriver's reach** —
     *     a Void sales order, a Cancelled or Closed purchase order. Void is a judgement about the
     *     document, not a consequence of what happened to one of its invoices.
     *  3. **A no-op is LEGAL, not a refusal**, and returns false. That is what keeps the log to one
     *     entry per real transition rather than one per flush that happened to touch the document.
     *
     * A document that derives nothing returns null from `deriveStatus()` and false from here.
     */
    public function applyDerivedStatus(DocumentActor $actor): bool;

    /**
     * This document's own log row, empty, for `setStatus()` to fill — or null if it keeps no
     * timeline.
     *
     * Nullable, which departs from the handoff. See the class docblock: six of the eleven
     * status-bearing documents have no log entity, and giving them one is five tables and a
     * migration.
     */
    public function newLogEntry(): ?DocumentLog;

    /**
     * May this document's own STATUS still allow editing?
     *
     * This is only the status half of "can this document be edited." A separate, per-instance
     * lock ({@see \App\Service\Document\DocumentLockService} on the sell side) can refuse an edit
     * this method would allow — a named person freezing a document whose status says nothing
     * against it — and the two are asked separately wherever both apply; neither replaces the
     * other. This method answers the status question alone, the same way `isStatus()` answers a
     * pure equality question rather than a business rule.
     *
     * Added when every status-bearing document joined the seam: five documents each answered this
     * in their own shape before — a private controller method taking a raw status string, a public
     * entity method, a bare inline conditional repeated at the point of use — three names for the
     * identical kind of check. Implementations are a one-line delegation to their own status
     * enum's `allowsEditing()`, which is where the actual rule is stated, once, the same reasoning
     * `InvoiceStatus::acceptsPayment()` and `VendorBillStatus::counts()` are stated once for.
     */
    public function canEditOnStatus(): bool;
}
