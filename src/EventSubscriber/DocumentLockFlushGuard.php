<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\DocumentLock;
use App\Service\Document\DocumentLockService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * THE enforcement point for a document lock. Nothing writes a frozen document past this.
 *
 * ## Why this exists rather than a guard in each controller
 *
 * The brief for this feature put it exactly right: *an unenumerated write path is an unlocked
 * document*. Counting the write paths on three documents gives around fifteen routes today — the
 * two save screens, five status/action endpoints, link and unlink, two payment endpoints, the
 * quote's accept and convert, the invoice-from-order path — and that list is a snapshot. It was
 * shorter last month and will be longer next month, and nothing about adding the sixteenth reminds
 * its author that a lock exists. A hand-kept list of callers is the thing `docs/QUEUE.md` names as
 * the anti-pattern, in exactly these words, about exactly this shape of problem.
 *
 * Every one of those paths ends in the same place: `EntityManager::flush()`. So the lock is checked
 * there, once, against the UnitOfWork's own account of what is about to be written. A new route, a
 * console command, a service called from a subscriber, a fixture — all of them are covered on the
 * day they are written, by nobody remembering anything.
 *
 * Controller-level guards still exist and are still worth having ({@see DocumentLockService::assertWritable()}).
 * They are an OPTIMISATION for a nicer screen — refusing before a request does any work, so nothing
 * half-finishes — not the enforcement. Exactly the relationship `StatusTransitionRefusedSubscriber`
 * already has with the local catches around a refused status move.
 *
 * ## What it refuses, in one sentence
 *
 * **A lock freezes what the document IS; it does not freeze what happens TO it.** The header row,
 * the product lines, the frozen address snapshots, the custom field values, the payments, and the
 * document's own deletion. Not the timeline, the audit log or the email log — which is what keeps
 * "a locked document can still be printed, emailed and cloned" true, since emailing an invoice
 * writes an `InvoiceLog` row through `Invoice::recordSent()`.
 *
 * `DocumentLockService::OWNERS` is that boundary, written down in one place.
 *
 * ## Why `onFlush` and not `preFlush`
 *
 * `preFlush` runs before changesets are computed, so there is nothing yet to inspect. `onFlush` runs
 * after `computeChangeSets()` and — this is the part that matters — BEFORE
 * `$conn->beginTransaction()` and outside the `finally` that calls `EntityManager::close()`. So
 * throwing from here refuses the write without closing the EntityManager, and the request can still
 * render a page. Throwing from a listener inside the transaction would leave a closed EM and a white
 * screen.
 *
 * ## Cost
 *
 * One `SELECT` per DISTINCT locked-document candidate in a changeset, and zero when a flush touches
 * no sell-side document — which is most of them. Locks are rare and the query is on a unique index.
 * The candidates are de-duplicated first, so a save rewriting forty lines of one order asks once.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class DocumentLockFlushGuard
{
    public function __construct(private readonly DocumentLockService $locks)
    {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $unitOfWork = $event->getObjectManager()->getUnitOfWork();

        // Deletions carry their own verb. "Order SO-9 is locked and cannot be edited" on a delete
        // would name the wrong act, and this refusal is the only thing standing between a locked
        // document and being gone.
        $this->refuse($unitOfWork->getScheduledEntityDeletions(), 'deleted');

        $this->refuse($unitOfWork->getScheduledEntityInsertions(), 'edited');
        $this->refuse($unitOfWork->getScheduledEntityUpdates(), 'edited');

        // A collection update is a link written on the OWNING side without either end being itself
        // scheduled — rare on these documents, since every one of their collections cascades, but
        // free to cover and not free to discover the day one does not.
        //
        // SCOPED BY WHAT THE COLLECTION HOLDS, not by who owns it, and that distinction is the
        // difference between a lock and a brick. Every collection on these documents is owned by the
        // document — lines, addresses, logs and payments alike — so refusing on the owner alone
        // refuses `$invoice->logs` too, and writing a timeline row is precisely what a locked
        // document must still be able to do. `Invoice::recordSent()` adds an `InvoiceLog` to that
        // collection, so with the owner check ungated, emailing a locked invoice failed with
        //
        //     Failed to send email: Invoice INV-x is locked and cannot be edited.
        //
        // — found by the "a locked document can still be printed, emailed and cloned" case, which
        // exists for exactly this class of over-reach.
        foreach ([$unitOfWork->getScheduledCollectionUpdates(), $unitOfWork->getScheduledCollectionDeletions()] as $collections) {
            $owners = [];
            foreach ($collections as $collection) {
                $owner = $collection->getOwner();
                if ($owner === null || !$this->locks->guardsCollectionOf($collection->getMapping()->targetEntity)) {
                    continue;
                }

                $owners[] = $owner;
            }
            $this->refuse($owners, 'edited');
        }
    }

    /**
     * @param iterable<object> $entities
     */
    private function refuse(iterable $entities, string $attempted): void
    {
        $seen = [];

        foreach ($entities as $entity) {
            // The lock rows themselves, or the refusal would make locking and unlocking impossible —
            // the first write of a lock is an insert of one of these.
            if ($entity instanceof DocumentLock) {
                continue;
            }

            $document = $this->locks->documentFor($entity);
            if ($document === null) {
                continue;
            }

            // One question per document per flush, however many of its rows moved.
            $key = spl_object_id($document);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $this->locks->assertWritable($document, $attempted);
        }
    }
}
