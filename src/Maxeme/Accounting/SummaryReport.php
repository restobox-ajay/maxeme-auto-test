<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Repository\InvoiceRepository;
use App\Service\AppSettings;

/**
 * Accounting › Summary Report ("Daily Summary", legacy ReportsController::ListReportViewAction):
 * the invoices dated in a range (whole shop days), per payment method, and their totals.
 */
final class SummaryReport
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly AppSettings $appSettings,
    ) {
    }

    /**
     * @param \DateTimeImmutable $from / $to shop dates (the whole days are included)
     *
     * @return array{invoices: list<Invoice>, totals: array{revenue: string, expense: string, discount: string, salesTax: string, net: string}}
     */
    public function run(\DateTimeImmutable $from, \DateTimeImmutable $to, ?PaymentType $method): array
    {
        $zone = $this->appSettings->timezone();
        $utc = new \DateTimeZone('UTC');
        $invoices = $this->invoices->datedBetween(
            (new \DateTimeImmutable($from->format('Y-m-d') . ' 00:00:00', $zone))->setTimezone($utc),
            (new \DateTimeImmutable($to->format('Y-m-d') . ' 23:59:59', $zone))->setTimezone($utc),
            $method,
        );

        $sum = static fn (callable $amount): string => Money::fromCents(array_sum(array_map(static fn (Invoice $invoice): int => Money::toCents($amount($invoice)), $invoices)));

        return [
            'invoices' => $invoices,
            'totals' => [
                'revenue' => $sum(static fn (Invoice $invoice): string => $invoice->getTotalPrice()),
                'expense' => $sum(static fn (Invoice $invoice): string => $invoice->getExpenseAmount()),
                'discount' => $sum(static fn (Invoice $invoice): string => $invoice->getDiscountTotal()),
                'salesTax' => $sum(static fn (Invoice $invoice): string => $invoice->getSalesTax()),
                'net' => $sum(static fn (Invoice $invoice): string => $invoice->getNetAmount()),
            ],
        ];
    }
}
