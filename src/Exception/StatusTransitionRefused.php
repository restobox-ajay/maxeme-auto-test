<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A status move the vocabulary does not allow (handoff section 6).
 *
 * A `DomainException`, like every other refusal an entity raises in this codebase, and caught
 * centrally by {@see \App\EventSubscriber\StatusTransitionRefusedSubscriber} so that forgetting to
 * catch it locally degrades to *the right message appeared* rather than to a 500.
 *
 * The owner's condition was exact: *"a throw works for me (as long as its caught)"*, and then
 * *"im always nervous someone forgets to catch a throw."* So this does not rely on discipline. An
 * uncaught `DomainException` is a 500, which is the opposite of loud — a person sees a blank error
 * page and learns nothing, and the log entry says only that something threw.
 *
 * Catching locally stays worthwhile for a nicer screen. It is an optimisation, not a requirement
 * anybody can fail to meet.
 *
 * ## The wording lives here
 *
 * Written once, on the exception, rather than each controller inventing its own — the same argument
 * as the shared colour classes. A refusal says what the document is, what was asked of it, and what
 * it WOULD accept, because "cannot do that" without the third part sends the person back to guess.
 */
final class StatusTransitionRefused extends \DomainException
{
    private function __construct(
        string $message,
        public readonly string $documentLabel,
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<string> $allowed the legal targets from $from, for the "it would accept" half
     */
    public static function move(string $documentLabel, string $from, string $to, array $allowed): self
    {
        return new self(
            sprintf(
                '%s is %s and cannot become %s. %s',
                $documentLabel,
                $from,
                $to,
                $allowed === []
                    ? sprintf('%s is a final status — nothing moves out of it.', $from)
                    : sprintf('From %s it can only become: %s.', $from, implode(', ', $allowed)),
            ),
            $documentLabel,
            $from,
            $to,
        );
    }
}
