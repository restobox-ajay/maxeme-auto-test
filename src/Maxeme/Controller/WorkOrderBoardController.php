<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Accounting\Money;
use App\Maxeme\Accounting\ReportPeriod;
use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Enum\WorkOrderStage;
use App\Maxeme\Listing\CsvExport;
use App\Maxeme\Repository\RepairOrderRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Service\AppSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Schedule › Work Order Board (spec item 21): the repair orders of a period (by their date) in the
 * vehicle's categories, Appointment Made → Pending on Quote → Check-in → Repairing → Complete,
 * Pending on Pick Up → Completed (Paid) (WorkOrderStage), with each one's count and total. A
 * count lists that category's repair orders underneath (?stage=).
 */
final class WorkOrderBoardController extends AbstractMaxemeController
{
    /** The period shown when none is chosen: the last 30 days. */
    private const DEFAULT_FROM = '-29 days';

    public function __construct(
        private readonly RepairOrderRepository $repairOrders,
        private readonly AppSettings $appSettings,
    ) {
    }

    #[Route('/admin/work-order-board', name: 'maxeme_board_index', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function index(Request $request): Response
    {
        $period = ReportPeriod::fromRequest($request, $this->appSettings->timezone(), self::DEFAULT_FROM);
        $stage = WorkOrderStage::tryFrom((string) $request->query->get('stage', ''));
        $board = $this->board($period);

        return $this->render('maxeme/board/index.html.twig', [
            'period' => $period,
            'board' => $board,
            'stage' => $stage,
            'rows' => $stage !== null ? $board[$stage->value]['repairOrders'] : [],
        ]);
    }

    /** Export CSV: the chosen category's repair orders, or every category's when none is chosen. */
    #[Route('/admin/work-order-board.csv', name: 'maxeme_board_export', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function export(Request $request, ActivityRecorder $activity, DocumentNumbers $numbers): Response
    {
        $period = ReportPeriod::fromRequest($request, $this->appSettings->timezone(), self::DEFAULT_FROM);
        $stage = WorkOrderStage::tryFrom((string) $request->query->get('stage', ''));
        $board = $this->board($period);
        $rows = [];
        foreach ($board as $key => $column) {
            if ($stage === null || $stage->value === $key) {
                foreach ($column['repairOrders'] as $repairOrder) {
                    $rows[] = [$column['stage'], $repairOrder];
                }
            }
        }
        $filename = sprintf('work-order-board-%s%s.csv', $stage !== null ? $stage->value . '-' : '', $period->slug());
        $activity->exported('work-order', 'RepairOrder', $filename, count($rows), sprintf('%s to %s%s', $period->from->format('Y-m-d'), $period->to->format('Y-m-d'), $stage !== null ? ', ' . $stage->label() : ''));
        $zone = $this->appSettings->timezone();

        return CsvExport::response($filename, ['Category', 'RO #', 'Date', 'Customer', 'Vehicle', 'Repair Name', 'Status', 'Master Technician', 'Total'], (static function () use ($rows, $numbers, $zone): \Generator {
            foreach ($rows as [$stage, $repairOrder]) {
                yield [
                    $stage->label(),
                    $numbers->repairOrderNumber($repairOrder),
                    $repairOrder->getCreatedOn()->setTimezone($zone)->format('m/d/Y'),
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

    /** @return array<string, array{stage: WorkOrderStage, count: int, total: string, repairOrders: list<RepairOrder>}> in board order */
    private function board(ReportPeriod $period): array
    {
        $board = [];
        foreach (WorkOrderStage::cases() as $stage) {
            $board[$stage->value] = ['stage' => $stage, 'count' => 0, 'cents' => 0, 'repairOrders' => []];
        }
        foreach ($this->repairOrders->findCreatedBetween($period->startUtc(), $period->endUtc()) as $repairOrder) {
            $stage = WorkOrderStage::of($repairOrder);
            if ($stage === null) {
                continue;
            }
            $column = &$board[$stage->value];
            ++$column['count'];
            $column['cents'] += Money::toCents($repairOrder->getTotal());
            $column['repairOrders'][] = $repairOrder;
            unset($column);
        }

        return array_map(static fn (array $column): array => ['stage' => $column['stage'], 'count' => $column['count'], 'total' => Money::fromCents($column['cents']), 'repairOrders' => $column['repairOrders']], $board);
    }
}
