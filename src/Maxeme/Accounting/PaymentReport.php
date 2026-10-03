<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Repository\InvoiceRepository;

/**
 * Accounting › Payment Report (legacy Daily Summary): the invoices dated in the period, by their
 * invoice date (set when first paid), optionally of one payment method, each split into Parts,
 * Labour, Discounts and Govt Fees (RevenueBreakdown), with its Subtotal, Taxes and Grand Total, and
 * the period's totals. Cancelled invoices are left out.
 */
final class PaymentReport
{
    /** The columns, in order: row key => heading (the page and the CSV share them). */
    public const AMOUNTS = [
        'parts' => 'Parts',
        'labour' => 'Labour',
        'discounts' => 'Discounts',
        'govtFees' => 'Govt Fees',
        'subtotal' => 'Subtotal',
        'taxes' => 'Taxes',
        'total' => 'Grand Total',
    ];

    public function __construct(
        private readonly InvoiceRepository $invoices,
    ) {
    }

    /**
     * @return array{rows: list<array{invoice: Invoice, amounts: array<string, string>}>, totals: array<string, string>}
     */
    public function run(ReportPeriod $period, ?PaymentType $method): array
    {
        $rows = [];
        $totals = array_fill_keys(array_keys(self::AMOUNTS), 0);
        foreach ($this->invoices->datedBetween($period->startUtc(), $period->endUtc(), $method) as $invoice) {
            if ($invoice->getStatus() === InvoiceStatus::Cancelled) {
                continue;
            }
            $split = RevenueBreakdown::ofInvoice($invoice);
            foreach ($totals as $key => $sum) {
                $totals[$key] = $sum + $split[$key];
            }
            $rows[] = ['invoice' => $invoice, 'amounts' => array_map(Money::fromCents(...), $split)];
        }

        return ['rows' => $rows, 'totals' => array_map(Money::fromCents(...), $totals)];
    }
}
