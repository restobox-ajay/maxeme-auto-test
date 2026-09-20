<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Service\QuantityScale;
use App\Entity\ProductInventory;
use App\Service\Inventory\InventoryGridColumns;
use PHPUnit\Framework\TestCase;

/**
 * The invariant InventoryGridColumns' own docblock claims: the signed cells add up to Available.
 *
 * ## Why this exists beside InventoryGridBucketCoverageCest
 *
 * That Cest already asserts this, and it is the right test — it reads the rendered page, which is
 * where a person actually sees the sum fail to balance. It is also a Codeception browser test, so
 * it does not run in the PHPUnit suite, and the defect this file was written for slipped through
 * exactly that gap: `shipped` was added to `getAvailableQuantity()` on 2026-09-15
 * (docs/plans/2026-09-15-shipment-approved-to-shipped-bucket.md) and to neither `COLUMNS` nor
 * `cellsFor()`, so the grid quietly subtracted units that appeared under no heading and
 * `workingFor()` printed a working that did not reach its own total.
 *
 * The assertions below need no database and no browser, which is the whole point: the registry and
 * the formula are both pure, so the one thing holding them together should be checked by the suite
 * that runs on every commit.
 *
 * ## Why each bucket gets a DISTINCT value
 *
 * A row of equal values, or of zeros, balances under almost any wrong sign table — two terms with
 * swapped signs cancel, and a missing term is invisible. Distinct powers of two make every term's
 * contribution unique, so a sign that is wrong, a term that is missing and a term counted twice are
 * all separately detectable rather than able to hide behind each other.
 */
final class InventoryGridColumnsBalanceTest extends TestCase
{
    public function testTheSignedCellsAddUpToAvailableForARowWhereEveryBucketIsSet(): void
    {
        $columns = new InventoryGridColumns();
        $row = self::rowWithEveryBucketSet();

        $total = 0;
        foreach ($columns->cellsFor($row) as $key => $cell) {
            if ($cell['sign'] === 0 || !is_numeric($cell['value'])) {
                continue;
            }

            $total += $cell['sign'] * QuantityScale::unitsAtColumnScale($cell['value']);
        }

        self::assertSame(
            QuantityScale::unitsAtColumnScale($row->getAvailableQuantity()),
            $total,
            'The signed cells no longer reconcile to getAvailableQuantity(). A term was added to the'
            . ' formula without a column here, or a column carries the wrong sign.',
        );
    }

    /**
     * Every field the availability formula reads has a column, and that column is a term in the sum.
     *
     * Stated separately from the arithmetic above because the two fail differently: a missing column
     * whose bucket happens to be zero on the fixture would balance, and this still catches it. The
     * field list is read off the formula by name rather than typed from the registry, so the
     * direction of the check is formula → registry, which is the direction a new bucket arrives in.
     */
    public function testEveryFieldAvailabilityReadsIsASignedColumn(): void
    {
        $columns = new InventoryGridColumns();

        $signedFields = [];
        foreach ($columns->cellsFor(self::rowWithEveryBucketSet()) as $key => $cell) {
            $field = $columns->all()[$key]['field'] ?? null;
            if ($field !== null && $cell['sign'] !== 0) {
                $signedFields[] = $field;
            }
        }

        foreach (self::AVAILABILITY_FIELDS as $field) {
            self::assertContains(
                $field,
                $signedFields,
                sprintf(
                    'ProductInventory::getAvailableQuantity() reads %s, but no InventoryGridColumns'
                    . ' column carries it as a term. The grid would subtract it from Available while'
                    . ' showing it nowhere.',
                    $field,
                ),
            );
        }
    }

    /**
     * Every term in {@see ProductInventory::getAvailableQuantity()}, by property name.
     *
     * Typed out deliberately rather than reflected off the method body: the point of this list is to
     * be a SECOND statement of the formula that a reviewer compares against the first. Reflection
     * would make the test agree with whatever the method does, which is the one thing it must not
     * do.
     */
    private const AVAILABILITY_FIELDS = [
        'quantity',
        'receivedQuantity',
        'transferInQuantity',
        'transferOutQuantity',
        'writeOffQuantity',
        'quarantineQuantity',
        'cartHoldQuantity',
        'salesHoldQuantity',
        'pendingQuantity',
        'approvedQuantity',
        'shippedQuantity',
        'backorderedQuantity',
    ];

    /** Distinct powers of two, so no two terms can cancel or mask one another. */
    private static function rowWithEveryBucketSet(): ProductInventory
    {
        return (new ProductInventory())
            ->setQuantity(4096)
            ->setReceivedQuantity(1)
            ->setTransferInQuantity(2)
            ->setTransferOutQuantity(4)
            ->setQuarantineQuantity(8)
            ->setWriteOffQuantity(16)
            ->setCartHoldQuantity(32)
            ->setSalesHoldQuantity(64)
            ->setPendingQuantity(128)
            ->setApprovedQuantity(256)
            ->setShippedQuantity(512)
            ->setBackorderedQuantity(1024);
    }
}
