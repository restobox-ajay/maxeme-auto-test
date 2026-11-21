<?php

declare(strict_types=1);

namespace App\Maxeme\Inventory;

use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\RepairOrderJob;
use App\Maxeme\Entity\RepairOrderJobLine;
use App\Service\Inventory\InventoryReservationReconciler;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps a repair order's stock holds in step with it: whenever a repair order, one of its services
 * or one of their lines is written, the repair order is reconciled after the flush (core's
 * InventoryReservationReconciler, with RepairOrderReservationSubject). So a status change moves
 * its parts between buckets, and a part added after the work started is held as approved at once.
 * An invoice written for it (issued, cancelled, deleted) reconciles it too: once an invoice is
 * issued its parts leave the stock and the hold is released, and they come back when it is
 * cancelled (RepairOrderStockIssue).
 *
 * Repair orders converted from the legacy invoices hold nothing (holdsStock off): their parts left
 * the stock count long ago.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class RepairOrderStockSubscriber
{
    /** @var array<int, RepairOrder> keyed by spl_object_id(): a new repair order has no id at onFlush */
    private array $queued = [];

    /** @var array<int, true> repair orders deleted in this flush (their reservations went with them) */
    private array $deleted = [];

    /** @param array{region: string} $warehouse */
    public function __construct(
        private readonly InventoryReservationReconciler $reconciler,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentNumbers $numbers,
        private readonly RepairOrderStockIssue $stock,
        #[Autowire(param: 'maxeme.warehouse')]
        private readonly array $warehouse,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            $repairOrder = match (true) {
                $entity instanceof RepairOrder => $entity,
                $entity instanceof RepairOrderJob, $entity instanceof RepairOrderJobLine, $entity instanceof Invoice => $entity->getRepairOrder(),
                default => null,
            };
            if ($repairOrder !== null) {
                $this->queued[spl_object_id($repairOrder)] = $repairOrder;
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof RepairOrder) {
                $this->deleted[spl_object_id($entity)] = true;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        // Cleared first: each reconcile() flushes, and that inner pass must find nothing to do.
        $repairOrders = array_diff_key($this->queued, $this->deleted);
        $this->queued = [];
        $this->deleted = [];

        foreach ($repairOrders as $repairOrder) {
            $this->reconcile($repairOrder);
        }
    }

    /** One repair order's hold and parts brought in line (also run by app:maxeme:sync-repair-order-stock). */
    public function reconcile(RepairOrder $repairOrder): void
    {
        if (!$repairOrder->holdsStock() || $repairOrder->getId() === null) {
            return;
        }
        // The hold first (released once an invoice is issued), then the parts off the shelf or back onto it.
        $this->reconciler->reconcile(
            new RepairOrderReservationSubject($repairOrder, $this->warehouse['region'], $this->numbers->repairOrderNumber($repairOrder), $this->stock->issuingInvoice($repairOrder) !== null),
            $this->entityManager,
        );
        $this->stock->sync($repairOrder);
    }
}
