<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Reporting;

use PHPUnit\Framework\TestCase;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Reporting\ApAgingReport;

/**
 * Which aging bucket a bill falls in — the boundaries, stated.
 *
 * Boundaries are where an aging report is wrong in practice: a bill due today is not overdue, a bill
 * thirty days late is in 1 to 30 and a bill thirty-one days late is not. Each of those is one day
 * either side of an answer somebody reconciles against a vendor's statement.
 *
 * The other rule here is the owner's: **a bill with no due date is due immediately and ages from its
 * own date.** A ninety-day-old bill with no terms recorded lands in Over 90 days, which is exactly
 * where it belongs — it is precisely the bill nobody is chasing. Assuming money is owed sooner never
 * hides a liability; assuming "we do not know" can.
 */
final class ApAgingBucketsTest extends TestCase
{
    private const TODAY = '2026-09-11';

    private function bill(?string $dueDate, string $documentDate = '2026-09-11'): VendorBill
    {
        return (new VendorBill())
            ->setVendor((new Vendor())->setName('Acme Supply'))
            ->setVendorName('Acme Supply')
            ->setDocumentDate($documentDate)
            ->setDueDate($dueDate);
    }

    private function bucketFor(?string $dueDate, string $documentDate = '2026-09-11'): string
    {
        return ApAgingReport::bucketFor(
            $this->bill($dueDate, $documentDate),
            new \DateTimeImmutable(self::TODAY),
        );
    }

    public function testADueDateInTheFutureIsCurrent(): void
    {
        self::assertSame('current', $this->bucketFor('2026-10-01'));
    }

    public function testABillDueTodayIsCurrentRatherThanOverdue(): void
    {
        self::assertSame('current', $this->bucketFor(self::TODAY), 'a bill is not late on the day it falls due');
    }

    public function testTheFirstDayLateIsTheFirstBucket(): void
    {
        self::assertSame('d1_30', $this->bucketFor('2026-09-10'));
    }

    public function testThirtyDaysLateIsStillTheFirstBucketAndThirtyOneIsNot(): void
    {
        self::assertSame('d1_30', $this->bucketFor('2026-08-12'), '30 days late');
        self::assertSame('d31_60', $this->bucketFor('2026-08-11'), '31 days late');
    }

    public function testSixtyDaysLateIsStillTheSecondBucketAndSixtyOneIsNot(): void
    {
        self::assertSame('d31_60', $this->bucketFor('2026-07-13'), '60 days late');
        self::assertSame('d61_90', $this->bucketFor('2026-07-12'), '61 days late');
    }

    public function testNinetyDaysLateIsStillTheThirdBucketAndNinetyOneIsNot(): void
    {
        self::assertSame('d61_90', $this->bucketFor('2026-06-13'), '90 days late');
        self::assertSame('d90_plus', $this->bucketFor('2026-06-12'), '91 days late');
    }

    public function testABillWithNoDueDateAgesFromItsOwnDate(): void
    {
        self::assertSame('d90_plus', $this->bucketFor(null, '2026-05-01'), 'no terms recorded on an old bill');
        self::assertSame('d31_60', $this->bucketFor(null, '2026-07-28'), '45 days old');
        self::assertSame('current', $this->bucketFor(null, self::TODAY), 'entered today, due today');
    }

    /** A report is not the place to discover bad data; it is the place that shows the balance anyway. */
    public function testAnUnreadableDateAgesAsOfTodayRatherThanThrowing(): void
    {
        self::assertSame('current', $this->bucketFor('not-a-date', 'also-not-a-date'));
    }

    public function testTheBucketsAreTheFiveThatAreShown(): void
    {
        self::assertSame(
            ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'],
            array_keys(ApAgingReport::BUCKETS),
        );
    }
}
