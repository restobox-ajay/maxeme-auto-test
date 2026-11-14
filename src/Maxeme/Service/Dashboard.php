<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\Reminder;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\ServiceReminderQueueEntry;
use App\Maxeme\Enum\AppointmentStatus;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Enum\PhoneStatus;
use App\Maxeme\Enum\ReminderStatus;
use App\Maxeme\Enum\RepairOrderStatus;
use App\Maxeme\Enum\ServiceReminderQueueStatus;
use App\Maxeme\Schedule\ScheduleSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The Dashboard (mockup 345c037): today's appointments, what is in the shop, what is ready for pick
 * up, unpaid invoices of the last 30 days and the "Needs attention" counts. Work is current when its
 * repair order has an appointment within two weeks either side of today; older records are history.
 */
final class Dashboard
{
    /** In the shop, waiting on parts first. */
    private const IN_SHOP = [RepairOrderStatus::AwaitingParts, RepairOrderStatus::InProgress, RepairOrderStatus::Authorized];

    private const UNPAID_SHOWN = 8;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ScheduleSettings $schedule,
    ) {
    }

    /**
     * @return array{
     *     today: \DateTimeImmutable,
     *     appointments: list<Appointment>,
     *     inShop: list<RepairOrder>,
     *     ready: list<array{repairOrder: RepairOrder, invoice: ?Invoice}>,
     *     unpaid: list<Invoice>, unpaidCount: int, owed: float,
     *     attention: list<array{label: string, count: int, route: string, params: array<string, string>, note: ?string}>
     * }
     */
    public function build(bool $withInvoices): array
    {
        $timezone = $this->schedule->timezone();
        $today = new \DateTimeImmutable('today', $timezone);
        $utc = static fn (\DateTimeImmutable $local): \DateTimeImmutable => $local->setTimezone(new \DateTimeZone('UTC'));
        $weekStart = $today->modify(sprintf('-%d days', (int) $today->format('w')));

        $appointments = $this->entityManager->createQueryBuilder()
            ->select('a', 'c', 'v', 'ro')->from(Appointment::class, 'a')
            ->join('a.client', 'c')->leftJoin('a.vehicle', 'v')->leftJoin('a.repairOrder', 'ro')
            ->andWhere('a.startTime >= :from AND a.startTime < :to')
            ->setParameter('from', $utc($today))->setParameter('to', $utc($today->modify('+1 day')))
            ->orderBy('a.startTime', 'ASC')
            ->getQuery()->getResult();

        /** @var list<RepairOrder> $current */
        $current = $this->entityManager->createQueryBuilder()
            ->select('ro')->from(RepairOrder::class, 'ro')
            ->andWhere('ro.id IN (SELECT IDENTITY(a2.repairOrder) FROM ' . Appointment::class . ' a2 WHERE a2.startTime >= :from AND a2.startTime < :to)')
            ->andWhere('ro.status IN (:statuses)')
            ->setParameter('from', $utc($today->modify('-14 days')))->setParameter('to', $utc($today->modify('+15 days')))
            ->setParameter('statuses', [...self::IN_SHOP, RepairOrderStatus::Completed, RepairOrderStatus::EstimateApproval])
            ->orderBy('ro.id', 'DESC')
            ->getQuery()->getResult();
        $of = static fn (RepairOrderStatus ...$statuses): array => array_values(array_filter($current, static fn (RepairOrder $ro): bool => in_array($ro->getStatus(), $statuses, true)));

        $inShop = $of(...self::IN_SHOP);
        usort($inShop, static fn (RepairOrder $a, RepairOrder $b): int => array_search($a->getStatus(), self::IN_SHOP, true) <=> array_search($b->getStatus(), self::IN_SHOP, true) ?: $b->getId() <=> $a->getId());

        // Ready for pick up, with its invoice (newest not cancelled) so the front desk knows what to collect.
        $completed = $of(RepairOrderStatus::Completed);
        $invoices = $this->liveInvoices($completed);
        $ready = array_map(static fn (RepairOrder $ro): array => ['repairOrder' => $ro, 'invoice' => $invoices[$ro->getId()] ?? null], $completed);
        $notInvoiced = count(array_filter($ready, static fn (array $row): bool => $row['invoice'] === null));

        $unpaid = [];
        $unpaidCount = 0;
        $owed = 0.0;
        if ($withInvoices) {
            /** @var list<Invoice> $all */
            $all = $this->entityManager->createQueryBuilder()
                ->select('i', 'c', 'COALESCE(i.lastModified, i.createdOn) AS HIDDEN touched')->from(Invoice::class, 'i')->leftJoin('i.client', 'c')
                ->andWhere('i.status = :unpaid')->andWhere('COALESCE(i.lastModified, i.createdOn) >= :since')
                ->setParameter('unpaid', InvoiceStatus::Unpaid)->setParameter('since', $utc($today->modify('-30 days')))
                ->orderBy('touched', 'DESC')
                ->getQuery()->getResult();
            $unpaidCount = count($all);
            $owed = array_sum(array_map(static fn (Invoice $invoice): float => (float) $invoice->getTotalPrice(), $all));
            $unpaid = array_slice($all, 0, self::UNPAID_SHOWN);
        }

        $notReached = [PhoneStatus::Reached, PhoneStatus::WrongNumber];
        $remindersToCall = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')->from(Reminder::class, 'r')
            ->andWhere('r.status IN (:open)')->andWhere('r.reminderDate >= :week')->andWhere('r.phoneStatus NOT IN (:done)')
            ->setParameter('open', [ReminderStatus::New, ReminderStatus::Delayed])->setParameter('week', $weekStart)->setParameter('done', $notReached)
            ->getQuery()->getSingleScalarResult()
            + (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(q.id)')->from(ServiceReminderQueueEntry::class, 'q')
            ->andWhere('q.status NOT IN (:closed)')->andWhere('q.queuedAt >= :week')->andWhere('q.phoneStatus NOT IN (:done)')
            ->setParameter('closed', [ServiceReminderQueueStatus::Booked, ServiceReminderQueueStatus::Resolved])->setParameter('week', $utc($weekStart))->setParameter('done', $notReached)
            ->getQuery()->getSingleScalarResult();
        $noShows = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(a.id)')->from(Appointment::class, 'a')
            ->andWhere('a.status = :noShow')->andWhere('a.startTime >= :week AND a.startTime < :to')
            ->setParameter('noShow', AppointmentStatus::NoShow)->setParameter('week', $utc($weekStart))->setParameter('to', $utc($today->modify('+1 day')))
            ->getQuery()->getSingleScalarResult();

        $attention = [
            ['label' => 'Quotes waiting for the customer', 'count' => count($of(RepairOrderStatus::EstimateApproval)), 'route' => 'maxeme_board_index', 'params' => [], 'note' => null],
            ['label' => 'Waiting on parts', 'count' => count($of(RepairOrderStatus::AwaitingParts)), 'route' => 'maxeme_board_index', 'params' => [], 'note' => null],
            ['label' => 'Ready for pick up', 'count' => count($ready), 'route' => 'maxeme_board_index', 'params' => [], 'note' => null],
            ['label' => 'Completed, no invoice issued', 'count' => $notInvoiced, 'route' => 'maxeme_repair_order_index', 'params' => [], 'note' => null],
        ];
        if ($withInvoices) {
            $attention[] = ['label' => 'Unpaid invoices (30 days)', 'count' => $unpaidCount, 'route' => 'maxeme_invoice_index', 'params' => [], 'note' => '$' . number_format($owed, 2)];
        }
        $attention[] = ['label' => 'Reminders to call this week', 'count' => $remindersToCall, 'route' => 'maxeme_reminder_index', 'params' => [], 'note' => null];
        $attention[] = ['label' => 'No-shows this week', 'count' => $noShows, 'route' => 'maxeme_appointment_calendar', 'params' => ['view' => 'list'], 'note' => null];

        return [
            'today' => $today,
            'appointments' => $appointments,
            'inShop' => $inShop,
            'ready' => $ready,
            'unpaid' => $unpaid,
            'unpaidCount' => $unpaidCount,
            'owed' => $owed,
            'attention' => $attention,
        ];
    }

    /**
     * @param list<RepairOrder> $repairOrders
     *
     * @return array<int, Invoice> the newest invoice not cancelled, by repair order id
     */
    private function liveInvoices(array $repairOrders): array
    {
        if ($repairOrders === []) {
            return [];
        }
        $byOrder = [];
        $rows = $this->entityManager->createQueryBuilder()
            ->select('i')->from(Invoice::class, 'i')
            ->andWhere('i.repairOrder IN (:orders)')->andWhere('i.status != :cancelled')
            ->setParameter('orders', $repairOrders)->setParameter('cancelled', InvoiceStatus::Cancelled)
            ->orderBy('i.id', 'DESC')
            ->getQuery()->getResult();
        foreach ($rows as $invoice) {
            $byOrder[$invoice->getRepairOrder()->getId()] ??= $invoice;
        }

        return $byOrder;
    }
}
