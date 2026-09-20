<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A write refused because somebody deliberately froze the document.
 *
 * A `DomainException`, like every other refusal an entity raises here, and caught centrally by
 * {@see \App\EventSubscriber\DocumentLockedSubscriber} — the same arrangement
 * {@see StatusTransitionRefused} already has, and for the owner's stated reason: *"a throw works for
 * me (as long as its caught)"*, then *"im always nervous someone forgets to catch a throw."* So
 * forgetting to catch this locally degrades to *the right message appeared*, never to a 500.
 *
 * That is load-bearing here rather than merely tidy. The last line of defence against an
 * unenumerated write path is {@see \App\EventSubscriber\DocumentLockFlushGuard}, which throws from inside
 * Doctrine's `onFlush` — a place no controller has a `try` around and none should have to.
 *
 * ## The wording lives here
 *
 * Written once, on the exception. A refusal in this codebase says what the document is, what was
 * refused, and **what to do about it** — the third part is what the province and disputed-bill
 * refusals merged tonight are shaped around:
 *
 *     "Bill B-12 is Disputed and cannot be edited. Return it to draft — the control is on the
 *      bill's own page — and then edit it."
 *
 * So this says the same thing in the same voice, and names Unlock rather than leaving the reader to
 * find it.
 */
final class DocumentLocked extends \DomainException
{
    private function __construct(
        string $message,
        public readonly string $documentLabel,
        public readonly string $lockedBy,
    ) {
        parent::__construct($message);
    }

    /**
     * @param string $documentLabel how the document names itself, e.g. "Order SO-1042"
     * @param string $attempted     what was refused, as a verb phrase: "edited", "deleted",
     *                              "approved". Reads as "… and cannot be edited."
     * @param string $lockedBy      display name of whoever locked it
     */
    public static function refuses(string $documentLabel, string $attempted, string $lockedBy, \DateTimeImmutable $lockedAt): self
    {
        return new self(
            sprintf(
                '%s is locked and cannot be %s. %s locked it on %s. Unlock it — the control is on '
                    . 'the document\'s own page — and then %s it.',
                $documentLabel,
                $attempted,
                $lockedBy,
                $lockedAt->format('j M Y'),
                self::verb($attempted),
            ),
            $documentLabel,
            $lockedBy,
        );
    }

    /**
     * "edited" -> "edit", so the instruction reads as an instruction.
     *
     * A small table rather than a suffix rule, because English does not have one: "deleted" drops a
     * d, "approved" drops a d, "cancelled" drops two letters, "sent" changes stem entirely. An
     * unlisted phrase is handed back verbatim and the sentence still parses ("…and then void it").
     */
    private static function verb(string $attempted): string
    {
        return [
            'edited' => 'edit',
            'deleted' => 'delete',
            'approved' => 'approve',
            'cancelled' => 'cancel',
            'voided' => 'void',
            'paid' => 'record payment against',
            'changed' => 'change',
        ][$attempted] ?? $attempted;
    }
}
