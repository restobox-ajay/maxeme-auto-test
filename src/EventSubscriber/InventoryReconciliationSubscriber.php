<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\CreditMemo;
use App\Entity\CreditMemoLine;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\Inventory\BackorderQueueSynchronizer;
use App\Service\Inventory\InventoryReservationReconciler;
use App\Service\Inventory\InvoiceReservationSubject;
use App\Service\Inventory\SalesOrderBackorderReservationSubject;
use App\Service\Inventory\SalesOrderReservationSubject;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Guarantees InventoryReservationReconciler::reconcile() runs for every SalesOrder and every
 * Invoice, regardless of which controller action created or changed it — checkout, admin create/
 * edit/updateStatus/cloneOrder, customer cancel/pay, quote conversion, Convert to Invoice, the
 * stale-unpaid sweep, and any future code path, with nothing to remember to wire in. Same "watch the
 * UnitOfWork directly" strategy AuditLogSubscriber and SalesOrderDerivedStatusSubscriber already use
 * (see docs/event-listeners.md), applied here because reconciliation needs the same "can't be
 * forgotten" guarantee audit logging does.
 *
 * ## An invoice write queues its ORDER too (#539 stage 3)
 *
 * The order holds `sales_hold` on its uninvoiced remainder, so anything that changes what an invoice
 * bills changes what its order still owes — and most of those writes never touch the order row at
 * all. Re-quantifying a line on a partially-invoiced order is the clearest case: the order's status
 * does not move, so SalesOrderDerivedStatusSubscriber writes nothing, and without queueing the order
 * here its sales hold would keep the old remainder indefinitely.
 *
 * The two documents are reconciled independently rather than in one pass, because they are two
 * ledgers against two buckets; the only thing they share is that one write can change both.
 *
 * ## A credit note write queues the INVOICE it credits (#586)
 *
 * And nothing else. A credit note holds no bucket of its own: it reduces what the invoice holds, by
 * being subtracted inside InvoiceReservationSubject::stockedQuantityFor(). So the note is never a
 * subject, there is no third reservation entity, and the only thing this listener does with one is
 * work out which invoices its lines release and queue those. See queueCreditedInvoices().
 *
 * Deliberately not something other code should listen to for "inventory changed" — that is what
 * InventoryBucketsReconciledEvent (dispatched from inside reconcile() itself) is for. This listener
 * is plumbing, not a public extension point.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class InventoryReconciliationSubscriber
{
    /** @var array<int, SalesOrder> keyed by spl_object_id() — an inserted order has no db id yet at onFlush time */
    private array $queuedOrders = [];

    /** @var array<int, Invoice> keyed by spl_object_id(), for the same reason */
    private array $queuedInvoices = [];

    /**
     * Documents being deleted in this flush, keyed by spl_object_id().
     *
     * A deletion still has to queue the document's PARENT — dropping a line changes what its order
     * holds — which is why the deletions loop calls queueFromEntity() at all. But the deleted
     * document itself must not be reconciled: by postFlush its row is gone, its reservations went
     * with it on cascade, and Doctrine has stripped its identifier, so anything that binds it as a
     * query parameter throws "does not have an identifier".
     */
    private array $deletedDocuments = [];

    public function __construct(
        private readonly InventoryReservationReconciler $reconciler,
        private readonly BackorderQueueSynchronizer $backorderQueue,
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

            // An invoice that has just been MOVED off an order — unlinked, or relinked elsewhere
            // (#539 stage 5) — leaves the order it left holding a sales hold for quantity nobody is
            // billing any more. getSalesOrder() cannot find that order: it already answers with the
            // new value. The changeset is the only place the old one still exists.
            if ($entity instanceof Invoice) {
                $previous = $uow->getEntityChangeSet($entity)['salesOrder'][0] ?? null;
                if ($previous instanceof SalesOrder) {
                    $this->queuedOrders[spl_object_id($previous)] = $previous;
                }
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof SalesOrder || $entity instanceof Invoice) {
                $this->deletedDocuments[spl_object_id($entity)] = true;
            }

            $this->queueFromEntity($entity);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->queuedOrders === [] && $this->queuedInvoices === []) {
            $this->deletedDocuments = [];

            return;
        }

        // Clear before reconciling: each reconcile() call below triggers its own inner flush (and
        // thus its own onFlush/postFlush pass) — clearing first ensures that inner pass sees an
        // empty queue and is a safe no-op, not infinite recursion.
        $orders = array_diff_key($this->queuedOrders, $this->deletedDocuments);
        $invoices = array_diff_key($this->queuedInvoices, $this->deletedDocuments);
        $this->deletedDocuments = [];
        $this->queuedOrders = [];
        $this->queuedInvoices = [];

        // Invoices first, deliberately. An invoice's own hold is what an order's sales hold is the
        // remainder OF, so settling the billed side before the unbilled one means each pass reads a
        // ledger the other has already finished writing. Either order converges — the reconciler
        // diffs rather than increments — but this one converges without a second pass.
        foreach ($invoices as $invoice) {
            $this->reconciler->reconcile(new InvoiceReservationSubject($invoice), $this->entityManager);
        }

        foreach ($orders as $order) {
            // Two subjects, one order (#548). The stocked part of what it owes and the part with no
            // stock behind it are two ledgers' worth of arithmetic through one reconciler, not two
            // reconcilers — see SalesOrderBackorderReservationSubject. Stock first, so that when a
            // release moves units from one bucket to the other the pass that grows the hold has
            // already run when the pass that shrinks the promise reads.
            $this->reconciler->reconcile(new SalesOrderReservationSubject($order), $this->entityManager);
            $this->reconciler->reconcile(new SalesOrderBackorderReservationSubject($order), $this->entityManager);

            // Derived from the same lines the pass above just read, for the same reason it is here
            // rather than at each call site: this is the one place that sees every line change.
            $this->backorderQueue->sync($order, $this->entityManager);
        }
    }

    private function queueFromEntity(object $entity): void
    {
        if ($entity instanceof SalesOrder) {
            $this->queuedOrders[spl_object_id($entity)] = $entity;

            return;
        }

        if ($entity instanceof SalesOrderLine) {
            $order = $entity->getOrder();
            $this->queuedOrders[spl_object_id($order)] = $order;

            return;
        }

        if ($entity instanceof Invoice) {
            $this->queueInvoice($entity);

            return;
        }

        if ($entity instanceof InvoiceLine) {
            $this->queueInvoice($entity->getInvoice());

            return;
        }

        // A credit note's write is an INVOICE write in disguise (#586). The note holds no bucket of
        // its own — it releases the invoice's, through InvoiceLine::getCreditedUnits() — so the
        // document that has to be reconciled is the invoice each credited line points at, and
        // nothing about the note itself.
        //
        // The status change is the case that makes this necessary rather than convenient: issuing or
        // voiding a note touches no invoice row at all, and without queueing from here the invoice
        // would go on holding stock for units the customer has sent back.
        if ($entity instanceof CreditMemo) {
            $this->queueCreditedInvoices($entity);

            return;
        }

        if ($entity instanceof CreditMemoLine) {
            $this->queueCreditedLine($entity);
        }
    }

    /**
     * Every invoice a credit note releases quantity from — which is not necessarily the one on its
     * header.
     *
     * `credit_memo.invoice_id` is provenance ("raised from INV-123"); the release happens per LINE,
     * through `credit_memo_line.invoice_line_id`, and a note may credit lines belonging to more than
     * one invoice. So the lines are the authority here and the header is queued as well, since
     * raising a note against an invoice is itself a reason to re-read that invoice's hold.
     */
    private function queueCreditedInvoices(CreditMemo $memo): void
    {
        $header = $memo->getInvoice();
        if ($header instanceof Invoice) {
            $this->queueInvoice($header);
        }

        foreach ($memo->getLines() as $line) {
            $this->queueCreditedLine($line);
        }
    }

    /** A standalone credit line — one crediting nothing in particular — releases nothing and queues nothing. */
    private function queueCreditedLine(CreditMemoLine $line): void
    {
        $invoiceLine = $line->getInvoiceLine();
        if ($invoiceLine instanceof InvoiceLine) {
            $this->queueInvoice($invoiceLine->getInvoice());
        }
    }

    /** An invoice, plus the order whose uninvoiced remainder it changes — a standalone one bills no order. */
    private function queueInvoice(Invoice $invoice): void
    {
        $this->queuedInvoices[spl_object_id($invoice)] = $invoice;

        $order = $invoice->getSalesOrder();
        if ($order instanceof SalesOrder) {
            $this->queuedOrders[spl_object_id($order)] = $order;
        }
    }
}
