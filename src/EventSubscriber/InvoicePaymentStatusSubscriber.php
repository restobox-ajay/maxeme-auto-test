<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\CreditMemoApplication;
use App\Entity\Invoice;
use App\Entity\InvoicePaymentApplication;
use App\Service\InvoicePaymentStatusDeriver;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * Keeps every invoice's derived payment status true, whatever wrote the invoice or its payment
 * claims (#539 stage 4).
 *
 * Same strategy, and the same reason for it, as SalesOrderDerivedStatusSubscriber next door and
 * OrderInventoryReconciliationSubscriber before that (see docs/event-listeners.md): a derived value
 * has to be recomputed on every write, and "every" cannot mean "at the call sites we remembered".
 *
 * `InvoicePaymentApplication` is watched rather than `InvoicePayment` (#708: a payment pool can span
 * several invoices, so the pool itself has no single invoice to queue — the CLAIM against one does).
 * Invoices are watched too, because the total is what a payment is measured against — repricing an
 * invoice, or adding a line to one, can turn a Paid invoice into a partially paid one without
 * anything touching a payment row at all.
 *
 * ## Both ends of a move, not just the destination
 *
 * A claim re-pointed at another invoice (`Invoice::moveApplication()`, withdraw-then-reapply on the
 * same underlying payment) is one row change with two consequences: the invoice that gained it may
 * become Paid, and the invoice that LOST it may cease to be. Only the first used to happen.
 * `$application->getInvoice()` after a move names the destination, and nothing about the move dirties
 * the source's own row — its `applications` collection changes, which Doctrine reports as a
 * collection update, and its timeline entry is a new log in that collection too, so the source never
 * appeared in `getScheduledEntityUpdates()` and was never queued. The result was an invoice reading
 * Paid while holding no claims at all.
 *
 * So a moved claim is read from the UNIT OF WORK's change set rather than from the entity: the change
 * set is the only place the invoice a claim used to be on still exists at flush time.
 *
 * **Why here and not at the call site that performs the move.** Three reasons, and the third is the
 * one that settles it:
 *
 *  1. This subscriber is the single writer of the derived payment status. That is the whole design
 *     — a status somebody can set by hand is a second answer to a question the payment rows already
 *     answer (see {@see \App\Service\InvoicePaymentStatusDeriver}). A move that queued its own two
 *     invoices would be a call site holding an opinion about derivation again.
 *  2. The same change set handles every other way a claim's owner can change — an import, a seeder,
 *     a merge tool, a bundle — not just the one screen somebody remembers to edit.
 *  3. A call-site fix would cover the move and nothing else. Deleting a claim and amending its
 *     amount are the two neighbouring holes, and they are already covered here for the same
 *     structural reason: a deletion and an amendment both leave `getInvoice()` naming the document
 *     that is actually affected, so the existing pass over insertions, updates and deletions
 *     queues them. Fixing the move at its call site would have left that coverage resting on a
 *     coincidence rather than on a rule.
 *
 * **What this does NOT do, deliberately.** It does not repair the losing invoice's in-memory
 * `applications` collection. A caller that re-points a claim must take it out of the old collection
 * and put it in the new one, exactly as `VendorBill::moveApplication()` does on the buy side and as
 * `Invoice::moveApplication()`/`applyPayment()` do here. Doing it from a listener is not merely out
 * of place, it is unsafe: `Invoice::$applications` is `orphanRemoval: true`, so calling
 * `removeElement()` on it schedules the claim for DELETION, and a listener trying to tidy up after a
 * sloppy move would silently destroy the row the move existed to preserve.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class InvoicePaymentStatusSubscriber
{
    /** @var array<int, Invoice> keyed by spl_object_id() — an inserted invoice has no db id at onFlush time */
    private array $queued = [];

    public function __construct(
        private readonly InvoicePaymentStatusDeriver $deriver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->queueFromEntity($entity);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->queueFromEntity($entity);
            $this->queueInvoiceThePaymentLeft($entity, $uow);
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->queueFromEntity($entity);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->queued === []) {
            return;
        }

        // Cleared before recalculating, for the reason the sibling subscribers clear first: the
        // flush below opens its own onFlush/postFlush pass, and that inner pass must find an empty
        // queue rather than recalculating the same invoices again.
        $invoices = $this->queued;
        $this->queued = [];

        $changed = false;
        foreach ($invoices as $invoice) {
            $changed = $this->deriver->recalculate($invoice) || $changed;
        }

        if ($changed) {
            $this->entityManager->flush();
        }
    }

    private function queueFromEntity(object $entity): void
    {
        if ($entity instanceof Invoice) {
            $this->queued[spl_object_id($entity)] = $entity;

            return;
        }

        if ($entity instanceof InvoicePaymentApplication) {
            $invoice = $entity->getInvoice();
            $this->queued[spl_object_id($invoice)] = $invoice;

            return;
        }

        // #603: applied credit is now part of the balance/status this subscriber keeps true, so an
        // invoice that gained or lost a CreditMemoApplication needs recalculating exactly as one
        // that gained or lost a payment claim does. CreditMemo::applyTo()/withdrawApplication() are
        // its only writers, and neither re-points an existing row at a different invoice the way a
        // payment claim can be moved, so there is no "queue the invoice it left" case to mirror.
        if ($entity instanceof CreditMemoApplication) {
            $invoice = $entity->getInvoice();
            $this->queued[spl_object_id($invoice)] = $invoice;
        }
    }

    /**
     * Queues the invoice a claim has just been moved OFF, when that is what the update was.
     *
     * `getEntityChangeSet()` gives [old, new] per changed field, and 'invoice' is only present when
     * the association actually changed — so an amended amount or a corrected date passes straight
     * through here, and an insertion's old value is null rather than an invoice.
     */
    private function queueInvoiceThePaymentLeft(object $entity, UnitOfWork $uow): void
    {
        if (!$entity instanceof InvoicePaymentApplication) {
            return;
        }

        $previous = $uow->getEntityChangeSet($entity)['invoice'][0] ?? null;
        if ($previous instanceof Invoice) {
            $this->queued[spl_object_id($previous)] = $previous;
        }
    }
}
