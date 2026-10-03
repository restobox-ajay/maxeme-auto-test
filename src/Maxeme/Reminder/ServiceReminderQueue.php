<?php

declare(strict_types=1);

namespace App\Maxeme\Reminder;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\ServiceReminder;
use App\Maxeme\Entity\ServiceReminderQueueEntry;
use App\Maxeme\Enum\ServiceReminderQueueStatus;
use App\Maxeme\Repository\ServiceReminderQueueRepository;
use App\Maxeme\Repository\ServiceReminderRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The service reminder queue's rules (spec "Service Reminder Queue"):
 *
 * - When a repair order is completed, each of its services that has a Service Reminder gets an
 *   entry, shortest reminder days first. The first is Queued. A later one is Skipped when it falls
 *   within SKIP_WITHIN_DAYS of the last queued one, and Queued only when it is more than
 *   QUEUE_AFTER_DAYS after it, so the customer isn't emailed too often. (Between the two it is
 *   skipped as well.) A repair order is queued once, however often it is completed.
 * - When the customer books a new appointment, every queued reminder of the same client and
 *   vehicle becomes Booked and is not sent.
 *
 * ServiceReminderQueueSubscriber calls these; nothing here flushes.
 */
final class ServiceReminderQueue
{
    public const SKIP_WITHIN_DAYS = 60;
    public const QUEUE_AFTER_DAYS = 90;

    public function __construct(
        private readonly ServiceReminderRepository $rules,
        private readonly ServiceReminderQueueRepository $queue,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<ServiceReminderQueueEntry> the entries made for a repair order that was just completed */
    public function queueFor(RepairOrder $repairOrder, \DateTimeImmutable $completedAt): array
    {
        $client = $repairOrder->getClient();
        $vehicle = $repairOrder->getVehicle();
        if ($client === null || $vehicle === null || $this->queue->hasEntriesFor($repairOrder)) {
            return [];
        }

        // Each service once (the first line naming it), with its reminder rule.
        $byService = [];
        foreach ($repairOrder->getJobs() as $job) {
            $service = $job->getService();
            if ($service === null || isset($byService[$service->getId()])) {
                continue;
            }
            $rule = $this->rules->findOneBy(['service' => $service]);
            if ($rule instanceof ServiceReminder) {
                $byService[$service->getId()] = [$rule, $job->getName() ?: $service->getName()];
            }
        }
        usort($byService, static fn (array $a, array $b): int => $a[0]->getReminderDays() <=> $b[0]->getReminderDays());

        $entries = [];
        $lastQueuedDays = null;
        foreach ($byService as [$rule, $name]) {
            $days = $rule->getReminderDays();
            $queued = $lastQueuedDays === null || $days - $lastQueuedDays > self::QUEUE_AFTER_DAYS;
            if ($queued) {
                $lastQueuedDays = $days;
            }
            $entry = new ServiceReminderQueueEntry($repairOrder, $client, $vehicle, $rule, $name, $queued ? ServiceReminderQueueStatus::Queued : ServiceReminderQueueStatus::Skipped, $completedAt);
            $this->entityManager->persist($entry);
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @return list<ServiceReminderQueueEntry> the queued reminders a new appointment booked */
    public function bookFor(Appointment $appointment): array
    {
        $booked = [];
        foreach ($this->queue->findQueuedFor($appointment->getClient(), $appointment->getVehicle()) as $entry) {
            // An appointment added to the very repair order that queued it is not a new booking.
            if ($appointment->getRepairOrder() !== null && $entry->getRepairOrder() === $appointment->getRepairOrder()) {
                continue;
            }
            $entry->changeStatus(ServiceReminderQueueStatus::Booked);
            $booked[] = $entry;
        }

        return $booked;
    }
}
