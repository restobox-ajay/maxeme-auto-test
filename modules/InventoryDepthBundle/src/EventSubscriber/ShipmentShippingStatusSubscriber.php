<?php

declare(strict_types=1);

namespace InventoryDepthBundle\EventSubscriber;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Service\InvoiceShippingStatusDeriver;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use InventoryDepthBundle\Entity\Shipment;
use InventoryDepthBundle\Entity\ShipmentLine;

/**
 * A shipment recorded here moves the invoice off "Not Shipped", without anybody touching the
 * invoice row.
 *
 * This is the bundle half of core's derived `invoice.shipping_status`. Core watches invoices —
 * `App\EventSubscriber\InvoiceShippingStatusSubscriber` — and cannot watch shipments, because core
 * has no shipment table and must not learn of one. This watches ours and calls **the core deriver**
 * for the invoices affected.
 *
 * ## It triggers, it does not write
 *
 * `App\Service\InvoiceShippingStatusDeriver` stays the single writer of the column, exactly as
 * `InvoicePaymentStatusDeriver` is for the payment one, and this class holds no opinion at all
 * about what the status should become. It names which invoices are now stale; the deriver decides
 * what they say, by asking every active `app.shipped_quantity` provider — including ours, which is
 * how our own rows get counted. A bundle that wrote the column itself would be a second answer to a
 * question the shipment rows already answer, and the two would disagree the first time this bundle
 * was switched off with its invoices left behind.
 *
 * ## What is watched, and why each one
 *
 * **`ShipmentLine` insert, update and delete.** The rows the shipped quantity is summed from. An
 * insert is the ordinary case (a picker records a shipment); an update covers an amended quantity
 * or a line re-pointed at another invoice line; a delete covers a line removed outright.
 *
 * **`Shipment` update, when `voidedAt` changed.** Not in the original brief, and needed: a void
 * leaves every `ShipmentLine` untouched on purpose — `ShipmentVoidService` records the withdrawal
 * as a second fact and returns the stock through a new movement rather than unwriting the lines —
 * so the row whose change makes an invoice cease to be Shipped is the SHIPMENT's, and watching only
 * lines would leave an invoice reading Shipped with the goods back on the shelf. The condition is
 * read off the change set rather than off `isVoided()`, so an ordinary edit to a long-voided
 * shipment does not re-derive every invoice it touches.
 *
 * A moved line's PREVIOUS invoice is queued too, from the unit of work's change set, for the reason
 * `InvoicePaymentStatusSubscriber` reads a moved payment's previous invoice there: after the move
 * `getInvoiceLine()` names the destination, nothing about the move dirties the source invoice's own
 * row, and the invoice that LOST the shipment would otherwise keep reading Shipped while holding no
 * shipment at all. The change set is the only place the old invoice line still exists at flush time.
 *
 * ## Bundle-only, by design
 *
 * Delete this bundle or switch it Inactive and nothing here runs, no shipment row exists to run it
 * for, and core's deriver answers from the invoice's own status — which is what the column meant
 * before shipments existed. Core has and needs zero awareness of this listener.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ShipmentShippingStatusSubscriber
{
    /** @var array<int, Invoice> keyed by spl_object_id() — an invoice inserted in this same flush has no db id yet */
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
            $this->queueFromEntity($entity);
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->queueFromEntity($entity);

            if ($entity instanceof ShipmentLine) {
                // The invoice this line has just been moved OFF, when that is what the update was.
                // getEntityChangeSet() gives [old, new] per changed field, and 'invoiceLine' is only
                // present when the association actually changed.
                $previousLine = $uow->getEntityChangeSet($entity)['invoiceLine'][0] ?? null;
                if ($previousLine !== null) {
                    $this->queueInvoiceOf($previousLine);
                }
            }

            if ($entity instanceof Shipment && \array_key_exists('voidedAt', $uow->getEntityChangeSet($entity))) {
                $this->queueEveryInvoiceOn($entity);
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

        // Cleared before recalculating, for the reason InvoiceShippingRemainderSubscriber clears
        // first: the flush below opens its own onFlush/postFlush pass, and that inner pass must find
        // an empty queue rather than recalculating the same invoices again.
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
        if ($entity instanceof ShipmentLine) {
            $this->queueInvoiceOf($entity->getInvoiceLine());

            return;
        }

        // A whole shipment inserted or deleted: its lines may not each be scheduled in their own
        // right, so the shipment is walked rather than waited on.
        if ($entity instanceof Shipment) {
            $this->queueEveryInvoiceOn($entity);
        }
    }

    private function queueEveryInvoiceOn(Shipment $shipment): void
    {
        foreach ($shipment->getLines() as $line) {
            $this->queueInvoiceOf($line->getInvoiceLine());
        }
    }

    /**
     * `ShipmentLine::$invoiceLine` is nullable at the column level so a line outlives the loss of
     * its attribution, and a line with none names no invoice to re-derive.
     */
    private function queueInvoiceOf(?object $invoiceLine): void
    {
        if (!$invoiceLine instanceof InvoiceLine) {
            return;
        }

        $invoice = $invoiceLine->getInvoice();
        $this->queued[spl_object_id($invoice)] = $invoice;
    }
}
