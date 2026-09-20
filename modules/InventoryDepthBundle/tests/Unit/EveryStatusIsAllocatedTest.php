<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Unit;

use InventoryDepthBundle\Entity\InventoryDetail;
use PHPUnit\Framework\TestCase;

/**
 * Every `inventory_detail.status` is subtracted from availability by exactly one thing (#581).
 *
 * This is the rule ProductInventory::getAvailableQuantity()'s docblock states and no code could
 * enforce until the groups lived in one place: "every bucket has to appear in this subtraction; one
 * left out is silent, since the quantity still sits in its ledger and its cache column and simply
 * never reaches availability."
 *
 * A status left out of every group is the silent case — stock in it stays sellable forever. A
 * status in two groups is the loud one, and reads as inventory going missing. Both are one-line
 * mistakes when a status is added, and neither shows up in a test about movements.
 */
final class EveryStatusIsAllocatedTest extends TestCase
{
    /** @return array<string, list<string>> */
    private function allocation(): array
    {
        return [
            // Not subtracted at all. This IS the sellable stock.
            'available' => [InventoryDetail::STATUS_AVAILABLE],
            // The transfer order, through `transfer_out` at the warehouse it names FROM and
            // `transfer_in` at the one it names TO (#584). Not a detail-row sum any more.
            'the transfer order that dispatched the units' => InventoryDetail::statusesAccountedForAtBothEnds(),
            'write_off bucket' => InventoryDetail::writeOffStatuses(),
            'quarantine bucket' => InventoryDetail::quarantineStatuses(),
            'the invoice that billed the units' => InventoryDetail::soldStatuses(),
        ];
    }

    public function testEveryStatusIsAccountedForByExactlyOneThing(): void
    {
        $seen = [];

        foreach ($this->allocation() as $who => $statuses) {
            foreach ($statuses as $status) {
                self::assertArrayNotHasKey(
                    $status,
                    $seen,
                    sprintf('"%s" is claimed by both %s and %s, so its units come off twice', $status, $seen[$status] ?? '', $who),
                );
                $seen[$status] = $who;
            }
        }

        self::assertSame(
            [],
            array_values(array_diff(InventoryDetail::statuses(), array_keys($seen))),
            'a status nothing subtracts leaves its stock sellable forever — decide which bucket it belongs to, '
            . 'or add it to soldStatuses() if a document already holds it',
        );

        self::assertSame(
            [],
            array_values(array_diff(array_keys($seen), InventoryDetail::statuses())),
            'a group names a status that no longer exists',
        );
    }

    /**
     * The list the movement layer actually reads is everything above except `available` itself —
     * composed, not restated, so this asserts the composition rather than a copy of it.
     */
    public function testTheAccountedForListIsEveryStatusButAvailable(): void
    {
        $expected = InventoryDetail::statuses();
        sort($expected);

        $actual = InventoryDetail::statusesAccountedForElsewhere();
        self::assertSame(array_unique($actual), $actual, 'a status listed twice would be harmless here and confusing everywhere else');

        $actual[] = InventoryDetail::STATUS_AVAILABLE;
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /**
     * The one status whose accounting follows the document across warehouses, rather than a bucket
     * summed per (product, warehouse) — the distinction MovementRequest::receivedDelta() turns on
     * (#584).
     *
     * A status added here that is NOT in statusesAccountedForElsewhere() would suppress `received`
     * on a crossing that nothing else records, and the units would stay sellable at a warehouse
     * they have left. So the subset relationship is asserted rather than assumed.
     */
    public function testTheCrossWarehouseListIsASubsetOfTheAccountedForList(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(
                InventoryDetail::statusesAccountedForAtBothEnds(),
                InventoryDetail::statusesAccountedForElsewhere(),
            )),
            'a status accounted for at both ends must first be accounted for at all',
        );

        // Named explicitly. Deriving the expectation from the method under test would assert
        // nothing, and this list being exactly one entry long is the claim #584 makes: only a
        // transfer order records itself at a warehouse other than the one holding the row.
        self::assertSame([InventoryDetail::STATUS_IN_TRANSIT], InventoryDetail::statusesAccountedForAtBothEnds());
        self::assertNotContains(
            InventoryDetail::STATUS_SOLD,
            InventoryDetail::statusesAccountedForAtBothEnds(),
            'an invoice holds its units on ONE product_inventory row, so a sale elsewhere records nothing here',
        );
    }

    /**
     * The adjustment screen's two lists partition the statuses: what a document owns, and what a
     * warehouse may record. Nothing in between, nothing in both (#581).
     *
     * The failure this catches is a status added to statuses() and then silently offered as an
     * adjustment destination because adjustableDestinations() is derived by subtraction. That
     * default is the right one — a new physical state should be recordable — but a new
     * DOCUMENT-backed state inheriting it would hand the adjustment form the power this issue took
     * away from it, and would do so without touching AdjustmentController at all.
     */
    public function testDocumentBackedAndAdjustableDestinationsPartitionEveryStatus(): void
    {
        $document = InventoryDetail::documentBackedStatuses();
        $adjustable = InventoryDetail::adjustableDestinations();

        self::assertSame(
            [],
            array_values(array_intersect($document, $adjustable)),
            'a status cannot be both a document\'s to write and an adjustment\'s to write',
        );

        $union = array_merge($document, $adjustable);
        sort($union);
        $expected = InventoryDetail::statuses();
        sort($expected);

        self::assertSame($expected, $union, 'every status is either a document\'s or an adjustment\'s');

        // Named explicitly, because these are the whole point of the rule and deriving them from
        // the same call the code under test uses would assert nothing. `returned` joined the list
        // in #596, when `sales_return` became the document that produces those rows — before that
        // nothing produced them at all, which is why #586 could leave it adjustable and say so.
        // `returned_to_vendor` joined in #638 for the identical reason, direction reversed: only
        // `VendorReturnShipService` may write it.
        self::assertSame(['sold', 'in_transit', 'returned', 'returned_to_vendor'], $document);
        self::assertNotContains(InventoryDetail::STATUS_SOLD, $adjustable);
        self::assertNotContains(
            InventoryDetail::STATUS_RETURNED,
            $adjustable,
            'an adjustment writing `returned` is stock come back against no RMA — the forgery #581 '
            . 'removed for `sold`, in the place #596 closed',
        );
        self::assertContains(InventoryDetail::STATUS_AVAILABLE, $adjustable, 'putting stock back is an adjustment');
        self::assertContains(InventoryDetail::STATUS_DAMAGED, $adjustable, 'so is finding it broken');
        self::assertContains(
            InventoryDetail::STATUS_QUARANTINE,
            $adjustable,
            'holding stock back is a decision a warehouse makes, not a document — "Put on hold" and '
            . '"Release from hold" both survive #596 untouched',
        );
    }
}
