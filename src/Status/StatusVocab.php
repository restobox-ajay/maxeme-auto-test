<?php

declare(strict_types=1);

namespace App\Status;

/**
 * One document type's status vocabulary: what statuses exist and what they are called.
 *
 * A dictionary, not logic — the handoff's section 3 ruling, and ruling R4 has since taken the last
 * piece of logic out of it. It does NOT know the initial state: the entities keep their hardcoded
 * field initialiser (`= XStatus::Draft`) and there is no `initial` key here.
 *
 * ## AMENDED — no shipped vocabulary declares `transitions` any more
 *
 * The owner deleted the `from => [allowed targets]` table (`STATUS-SEAM-HANDOFF.md`, R4): it had no
 * consumers outside the status layer, it was wrong in both directions at once, and a grid cannot
 * express a rule that depends on the document's own facts. No provider in this codebase declares one
 * now, and no document consults one: what a document will accept is stated in its own `setStatus()`,
 * which is the single gate.
 *
 * {@see self::allows()} and {@see self::transitionsFrom()} therefore answer about data nothing
 * supplies. They are kept only because `tests/Status/StatusVocabTest.php` is built around them and
 * ruling R5 permits no edit to an existing test that is not a call site which will not compile.
 * **They are vestigial — do not wire anything new to them**, and they should go with that test file
 * whenever the owner chooses to remove it by hand. The parsing and boot-time validation below stays
 * for the same reason: `StatusVocabularyLoaderTest` feeds it fixtures that declare the key.
 *
 * **The list of facts is what survives, and it is load-bearing**: `statusLabel()`,
 * `listStatuses()`, the status badge and the status column all read it, and
 * `applyDerivedStatus()` refuses to write a status it does not mark `derived`.
 *
 * ## Slug and label are not the same thing
 *
 * The **slug** is the value in the column: the thing guards compare, and what scripts, imports,
 * exports and integrations depend on. It is frozen — `'On Hold'` and `'Partially Received'` keep
 * their spaces and capitals forever, because that is the wire format.
 *
 * The **label** is display only, and a customer may change it freely. Relabelling `Draft` to
 * `Unsubmitted` touches no data and breaks no script.
 *
 * Today every label equals its slug, which is exactly why nothing has noticed the difference. The
 * moment they diverge, anything printing the slug shows the wire value instead of the customer's
 * word — and it fails silently, because the page still renders something plausible. That is what
 * `HasStatus::statusLabel()` exists to prevent.
 *
 * ## `derived` is a flag on a status, not an exclusion from the map
 *
 * A derived status is one the deriver is allowed to WRITE — it is not "a status no human may
 * reach". `SalesOrderStatus::derivable()` includes `Approved`, which `SalesOrder::approve()` also
 * sets by hand, and `PurchaseOrderStatus::isDerivable()` includes `Issued`, which
 * `PurchaseOrder::issue()` also sets by hand. So the flag transcribes "the deriver may write this"
 * and nothing stronger; a screen that hides every flagged move would hide the Approve button.
 *
 * Derived moves stay IN {@see \App\Contract\Status\HasStatus::allowedTransitions()} carrying the
 * flag, rather than being left out of it. They are reachable — they are just not a human's to make —
 * and a method that omitted them would be lying.
 */
final class StatusVocab
{
    /**
     * @param array<string, array{label: string, derived: bool}> $statuses    slug => definition
     * @param array<string, list<string>>                        $transitions from-slug => to-slugs
     */
    private function __construct(
        public readonly string $key,
        private readonly array $statuses,
        private readonly array $transitions,
    ) {
    }

    /**
     * Builds a vocabulary from a provider's raw array, checking it against itself as it goes.
     *
     * Every check here is a boot-time failure by design. A vocabulary naming a transition target
     * that is not one of its own statuses is a typo in a hand-written array, and the alternative to
     * failing loudly is a transition that silently never fires — which is the exact class of defect
     * this seam exists to delete.
     *
     * @param array{statuses: array<string, string|array{label?: string, derived?: bool}>, transitions?: array<string, list<string>>} $definition
     *
     * @throws \LogicException on a malformed or self-inconsistent definition
     */
    public static function fromArray(string $key, array $definition): self
    {
        if ($definition['statuses'] === []) {
            throw new \LogicException(sprintf('Status vocabulary "%s" declares no statuses.', $key));
        }

        $statuses = [];
        foreach ($definition['statuses'] as $slug => $spec) {
            if (!is_string($slug) || trim($slug) === '') {
                throw new \LogicException(sprintf('Status vocabulary "%s" has a status with an empty slug.', $key));
            }

            // A bare string is shorthand for "label only, not derived" — the common case, and the
            // shape most of these entries have.
            $spec = is_string($spec) ? ['label' => $spec] : $spec;

            $label = $spec['label'] ?? $slug;
            if (trim($label) === '') {
                throw new \LogicException(sprintf('Status "%s" in vocabulary "%s" has an empty label.', $slug, $key));
            }

            $statuses[$slug] = ['label' => $label, 'derived' => (bool) ($spec['derived'] ?? false)];
        }

        $transitions = [];
        foreach ($definition['transitions'] ?? [] as $from => $targets) {
            if (!isset($statuses[$from])) {
                throw new \LogicException(sprintf(
                    'Status vocabulary "%s" declares transitions FROM "%s", which is not one of its statuses (%s).',
                    $key,
                    $from,
                    implode(', ', array_keys($statuses)),
                ));
            }

            foreach ($targets as $target) {
                if (!isset($statuses[$target])) {
                    throw new \LogicException(sprintf(
                        'Status vocabulary "%s" declares the transition "%s" -> "%s", but "%s" is not one of its'
                            . ' statuses (%s).',
                        $key,
                        $from,
                        $target,
                        $target,
                        implode(', ', array_keys($statuses)),
                    ));
                }
            }

            $transitions[$from] = array_values(array_unique($targets));
        }

        return new self($key, $statuses, $transitions);
    }

