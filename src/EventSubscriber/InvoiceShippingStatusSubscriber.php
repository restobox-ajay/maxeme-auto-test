<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Invoice;
use App\Service\InvoiceShippingStatusDeriver;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Keeps every invoice's derived shipping status true, whatever wrote the invoice.
 *
 * Same strategy, and the same reason for it, as InvoicePaymentStatusSubscriber next door (see
 * docs/event-listeners.md): a derived value has to be recomputed on every write, and "every" cannot
 * mean "at the call sites we remembered". Checkout, admin updateStatus, quote conversion, an
 * import, a seeder — they all flush through here and none of them has to know this exists.
 *
 * ## Why this watches invoices and nothing else
 *
 * The payment subscriber watches InvoicePaymentApplication as well as Invoice, because payment
 * claims are core rows it can see. There is no core equivalent here: **core has no shipment table**, so there is no
 * second entity for this listener to watch. That is not a gap, it is the seam working as intended —
 * whoever owns the shipment rows watches its own, and calls
 * {@see \App\Service\InvoiceShippingStatusDeriver} for the invoices affected
 * (`InventoryDepthBundle\EventSubscriber\ShipmentShippingStatusSubscriber` is the one that does
 * today). The deriver stays the single writer of the column either way, and core keeps zero
 * compile-time knowledge of any bundle.
 *
 * What this listener is therefore responsible for on its own is the half that IS core's: an invoice
 * whose own row moved. A status transition to Completed is the case that matters — with no
 * shipped-quantity provider active, Completed is the only evidence core has that anything shipped,
 * so that write is exactly when the column has to be recomputed. Adding or repricing a line matters
 * too when a provider IS active, for the reason repricing matters to the payment status: the billed
 * quantity is what a shipment is measured against, so billing one more case can turn a Shipped
 * invoice into a partially shipped one without a shipment row being touched at all.
 *
 * Every scheduled invoice is queued rather than only those whose `status` changed, deliberately. A
 * narrower filter would have to name every field the answer depends on — status today, and the line
 * collection through a bundle's arithmetic — and the derivation is cheap and idempotent: a
 * recalculation that finds nothing moved writes nothing, logs nothing and flushes nothing.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class InvoiceShippingStatusSubscriber
{
    /** @var array<int, Invoice> keyed by spl_object_id() — an inserted invoice has no db id at onFlush time */
    private array $queued = [];

    public function __construct(
        private readonly InvoiceShippingStatusDeriver $deriver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->queue($entity);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->queue($entity);
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

    /**
     * Deletions are not queued, unlike the payment subscriber's.
     *
     * A deleted invoice has no status left to derive, and its lines go with it. There is no
     * surviving document the way a deleted payment leaves an invoice behind that has to be
     * reconsidered.
     */
    private function queue(object $entity): void
    {
        if ($entity instanceof Invoice) {
            $this->queued[spl_object_id($entity)] = $entity;
        }
    }
}
