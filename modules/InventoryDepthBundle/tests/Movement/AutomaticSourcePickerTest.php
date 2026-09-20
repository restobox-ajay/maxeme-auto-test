<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Movement;

use App\Service\QuantityScale;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\AutomaticSourcePicker;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\InsufficientStockException;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;

/**
 * Earliest expiry first, then lowest bin sort_key. No UI, no operator choice — just enough that a
 * withdrawal expressed as "take 5 out of this warehouse" names real rows instead of lying.
 */
final class AutomaticSourcePickerTest extends DoctrineIntegrationTestCase
{
    private AutomaticSourcePicker $picker;
    private StockMovementService $movements;
    private Warehouse $warehouse;
    private ProductCore $product;
    private WarehouseLocation $near;
    private WarehouseLocation $far;
    private InventoryLot $soonest;
    private InventoryLot $later;
    private InventoryLot $undated;

    protected function setUp(): void
    {
        parent::setUp();

        $this->picker = self::getContainer()->get(AutomaticSourcePicker::class);
        $this->movements = self::getContainer()->get(StockMovementService::class);

        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $this->product = (new ProductCore())
            ->setSku('FEFO-1')->setName('Perishable')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);

        $this->near = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('A-01')->setSortKey(1);
        $this->far = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('Z-99')->setSortKey(99);
        $this->em->persist($this->near);
        $this->em->persist($this->far);

        $this->soonest = (new InventoryLot())->setProduct($this->product)->setCode('L-SOON')->setExpiry(new \DateTimeImmutable('2026-09-01'));
        $this->later = (new InventoryLot())->setProduct($this->product)->setCode('L-LATER')->setExpiry(new \DateTimeImmutable('2027-01-01'));
        $this->undated = (new InventoryLot())->setProduct($this->product)->setCode('L-NONE');
        foreach ([$this->soonest, $this->later, $this->undated] as $lot) {
            $this->em->persist($lot);
        }

        $this->em->flush();

        // Deliberately stocked so that the *convenient* bin holds the *latest* stock: a picker that
        // sorted on sort_key alone would pass this test's totals and fail its ordering.
        $request = MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed');
        $request->receive($this->product, new DetailKey($this->warehouse, $this->near, $this->later), 10);
        $request->receive($this->product, new DetailKey($this->warehouse, $this->far, $this->soonest), 4);
        $request->receive($this->product, new DetailKey($this->warehouse, $this->far, $this->undated), 6);
        $this->movements->apply($request);
    }

    public function testItTakesTheEarliestExpiryFirstEvenFromTheFurthestBin(): void
    {
        $plan = $this->picker->plan($this->product, $this->warehouse, 6);

        self::assertCount(2, $plan);
        self::assertSame('L-SOON', $plan[0]['detail']->getLot()?->getCode());
        self::assertSame('4.0000', $plan[0]['quantity'], 'the soonest-expiring lot is exhausted first');
        self::assertSame('L-LATER', $plan[1]['detail']->getLot()?->getCode());
        self::assertSame('2.0000', $plan[1]['quantity']);
    }

    /** An undated batch is never more urgent than a dated one, so it sorts last rather than first. */
    public function testAnUndatedLotSortsAfterEveryDatedOne(): void
    {
        $plan = $this->picker->plan($this->product, $this->warehouse, 20);

        $codes = array_map(static fn (array $step): ?string => $step['detail']->getLot()?->getCode(), $plan);

        self::assertSame(['L-SOON', 'L-LATER', 'L-NONE'], $codes);
    }

    public function testItRefusesRatherThanPartiallyPlanningWhatTheWarehouseCannotCover(): void
    {
        $this->expectException(InsufficientStockException::class);
        $this->picker->plan($this->product, $this->warehouse, 21);
    }

    /** Folded into a request, the withdrawal still preserves the invariant. */
    public function testAWithdrawalItPlansKeepsTheInvariant(): void
    {
        $request = $this->picker->addWithdrawal(
            MovementRequest::of(InventoryMovementGroup::TYPE_SHIP, 'op-pick', null, null, 'SO-9'),
            $this->product,
            $this->warehouse,
            6,
            \InventoryDepthBundle\Entity\InventoryDetail::STATUS_SOLD,
        );

        $this->movements->apply($request);
        $this->em->clear();

        $product = $this->em->find(ProductCore::class, $this->product->getId());
        $warehouse = $this->em->find(Warehouse::class, $this->warehouse->getId());
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertInstanceOf(Warehouse::class, $warehouse);

        $core = $this->em->getRepository(\App\Entity\ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse]);
        self::assertInstanceOf(\App\Entity\ProductInventory::class, $core);

        $details = self::getContainer()->get(\InventoryDepthBundle\Repository\InventoryDetailRepository::class);

        self::assertSame('14.0000', $details->availableTotal($product, $warehouse), '20 received, 6 shipped off the shelf');

        // `received` did NOT move, and that is the fix rather than the bug (#581). Shipping is not
        // an arrival and it is not stock vanishing from the ledger either — the units are billed,
        // and the invoice that billed them holds them in `approved` until it is paid. Taking them
        // off here as well would subtract the same 6 twice.
        //
        // SellingADimensionalProductTest is the transaction that shows the two halves meeting: a
        // dimensional product sold and shipped through a real invoice reads 25 sellable of 30, not
        // 20. This test has no invoice, so the 6 stay in the figure, which is the right answer for
        // stock marked sold with nothing billing it.
        self::assertSame(
            '20.0000',
            QuantityScale::add($core->getQuantity(), $core->getReceivedQuantity()),
            'nothing arrived, nothing left outright',
        );

        self::assertSame(
            $core->getQuantity() + $core->getReceivedQuantity(),
            $details->availableTotal($product, $warehouse) + 6,
            'the invariant, with the sold units named rather than folded away',
        );
    }
}
