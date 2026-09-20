<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Contract\Status\HasStatus;
use App\Contract\Status\StatusVocabularyLoaderInterface;
use App\Status\StatusVocabularyRegistry;
use Tests\Support\FunctionalTester;

/**
 * There is ONE gate. No status verb is reachable from outside the entity.
 *
 * ## Why this file exists
 *
 * Owner ruling, recorded in `STATUS-SEAM-HANDOFF.md` under R1 and R2 and not open for
 * relitigation:
 *
 * > *"The purpose of the exercise is there is only 1 gate where set status can happen, and any
 * > changes we do is only at one place. Not fixing 56 instances of handrolling."*
 *
 * > *"SetStatus needs to be the new place for approve and approve() should be removed."*
 * > *"All status verbs."*
 *
 * This was built the wrong way round twice and merged both times — once with a transitions map
 * made authoritative, once with `setStatus()` demoted to protected while the verbs stayed public,
 * which is the exact inverse of the ruling. Both were reverted. This file is what stops a third
 * attempt: it is a machine that re-asks the owner's question on every run, so the answer cannot
 * drift back by anybody's reasoning, mine included.
 *
 * ## The criterion is the NAME, and nothing else
 *
 * > *"Status names cannot be verbs and MUST be moved. It's about status names. Other verbs are
 * > out of scope."*
 *
 * A method named after a status in that document's own vocabulary must not be publicly callable.
 * `approve()` for `Approved`, `void()` for `Void`, `cancel()` for `Cancelled`. That is the whole
 * rule. **Every other method on the class is out of scope** — not "must call the gate", not
 * "should not write the status", simply out of scope. `issue()` is out of scope because `Issued`
 * is not a status in the invoice vocabulary.
 *
 * An earlier version of this file also scanned every public method's source for direct writes to
 * the status property. That went beyond the ruling and is gone: it would have failed methods the
 * owner has said repeatedly are not part of this.
 *
 * ## It is DERIVED, never hand-kept
 *
 * A hand-written list of banned method names is the anti-pattern `docs/QUEUE.md` names elsewhere:
 * it rots, and the day it rots it reads as a considered decision describing something no longer
 * true. So the subject is derived from each document's own vocabulary — every status it can hold
 * is turned back into the English verb that would move it there. A document that adds a status is
 * covered the moment it does so, with no edit here.
 *
 * ## What is still allowed, and asserted to be
 *
 *  - `setStatus()` is public. That is the gate, and the positive control: if this file passed
 *     simply because nothing on the class was public any more, that assertion would fail too.
 *  - **Every method not named after a status.** This file never looks at them. `issue()` is one —
 *     `Issued` is not a status in the invoice vocabulary, and the owner has said so directly:
 *     *"Issue is not a status."* Nothing is required of these methods here.
 *  - A **private** verb that `setStatus()` delegates to. The ruling is about reachability from
 *     outside, not about how the body is organised inside the class.
 */
final class NoStatusVerbIsReachableCest
{
    /**
     * Suffix rules turning a status back into the verb that would set it.
     *
     * Deliberately generous — it is better to test a name nothing was ever going to be called than
     * to miss one. Each rule is `[suffix to strip, what to put back]`.
     */
    private const VERB_ENDINGS = [
        ['lled', 'l'],   // Cancelled  -> cancel
        ['pped', 'p'],   // Shipped    -> ship
        ['ssed', 'ss'],  // Processed  -> process
        ['ied', 'y'],    // Applied    -> apply
        ['ved', 've'],   // Approved   -> approve, Received -> receive
        ['sed', 'se'],   // Closed     -> close, Released   -> release
        ['ted', 'te'],   // Completed  -> complete, Quoted  -> quote
        ['ced', 'ce'],   // Priced     -> price, Invoiced   -> invoice
        ['ded', 'de'],   // Voided     -> void(e)
        ['ged', 'ge'],   // Charged    -> charge
        ['ned', 'ne'],   // Declined   -> decline
        ['ed', ''],      // Submitted  -> submitt, Rejected -> reject
        ['d', ''],       // Issued     -> issue
        ['', ''],        // Void       -> void, Draft       -> draft
    ];

    /**
     * The registry is primed on `kernel.request` and `console.command`, and this file reaches a
     * vocabulary through neither — it asks the classes directly, with no HTTP at all. So it primes
     * the registry from the container itself, exactly as the registry's own error message
     * instructs, and hands it back afterwards.
     */
    public function _before(FunctionalTester $I): void
    {
        StatusVocabularyRegistry::reset();
        StatusVocabularyRegistry::use($I->grabService(StatusVocabularyLoaderInterface::class));
    }

