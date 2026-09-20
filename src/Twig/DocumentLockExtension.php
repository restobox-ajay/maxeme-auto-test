<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Document\DocumentLockService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `document_is_locked(document)` and `document_lock(document)` for the templates.
 *
 * The behaviour of this feature is deliberately complete without a single template change — the
 * routes, the service and the flush guard all work whether or not a button exists, which is what
 * lets the controls be wired by a separate change against the five sell-side templates two other
 * workers are holding. This extension is the seam between the two halves, added now so that change
 * is markup and nothing else.
 *
 * A Twig function rather than a method on the entity, because the lock is not held on the entity —
 * see {@see \App\Entity\DocumentLock} for why it is a row beside the document rather than a column
 * on it. `document.isLocked` would have to be that column.
 *
 * `document_lock()` returns the row — `locked_by`, `locked_at`, `reason` — so a page can say who
 * froze it and why rather than only that somebody did. A refusal that cannot be traced to a person
 * is the kind that ends in somebody editing the database by hand.
 */
final class DocumentLockExtension extends AbstractExtension
{
    public function __construct(private readonly DocumentLockService $locks)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('document_is_locked', $this->isLocked(...)),
            new TwigFunction('document_lock', $this->lock(...)),
        ];
    }

    /**
     * False for anything that is not one of the three lockable documents, rather than a throw.
     *
     * A template asking whether a credit memo is locked is asking a question with a true answer —
     * "no" — and a shared partial rendered for four document types must not become the reason a page
     * 500s. The write path is where a wrong type is loud: `DocumentLockService::typeFor()` throws.
     */
    public function isLocked(?object $document): bool
    {
        return $document !== null && $this->locks->isLockable($document) && $this->locks->isLocked($document);
    }

    /** @return array{locked_by: string, locked_at: string, reason: string|null}|null */
    public function lock(?object $document): ?array
    {
        return $document !== null && $this->locks->isLockable($document)
            ? $this->locks->lockRow($document)
            : null;
    }
}
