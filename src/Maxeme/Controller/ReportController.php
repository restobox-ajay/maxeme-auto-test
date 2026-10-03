<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Accounting\PaymentReport;
use App\Maxeme\Accounting\ReportPeriod;
use App\Maxeme\Accounting\ServiceReport;
use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Listing\CsvExport;
use App\Maxeme\Repository\PaymentTypeRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Service\AppSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accounting › Payment Report and Service Report. Each is a GET form (from, to as yyyy-mm-dd; the
 * preset dropdown only fills them in) so a report can be bookmarked, and each exports the same
 * table as CSV, every row of it.
 */
final class ReportController extends AbstractMaxemeController
{
    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly DocumentNumbers $numbers,
    ) {
    }

    #[Route('/admin/accounting/summary-report', name: 'maxeme_report_summary', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function payment(Request $request, PaymentReport $report, PaymentTypeRepository $paymentTypes): Response
    {
        $period = ReportPeriod::fromRequest($request, $this->appSettings->timezone());
        $methodId = self::idParam($request->query, 'method');
        $method = $methodId !== null ? $paymentTypes->find($methodId) : null;

        return $this->render('maxeme/report/payment.html.twig', [
            'period' => $period,
            'method' => $method,
            'methods' => $paymentTypes->findAllOrdered(),
            'result' => $report->run($period, $method),
            'amounts' => PaymentReport::AMOUNTS,
        ]);
    }

    #[Route('/admin/accounting/summary-report.csv', name: 'maxeme_report_summary_export', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function paymentExport(Request $request, PaymentReport $report, PaymentTypeRepository $paymentTypes, ActivityRecorder $activity): Response
    {
        $period = ReportPeriod::fromRequest($request, $this->appSettings->timezone());
        $methodId = self::idParam($request->query, 'method');
        $method = $methodId !== null ? $paymentTypes->find($methodId) : null;
        $result = $report->run($period, $method);
        $filename = sprintf('payment-report-%s.csv', $period->slug());
        $activity->exported('accounting', 'Invoice', $filename, count($result['rows']), sprintf('%s to %s%s', $period->from->format('Y-m-d'), $period->to->format('Y-m-d'), $method !== null ? ', ' . $method->getName() : ''));

        $numbers = $this->numbers;
        $timezone = $this->appSettings->timezone();

        return CsvExport::response($filename, ['Invoice #', 'Date', 'Time', 'Payment method', ...array_values(PaymentReport::AMOUNTS)], (static function () use ($result, $numbers, $timezone): \Generator {
            foreach ($result['rows'] as $row) {
                $date = $row['invoice']->getDocumentDate()->setTimezone($timezone);
                yield [$numbers->number($row['invoice']), $date->format('m/d/Y'), $date->format('h:i:s A'), $row['invoice']->getPaymentType()?->getName(), ...array_values($row['amounts'])];
            }
            yield ['Overall', '', '', '', ...array_values($result['totals'])];
        })());
    }

    #[Route('/admin/accounting/service-report', name: 'maxeme_report_service', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function service(Request $request, ServiceReport $report): Response
    {
        $period = ReportPeriod::fromRequest($request, $this->appSettings->timezone());

        return $this->render('maxeme/report/service.html.twig', [
            'period' => $period,
            'result' => $report->run($period),
            'amounts' => ServiceReport::AMOUNTS,
        ]);
    }

    #[Route('/admin/accounting/service-report.csv', name: 'maxeme_report_service_export', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function serviceExport(Request $request, ServiceReport $report, ActivityRecorder $activity): Response
    {
        $period = ReportPeriod::fromRequest($request, $this->appSettings->timezone());
        $result = $report->run($period);
        $filename = sprintf('service-report-%s.csv', $period->slug());
        $activity->exported('accounting', 'RepairOrder', $filename, count($result['rows']), sprintf('%s to %s', $period->from->format('Y-m-d'), $period->to->format('Y-m-d')));

        $numbers = $this->numbers;
        $timezone = $this->appSettings->timezone();

        return CsvExport::response($filename, ['Repair Order Date', 'Repair Order #', 'Service Name', 'Customer', ...array_values(ServiceReport::AMOUNTS)], (static function () use ($result, $numbers, $timezone): \Generator {
            foreach ($result['rows'] as $row) {
                $repairOrder = $row['repairOrder'];
                yield [$repairOrder->getCreatedOn()->setTimezone($timezone)->format('m/d/Y'), $numbers->repairOrderNumber($repairOrder), $row['job']->getName(), $repairOrder->getClient()?->getFullName(), ...array_values($row['amounts'])];
            }
            yield ['Overall', '', '', '', ...array_values($result['totals'])];
        })());
    }
}
