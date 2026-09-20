<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Repository;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use InventoryDepthBundle\Repository\InventoryLotRepository;

/**
 * The two questions #590's screens ask of the database:
 *
 *  - which lots share a `(product_id, code)` — so the forks the old write-once form left behind can
 *    be **reported**. Nothing here merges anything, and nothing here may: a repeated batch code
 *    across two production runs with different dates is legitimate, and collapsing the pair would
 *    lose one of the two dates.
 *  - how many units are standing in a bin — the number bin closure refuses on.
 */
final class DuplicateLotsAndBinContentsTest extends DoctrineIntegrationTestCase
{
    private InventoryLotRepository $lots;
    private InventoryDetailRepository $details;
    private Warehouse $warehouse;
    private ProductCore $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lots = self::getContainer()->get(InventoryLotRepository::class);
        $this->details = self::getContainer()->get(InventoryDetailRepository::class);

        $region = (new FulfillmentRegion())->setName('Dup Region');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->product = (new ProductCore())
            ->setSku('DUP-1')
            ->setName('Duplicate Lot Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);
        $this->em->flush();
    }

    private function lot(string $code, ?string $expiry, ?ProductCore $product = null): InventoryLot
    {
        $lot = (new InventoryLot())
            ->setProduct($product ?? $this->product)
            ->setCode($code)
            ->setExpiry($expiry === null ? null : new \DateTimeImmutable($expiry));
        $this->em->persist($lot);
        $this->em->flush();

        return $lot;
    }

    private function bin(string $code): WarehouseLocation
    {
        $bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode($code)->setSortKey(10);
        $this->em->persist($bin);
        $this->em->flush();

        return $bin;
    }

    /** Rows are made through findOrCreate() because it is the only thing that makes them. */
    private function row(WarehouseLocation $bin, string $status, int $quantity): InventoryDetail
    {
        $row = $this->details->findOrCreate($this->product, $this->warehouse, $bin, null, null, $status);
        $row->setQuantity($quantity);
        $this->em->flush();

        return $row;
    }

    public function testALotCodeUsedOnceIsNotADuplicate(): void
    {
        $this->lot('BATCH-A', '2027-01-31');
        $this->lot('BATCH-B', '2027-02-28');

        self::assertSame([], $this->lots->duplicateGroups());
    }

    public function testTwoLotsSharingACodeOnOneProductAreGroupedAndReported(): void
    {
        $first = $this->lot('BATCH-A', '2027-01-31');
        $second = $this->lot('BATCH-A', '2028-01-31');
        $this->lot('BATCH-B', '2027-02-28');

        $groups = $this->lots->duplicateGroups();

        self::assertCount(1, $groups, 'only the repeated code is a group');
        self::assertSame('BATCH-A', $groups[0]['code']);
        self::assertSame($this->product->getId(), $groups[0]['product']->getId());
        self::assertSame(
            [$first->getId(), $second->getId()],
            array_map(static fn (InventoryLot $l): ?int => $l->getId(), $groups[0]['lots']),
            'both rows are named, in row order, and both are still there',
        );

        // The point of the report: the earlier date survives. Merging would have lost it.
        self::assertSame('2027-01-31', $groups[0]['lots'][0]->getExpiry()?->format('Y-m-d'));
        self::assertSame('2028-01-31', $groups[0]['lots'][1]->getExpiry()?->format('Y-m-d'));
    }

    public function testTheSameCodeOnTwoDifferentProductsIsNotADuplicate(): void
    {
        $other = (new ProductCore())
            ->setSku('DUP-2')
            ->setName('Another Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($other);
        $this->em->flush();

        $this->lot('BATCH-A', '2027-01-31');
        $this->lot('BATCH-A', '2027-01-31', $other);

        self::assertSame([], $this->lots->duplicateGroups(), 'lot codes are only ambiguous within one product');
    }

    public function testOthersSharingCodeNamesTheTwinAndNotTheLotItself(): void
    {
        $first = $this->lot('BATCH-A', '2027-01-31');
        $second = $this->lot('BATCH-A', '2028-01-31');

        $twins = $this->lots->othersSharingCode($second);

        self::assertCount(1, $twins);
        self::assertSame($first->getId(), $twins[0]->getId());
        self::assertSame([], $this->lots->othersSharingCode($this->lot('BATCH-C', null)));
    }

    /**
     * Every status counts, not just `available`. A quarantined carton is as stranded by a closed
     * bin as a sellable one — every bin dropdown in the bundle is Active-only.
     */
    public function testUnitsInLocationCountsEveryStatusThatIsStillInTheBin(): void
    {
        $bin = $this->bin('C-01');

        self::assertSame('0.0000', $this->details->unitsInLocation($bin), 'a bin nothing has ever been in holds nothing');

        $this->row($bin, InventoryDetail::STATUS_AVAILABLE, 7);
        $this->row($bin, InventoryDetail::STATUS_QUARANTINE, 3);
        $this->row($bin, InventoryDetail::STATUS_DAMAGED, 2);

        self::assertSame('12.0000', $this->details->unitsInLocation($bin));
    }

    /** A row at 0 is history, and history is not stock standing in a bin. */
    public function testARowEmptiedToZeroDoesNotKeepABinClosed(): void
    {
        $bin = $this->bin('C-02');
        $row = $this->row($bin, InventoryDetail::STATUS_AVAILABLE, 5);

        $row->setQuantity(0);
        $this->em->flush();

        self::assertSame('0.0000', $this->details->unitsInLocation($bin));
    }

    public function testUnitsByLocationAnswersAWholePageInOneQuery(): void
    {
        $stocked = $this->bin('C-03');
        $empty = $this->bin('C-04');
        $this->row($stocked, InventoryDetail::STATUS_AVAILABLE, 6);

        $totals = $this->details->unitsByLocation([$stocked, $empty]);

        self::assertSame(6, $totals[$stocked->getId()] ?? 0);
        self::assertArrayNotHasKey($empty->getId(), $totals, 'a bin holding nothing is simply absent');
        self::assertSame([], $this->details->unitsByLocation([]));
    }
}