    public function _after(FunctionalTester $I): void
    {
        StatusVocabularyRegistry::reset();
    }

    /**
     * A document cannot dodge the rule by staying off the seam.
     *
     * The scan below only sees classes implementing HasStatus, so not joining made Invoice and
     * CreditMemo invisible to it — and that is exactly where cancel(), complete() and void() were
     * sitting. A guard that can be evaded by declaring one interface less is not a guard.
     */
    public function everyDocumentWithAStatusIsOnTheSeam(FunctionalTester $I): void
    {
        $offSeam = [];

        foreach ($this->entityFiles() as $file) {
            $class = $this->classIn($file);

            if ($class === null || !class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || !$reflection->hasProperty('status')) {
                continue;
            }

            if (!$reflection->isSubclassOf(\App\Entity\AbstractSalesDocument::class)) {
                continue;
            }

            if (!$reflection->implementsInterface(HasStatus::class)) {
                $offSeam[] = $this->shortName($class);
            }
        }

        $I->assertSame(
            [],
            $offSeam,
            "A sell-side document has a status but is not on the seam, so the check below cannot\n"
            . "see it and its status verbs go unexamined. Put it on the seam.",
        );
    }

    public function noStatusVerbDerivedFromAVocabularyIsPubliclyCallable(FunctionalTester $I): void
    {
        $documents = $this->documentsOnTheSeam();

        // Two sentinels, because "found nothing" and "nothing has joined the seam yet" are
        // different failures and only the first one makes this file a liar. The document count is
        // deliberately NOT pinned to a number: the seam is being rolled out one document at a time,
        // and a threshold here would either block that rollout or quietly rot behind it.
        $I->assertGreaterThan(
            30,
            count($this->entityFiles()),
            'the source walk found almost no entity files, so every assertion below is vacuous',
        );

        $I->assertNotEmpty(
            $documents,
            'no class implements HasStatus, so this file proved nothing — check the walk over '
            . 'src/Entity and modules/*/src/Entity',
        );

        $offenders = [];

        foreach ($documents as $class) {
            $vocabulary = $class::loadStatusVocab();

            foreach ($vocabulary->slugs() as $slug) {
                foreach ($this->verbNamesFor($slug) as $verb) {
                    if (!method_exists($class, $verb)) {
                        continue;
                    }

                    $method = new \ReflectionMethod($class, $verb);

                    if ($method->isPublic() && $method->getDeclaringClass()->getName() !== \stdClass::class) {
                        $offenders[] = sprintf(
                            '%s::%s() is public, and "%s" is a status in the "%s" vocabulary',
                            $this->shortName($class),
                            $verb,
                            $slug,
                            $vocabulary->key,
                        );
                    }
                }
            }
        }

        $I->assertSame(
            [],
            array_values(array_unique($offenders)),
            "A status verb is publicly callable. There is to be exactly ONE gate — setStatus().\n\n"
            . "Move the verb's logic into setStatus(), or make the verb PRIVATE and have setStatus()\n"
            . "delegate to it. Either is fine; what must not exist is a second way in.\n\n"
            . "See STATUS-SEAM-HANDOFF.md, rulings R1 and R2. This is not a style preference and it\n"
            . "is not open for relitigation — it has been built the wrong way round twice already.\n\n"
            . "If the method does substantive NON-status work (allocating a number, freezing\n"
            . "addresses, computing tax) it may stay public — but then it must CALL setStatus()\n"
            . "rather than assigning the status itself, which is what the companion test checks.",
        );
    }

