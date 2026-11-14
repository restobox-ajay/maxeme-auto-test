<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Accounting\Money;
use App\Maxeme\Accounting\ReportPeriod;
use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Enum\RepairOrderStatus;
use App\Maxeme\Listing\CsvExport;
use App\Maxeme\Repository\AppointmentRepository;
use App\Maxeme\Repository\RepairOrderRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RepairOrderStatusChange;
use App\Service\AppSettings;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Schedule › Repair Order Board (mockups aa322bb, 8c0ad56, 7bd9227): a kanban with one column per
 * repair order status, Cancelled included, nothing merged or split. A repair order is on it when one
 * of its appointments falls in the period (today by default); one with no appointment while still
 * being worked on, or, Completed or later, only for a period within the last 60 days. ?open=1 adds
 * every open repair order whatever the dates of its appointments. Dragging a card to another column
 * changes the repair order's status (RepairOrderStatusChange). A column's head lists its repair
 * orders underneath (?stage=).
 */
final class RepairOrderBoardController extends AbstractMaxemeController
{
    /** Repair orders with no appointment show in the finished columns only when created this many days back at most. */
    public const RECENT_DAYS = 60;

    /** Cards shown per column; the rest are behind "+N more" (the column's list). */
    public const CAP = 30;

    public function __construct(
        private readonly RepairOrderRepository $repairOrders,
        private readonly AppSettings $appSettings,
    ) {
    }

    #[Route('/admin/repair-order-board', name: 'maxeme_board_index', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function index(Request $request, AppointmentRepository $appointments): Response
    {
        $period = ReportPeriod::fromRequest($request, $this->appSettings->timezone());
        $openAll = $request->query->get('open') === '1';
        $stage = RepairOrderStatus::tryFrom((string) $request->query->get('stage', ''));
        $board = $this->board($period, $openAll);
        $empty = array_sum(array_map(static fn (array $column): int => $column['count'], $board)) === 0;
        $latest = $empty && !$openAll ? $appointments->latestRepairOrderStartUpTo(new \DateTimeImmutable()) : null;

        return $this->render('maxeme/board/index.html.twig', [
            'period' => $period,
            'openAll' => $openAll,
            'board' => $board,
            'stage' => $stage,
            'rows' => $stage !== null ? self::byAppointment($board[$stage->value]['repairOrders'], $period) : [],
            'hidden' => $openAll ? 0 : $this->repairOrders->countOpenWithAppointmentsOutside($period->startUtc(), $period->endUtc()),
            'latestDay' => $latest?->setTimezone($this->appSettings->timezone())->format('Y-m-d'),
            'recentDays' => self::RECENT_DAYS,
            'cap' => self::CAP,
        ]);
    }

