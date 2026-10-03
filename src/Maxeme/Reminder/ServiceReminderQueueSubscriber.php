<?php

declare(strict_types=1);

namespace App\Maxeme\Reminder;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Enum\RepairOrderStatus;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Feeds the service reminder queue: a repair order whose status becomes Completed (or any later
 * work-done status) queues its reminders, and a new appointment books the queued reminders of its
 * client's vehicle (ServiceReminderQueue). Both happen after the flush, in a flush of their own.
 *
 * Repair orders converted from legacy invoices (holdsStock off) queue nothing.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ServiceReminderQueueSubscriber
{
    /** @var array<int, RepairOrder> keyed by spl_object_id() */
    private array $completed = [];

    /** @var array<int, Appointment> keyed by spl_object_id() */
    private array $appointments = [];

    public function __construct(
        private readonly ServiceReminderQueue $queue,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof RepairOrder && $entity->getStatus()->isWorkDone()) {
                $this->completed[spl_object_id($entity)] = $entity;
            } elseif ($entity instanceof Appointment) {
                $this->appointments[spl_object_id($entity)] = $entity;
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof RepairOrder || !isset($uow->getEntityChangeSet($entity)['status'])) {
                continue;
            }
            [$before, $after] = array_map(self::status(...), $uow->getEntityChangeSet($entity)['status']);
            if (($after?->isWorkDone() ?? false) && !($before?->isWorkDone() ?? false)) {
                $this->completed[spl_object_id($entity)] = $entity;
            }
        }
    }

    /** A change set holds the enum, or its raw value. */
    private static function status(mixed $value): ?RepairOrderStatus
    {
        return $value instanceof RepairOrderStatus ? $value : RepairOrderStatus::tryFrom((string) $value);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        // Cleared first: the flush below must find nothing to do here.
        $completed = $this->completed;
        $appointments = $this->appointments;
        $this->completed = [];
        $this->appointments = [];

        $changed = false;
        $now = new \DateTimeImmutable();
        foreach ($completed as $repairOrder) {
            if ($repairOrder->holdsStock() && $repairOrder->getId() !== null) {
                $changed = $this->queue->queueFor($repairOrder, $now) !== [] || $changed;
            }
        }
        foreach ($appointments as $appointment) {
            if ($appointment->getId() !== null) {
                $changed = $this->queue->bookFor($appointment) !== [] || $changed;
            }
        }
        if ($changed) {
            $this->entityManager->flush();
        }
    }
}
