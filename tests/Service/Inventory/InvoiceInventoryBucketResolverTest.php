<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Entity\InvoiceInventoryReservation;
use App\Enum\InvoiceStatus;
use App\Service\Inventory\InvoiceInventoryBucketResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The invoice half of #539's authoritative status→bucket table, pinned case by case. Every case of
 * the enum appears, and the test below proves that stays true — a case added without a bucket
 * decision would otherwise hold nothing, silently.
 */
final class InvoiceInventoryBucketResolverTest extends TestCase
{
    #[DataProvider('statusProvider')]
    public function testBucketForStatus(string $status, ?string $expectedBucket): void
    {
        self::assertSame($expectedBucket, InvoiceInventoryBucketResolver::bucketForStatus($status));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function statusProvider(): iterable
    {
        yield 'Draft holds nothing' => ['Draft', null];
        // The abandoned-checkout case. It counts as invoiced — which zeroes the order's sales hold —
        // and holds nothing itself, so the two rules meet at "an abandoned checkout holds nothing".
        yield 'On Hold holds nothing' => ['On Hold', null];
        yield 'Pending holds pending' => ['Pending', InvoiceInventoryReservation::BUCKET_PENDING];
        yield 'Processing holds approved' => ['Processing', InvoiceInventoryReservation::BUCKET_APPROVED];
        yield 'Completed holds shipped' => ['Completed', InvoiceInventoryReservation::BUCKET_SHIPPED];
        yield 'Cancelled holds nothing' => ['Cancelled', null];
    }

    public function testEveryInvoiceStatusIsCovered(): void
    {
        $covered = array_map(
            static fn (array $row): string => $row[0],
            iterator_to_array(self::statusProvider()),
        );

        self::assertEqualsCanonicalizing(array_column(InvoiceStatus::cases(), 'value'), array_values($covered));
    }

    /**
     * The pre-filter lists the recalc commands query with have to be exactly the sets
     * bucketForStatus() answers for, for the reason the order resolver's twin test states: a status
     * missing from a pre-filter zeroes its bucket silently.
     */
    public function testPreFilterListsAgreeWithBucketForStatus(): void
    {
        foreach (InvoiceInventoryBucketResolver::pendingStatuses() as $status) {
            self::assertSame(InvoiceInventoryReservation::BUCKET_PENDING, InvoiceInventoryBucketResolver::bucketForStatus($status));
        }

        foreach (InvoiceInventoryBucketResolver::approvedStatuses() as $status) {
            self::assertSame(InvoiceInventoryReservation::BUCKET_APPROVED, InvoiceInventoryBucketResolver::bucketForStatus($status));
        }

        foreach (InvoiceInventoryBucketResolver::shippedStatuses() as $status) {
            self::assertSame(InvoiceInventoryReservation::BUCKET_SHIPPED, InvoiceInventoryBucketResolver::bucketForStatus($status));
        }

        $listed = array_merge(
            InvoiceInventoryBucketResolver::pendingStatuses(),
            InvoiceInventoryBucketResolver::approvedStatuses(),
            InvoiceInventoryBucketResolver::shippedStatuses(),
        );
        foreach (array_column(InvoiceStatus::cases(), 'value') as $case) {
            $holdsStock = InvoiceInventoryBucketResolver::bucketForStatus($case) !== null;
            self::assertSame($holdsStock, in_array($case, $listed, true), sprintf('%s is listed but holds nothing, or holds stock but is not listed', $case));
        }
    }
}
