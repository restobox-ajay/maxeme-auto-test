<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Command;

use App\Entity\FulfillmentRegion;
use App\Entity\InventoryReconciliationDiscrepancy;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Command\InventoryDetailRecalcCommand;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The invariant checked from outside the code that maintains it — and, crucially, **never
 * corrected**.
 *
 * That is the one place this differs from its bucket siblings, and it is deliberate: a bucket is a
 * derived cache whose source of truth is a document, so recomputing it is always right. The physical
 * total is not derived from anything the computer knows. If the two disagree, one of them is a record
 * of reality and the machine cannot tell which, so overwriting either destroys the evidence.
 */
final class InventoryDetailRecalcCommandTest extends DoctrineIntegrationTestCase
{
    private Warehouse $warehouse;
    private ProductCore $product;

    protected function setUp(): void
    {
        parent::setUp();

        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $this->product = (new ProductCore())
            ->setSku('DRIFT-1')->setName('Drifter')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);

        $bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('A-01')->setSortKey(1);
        $this->em->persist($bin);
        $this->em->flush();

        self::getContainer()->get(StockMovementService::class)->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed')
                ->receive($this->product, new DetailKey($this->warehouse, $bin), 30)
        );
    }

    private function check(): CommandTester
    {
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:inventory-depth:detail-check')
        );
        $tester->execute([]);

        return $tester;
    }

    private function inventory(): ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
        ]);
        self::assertInstanceOf(ProductInventory::class, $row);

        return $row;
    }

    public function testAConsistentProductReportsNoDrift(): void
    {
        $tester = $this->check();
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('reconciles to its detail', $tester->getDisplay());
        self::assertCount(0, $this->em->getRepository(InventoryReconciliationDiscrepancy::class)->findAll());
    }

    /**
     * Selling stock and writing some off is not drift (#581).
     *
     * Both take units out of the available rows without touching `received`: a shipment because the
     * invoice that billed it holds the units, a write-off because the `write_off` bucket does. If
     * this command still compared `SUM(available detail)` against `quantity + received` alone, both
     * would look like stock going missing — and it would say so about every product that had ever
     * been sold, which is the loudest possible way to be wrong about something working correctly.
     */
    public function testStockThatWasSoldOrWrittenOffIsNotDrift(): void
    {
        $bin = $this->em->getRepository(WarehouseLocation::class)->findOneBy(['code' => 'A-01']);
        self::assertInstanceOf(WarehouseLocation::class, $bin);

        $movements = self::getContainer()->get(StockMovementService::class);

        $movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_SHIP, 'op-ship', null, null, 'SO-1')
                ->move(
                    $this->product,
                    new DetailKey($this->warehouse, $bin),
                    new DetailKey($this->warehouse, $bin, null, null, InventoryDetail::STATUS_SOLD),
                    7,
                )
        );

        $movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'op-damage')
                ->move(
                    $this->product,
                    new DetailKey($this->warehouse, $bin),
                    new DetailKey($this->warehouse, $bin, null, null, InventoryDetail::STATUS_DAMAGED),
                    4,
                )
        );

        $this->em->flush();
        $this->em->clear();

        // 30 in, 7 sold, 4 damaged: 19 on the shelf, 30 still on record as having arrived.
        $row = $this->inventory();
        self::assertSame('30.0000', $row->getReceivedQuantity(), 'neither a sale nor a write-off is an arrival');
        self::assertSame('4.0000', $row->getWriteOffQuantity());

        $tester = $this->check();
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('reconciles to its detail', $tester->getDisplay());
        self::assertCount(
            0,
            $this->em->getRepository(InventoryReconciliationDiscrepancy::class)->findAll(),
            'every departure is accounted for by a named term, so there is nothing to report',
        );
    }

    /**
     * Drift is reported and NOT corrected. The quantity is asserted still wrong afterwards, which
     * reads oddly and is exactly the point: a human resolves it through Stock Adjustment so the
     * correction carries a reason.
     */
    public function testDriftIsLoggedAndDeliberatelyLeftAlone(): void
    {
        // A write nothing in this bundle would make — standing in for the direct database edit or
        // the bug that this command exists to notice.
        // Both halves, because since #564 the cached figure this command guards is
        // quantity + received — the movement layer stopped writing `quantity` and maintains the
        // gap instead. Setting only one leaves the other holding the receipt and the row still
        // reconciling, which would seed no drift at all.
        $this->inventory()->setQuantity(25)->setReceivedQuantity(0);
        $this->em->flush();

        $tester = $this->check();
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('NOT corrected', $tester->getDisplay());

        /** @var list<InventoryReconciliationDiscrepancy> $found */
        $found = $this->em->getRepository(InventoryReconciliationDiscrepancy::class)->findAll();

        self::assertCount(1, $found);
        self::assertSame(InventoryDetailRecalcCommand::PSEUDO_BUCKET, $found[0]->getBucket());
        self::assertSame(InventoryDetailRecalcCommand::SOURCE, $found[0]->getSource());
        self::assertSame('25.0000', $found[0]->getCachedQuantity());
        self::assertSame('30.0000', $found[0]->getRecomputedQuantity());

        $this->em->clear();
        self::assertSame('25.0000', $this->inventory()->getQuantity(), 'detection only — the command must never correct');
    }

    /**
     * Simple-inventory bucket parity plan (2026-09-15): `quarantine`/`write_off` are now
     * independently accumulated rather than derived from these very detail sums, so they can
     * genuinely drift from them — and this is the check built specifically to notice when they do.
     * Reported under the real bucket name, not `PSEUDO_BUCKET`, because unlike `quantity + received`
     * these two ARE ordinary bucket claims now.
     */
    public function testQuarantineDriftIsLoggedUnderItsOwnBucketName(): void
    {
        // A write nothing in this bundle would make, standing in for the direct database edit or the
        // bug this check exists to notice — the detail rows say 0 units are quarantined, but the
        // bucket claims 6.
        $this->inventory()->setQuarantineQuantity(6);
        $this->em->flush();

        $tester = $this->check();
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('NOT corrected', $tester->getDisplay());

        /** @var list<InventoryReconciliationDiscrepancy> $found */
        $found = $this->em->getRepository(InventoryReconciliationDiscrepancy::class)->findAll();

        $quarantineRow = null;
        foreach ($found as $row) {
            if ($row->getBucket() === 'quarantine') {
                $quarantineRow = $row;
            }
        }

        self::assertNotNull($quarantineRow, 'a quarantine drift must be reported under its own bucket name');
        self::assertSame('6.0000', $quarantineRow->getCachedQuantity());
        self::assertSame('0.0000', $quarantineRow->getRecomputedQuantity(), 'no detail row was ever quarantined');

        $this->em->clear();
        self::assertSame('6.0000', $this->inventory()->getQuarantineQuantity(), 'detection only — the command must never correct');
    }

    /** A product nobody opted in is not this command's business. */
    public function testASimpleProductIsNotChecked(): void
    {
        $this->product->setInventoryMode(ProductCore::INVENTORY_MODE_SIMPLE);
        $this->inventory()->setQuantity(25);
        $this->em->flush();

        $tester = $this->check();
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('No products are on dimensional inventory', $tester->getDisplay());
        self::assertCount(0, $this->em->getRepository(InventoryReconciliationDiscrepancy::class)->findAll());
    }
}
