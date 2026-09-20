<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePaymentApplication;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\SalesOrderStatusDeriver;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Keeps every sales order's derived status true, whatever wrote the order or its invoices
 * (#539 stage 2).
 *
 * Apart from Approved and Void, an order's status is a function of its invoice set — so it has to
 * be recomputed on every write to either side, and "every" means the same thing here that it means
 * for audit logging and inventory reconciliation: not "at the call sites we remembered". Same
 * "watch the UnitOfWork directly" strategy InventoryReconciliationSubscriber and
 * AuditLogSubscriber already use (see docs/event-listeners.md), for the same reason.
 *
 * Invoice and InvoiceLine are watched as well as SalesOrder and SalesOrderLine, because issuing,
 * cancelling or re-quantifying an invoice is exactly what moves its order between Approved,
 * Partially Invoiced, Invoiced and Closed — and none of those writes touches the order row at all.
 *
 * InvoicePaymentApplication joins them in stage 4 (InvoicePayment itself before #708 split a payment
 * pool from its claims), for the same reason and one more: Closed is "fully invoiced and every
 * invoice paid", so the last claim against the last invoice is the write that closes an order — and
 * it touches neither the order nor the invoice row. The deriver reads the payment rows directly
 * through Invoice::isFullyPaid() rather than the derived payment_status column, so it does not
 * matter whether InvoicePaymentStatusSubscriber has run first.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class SalesOrderDerivedStatusSubscriber
{
    /** @var array<int, SalesOrder> keyed by spl_object_id() — an inserted order has no db id at onFlush time */
    private array $queued = [];

    public function __construct(
        private readonly SalesOrderStatusDeriver $deriver,
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

            // An invoice unlinked from its order, or relinked to another one (#539 stage 5), changes
            // the status of the order it LEFT — and getSalesOrder() cannot name that order, because
            // it already answers with the new value. The changeset is where the old one survives.
            if ($entity instanceof Invoice) {
                $previous = $uow->getEntityChangeSet($entity)['salesOrder'][0] ?? null;
                if ($previous instanceof SalesOrder) {
                    $this->queued[spl_object_id($previous)] = $previous;
                }
            }
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

        // Cleared before recalculating, for the reason InventoryReconciliationSubscriber
        // clears first: the flush below opens its own onFlush/postFlush pass, and that inner pass
        // must find an empty queue. Without this the second pass would recalculate the same order
        // again — reaching the same answer, so applyDerivedStatus() would return false and stop it
        // — but only by luck rather than by construction.
        $orders = $this->queued;
        $this->queued = [];

        $changed = false;
        foreach ($orders as $order) {
            $changed = $this->deriver->recalculate($order) || $changed;
        }

        if ($changed) {
            $this->entityManager->flush();
        }
    }

    private function queueFromEntity(object $entity): void
    {
        if ($entity instanceof SalesOrder) {
            $this->queued[spl_object_id($entity)] = $entity;

            return;
        }

        if ($entity instanceof SalesOrderLine) {
            $order = $entity->getOrder();
            $this->queued[spl_object_id($order)] = $order;

            return;
        }

        if ($entity instanceof Invoice) {
            $this->queueOrderOf($entity);

            return;
        }

        if ($entity instanceof InvoiceLine) {
            $this->queueOrderOf($entity->getInvoice());

            return;
        }

        if ($entity instanceof InvoicePaymentApplication) {
            $this->queueOrderOf($entity->getInvoice());
        }
    }

    private function queueOrderOf(?Invoice $invoice): void
    {
        $order = $invoice?->getSalesOrder();

        // A standalone invoice bills no order, and there is nothing to derive from it.
        if ($order instanceof SalesOrder) {
            $this->queued[spl_object_id($order)] = $order;
        }
    }
}
