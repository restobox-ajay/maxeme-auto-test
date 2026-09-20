<?php

declare(strict_types=1);

namespace InventoryDepthBundle\EventSubscriber;

use App\Entity\Invoice;
use App\Service\QuantityScale;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use InventoryDepthBundle\Shipment\ShipmentException;
use InventoryDepthBundle\Shipment\ShipmentRequest;
use InventoryDepthBundle\Shipment\ShipmentService;
use Psr\Log\LoggerInterface;

/**
 * Completed auto-ships the remainder (`docs/plans/2026-09-14-shipment-dispatch.md`, "Completed
 * auto-ships the remainder").
 *
 * Owner: *"u basically have a shipment doc that claimed the entire thing in 1 click. Nothing wrong
 * with that."* One function either way — `ShipmentService::ship()` is the only path that ever
 * writes a `ShipmentLine` or moves `available` to `sold`, whether an admin filled in the form or
 * this listener did it on their behalf. There is no second bucket-writing path here.
 *
 * ## Watches the UnitOfWork directly, the same "can't be forgotten" strategy
 *
 * Same shape as `App\EventSubscriber\InventoryReconciliationSubscriber`: reachable from checkout,
 * admin updateStatus, quote conversion, and any future code path that moves an invoice to
 * Completed, with nothing to remember to wire in. Bundle-only, by design — core has and needs zero
 * awareness of this listener; if `InventoryDepthBundle` is deleted or inactive, invoices still
 * complete exactly as they did before Shipment existed, and simply produce no `ShipmentLine`.
 *
 * ## Never touches a reservation or a bucket column
 *
 * That transfer already happened, unconditionally, by the time this listener's `postFlush` runs:
 * `InvoiceInventoryBucketResolver::bucketForStatus('Completed')` maps to `shipped`, and
 * `InventoryReservationReconciler::reconcile()` — driven by the SAME status write, through
 * `InventoryReconciliationSubscriber` — already moved the whole `approved` balance there. This
 * listener's entire job is the paperwork and the physical movement `ship()` already does for an
 * explicit shipment; calling it from Completed instead of a form does not make it a different
 * operation, and this never writes `InvoiceInventoryReservation` or either bucket column itself.
 *
 * ## Only the uncovered remainder
 *
 * Read off `ShipmentService::remainingToShip()` per line — an admin may already have shipped
 * some or all of an invoice's lines explicitly, any time before Completed, and this only ever ships
 * what's left. A line with nothing left is skipped outright, so an invoice that was fully shipped
 * by hand generates no auto-shipment at all, and a partially-shipped one only ships the rest.
 *
 * The remainder is a DECIMAL quantity, the exact string the column holds (2026-09-17). It used to
 * be `(int) round((float) …)`, which broke this listener in both directions: a line with 0.4 left
 * read as 0 and was skipped, completing the invoice with goods still owed, and a line with 1.6 left
 * read as 2 and built a request the oversell guard then refused — so the whole auto-shipment,
 * including every other line on it, was logged away and lost.
 *
 * ## Refuses quietly, not loudly
 *
 * A `ShipmentException` here (no warehouse resolvable for a line's fulfillment region, a tracked
 * line's own lot no longer covering the remaining quantity) is logged and swallowed rather than
 * thrown back through the status transition. The invoice has already, correctly, become Completed
 * by the time this runs — a physical shipping problem on one line is not a reason to fail that
 * write and leave the invoice stuck, and the log line is exactly what a warehouse admin needs to go
 * finish the paperwork by hand.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class InvoiceShippingRemainderSubscriber
{
    /** @var array<int, Invoice> keyed by spl_object_id() */
    private array $completedInvoices = [];

    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Invoice) {
                continue;
            }

            [$old, $new] = $uow->getEntityChangeSet($entity)['status'] ?? [null, null];
            if ($new === 'Completed' && $old !== 'Completed') {
                $this->completedInvoices[spl_object_id($entity)] = $entity;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->completedInvoices === []) {
            return;
        }

        // Clear before shipping: ship() triggers its own inner flush (and thus its own
        // onFlush/postFlush pass) — clearing first ensures that inner pass sees an empty queue and
        // is a safe no-op, not infinite recursion. Same discipline as
        // InventoryReconciliationSubscriber::postFlush() for the same reason.
        $invoices = $this->completedInvoices;
        $this->completedInvoices = [];

        foreach ($invoices as $invoice) {
            $this->shipRemainder($invoice);
        }
    }

    private function shipRemainder(Invoice $invoice): void
    {
        $request = null;

        foreach ($invoice->getLines() as $line) {
            // The exact decimal string, never a rounded whole: a line with 0.4 outstanding used to
            // read as 0 here and be skipped, so the invoice completed with goods still owed and
            // nothing recorded for them.
            $remaining = $this->shipments->remainingToShip($line);
            if (QuantityScale::compare($remaining, 0) <= 0) {
                continue;
            }

            $request ??= ShipmentRequest::for(
                $invoice->getCompany(),
                null,
                'Auto-shipped: invoice completed with no explicit shipment covering this line.',
                null,
                'invoice-completed-' . $invoice->getId(),
            );
            $request->add($line, $remaining);
        }

        if ($request === null) {
            return;
        }

        try {
            $this->shipments->ship($request, 'system');
        } catch (ShipmentException $e) {
            $this->logger->warning('Completed invoice auto-ship could not be recorded: ' . $e->getMessage(), [
                'invoice' => $invoice->getDocumentNumber(),
            ]);
        }
    }
}
