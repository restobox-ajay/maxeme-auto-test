<?php

declare(strict_types=1);

namespace App\Maxeme\Reminder;

use App\Maxeme\Accounting\ReportPeriod;
use App\Maxeme\Entity\Reminder;
use App\Maxeme\Entity\ServiceReminderQueueEntry;
use App\Maxeme\Enum\ReminderStatus;
use App\Maxeme\Enum\ServiceReminderQueueStatus;
use App\Maxeme\Listing\ListQuery;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Schedule › Reminders (mockup 1b7f972): the reminders (the old Reminder Todo's) and the service
 * reminder queue in one list, dated in a period: a reminder by the day it came due, a queue entry by
 * when it was queued. Two statuses each (mockup aa322bb): the reminder's own (a queue entry is New until
 * it is Booked or Resolved) and the email's (queue entries only: Queued, Sent, Skipped, Error), plus how
 * the call went (phone status). Newest first by default.
 */
final class ReminderList
{
    public const SORTS = ['date', 'status', 'client'];

    /** The reminder statuses the Status filter offers: a reminder's, and a queue entry's New / Booked / Resolved. */
    public const STATUSES = ['new' => 'New', 'unavailable' => 'Unavailable', 'delayed' => 'Delayed', 'booked' => 'Booked', 'resolved' => 'Resolved', 'declined' => 'Declined'];

