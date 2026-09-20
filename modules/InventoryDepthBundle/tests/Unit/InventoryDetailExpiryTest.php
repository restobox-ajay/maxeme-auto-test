<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Unit;

use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use PHPUnit\Framework\TestCase;

/**
 * `InventoryDetail::$expiry` and `InventoryLot::$expiry` are never both real for the same row (#795)
 * — that is what lets every reader say `COALESCE(l.expiry, d.expiry)` without judging which wins.
 * `setExpiry()` is the guard, and this pins it in isolation, with no database involved.
 */
final class InventoryDetailExpiryTest extends TestCase
{
    public function testExpiryCanBeSetOnARowWithNoLot(): void
    {
        $detail = (new InventoryDetail())->setExpiry(new \DateTimeImmutable('2027-03-01'));

        self::assertSame('2027-03-01', $detail->getExpiry()?->format('Y-m-d'));
    }

    public function testExpiryIsRefusedOnceTheRowCarriesALot(): void
    {
        $lot = (new InventoryLot())->setCode('BATCH-1');
        $detail = (new InventoryDetail())->setLot($lot);

        $this->expectException(\InvalidArgumentException::class);

        $detail->setExpiry(new \DateTimeImmutable('2027-03-01'));
    }

    /** Clearing it back to null is not "setting" a date, so it stays legal alongside a lot. */
    public function testExpiryCanBeClearedOnARowThatCarriesALot(): void
    {
        $lot = (new InventoryLot())->setCode('BATCH-1');
        $detail = (new InventoryDetail())->setLot($lot);

        $detail->setExpiry(null);

        self::assertNull($detail->getExpiry());
    }
}
