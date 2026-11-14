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
        $period = ReportPeriod::withFallback($request, $this->appSettings->timezone(), self::DEFAULT_FROM, $report->latestPayment());
        $all = $paymentTypes->findAllOrdered();
        $picked = self::pickedMethods($request, $all);
        $latest = $report->latestPayment()?->setTimezone($this->appSettings->timezone());

        return $this->render('maxeme/report/payment.html.twig', [
            'period' => $period,
            'picked' => $picked,
            'methods' => $all,
            'result' => $report->run($period, $picked, $all),
            'amounts' => PaymentReport::AMOUNTS,
            'latestDay' => $latest?->format('Y-m-d'),
        ]);
    }

    /** Reports open on the last 30 days (or the newest record's month, ReportPeriod::withFallback). */
    private const DEFAULT_FROM = '-29 days';

    /**
     * The ticked payment methods (?method=1,3), in the methods' order; none (or all) = every method.
     *
     * @param list<\App\Maxeme\Entity\PaymentType> $all
     *
     * @return list<\App\Maxeme\Entity\PaymentType>
     */
    private static function pickedMethods(Request $request, array $all): array
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query->get('method', ''))));
        $picked = array_values(array_filter($all, static fn ($method): bool => in_array($method->getId(), $ids, true)));

        return count($picked) === count($all) ? [] : $picked;
    }

    #[Route('/admin/accounting/summary-report.csv', name: 'maxeme_report_summary_export', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function paymentExport(Request $request, PaymentReport $report, PaymentTypeRepository $paymentTypes, ActivityRecorder $activity): Response
    {
        $period = ReportPeriod::withFallback($request, $this->appSettings->timezone(), self::DEFAULT_FROM, $report->latestPayment());
        $all = $paymentTypes->findAllOrdered();
        $picked = self::pickedMethods($request, $all);
        $result = $report->run($period, $picked, $all);
        $filename = sprintf('payment-report-%s.csv', $period->slug());
        $activity->exported('accounting', 'Invoice', $filename, count($result['rows']), sprintf('%s to %s%s', $period->from->format('Y-m-d'), $period->to->format('Y-m-d'), $picked !== [] ? ', ' . implode(', ', array_map(static fn ($method): string => $method->getName(), $picked)) : ''));

        $numbers = $this->numbers;
        $timezone = $this->appSettings->timezone();

        return CsvExport::response($filename, ['Invoice #', 'Date', 'Time', 'Payment method', ...array_values(PaymentReport::AMOUNTS), 'Remark'], (static function () use ($result, $numbers, $timezone): \Generator {
            foreach ($result['rows'] as $row) {
                $date = $row['invoice']->getDocumentDate()->setTimezone($timezone);
                yield [$numbers->number($row['invoice']), $date->format('m/d/Y'), $date->format('h:i:s A'), $row['invoice']->getPaymentType()?->getName(), ...array_values($row['amounts']), $row['invoice']->getAdminNote()];
            }
            if ($result['rows'] !== []) {
                yield ['Overall', '', '', '', ...array_values($result['totals']), ''];
            }
        })());
    }

    #[Route('/admin/accounting/service-report', name: 'maxeme_report_service', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function service(Request $request, ServiceReport $report): Response
    {
        $period = ReportPeriod::withFallback($request, $this->appSettings->timezone(), self::DEFAULT_FROM, $report->latestFinished());

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
        $period = ReportPeriod::withFallback($request, $this->appSettings->timezone(), self::DEFAULT_FROM, $report->latestFinished());
        $result = $report->run($period);
        $filename = sprintf('service-report-%s.csv', $period->slug());
        $activity->exported('accounting', 'RepairOrder', $filename, count($result['rows']), sprintf('%s to %s', $period->from->format('Y-m-d'), $period->to->format('Y-m-d')));

        $numbers = $this->numbers;
        $timezone = $this->appSettings->timezone();

        return CsvExport::response($filename, ['Repair Order Date', 'Repair Order #', 'Service Name', 'Customer', ...array_values(ServiceReport::AMOUNTS)], (static function () use ($result, $numbers, $timezone): \Generator {
            foreach ($result['rows'] as $row) {
                $repairOrder = $row['repairOrder'];
                yield [$repairOrder->getCreatedOn()->setTimezone($timezone)->format('m/d/Y'), $numbers->repairOrderNumber($repairOrder), $row['name'], $repairOrder->getClient()?->getFullName(), ...array_values($row['amounts'])];
            }
            yield ['Overall', '', '', '', ...array_values($result['totals'])];
        })());
    }
}
