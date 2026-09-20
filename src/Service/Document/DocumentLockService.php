<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Contract\Document\LockableDocument;
use App\Entity\DocumentLock;
use App\Exception\DocumentLocked;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Freeze a document against editing — the human act, and the one place that answers "is this one
 * locked".
 *
 * ## Lock is NOT the status rules, and does not replace them
 *
 * The application already refuses plenty of edits by status, and every document with a status now
 * answers the same one-line question — `HasStatus::canEditOnStatus()`, delegating to its own
 * status enum's `allowsEditing()`. Every one of those refusals is a consequence of where the
 * document IS in its life, decided by the application.
 *
 * This is a different thing on top of them: a deliberate act by a named person, on a document whose
 * status would otherwise allow the edit, saying *leave this one alone*. A draft can be locked. An
 * approved order can be locked. The two never merge and neither weakens the other — the status rule
 * is asked first by the code that already asks it, and this is asked as well.
 *
 * ## What a lock refuses, and what it does not
 *
 * The rule is one sentence, and it is the SAME sentence {@see SalesDocumentCloner} is built on:
 *
 *   **A lock freezes what the document IS. It does not freeze what happens TO it.**
 *
 * Frozen: the header row, the product lines, the frozen address snapshots, the custom field values,
 * the payments, the status, and the document's own existence (it cannot be deleted).
 *
 * Not frozen: the timeline, the audit log, the email log. A locked document can still be printed,
 * emailed and cloned — and emailing an invoice WRITES an `InvoiceLog` row through
 * `Invoice::recordSent()`, so if the timeline were frozen too, "can still be emailed" would be
 * false. The exemption is not a loophole; it is what makes the promise true.
 *
 * ## Who may lock, and who may unlock
 *
 * **Lock: any admin. Unlock: `ROLE_SUPER_ADMIN`.**
 *
 * Asymmetric on purpose. A lock is a safety catch, and a safety catch anybody can take off is
 * decoration — the person who would ignore the warning is exactly the person who would press Unlock
 * without thinking. Making it harder to remove than to apply is the whole point of the control.
 *
 * No new role is invented. `config/packages/security.yaml` already declares
 * `ROLE_TECH_SUPPORT: [ROLE_SUPER_ADMIN]`, with the comment *"Any current or future
 * ROLE_SUPER_ADMIN gate covers Tech Support automatically — there is no second gate to remember"*.
 * So gating on `ROLE_SUPER_ADMIN` admits Super Admin and Tech Support and excludes plain Admin and
 * Plant Staff, using the hierarchy that is already there. Gating on `ROLE_TECH_SUPPORT` instead
 * would have been the mistake: it would exclude the Super Admin, who outranks them.
 *
 * ## Which documents, and where that list lives
 *
 * Not here. Until #759 this class hardcoded `TYPES`/`OWNERS` for the three documents core owns —
 * Estimate, SalesOrder, Invoice — which is exactly why `PurchaseOrder`/`VendorBill` had no lock at
 * all: core cannot name `ProcurementBundle`'s entity classes without reversing the dependency "core
 * reads nothing a bundle writes". {@see LockableDocumentCatalogue} is the seam now, the same shape
 * `DocumentPrefixCatalogue` (#615) and `CustomFieldObjectTypeCatalogue` (#745) are; core registers
 * its own three through {@see CoreLockableDocumentProvider} rather than staying special.
 *
 * ## Reading, not writing, goes through DBAL
 *
 * {@see \App\EventSubscriber\DocumentLockFlushGuard} asks this question from inside `onFlush`, where
 * running an ORM query would reach into the UnitOfWork mid-commit. One raw `SELECT` is safe
 * everywhere, so both callers use the same path and there is only one answer to the question.
 */
final class DocumentLockService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LockableDocumentCatalogue $documents,
    ) {
    }

    /**
     * True for a registered document; false for everything else, including its own lines.
     *
     * `instanceof`, not an exact `::class` lookup — {@see LockableDocumentCatalogue::forDocument()}
     * checks it that way. Doctrine hands out proxies, and a `Proxies\__CG__\App\Entity\SalesOrder`
     * is a SalesOrder — a template that asked this about a lazily-loaded document would otherwise be
     * told it is not lockable and render no Unlock control on a document that is locked.
     */
    public function isLockable(object $document): bool
    {
        return $this->documents->forDocument($document) !== null;
    }

    /**
     * Whether a collection holding $targetEntity is one some document's lock freezes.
     *
     * Asked of the CONTENTS, never of the owner. Every collection on a lockable document is owned by
     * that document — lines, addresses, logs and payments alike — so a guard that keyed off the
     * owner would freeze the timeline as well, and a locked invoice could not record that it was
     * emailed. This is the same owners boundary {@see LockableDocument::$owners} states per
     * document, asked one level up.
     */
    public function guardsCollectionOf(string $targetEntity): bool
    {
        return $this->documents->guardsCollectionOf($targetEntity);
    }

    /**
     * The document a write to $entity is a write to, or null when it is neither a lockable document
     * nor a child of one.
     */
    public function documentFor(object $entity): ?object
    {
        return $this->documents->documentFor($entity);
    }

    /** The `document_lock.document_type` key for a lockable document. */
    public function typeFor(object $document): string
    {
        return $this->registeredOrThrow($document)->typeKey;
    }

    /**
     * How the document names itself in a refusal — "Quote QO-14", "Order SO-1042", "PO PO-7".
     *
     * The type word matters: a person reading "SO-1042 is locked" out of context has to work out
     * what SO- is, and the sell side alone prints three prefixes that all look alike in a hurry.
     */
    public function labelFor(object $document): string
    {
        $registered = $this->registeredOrThrow($document);

        return $registered->labelPrefix . ' ' . $document->{$registered->numberAccessor}();
    }

    public function isLocked(object $document): bool
    {
        return $this->lockRow($document) !== null;
    }

    /**
     * The stored lock as a plain row, or null.
     *
     * @return array{locked_by: string, locked_at: string, reason: string|null}|null
     */
    public function lockRow(object $document): ?array
    {
        $id = $this->idOf($document);
        if ($id === null) {
            // Never persisted, so nothing can have locked it. Asking the database with a null id
            // would match every row whose document_id IS NULL, which is none — but saying so here
            // keeps the query off the hot path for every unsaved document in the application.
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT locked_by, locked_at, reason FROM document_lock WHERE document_type = ? AND document_id = ?',
            [$this->typeFor($document), $id],
        );

        return $row === false ? null : [
            'locked_by' => (string) $row['locked_by'],
            'locked_at' => (string) $row['locked_at'],
            'reason' => $row['reason'] === null ? null : (string) $row['reason'],
        ];
    }

    /**
     * Throw unless the document is free to be written.
     *
     * $attempted is the verb phrase the refusal reads with — "edited", "deleted", "approved" — so
     * the sentence says what was actually refused rather than "cannot be changed" for everything.
     * The message and the instruction both live on {@see DocumentLocked}.
     */
    public function assertWritable(object $document, string $attempted = 'edited'): void
    {
        $lock = $this->lockRow($document);
        if ($lock === null) {
            return;
        }

        throw DocumentLocked::refuses(
            $this->labelFor($document),
            $attempted,
            $lock['locked_by'],
            $this->lockedAt($lock['locked_at']),
        );
    }

    /**
     * Freeze it. Idempotent: locking an already-locked document is a no-op rather than a second row
     * the unique index would refuse and a duplicate-key page nobody can act on.
     */
    public function lock(object $document, DocumentActor $actor, ?string $reason, EntityManagerInterface $entityManager): void
    {
        if ($this->isLocked($document)) {
            return;
        }

        $id = $this->idOf($document);
        if ($id === null) {
            throw new \LogicException('A document that has never been saved cannot be locked.');
        }

        $reason = trim((string) $reason);

        $entityManager->persist(
            (new DocumentLock())
                ->setDocumentType($this->typeFor($document))
                ->setDocumentId($id)
                ->setLockedBy($actor->displayName)
                ->setReason($reason === '' ? null : mb_substr($reason, 0, 255)),
        );
    }

    /**
     * Release it. Also idempotent, for the mirror reason — an unlock that arrives twice (a double
     * submit, a back button) must not be an error page.
     *
     * Deletes the row rather than flagging it released. A released lock is not a fact about the
     * document any more, and keeping the history here would mean every read had to filter — the
     * history of who locked and unlocked what is `audit_log`'s job, and it records both writes
     * without being told to.
     */
    public function unlock(object $document, EntityManagerInterface $entityManager): void
    {
        $id = $this->idOf($document);
        if ($id === null) {
            return;
        }

        $lock = $entityManager->getRepository(DocumentLock::class)->findOneBy([
            'documentType' => $this->typeFor($document),
            'documentId' => $id,
        ]);

        if ($lock !== null) {
            $entityManager->remove($lock);
        }
    }

    /**
     * Every lockable document declares `getId(): ?int` the same way, so unlike `labelFor()` this
     * needs no per-document accessor — only the registration check, which is what actually varies.
     */
    private function idOf(object $document): ?int
    {
        $this->registeredOrThrow($document);

        /** @var ?int */
        return $document->getId();
    }

    private function registeredOrThrow(object $document): LockableDocument
    {
        return $this->documents->forDocument($document)
            ?? throw new \InvalidArgumentException(sprintf('%s is not a lockable document.', $document::class));
    }

    /**
     * The stored timestamp as a date the refusal can print.
     *
     * SQLite hands back whatever string Doctrine wrote, and a row written by an older format — or by
     * hand — must not turn a refusal into a parse error. An unreadable value falls back to now,
     * which makes the sentence read slightly wrong on a row nobody has, rather than replacing a
     * refusal with a 500 on a row somebody does.
     */
    private function lockedAt(string $stored): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($stored);
        } catch (\Exception) {
            return new \DateTimeImmutable();
        }
    }
}