    /** Is this slug one this vocabulary knows? The one question that answers false rather than throwing. */
    public function has(string $slug): bool
    {
        return isset($this->statuses[$slug]);
    }

    /** @return list<string> every slug, in declaration order */
    public function slugs(): array
    {
        return array_keys($this->statuses);
    }

    /**
     * The whole vocabulary as slug => label, which is what a filter bar and a dropdown both want.
     *
     * Both halves, deliberately: the slug is the option value and the label is the option text. A
     * control built with a single string posts a label, which then matches no row at all.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return array_map(static fn (array $status): string => $status['label'], $this->statuses);
    }

    /** @throws \LogicException on a slug this vocabulary does not know — the typo guard */
    public function labelFor(string $slug): string
    {
        $this->assertKnown($slug);

        return $this->statuses[$slug]['label'];
    }

    /** May the deriver write this status? See the class docblock on what the flag does and does not mean. */
    public function isDerived(string $slug): bool
    {
        $this->assertKnown($slug);

        return $this->statuses[$slug]['derived'];
    }

    /**
     * VESTIGIAL (ruling R4) — no shipped vocabulary declares transitions, so this answers the empty
     * list for every real one. Nothing in the application calls it; see the class docblock for why
     * it is still here and what it is waiting on. The live answer is
     * {@see \App\Contract\Status\HasStatus::allowedTransitions()}.
     *
     * The legal moves out of $slug, each carrying its target's `derived` flag — including out of a
     * slug this vocabulary has never heard of.
     *
     * **You can always leave a place that no longer exists; you just cannot go back to it.** A
     * stored value the vocabulary no longer knows is not a final status, it is a stranded one: every
     * status this vocabulary DOES know is a way out of it, and nothing is a way back in. Answering
     * with the empty list instead — which reads as "final" everywhere it is consumed — is what left
     * a document holding a legacy value permanently unsaveable, with a picker offering nothing.
     *
     * This is the same ruling {@see self::allows()} makes about a move, and the same one section 7
     * of the handoff already makes about a load: report an unrecognised value, never refuse on it.
     *
     * @return array<string, array{label: string, derived: bool}> target-slug => definition
     */
    public function transitionsFrom(string $slug): array
    {
        if (!$this->has($slug)) {
            return $this->statuses;
        }

        $moves = [];
        foreach ($this->transitions[$slug] ?? [] as $target) {
            $moves[$target] = $this->statuses[$target];
        }

        return $moves;
    }

    /**
     * VESTIGIAL (ruling R4) — no shipped vocabulary declares transitions, so this answers true only
     * for a self-move on every real one. Nothing in the application calls it; the live answer is
     * {@see \App\Contract\Status\HasStatus::canTransitionTo()}, which asks the document.
     *
     * Is this move legal — the ONLY question this map answers about a transition.
     *
     * It deliberately knows nothing about payments, receipts or balances. "An invoice holding
     * payments cannot be cancelled" and "a purchase order with receipts cannot be cancelled" are
     * facts about rows on the document, not about its status, and they stay in the verb's own
     * guard where the from-state and the caller's intent are both still in hand.
     *
     * A move to the status the document already holds is legal and is a no-op — see
     * `HasStatus::setStatus()`, which normally must not throw on one, because that is what keeps a
     * recalculation on every flush from being a refusal.
     *
     * ## The two ends of this question are NOT symmetric
     *
     * **$to must be known, and that stays a throw.** Nothing may transition INTO a status this
     * vocabulary does not have: that is the typo guard, it is the only reason `isStatus()` exists
     * rather than a raw `===`, and a silent false here would put it back.
     *
     * **$from need not be.** An unrecognised stored value permits every move OUT, because the
     * alternative is a document that can never be saved again by any means — the guard read
     * `!has($from) || !allows($from, $to)`, so the refusal fired before the target was even
     * considered, and every path that writes a status (the deriver included) died on it. The owner's
     * ruling: *you can always leave a place that no longer exists; you just cannot go back to it.*
     *
     * $from is never a literal anybody typed — it is what `readStatus()` found in the column — so
     * tolerating it here hides no typo. $to is always the caller's argument, so it still cannot be
     * one.
     */
    public function allows(string $from, string $to): bool
    {
        $this->assertKnown($to);

        if (!$this->has($from)) {
            return true;
        }

        return $from === $to || in_array($to, $this->transitions[$from] ?? [], true);
    }

    private function assertKnown(string $slug): void
    {
        if (!isset($this->statuses[$slug])) {
            throw new \LogicException(sprintf(
                'There is no status "%s" in the "%s" vocabulary. It has: %s.',
                $slug,
                $this->key,
                implode(', ', array_keys($this->statuses)),
            ));
        }
    }
}