    /** The Email Status filter's choices. */
    public const EMAIL_STATUSES = ['queued' => 'Queued', 'sent' => 'Sent', 'skipped' => 'Skipped', 'error' => 'Error'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Every row of the period in the list's search, filters (status, phone, email) and sort.
     *
     * @return list<array{kind: string, reminder: ?Reminder, entry: ?ServiceReminderQueueEntry, date: \DateTimeImmutable, status: string, statusLabel: string, statusBadge: string, emailStatus: ?string, phoneStatus: string, search: string}>
     */
    public function rows(ReportPeriod $period, ListQuery $list): array
    {
        $rows = [];
        $reminders = $this->entityManager->getRepository(Reminder::class)->createQueryBuilder('r')
            ->addSelect('c', 'v', 'i')
            ->join('r.client', 'c')
            ->join('r.vehicle', 'v')
            ->leftJoin('r.invoice', 'i')
            ->andWhere('r.reminderDate BETWEEN :start AND :end')
            ->setParameter('start', $period->startUtc())
            ->setParameter('end', $period->endUtc())
            ->getQuery()->getResult();
        foreach ($reminders as $reminder) {
            $rows[] = self::row('reminder', $reminder, null, $reminder->getReminderDate(), $reminder->getStatus()->value, $reminder->getStatus()->label(), $reminder->getStatus()->badge(), null, $reminder->getPhoneStatus()->value,
                [$reminder->getClient()->getFullName(), $reminder->getVehicle()->getFullName(), $reminder->getNote(), $reminder->getDelayNote(), $reminder->getClient()->getEmail(), implode(' ', $reminder->getClient()->getPhones())]);
        }
        $entries = $this->entityManager->getRepository(ServiceReminderQueueEntry::class)->createQueryBuilder('e')
            ->addSelect('c', 'v')
            ->join('e.client', 'c')
            ->join('e.vehicle', 'v')
            ->andWhere('e.queuedAt BETWEEN :start AND :end')
            ->setParameter('start', $period->startUtc())
            ->setParameter('end', $period->endUtc())
            ->getQuery()->getResult();
        foreach ($entries as $entry) {
            $own = $entry->isClosed() ? $entry->getStatus() : null;
            $status = $own?->value ?? 'new';
            $rows[] = self::row('queue', null, $entry, $entry->getQueuedAt(), $status, self::STATUSES[$status], $own !== null ? 'success' : ReminderStatus::New->badge(), self::emailStatus($entry), $entry->getPhoneStatus()->value,
                [$entry->getClient()->getFullName(), $entry->getVehicle()->getFullName(), $entry->getServiceName(), $entry->getEmail(), implode(' ', $entry->getClient()->getPhones())]);
        }

        $filters = $list->filters;
        $search = mb_strtolower($list->search);
        $rows = array_values(array_filter($rows, static fn (array $row): bool => ($search === '' || str_contains($row['search'], $search))
            && (($filters['status'] ?? '') === '' || $row['status'] === $filters['status'])
            && (($filters['phone'] ?? '') === '' || $row['phoneStatus'] === $filters['phone'])
            && (($filters['email'] ?? '') === '' || $row['emailStatus'] === $filters['email'])));

        $sign = $list->dir === 'desc' ? -1 : 1;
        usort($rows, static fn (array $a, array $b): int => $sign * match ($list->sort) {
            'status' => strcmp($a['statusLabel'], $b['statusLabel']),
            'client' => strcasecmp(self::client($a), self::client($b)),
            default => $a['date'] <=> $b['date'],
        } ?: $b['date'] <=> $a['date']);

        return $rows;
    }

    /** The date of the newest reminder or queue entry, whatever the period (the "show its month" hint). */
    public function latestDate(): ?\DateTimeImmutable
    {
        $reminder = $this->entityManager->getRepository(Reminder::class)->createQueryBuilder('r')->select('MAX(r.reminderDate)')->getQuery()->getSingleScalarResult();
        $entry = $this->entityManager->getRepository(ServiceReminderQueueEntry::class)->createQueryBuilder('e')->select('MAX(e.queuedAt)')->getQuery()->getSingleScalarResult();
        $dates = array_filter([$reminder, $entry]);

        return $dates !== [] ? new \DateTimeImmutable(max($dates), new \DateTimeZone('UTC')) : null;
    }

    /** How many are dated after the period (the "show up to" hint). */
    public function countAfter(ReportPeriod $period): int
    {
        $after = $period->endUtc();

        return (int) $this->entityManager->getRepository(Reminder::class)->createQueryBuilder('r')->select('COUNT(r.id)')->andWhere('r.reminderDate > :after')->setParameter('after', $after)->getQuery()->getSingleScalarResult()
            + (int) $this->entityManager->getRepository(ServiceReminderQueueEntry::class)->createQueryBuilder('e')->select('COUNT(e.id)')->andWhere('e.queuedAt > :after')->setParameter('after', $after)->getQuery()->getSingleScalarResult();
    }

    /**
     * The email's status: an open entry's own; a Booked or Resolved one shows Sent when it was sent.
     */
    public static function emailStatus(ServiceReminderQueueEntry $entry): ?string
    {
        if ($entry->isClosed()) {
            return $entry->getSentAt() !== null ? ServiceReminderQueueStatus::Sent->value : null;
        }

        return $entry->getStatus()->value;
    }

    /**
     * @param list<?string> $searchable
     *
     * @return array{kind: string, reminder: ?Reminder, entry: ?ServiceReminderQueueEntry, date: \DateTimeImmutable, status: string, statusLabel: string, statusBadge: string, emailStatus: ?string, phoneStatus: string, search: string}
     */
    private static function row(string $kind, ?Reminder $reminder, ?ServiceReminderQueueEntry $entry, \DateTimeImmutable $date, string $status, string $label, string $badge, ?string $emailStatus, string $phoneStatus, array $searchable): array
    {
        return [
            'kind' => $kind,
            'reminder' => $reminder,
            'entry' => $entry,
            'date' => $date,
            'status' => $status,
            'statusLabel' => $label,
            'statusBadge' => $badge,
            'emailStatus' => $emailStatus,
            'phoneStatus' => $phoneStatus,
            'search' => mb_strtolower(implode(' ', array_filter($searchable))),
        ];
    }

    /** @param array{reminder: ?Reminder, entry: ?ServiceReminderQueueEntry} $row */
    private static function client(array $row): string
    {
        return ($row['reminder'] ?? $row['entry'])->getClient()->getFullName();
    }
}