    /** A card dropped on another column: the repair order takes that status, asking first when the mockup does. */
    #[Route('/admin/repair-order-board/{id}/status', name: 'maxeme_board_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function move(#[MapEntity] RepairOrder $repairOrder, Request $request, RepairOrderStatusChange $statusChange, DocumentNumbers $numbers): JsonResponse
    {
        $to = RepairOrderStatus::tryFrom((string) $request->request->get('status', ''));
        if ($to === null) {
            return $this->json(['message' => 'Choose a status.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $question = $statusChange->move($repairOrder, $to, $request->request->get('confirmed') === '1');
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($question !== null) {
            return $this->json(['confirm' => $question], Response::HTTP_CONFLICT);
        }

        return $this->json(['message' => sprintf('%s moved to %s.', $numbers->repairOrderNumber($repairOrder), $to->label())]);
    }

    /** Export CSV: the chosen column's repair orders, or every column's when none is chosen. */
    #[Route('/admin/repair-order-board.csv', name: 'maxeme_board_export', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function export(Request $request, ActivityRecorder $activity, DocumentNumbers $numbers): Response
    {
        $period = ReportPeriod::fromRequest($request, $this->appSettings->timezone());
        $openAll = $request->query->get('open') === '1';
        $stage = RepairOrderStatus::tryFrom((string) $request->query->get('stage', ''));
        $rows = [];
        foreach ($this->board($period, $openAll) as $key => $column) {
            if ($stage === null || $stage->value === $key) {
                foreach ($column['repairOrders'] as $repairOrder) {
                    $rows[] = $repairOrder;
                }
            }
        }
        $filename = sprintf('repair-order-board-%s%s.csv', $stage !== null ? $stage->value . '-' : '', $period->slug());
        $activity->exported('work-order', 'RepairOrder', $filename, count($rows), sprintf('%s to %s%s%s', $period->from->format('Y-m-d'), $period->to->format('Y-m-d'), $stage !== null ? ', ' . $stage->label() : '', $openAll ? ', all open' : ''));
        $zone = $this->appSettings->timezone();

        return CsvExport::response($filename, ['Category', 'RO #', 'Appointment', 'Appointment Status', 'Customer', 'Vehicle', 'Repair Name', 'Status', 'Assigned Technician', 'Total'], (static function () use ($rows, $numbers, $zone, $period): \Generator {
            foreach ($rows as $repairOrder) {
                $appointment = self::appointmentOf($repairOrder, $period);
                yield [
                    $repairOrder->getStatus()->label(),
                    $numbers->repairOrderNumber($repairOrder),
                    $appointment?->getStartTime()->setTimezone($zone)->format('m/d/Y'),
                    $appointment?->getStatus()->label(),
                    $repairOrder->getClient()?->getFullName(),
                    $repairOrder->getVehicle()?->getFullName(),
                    $repairOrder->getName(),
                    $repairOrder->getStatus()->label(),
                    $repairOrder->getMasterTechnician()?->getName(),
                    $repairOrder->getTotal(),
                ];
            }
        })());
    }

    /**
     * The appointment a card shows: the first one in the period, else the next one still pending,
     * else the latest (the same pick as the Repair Orders list).
     */
    public static function appointmentOf(RepairOrder $repairOrder, ReportPeriod $period): ?Appointment
    {
        $start = $period->startUtc();
        $end = $period->endUtc();
        foreach (self::sorted($repairOrder) as $appointment) {
            if ($appointment->getStartTime() >= $start && $appointment->getStartTime() <= $end) {
                return $appointment;
            }
        }

        return $repairOrder->getCurrentAppointment();
    }

    /** @return array<string, array{status: RepairOrderStatus, count: int, total: string, repairOrders: list<RepairOrder>, cards: list<array{repairOrder: RepairOrder, appointment: ?Appointment}>}> in status order */
    private function board(ReportPeriod $period, bool $openAll): array
    {
        $board = [];
        foreach (RepairOrderStatus::cases() as $status) {
            $board[$status->value] = ['status' => $status, 'cents' => 0, 'repairOrders' => []];
        }
        $recentFrom = (new \DateTimeImmutable('today', $this->appSettings->timezone()))->modify(sprintf('-%d days', self::RECENT_DAYS))->setTimezone(new \DateTimeZone('UTC'));
        foreach ($this->repairOrders->findForBoard($period->startUtc(), $period->endUtc(), $openAll, $recentFrom) as $repairOrder) {
            $column = &$board[$repairOrder->getStatus()->value];
            $column['cents'] += Money::toCents($repairOrder->getTotal());
            $column['repairOrders'][] = $repairOrder;
            unset($column);
        }

        return array_map(static fn (array $column): array => [
            'status' => $column['status'],
            'count' => count($column['repairOrders']),
            'total' => Money::fromCents($column['cents']),
            'repairOrders' => $column['repairOrders'],
            'cards' => array_map(static fn (RepairOrder $repairOrder): array => ['repairOrder' => $repairOrder, 'appointment' => self::appointmentOf($repairOrder, $period)], array_slice($column['repairOrders'], 0, self::CAP)),
        ], $board);
    }

    /**
     * A column's list: by the appointment shown (else the date it was made), earliest first.
     *
     * @param list<RepairOrder> $repairOrders
     *
     * @return list<array{repairOrder: RepairOrder, appointment: ?Appointment}>
     */
    private static function byAppointment(array $repairOrders, ReportPeriod $period): array
    {
        $rows = array_map(static fn (RepairOrder $repairOrder): array => ['repairOrder' => $repairOrder, 'appointment' => self::appointmentOf($repairOrder, $period)], $repairOrders);
        usort($rows, static fn (array $a, array $b): int => ($a['appointment']?->getStartTime() ?? $a['repairOrder']->getCreatedOn()) <=> ($b['appointment']?->getStartTime() ?? $b['repairOrder']->getCreatedOn()));

        return $rows;
    }

    /** @return list<Appointment> by start time */
    private static function sorted(RepairOrder $repairOrder): array
    {
        $appointments = $repairOrder->getAppointments();
        usort($appointments, static fn (Appointment $a, Appointment $b): int => $a->getStartTime() <=> $b->getStartTime());

        return $appointments;
    }
}
