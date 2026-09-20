<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Inventory;

use App\Service\QuantityScale;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Inventory\InventoryModeSwitcher;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * The switch is the one moment the two layers meet on an existing product, and the property that
 * matters is that **the number does not change**.
 */
final class InventoryModeSwitcherTest extends DoctrineIntegrationTestCase
{
    private InventoryModeSwitcher $switcher;
    private Warehouse $warehouse;
    private Warehouse $second;
    private ProductCore $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->switcher = self::getContainer()->get(InventoryModeSwitcher::class);
        $warehouses = self::getContainer()->get(WarehouseFulfillmentRegionService::class);

        $west = (new FulfillmentRegion())->setName('West');
        $east = (new FulfillmentRegion())->setName('East');
        $this->em->persist($west);
        $this->em->persist($east);

        $this->warehouse = $warehouses->createWarehouseForRegion($west, 'BC', 'CA');
        $this->second = $warehouses->createWarehouseForRegion($east, 'BC', 'CA');

        $this->product = (new ProductCore())->setSku('SWITCH-1')->setName('Switchable')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($this->product);

        $this->em->persist((new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(47));
        $this->em->persist((new ProductInventory())->setProduct($this->product)->setWarehouse($this->second)->setQuantity(3));

        $this->em->flush();
    }

