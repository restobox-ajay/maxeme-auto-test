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
    public const SORTS = ['date', 'status', 'client', 'phoneStatus', 'emailStatus'];

    /** The header text boxes (mockup reminder-queue.html): filters[field], a case-insensitive "contains". */
    public const TEXT_FILTERS = ['client', 'vehicle', 'note', 'phoneNumber', 'emailAddress'];

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
                [$reminder->getClient()->getFullName(), $reminder->getVehicle()->getFullName(), $reminder->getNote(), $reminder->getDelayNote(), $reminder->getClient()->getEmail(), implode(' ', $reminder->getClient()->getPhones())],
                [
                    'client' => self::clientText($reminder->getClient()),
                    'vehicle' => self::vehicleText($reminder->getVehicle()),
                    'note' => trim($reminder->getNote() . ' ' . $reminder->getDelayNote()),
                    'phoneNumber' => implode(' ', $reminder->getClient()->getPhones()),
                    'emailAddress' => (string) $reminder->getClient()->getEmail(),
                ], $reminder->getPhoneStatus()->label());
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
                [$entry->getClient()->getFullName(), $entry->getVehicle()->getFullName(), $entry->getServiceName(), $entry->getEmail(), implode(' ', $entry->getClient()->getPhones())],
                [
                    'client' => self::clientText($entry->getClient()),
                    'vehicle' => self::vehicleText($entry->getVehicle()),
                    'note' => sprintf('%s %d days due %s', $entry->getServiceName(), $entry->getReminderDays(), $entry->getDueAt()->format('m/d/Y')),
                    'phoneNumber' => implode(' ', $entry->getClient()->getPhones()),
                    'emailAddress' => (string) ($entry->getEmail() ?: $entry->getClient()->getEmail()),
                ], $entry->getPhoneStatus()->label());
        }

        $filters = $list->filters;
        $search = mb_strtolower($list->search);
        $rows = array_values(array_filter($rows, static fn (array $row): bool => ($search === '' || self::searchMatches($row['search'], $search))
            && (($filters['status'] ?? '') === '' || $row['status'] === $filters['status'])
            && (($filters['phone'] ?? '') === '' || $row['phoneStatus'] === $filters['phone'])
            && (($filters['email'] ?? '') === '' || $row['emailStatus'] === $filters['email'])
            && self::textFiltersMatch($row['texts'], $filters)));

        $sign = $list->dir === 'desc' ? -1 : 1;
        usort($rows, static fn (array $a, array $b): int => $sign * match ($list->sort) {
            'status' => strcmp($a['statusLabel'], $b['statusLabel']),
            'client' => strcasecmp(self::client($a), self::client($b)),
            'phoneStatus' => strcmp($a['phoneStatusLabel'], $b['phoneStatusLabel']),
            'emailStatus' => strcmp(self::EMAIL_STATUSES[$a['emailStatus']] ?? '', self::EMAIL_STATUSES[$b['emailStatus']] ?? ''),
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
    /**
     * The search box text is in the row's text; a phone number also matches however it is typed
     * ("604-555-0101" finds a phone stored as 6045550101).
     */
    private static function searchMatches(string $haystack, string $search): bool
    {
        if (str_contains($haystack, $search)) {
            return true;
        }
        if (strlen(preg_replace('/\D+/', '', $search) ?? '') < 3) {
            return false;
        }
        $strip = static fn (string $text): string => str_replace(['-', ' ', '(', ')', '.', '+'], '', $text);

        return str_contains($strip($haystack), $strip($search));
    }

    /**
     * Every filled header text box is in its column's text (any case); the Phone number box
     * compares digits, so "604-555-0101" finds 6045550101.
     *
     * @param array<string, string> $texts
     * @param array<string, string> $filters
     */
    private static function textFiltersMatch(array $texts, array $filters): bool
    {
        foreach (self::TEXT_FILTERS as $field) {
            $value = $filters[$field] ?? '';
            if ($value === '') {
                continue;
            }
            $text = $texts[$field] ?? '';
            $digits = preg_replace('/\D+/', '', $value) ?? '';
            $matches = $field === 'phoneNumber' && $digits !== ''
                ? str_contains(preg_replace('/\D+/', '', $text) ?? '', $digits)
                : mb_stripos($text, $value) !== false;
            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    /** The Client Info column's text: the name and the latest note. */
    private static function clientText(\App\Maxeme\Entity\Client $client): string
    {
        return trim($client->getFullName() . ' ' . ($client->getLatestNote()?->getText() ?? ''));
    }

    /** The Vehicle column's text: the vehicle and its plate. */
    private static function vehicleText(\App\Maxeme\Entity\Vehicle $vehicle): string
    {
        return trim($vehicle->getFullName() . ' ' . $vehicle->getLicensePlate());
    }

    /** @param array<string, string> $texts the header boxes' column texts (TEXT_FILTERS) */
    private static function row(string $kind, ?Reminder $reminder, ?ServiceReminderQueueEntry $entry, \DateTimeImmutable $date, string $status, string $label, string $badge, ?string $emailStatus, string $phoneStatus, array $searchable, array $texts = [], string $phoneStatusLabel = ''): array
    {
        return [
            'texts' => $texts,
            'phoneStatusLabel' => $phoneStatusLabel,
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