    /**
     * Nothing outside the entities changes a status by any route except the gate.
     *
     * > *"And any external class that change status must call set status."*
     *
     * Two routes are checked, because they fail differently. A PHP call to a status verb dies
     * loudly once the verb is gone, so it is cheap to find. **Raw SQL does not** — it is invisible
     * to every PHP-level check in this repo, and handoff section 8 records that the end-to-end
     * walkthrough found seeders writing status literals in embedded SQL exactly that way.
     *
     * Both sides are derived. The verb names come from the vocabularies, and the SQL side matches
     * the shape of a write rather than a list of tables.
     */
    public function noExternalClassChangesAStatusWithoutTheGate(FunctionalTester $I): void
    {
        $verbs = [];

        foreach ($this->documentsOnTheSeam() as $class) {
            foreach ($class::loadStatusVocab()->slugs() as $slug) {
                foreach ($this->verbNamesFor($slug) as $verb) {
                    if (method_exists($class, $verb)) {
                        $verbs[$verb] = $verb;
                    }
                }
            }
        }

        $offenders = [];
        $root = dirname(__DIR__, 2);

        foreach ($this->productionFiles() as $file) {
            if (str_contains($file, '/src/Entity/')) {
                continue;
            }

            $source = (string) file_get_contents($file);
            $short = str_replace($root . '/', '', $file);

            foreach ($verbs as $verb) {
                if (preg_match('/->' . preg_quote($verb, '/') . '\s*\(/', $source) === 1) {
                    $offenders[] = sprintf('%s calls ->%s()', $short, $verb);
                }
            }

            if (preg_match('/(?:UPDATE|INSERT\s+INTO)[^;\n]{0,200}\bstatus\b[^;\n]{0,80}=/i', $source) === 1) {
                $offenders[] = $short . ' writes a status column in raw SQL';
            }
        }

        $I->assertSame(
            [],
            array_values(array_unique($offenders)),
            "Something outside the entities changes a status without going through setStatus().\n\n"
            . "> \"And any external class that change status must call set status.\"\n\n"
            . "Call \$document->setStatus(\$target, \$actor, \$comment) instead. If this names raw\n"
            . "SQL, that is the worst case — no PHP-level check in this repo can see it, which is\n"
            . "how status literals in seeders went unnoticed before.\n\n"
            . "See STATUS-SEAM-HANDOFF.md, rulings R1 and R2.",
        );

        // Without this the scan proves nothing: a broken file walk reports an empty offender list.
        $I->assertGreaterThan(
            200,
            count($this->productionFiles()),
            'the production file walk found almost nothing, so the assertion above is vacuous',
        );
    }

    public function theGateItselfIsPublicOnEveryDocument(FunctionalTester $I): void
    {
        $missing = [];

        foreach ($this->documentsOnTheSeam() as $class) {
            if (!method_exists($class, 'setStatus')) {
                $missing[] = $this->shortName($class) . ' has no setStatus() at all';

                continue;
            }

            if (!(new \ReflectionMethod($class, 'setStatus'))->isPublic()) {
                $missing[] = $this->shortName($class) . '::setStatus() is not public';
            }
        }

        $I->assertSame(
            [],
            $missing,
            "setStatus() is THE gate and must be public on every document that has a status.\n\n"
            . "A previous attempt made it protected and kept the verbs as the public API. That is\n"
            . "the exact inverse of the ruling and was reverted in 2c0a1f78. See R1, R2 and R6.\n\n"
            . "This assertion is also what stops the two tests above passing vacuously: they cannot\n"
            . "be satisfied by hiding everything, because the gate has to stay open.",
        );
    }

    /**
     * Every concrete document class implementing {@see HasStatus}.
     *
     * Walks the source tree rather than taking a list, so a document added tomorrow is covered
     * without an edit here. That is the whole point — see the class docblock.
     *
     * @return list<class-string<HasStatus>>
     */
    private function documentsOnTheSeam(): array
    {
        $found = [];

        foreach ($this->entityFiles() as $file) {
            $class = $this->classIn($file);

            if ($class === null || !class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || !$reflection->implementsInterface(HasStatus::class)) {
                continue;
            }

            $found[] = $class;
        }

        sort($found);

        return $found;
    }

    /** @return list<string> */
    private function entityFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach ([$root . '/src/Entity', ...glob($root . '/modules/*/src/Entity') ?: []] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * Every PHP file the application actually runs — src/ and each bundle's src/.
     *
     * Not tests, not migrations: a migration writing a status literal is a historical fact being
     * replayed, not a caller choosing to go around the gate.
     *
     * @return list<string>
     */
    private function productionFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach ([$root . '/src', ...glob($root . '/modules/*/src') ?: []] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function classIn(string $file): ?string
    {
        $source = (string) file_get_contents($file);

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            return null;
        }

        if (preg_match('/^(?:final\s+)?(?:readonly\s+)?(?:abstract\s+)?class\s+(\w+)/m', $source, $name) !== 1) {
            return null;
        }

        return trim($namespace[1]) . '\\' . $name[1];
    }

    /**
     * The verb names that would set this status, e.g. 'Cancelled' -> cancel, cancelled.
     *
     * @return list<string>
     */
    private function verbNamesFor(string $slug): array
    {
        $word = lcfirst(str_replace(' ', '', ucwords(strtolower($slug))));
        $names = [];

        foreach (self::VERB_ENDINGS as [$suffix, $replacement]) {
            if ($suffix === '') {
                $names[] = $word;

                continue;
            }

            if (str_ends_with($word, $suffix)) {
                $names[] = substr($word, 0, -strlen($suffix)) . $replacement;
            }
        }

        return array_values(array_filter(array_unique($names), static fn (string $n): bool => strlen($n) > 2));
    }

    private function shortName(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts);
    }
}
