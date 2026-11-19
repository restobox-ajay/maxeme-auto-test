<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Repository\InvoiceRepository;
use App\Maxeme\Service\InvoicePayments;

/**
 * Accounting › Payment Report (legacy Daily Summary; mockups c857353 … 28b649a, 8b30420): the payments
 * of a period, i.e. the PAID invoices by their invoice (payment) date, filtered by any of the ticked
 * payment methods, each with its Subtotal (before tax, after fees and discounts), Taxes and Grand
 * Total, and the period's totals. Beside it: what each payment method took in the period with tax,
 * and the invoices issued in the period that nobody has paid yet (not payments, out of the totals).
 * An invoice paid with more than one method shows them all, is found by any of them, and counts toward
 * each method's total only what was paid with it.
 */
final class PaymentReport
{
    /** The columns, in order: row key => heading (the page and the CSV share them). The breakdown is in the Service report. */
    public const AMOUNTS = [
        'subtotal' => 'Subtotal',
        'taxes' => 'Taxes',
        'total' => 'Grand Total',
    ];

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoicePayments $payments,
    ) {
    }

    /**
     * @param list<PaymentType> $methods the ticked ones; none = every method
     * @param list<PaymentType> $allMethods every method, for the per-method totals
     *
     * @return array{rows: list<array{invoice: Invoice, amounts: array<string, string>, method: string}>, totals: array<string, string>, byMethod: list<array{method: PaymentType, count: int, total: string}>, unpaid: list<Invoice>}
     */
    public function run(ReportPeriod $period, array $methods, array $allMethods): array
    {
        $picked = array_map(static fn (PaymentType $method): ?int => $method->getId(), $methods);
        $rows = [];
        $totals = array_fill_keys(array_keys(self::AMOUNTS), 0);
        $byMethod = [];
        $unpaid = [];
        $paid = [];
        foreach ($this->invoices->datedBetween($period->startUtc(), $period->endUtc()) as $invoice) {
            if ($invoice->getStatus() === InvoiceStatus::Cancelled) {
                continue;
            }
            if (!$invoice->isPaid()) {
                $unpaid[] = $invoice;
                continue;
            }
            $paid[] = $invoice;
        }
        $methodsOf = $this->payments->methodsByInvoice($paid);
        foreach ($paid as $invoice) {
            $split = RevenueBreakdown::ofInvoice($invoice);
            // What it was paid with; one without payment records (imported): its own method. One method takes
            // the invoice's total (as before: cash rounded to the nickel does not move the totals); with
            // several, each takes what was paid with it.
            $methods = $methodsOf[$invoice->getId()] ?? [['type' => $invoice->getPaymentType(), 'cents' => $split['total']]];
            if (count($methods) === 1) {
                $methods[0]['cents'] = $split['total'];
            }
            $methodIds = [];
            foreach ($methods as $method) {
                $methodId = $method['type']?->getId();
                if ($methodId === null) {
                    continue;
                }
                $byMethod[$methodId] ??= ['count' => 0, 'cents' => 0];
                ++$byMethod[$methodId]['count'];
                $byMethod[$methodId]['cents'] += $method['cents'];
                $methodIds[] = $methodId;
            }
            if ($picked !== [] && array_intersect($methodIds, $picked) === []) {
                continue;
            }
            foreach ($totals as $key => $sum) {
                $totals[$key] = $sum + $split[$key];
            }
            $rows[] = ['invoice' => $invoice, 'amounts' => array_map(Money::fromCents(...), array_intersect_key($split, self::AMOUNTS)), 'method' => self::methodLabel($methods)];
        }
        usort($rows, static fn (array $a, array $b): int => $a['invoice']->getDocumentDate() <=> $b['invoice']->getDocumentDate() ?: $a['invoice']->getId() <=> $b['invoice']->getId());
        usort($unpaid, static fn (Invoice $a, Invoice $b): int => $a->getDocumentDate() <=> $b->getDocumentDate() ?: $a->getId() <=> $b->getId());

        $perMethod = [];
        foreach ($allMethods as $method) {
            if (isset($byMethod[$method->getId()])) {
                $perMethod[] = ['method' => $method, 'count' => $byMethod[$method->getId()]['count'], 'total' => Money::fromCents($byMethod[$method->getId()]['cents'])];
            }
        }

        return [
            'rows' => $rows,
            'totals' => array_map(Money::fromCents(...), $totals),
            'byMethod' => $perMethod,
            // Issued but not paid: only shown when no method is picked (they have none yet).
            'unpaid' => $picked === [] ? $unpaid : [],
        ];
    }

    /**
     * The Payment method cell: the method's name, or with several, each with what it paid
     * ("Debit 100.00 + Credit Card 218.60").
     *
     * @param list<array{type: ?PaymentType, cents: int}> $methods
     */
    public static function methodLabel(array $methods): string
    {
        if (count($methods) === 1) {
            return (string) $methods[0]['type']?->getName();
        }

        return implode(' + ', array_map(static fn (array $method): string => trim(sprintf('%s %s', $method['type']?->getName(), Money::fromCents($method['cents']))), $methods));
    }

    /** When the newest paid invoice is dated (for the default period: never an empty page). */
    public function latestPayment(): ?\DateTimeImmutable
    {
        $latest = $this->invoices->createQueryBuilder('i')
            ->select('MAX(i.lastModified)')
            ->andWhere('i.status = :paid')->setParameter('paid', InvoiceStatus::Paid)
            ->getQuery()->getSingleScalarResult();

        return $latest !== null ? new \DateTimeImmutable((string) $latest, new \DateTimeZone('UTC')) : null;
    }
}