    private function quantityIn(Warehouse $warehouse): string
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $this->product, 'warehouse' => $warehouse]);

        return QuantityScale::canonical($row instanceof ProductInventory ? $row->getQuantity() : 0);
    }

    /**
     * The opening balance is read from the very total it replaces, so the number is identical either
     * side of the switch — and `SUM(available) == quantity` holds from the first instant.
     */
    public function testSwitchingOnCarriesTheExistingTotalInAsAnOpeningBalance(): void
    {
        $group = $this->switcher->toDimensional($this->product, 'tester@example.test');

        self::assertNotNull($group);
        self::assertSame('Opening balance on switching to dimensional inventory', $group->getReason());
        self::assertSame('tester@example.test', $group->getActor());

        $this->em->clear();

        self::assertSame('47.0000', $this->quantityIn($this->warehouse), 'the number must be exactly what it was');
        self::assertSame('3.0000', $this->quantityIn($this->second));

        $details = self::getContainer()->get(InventoryDetailRepository::class);
        $product = $this->em->find(ProductCore::class, $this->product->getId());
        self::assertInstanceOf(ProductCore::class, $product);

        foreach ([$this->warehouse, $this->second] as $warehouse) {
            $live = $this->em->find(Warehouse::class, $warehouse->getId());
            self::assertInstanceOf(Warehouse::class, $live);
            self::assertSame(
                $this->quantityIn($live),
                $details->availableTotal($product, $live),
                'the invariant must hold from the first instant',
            );
        }
    }

    /** Honest: "we have 47, we don't yet know where." A wrong bin is indistinguishable from a right one. */
    public function testTheOpeningBalanceLeavesTheBinAndLotUnspecified(): void
    {
        $this->switcher->toDimensional($this->product);
        $this->em->clear();

        /** @var list<InventoryDetail> $rows */
        $rows = $this->em->getRepository(InventoryDetail::class)->findAll();

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertNull($row->getLocation());
            self::assertNull($row->getLot());
            self::assertNull($row->getSerial());
            self::assertSame(InventoryDetail::STATUS_AVAILABLE, $row->getStatus());
        }
    }

    /** Nothing at all: the total is already there. And the breakdown is kept, not deleted. */
    public function testSwitchingBackChangesNoNumberAndKeepsTheBreakdown(): void
    {
        $this->switcher->toDimensional($this->product);
        $before = $this->quantityIn($this->warehouse);
        $detailCountBefore = \count($this->em->getRepository(InventoryDetail::class)->findAll());

        $this->switcher->toSimple($this->product);
        $this->em->clear();

        self::assertSame($before, $this->quantityIn($this->warehouse));
        self::assertCount($detailCountBefore, $this->em->getRepository(InventoryDetail::class)->findAll(), 'the rows go dormant, they are not deleted');

        $product = $this->em->find(ProductCore::class, $this->product->getId());
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertSame(ProductCore::INVENTORY_MODE_SIMPLE, $product->getInventoryMode());
    }

    /** A product at zero everywhere needs no movement; writing an empty group would be a record of nothing. */
    public function testAProductWithNoStockOpensWithNoMovementAtAll(): void
    {
        $empty = (new ProductCore())->setSku('EMPTY-1')->setName('Empty')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($empty);
        $this->em->flush();

        self::assertNull($this->switcher->toDimensional($empty));
        self::assertSame(ProductCore::INVENTORY_MODE_DIMENSIONAL, $empty->getInventoryMode());
    }

    /**
     * The mixed case the two single-warehouse fixtures above cannot reach: one warehouse already
     * has real detail rows from pre-switch activity (StockMovementService.php, 2026-09-18 — a
     * `simple` product resolves a real row for every movement now), the other has only ever been
     * imported into. The opening balance must only open the second one — crediting the first again
     * would double it — and the write-back must only zero what THIS call actually credited, not the
     * first warehouse's real, independently-earned `received`.
     */
    public function testAWarehouseWithRealPreSwitchDetailRowsOnlyOpensTheGapNotTheWholeQuantityAgain(): void
    {
        $movements = self::getContainer()->get(StockMovementService::class);

        // Real pre-switch activity on $this->warehouse only — the product is still `simple` here,
        // and StockMovementService now resolves a real row for it regardless. This shapes 5 of the
        // warehouse's stock; the other 42 of its 47 `quantity` is still unshaped.
        $movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'pre-switch-found')
                ->receive($this->product, new DetailKey($this->warehouse), '5')
        );

        $this->em->clear();
        $product = $this->em->find(ProductCore::class, $this->product->getId());
        $warehouse = $this->em->find(Warehouse::class, $this->warehouse->getId());
        $second = $this->em->find(Warehouse::class, $this->second->getId());
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertInstanceOf(Warehouse::class, $warehouse);
        self::assertInstanceOf(Warehouse::class, $second);

        $receivedBefore = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse])?->getReceivedQuantity();
        self::assertSame('5.0000', $receivedBefore, 'the pre-switch receipt really did credit received, the normal way');

        $group = $this->switcher->toDimensional($product, 'tester@example.test');
        $this->em->clear();

        $details = self::getContainer()->get(InventoryDetailRepository::class);
        $product = $this->em->find(ProductCore::class, $product->getId());
        $warehouse = $this->em->find(Warehouse::class, $warehouse->getId());
        $second = $this->em->find(Warehouse::class, $second->getId());
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertInstanceOf(Warehouse::class, $warehouse);
        self::assertInstanceOf(Warehouse::class, $second);

        // $this->warehouse: the 5 already shaped stays put, and the remaining 42 of `quantity`
        // (47 total) opens on top of it — never the whole 47 again, which would double the 5.
        self::assertSame('52.0000', $details->availableTotal($product, $warehouse), 'the real 5 plus exactly the 42 still unshaped, not 47 opened again on top of 5');
        $rowA = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse]);
        self::assertInstanceOf(ProductInventory::class, $rowA);
        self::assertSame('5.0000', $rowA->getReceivedQuantity(), 'real, earned received survives — only the 42-unit opening addition was written back out, not the whole bucket');
        self::assertSame('47.0000', $rowA->getQuantity(), 'quantity itself is never written by any of this');

        // $this->second: had nothing but an import figure — fully opens, same as ever.
        self::assertSame('3.0000', $details->availableTotal($product, $second), 'the untouched warehouse still gets its opening balance in full');
        $rowB = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $second]);
        self::assertInstanceOf(ProductInventory::class, $rowB);
        self::assertSame('0.0000', $rowB->getReceivedQuantity(), 'this one DID just get an opening balance, so it must be written back to zero');

        self::assertNotNull($group, 'both warehouses still had a gap, so a group is still written');
    }

    /**
     * The double-switch case the fix above exists for as much as the mixed-warehouse one: a
     * product that goes dimensional, back to simple (rows stay dormant, per this class's own
     * doc), then dimensional again with NO new activity in between must not open a second time —
     * the gap is already zero, because the first switch's rows are still sitting there.
     */
    public function testSwitchingToDimensionalASecondTimeAfterRevertingOpensNothingMore(): void
    {
        $this->switcher->toDimensional($this->product, 'tester@example.test');
        $this->em->clear();

        $product = $this->em->find(ProductCore::class, $this->product->getId());
        self::assertInstanceOf(ProductCore::class, $product);
        $this->switcher->toSimple($product);

        $product = $this->em->find(ProductCore::class, $product->getId());
        self::assertInstanceOf(ProductCore::class, $product);
        $secondGroup = $this->switcher->toDimensional($product, 'tester@example.test');
        $this->em->clear();

        self::assertNull($secondGroup, 'the rows from the first switch never went away, so there is no gap left to open');

        $details = self::getContainer()->get(InventoryDetailRepository::class);
        $product = $this->em->find(ProductCore::class, $product->getId());
        $warehouse = $this->em->find(Warehouse::class, $this->warehouse->getId());
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertInstanceOf(Warehouse::class, $warehouse);

        self::assertSame('47.0000', $details->availableTotal($product, $warehouse), 'still exactly what the FIRST switch opened — not doubled by the second');
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse]);
        self::assertInstanceOf(ProductInventory::class, $row);
        self::assertSame('0.0000', $row->getReceivedQuantity());
    }

    /** With the provider live, core sees the product as dimensional and offers a breakdown link instead of a box. */
    public function testCoreSeesTheProductAsDimensionalOnceSwitched(): void
    {
        $resolver = self::getContainer()->get(InventoryModeResolver::class);

        self::assertFalse($resolver->isDimensional($this->product));
        self::assertTrue($resolver->isDimensionalAvailable(), 'the bundle is installed in this test kernel');

        $this->switcher->toDimensional($this->product);

        self::assertTrue($resolver->isDimensional($this->product));
        self::assertNotNull($resolver->breakdownUrl($this->product, $this->warehouse));
    }
}
