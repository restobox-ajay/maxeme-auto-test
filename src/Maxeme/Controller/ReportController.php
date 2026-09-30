<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Accounting\SummaryReport;
use App\Maxeme\Enum\PaymentMethod;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Service\AppSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accounting › Summary Report. A GET form (startDate, endDate as mm/dd/yyyy, method) so a report
 * can be bookmarked; the legacy page POSTed and crashed on a malformed date.
 */
final class ReportController extends AbstractMaxemeController
{
    private const DATE = 'm/d/Y';

    #[Route('/admin/accounting/summary-report', name: 'maxeme_report_summary', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function summary(Request $request, SummaryReport $report, AppSettings $appSettings): Response
    {
        $today = (new \DateTimeImmutable('now', $appSettings->timezone()))->format(self::DATE);
        $start = trim((string) $request->query->get('startDate', '')) ?: $today;
        $end = trim((string) $request->query->get('endDate', '')) ?: $today;
        $method = PaymentMethod::tryFrom((string) $request->query->get('method', ''));

        $from = \DateTimeImmutable::createFromFormat('!' . self::DATE, $start);
        $to = \DateTimeImmutable::createFromFormat('!' . self::DATE, $end);
        $error = $from === false || $to === false ? 'Enter the dates as mm/dd/yyyy.' : null;

        return $this->render('maxeme/report/summary.html.twig', [
            'startDate' => $start,
            'endDate' => $end,
            'method' => $method,
            'methods' => PaymentMethod::reportOrder(),
            'error' => $error,
            'result' => $error === null ? $report->run($from, $to, $method) : null,
        ]);
    }
}
