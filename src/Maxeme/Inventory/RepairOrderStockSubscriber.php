<?php

declare(strict_types=1);

namespace App\Maxeme\Inventory;

use App\Maxeme\Document\DocumentNumbers;
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
                $entity instanceof RepairOrderJob, $entity instanceof RepairOrderJobLine => $entity->getRepairOrder(),
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
            if ($repairOrder->holdsStock() && $repairOrder->getId() !== null) {
                $this->reconciler->reconcile(
                    new RepairOrderReservationSubject($repairOrder, $this->warehouse['region'], $this->numbers->repairOrderNumber($repairOrder)),
                    $this->entityManager,
                );
            }
        }
    }
}
