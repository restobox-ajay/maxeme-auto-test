<?php

declare(strict_types=1);

namespace ProcurementBundle\Reporting;

use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Enum\VendorBillStatus;

/**
 * Accounts payable, aged: what we owe each vendor and how overdue it is.
 *
 * A pivot and nothing else. Rows are vendors, columns are age buckets, each cell is the summed open
 * balance of that vendor's bills falling in that bucket. It creates nothing, changes nothing and
 * has no actions — which is why it can be read from a GET with no CSRF token and no side effects.
 *
 * ## Three decisions worth stating
 *
 * **1. A bill with no due date is due immediately, and ages from its own date.**
 * `vendor_bill.due_date` is nullable and plenty of bills arrive with no terms recorded. Folding
 * those into Current would park the one bill nobody is chasing in the column people skim past; a
 * separate "no due date" column would do the same thing with extra steps. Ageing from the document
 * date instead makes a ninety-day-old bill with no terms land in 90+, which is exactly where it
 * belongs. The assumption is also the conservative one: assuming money is owed sooner never hides a
 * liability, while assuming "we do not know" can. The screen says so, because a person reading a
 * 90+ figure needs to know a row may be there for want of terms rather than for want of payment.
 *
 * **2. Draft and Void bills are excluded.** A draft has authorised nothing and is not a liability;
 * a void bill never was one. That is `VendorBillStatus::counts()` — the same rule
 * `ExceptionController` applies to its own sum, stated once on the enum and read from both places
 * rather than written twice.
 *
 * **3. The balance comes from the same place the bill screen's balance does.** `VendorBill::getBalance()`
 * is total minus the sum of the payment rows. This report does not re-derive it from a stored
 * figure or re-sum the payments itself: one source, two readers, so the report and the document can
 * never disagree about what is owed.
 *
 * ## Currency is grouped, never converted
 *
 * A purchase document is read in the currency it was raised in and is never converted (#555), so
 * adding CAD and USD balances into one cell would produce a number that is not money. Rows are
 * therefore keyed by vendor AND currency, and the totals row is per currency too.
 */
final class ApAgingReport
{
    /** Bucket key => label, in the order they are shown. */
    public const BUCKETS = [
        'current' => 'Current',
        'd1_30' => '1 to 30 days',
        'd31_60' => '31 to 60 days',
        'd61_90' => '61 to 90 days',
        'd90_plus' => 'Over 90 days',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{
     *     rows: list<array{vendorId: int, vendor: string, currency: string, buckets: array<string, string>, total: string}>,
     *     totals: array<string, array{buckets: array<string, string>, total: string}>,
     *     bills: int
     * }
     */
    public function build(\DateTimeImmutable $today, int $vendorId = 0): array
    {
        $qb = $this->em->getRepository(VendorBill::class)->createQueryBuilder('b')
            // The claims come back with the bills, in one query. getBalance() sums them, so
            // without this the report is an N+1 over every open bill on file — and a report is
            // exactly the screen somebody opens with a thousand of them.
            ->leftJoin('b.applications', 'p')
            ->addSelect('p')
            ->andWhere('b.status NOT IN (:ignored)')
            // Draft and Void, spelled from the enum rather than typed as strings, so a new status
            // cannot silently start or stop counting. The enumeration itself now lives on the enum
            // beside the predicate it is derived from — this report and the exception worklist ask
            // the same question and used to hold two separate answers to it.
            ->setParameter('ignored', VendorBillStatus::uncounted())
            ->orderBy('b.vendorName', 'ASC')
            ->addOrderBy('b.id', 'ASC');

        if ($vendorId > 0) {
            $qb->andWhere('IDENTITY(b.vendor) = :vendor')->setParameter('vendor', $vendorId);
        }

        /** @var list<VendorBill> $bills */
        $bills = $qb->getQuery()->getResult();

        /** @var array<string, array{vendorId: int, vendor: string, currency: string, buckets: array<string, int>, total: int}> $rows */
        $rows = [];
        /** @var array<string, array{buckets: array<string, int>, total: int}> $totals */
        $totals = [];
        $counted = 0;

        foreach ($bills as $bill) {
            $balance = VendorBill::cents($bill->getBalance());
            if ($balance === 0) {
                // Settled: it owes nothing and belongs on no aging row. An OVERPAID bill has a
                // negative balance and is deliberately kept — money sitting with a vendor is
                // information, and netting it out of sight is how it stays forgotten.
                continue;
            }

            $currency = $bill->getCurrency();
            $key = (int) $bill->getVendor()->getId() . '|' . $currency;

            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'vendorId' => (int) $bill->getVendor()->getId(),
                    'vendor' => $bill->getVendorName(),
                    'currency' => $currency,
                    'buckets' => array_fill_keys(array_keys(self::BUCKETS), 0),
                    'total' => 0,
                ];
            }
            if (!isset($totals[$currency])) {
                $totals[$currency] = ['buckets' => array_fill_keys(array_keys(self::BUCKETS), 0), 'total' => 0];
            }

            $bucket = self::bucketFor($bill, $today);

            $rows[$key]['buckets'][$bucket] += $balance;
            $rows[$key]['total'] += $balance;
            $totals[$currency]['buckets'][$bucket] += $balance;
            $totals[$currency]['total'] += $balance;
            ++$counted;
        }

        return [
            'rows' => array_values(array_map(
                static fn (array $row): array => [
                    'vendorId' => $row['vendorId'],
                    'vendor' => $row['vendor'],
                    'currency' => $row['currency'],
                    'buckets' => array_map(static fn (int $c): string => VendorBill::money($c), $row['buckets']),
                    'total' => VendorBill::money($row['total']),
                ],
                $rows,
            )),
            'totals' => array_map(
                static fn (array $row): array => [
                    'buckets' => array_map(static fn (int $c): string => VendorBill::money($c), $row['buckets']),
                    'total' => VendorBill::money($row['total']),
                ],
                $totals,
            ),
            'bills' => $counted,
        ];
    }

    /**
     * Which bucket one bill falls in, by whole days between its due date and today.
     *
     * Due today or later is Current: a bill is not overdue on the day it falls due. The due date is
     * the bill's own date when it has no terms — see the class docblock for why that is the right
     * assumption rather than a shortcut.
     *
     * Both columns are plain 'Y-m-d' strings, which is what makes this comparable at all: the format
     * IS the invariant, and it sorts and compares in calendar order. An unparseable value is aged as
     * of today rather than throwing, because a report is not the place to discover bad data — it is
     * the place that shows the balance regardless.
     */
    public static function bucketFor(VendorBill $bill, \DateTimeImmutable $today): string
    {
        $due = self::dayOrNull($bill->getDueDate()) ?? self::dayOrNull($bill->getDocumentDate()) ?? $today;
        $days = (int) $today->setTime(0, 0)->diff($due->setTime(0, 0))->format('%r%a');

        // $days is positive when the due date is in the future, negative when it has passed.
        $overdue = -$days;

        return match (true) {
            $overdue <= 0 => 'current',
            $overdue <= 30 => 'd1_30',
            $overdue <= 60 => 'd31_60',
            $overdue <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }

    private static function dayOrNull(?string $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return ($date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value) ? $date : null;
    }
}
