<?php

declare(strict_types=1);

namespace InventoryDepthBundle\EventSubscriber;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Enum\InvoiceShippingStatus;
use App\Service\Document\DocumentLockService;
use App\Service\DocumentActor;
use App\Service\InvoiceShippingStatusDeriver;
use App\Service\QuantityScale;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use InventoryDepthBundle\Entity\Shipment;
use InventoryDepthBundle\Entity\ShipmentLine;
use InventoryDepthBundle\Shipment\ShipmentService;
use Psr\Log\LoggerInterface;

/**
 * A shipment that leaves nothing outstanding completes the invoice.
 *
 * Owner: *"Shipment service just gives ability to set partially shipped and specify how many. And if
 * everything was shipped then invoice is set to completed"*. The first half is
 * `ShipmentService::ship()` plus core's derived shipping status; this is the second half, and it is
 * the exact mirror of {@see InvoiceShippingRemainderSubscriber}, which goes the other way — Completed
 * auto-ships whatever is left.
 *
 * ## `setStatus()` is the gate, and nothing here is a second door
 *
 * There is ONE way to move a status on this codebase and it is `setStatus($target, $actor, $comment)`
 * (`STATUS-SEAM-HANDOFF.md`, owner rulings R1 and R2). So this does not add a `complete()`, does not
 * write `Invoice::$status`, and does not reach for `applyDerivedStatus()` — an invoice derives
 * nothing on the lifecycle axis and `Invoice`'s own docblock says why. It asks for `'Completed'` at
 * the front door like any admin pressing the button, and every rule the gate holds still applies,
 * unchanged and un-bypassed.
 *
 * The actor is `DocumentActor::system()`, matching what
 * `App\Service\InvoiceShippingStatusDeriver::recalculate()` signs its own timeline entry with, and
 * for the same reason: nobody performed this. Recording the shipment was the act, and whoever
 * recorded it signed their own entry; this is its consequence. The comment is left to the gate, so
 * the timeline reads "Fulfilment completed." — byte for byte what the deleted `complete()` verb
 * wrote and what a hand-completed invoice still writes today.
 *
 * ## It asks the deriver. It does not re-derive.
 *
 * `InvoiceShippingStatusDeriver::statusFor()` answering `Shipped` for an invoice that is NOT yet
 * Completed already means exactly "every billed line is covered by shipments that still stand" —
 * that is the branch of it that reads the providers, caps each line at its own billed quantity, and
 * compares in whole ten-thousandths. Re-implementing the question here would be a second answer to
 * it, and the two would part company the first time one of them learned about a void, a zero-quantity
 * line or a second provider. One writer for the shipping column, one answer to "is it all covered".
 *
 * Three things fall out of reusing it rather than counting lines here, and each is asserted rather
 * than assumed in `InvoiceCompletedWhenFullyShippedSubscriberTest`:
 *
 *  - **An invoice with nothing billable never completes.** No lines, or every line zero-quantity: the
 *    deriver answers `NotShipped` there ("Shipped and not shipped are both vacuously true of
 *    nothing"), so an empty invoice cannot complete itself by having nothing to wait for.
 *  - **The bundle's own Active flag is honoured for free.** The deriver only asks providers whose
 *    bundle `BundleStatusRepository::isActiveForInstance()` accepts. Switch InventoryDepthBundle off
 *    and there is nothing listening, so a not-yet-Completed invoice reads `NotShipped` and this can
 *    never fire — which is the same thing as this listener not existing.
 *  - **A voided shipment un-covers the line.** The deriver's sum excludes voided shipments, so an
 *    invoice that would have completed on a shipment does not complete on one that was withdrawn.
 *
 * ## Why it cannot loop, in both directions
 *
 * A flush inside a `postFlush` that triggers another flush is where this shape spins, so both ends
 * are written down and `InvoiceCompletedWhenFullyShippedSubscriberTest` pins them:
 *
 *  - **ship everything -> Completed -> remainder.** This completes the invoice and flushes. That
 *    flush wakes `InvoiceShippingRemainderSubscriber`, which asks `remainingUnitsToShip()` per line
 *    and finds zero on every one of them — that is precisely the condition that got us here — so it
 *    builds no request, calls nothing, and the chain stops with no second shipment.
 *  - **Completed by hand -> auto-ship -> complete again.** The remainder listener ships what is
 *    left, those `ShipmentLine` inserts queue the invoice here, and the FIRST line of the work below
 *    is "already Completed? then nothing to do". It stops before the deriver is even asked.
 *
 * The queue is emptied before any of the work, the same discipline
 * `InvoiceShippingRemainderSubscriber` and `ShipmentShippingStatusSubscriber` both state: the inner
 * flush opens its own onFlush/postFlush pass, and that pass must find an empty queue rather than
 * doing the same work again. Note also that the already-Completed check is not merely an
 * optimisation — `setStatus('Completed')` on an invoice that is already Completed THROWS, because
 * the gate runs the document's rules before the no-op check, on purpose, so that a rule about a
 * self-move can still refuse.
 *
 * ## Refusals are quiet
 *
 * The invoice genuinely may not be able to complete: it is Cancelled, or still at Pending (the gate
 * allows Completed only from Processing), or somebody has frozen it with a document lock. Every one
 * of those is logged and swallowed, never thrown back through the shipment — the same reasoning
 * `InvoiceShippingRemainderSubscriber`'s docblock gives for the mirror case, read the other way
 * round: the goods have already, correctly, been recorded as gone by the time this runs, and a
 * lifecycle rule about the invoice is not a reason to fail that write and lose the shipment.
 *
 * At `info` rather than `warning`, which is the one place this deliberately differs from the mirror.
 * There, a refusal means a physical shipping problem a warehouse admin has to go and finish by hand.
 * Here the overwhelmingly common refusal is "this invoice is not at Processing yet", which is
 * ordinary rather than wrong, and a warning for it would be noise of the kind that trains people to
 * stop reading the log.
 *
 * The lock is asked BEFORE the status is written rather than caught after, because
 * `DocumentLockFlushGuard` refuses at the next flush, not at the setter: catching it there would
 * leave the invoice sitting in memory marked Completed with a timeline entry queued behind it,
 * waiting to be refused again by whoever flushed next. `DocumentLockService::assertWritable()` is
 * described as an optimisation for a nicer screen; this is the same pre-check for the same reason,
 * and the flush guard remains the enforcement.
 *
 * ## Bundle-only, like everything else here
 *
 * Core has and needs zero awareness of this listener: it is discovered by
 * `#[AsDoctrineListener]` in this bundle's own container, and it watches `ShipmentLine`, a table
 * core does not know exists. Delete `InventoryDepthBundle` or switch it Inactive and invoices behave
 * exactly as they did before Shipment existed — nothing completes itself, because nothing records a
 * shipment to complete it.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class InvoiceCompletedWhenFullyShippedSubscriber
{
    /** @var array<int, Invoice> keyed by spl_object_id() — an invoice inserted in this same flush has no db id yet */
    private array $queued = [];

    /** @var array<int, Invoice> invoices a void in this flush may have taken back off full coverage */
    private array $queuedForReopen = [];

    public function __construct(
        private readonly InvoiceShippingStatusDeriver $deriver,
        private readonly ShipmentService $shipments,
        private readonly DocumentLockService $locks,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Queues the invoices a `ShipmentLine` written in this flush might have finished off.
     *
     * A void is queued separately. It can never COMPLETE an invoice, but it is the one thing that
     * un-completes one: an invoice this subscriber completed, whose goods have since come back,
     * would otherwise sit at Completed — and so read Shipped, by core's rule — with nothing on the
     * shelf to show for it. Kept in its own queue because "coverage is incomplete" is true of every
     * Completed invoice that predates Shipment, and none of those should be reopened.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof ShipmentLine) {
                $this->queueInvoiceOf($entity->getInvoiceLine());
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Shipment && isset($uow->getEntityChangeSet($entity)['voidedAt'])) {
                foreach ($entity->getLines() as $line) {
                    $invoiceLine = $line->getInvoiceLine();
                    if ($invoiceLine instanceof InvoiceLine) {
                        $this->queuedForReopen[spl_object_id($invoiceLine->getInvoice())] = $invoiceLine->getInvoice();
                    }
                }
            }

            if ($entity instanceof ShipmentLine) {
                // The line's CURRENT invoice only. An amended quantity, or a line re-pointed at
                // another invoice line, can finish off the invoice it now belongs to; the one it
                // left has lost coverage, which is the reducing case above.
                $this->queueInvoiceOf($entity->getInvoiceLine());
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->queued === [] && $this->queuedForReopen === []) {
            return;
        }

        // Emptied before any work: the flush below opens its own onFlush/postFlush pass, and that
        // inner pass must find nothing here rather than completing the same invoices again. See the
        // class docblock for why the chain terminates in both directions.
        $invoices = $this->queued;
        $reopen = $this->queuedForReopen;
        $this->queued = [];
        $this->queuedForReopen = [];

        $completed = false;
        foreach ($invoices as $invoice) {
            $completed = $this->completeIfNothingOutstanding($invoice) || $completed;
        }
        foreach ($reopen as $invoice) {
            $completed = $this->reopenIfNoLongerCovered($invoice) || $completed;
        }

        if ($completed) {
            $this->entityManager->flush();
        }
    }

    /**
     * Moves $invoice to Completed when its shipments leave nothing outstanding, and says whether it
     * did — so postFlush owes one flush for the whole batch rather than one per invoice.
     */
    private function completeIfNothingOutstanding(Invoice $invoice): bool
    {
        if ($invoice->getStatus() === 'Completed') {
            // Already there, and the gate would THROW rather than no-op on a self-move. This is also
            // the terminator for the Completed -> auto-ship -> here direction.
            return false;
        }

        if ($this->deriver->statusFor($invoice) !== InvoiceShippingStatus::Shipped) {
            return false;
        }

        if ($this->locks->isLocked($invoice)) {
            $this->logger->info('Fully shipped invoice was not completed automatically: it is locked.', [
                'invoice' => $invoice->getDocumentNumber(),
            ]);

            return false;
        }

        try {
            $invoice->setStatus('Completed', DocumentActor::system());
        } catch (\DomainException $e) {
            // The document's own rules: Cancelled, or not yet at Processing, or any future rule the
            // gate grows. Quiet, for the reason the class docblock gives — the goods are already
            // recorded as gone and this must not fail the shipment that recorded them.
            $this->logger->info('Fully shipped invoice was not completed automatically: ' . $e->getMessage(), [
                'invoice' => $invoice->getDocumentNumber(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * `ShipmentLine::$invoiceLine` is nullable at the column level so a line outlives the loss of its
     * attribution, and a line with none names no invoice to complete.
     */
    /**
     * Takes $invoice back off Completed when a void has left one of its lines short.
     *
     * Asks ShipmentService rather than the deriver: the deriver answers Shipped for anything
     * Completed, by rule, so it cannot be used to tell whether the goods are actually covered.
     */
    private function reopenIfNoLongerCovered(Invoice $invoice): bool
    {
        if ($invoice->getStatus() !== 'Completed') {
            return false;
        }

        $short = false;
        foreach ($invoice->getLines() as $line) {
            if (QuantityScale::compare($this->shipments->remainingToShip($line), 0) > 0) {
                $short = true;
                break;
            }
        }

        if (!$short || $this->locks->isLocked($invoice)) {
            return false;
        }

        try {
            $invoice->setStatus('Processing', DocumentActor::system());
        } catch (\DomainException $e) {
            $this->logger->info('Invoice was not reopened after a shipment void: ' . $e->getMessage(), [
                'invoice' => $invoice->getDocumentNumber(),
            ]);

            return false;
        }

        return true;
    }

    private function queueInvoiceOf(?object $invoiceLine): void
    {
        if (!$invoiceLine instanceof InvoiceLine) {
            return;
        }

        $invoice = $invoiceLine->getInvoice();
        $this->queued[spl_object_id($invoice)] = $invoice;
    }
}
