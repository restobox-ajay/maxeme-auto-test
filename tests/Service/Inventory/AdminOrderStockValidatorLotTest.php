<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\Inventory\AdminOrderStockValidator;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * AdminOrderStockValidator's lot-scoped refusal (2026-09-14 lot/serial/expiry plan, section 3):
 * the same "refuse a reservation that would exceed what a specific lot has actually available" the
 * plan calls for, run ALONGSIDE the existing aggregate product+warehouse check rather than instead
 * of it — a save can be short on a lot while the aggregate shelf has plenty left elsewhere.
 */
final class AdminOrderStockValidatorLotTest extends DoctrineIntegrationTestCase
{
    private FulfillmentRegion $region;
    private Warehouse $warehouse;
    private Company $company;
    private ProductCore $product;
    private InventoryLot $lot;
    private AdminOrderStockValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($this->region);

        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);

        $policy = (new TrackingPolicy())->setName('Lot')->setMode(TrackingPolicy::MODE_LOT)->setTrackOut(true);
        $this->em->persist($policy);

        $this->product = (new ProductCore())->setSku('LOT-VALIDATOR')->setName('Lot Tracked Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
            ->setTrackingPolicy($policy);
        $this->em->persist($this->product);
        // Plenty of AGGREGATE stock — 100 — so the aggregate check alone would never refuse
        // anything here. Only the lot-scoped check, against the much smaller lot below, can.
        $this->em->persist((new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(100));
        $this->em->flush();

        $this->lot = (new InventoryLot())->setProduct($this->product)->setCode('SMALL-BATCH');
        $this->em->persist($this->lot);
        $this->em->flush();

        /** @var InventoryDetailRepository $details */
        $details = self::getContainer()->get(InventoryDetailRepository::class);
        $details->findOrCreate($this->product, $this->warehouse, null, $this->lot, null, InventoryDetail::STATUS_AVAILABLE)->setQuantity(5);
        $this->em->flush();

        $this->validator = self::getContainer()->get(AdminOrderStockValidator::class);
    }

    /** @return list<array<string, mixed>> */
    private function postedLine(string $qty, ?int $lotId): array
    {
        return [[
            'product_id' => (string) $this->product->getId(),
            'location' => $this->region->getName(),
            'qty' => $qty,
            'lot_id' => $lotId,
        ]];
    }

    public function testALineWithinTheLotsAvailabilityRaisesNoShortfall(): void
    {
        $shortfalls = $this->validator->shortfallsFor(
            $this->postedLine('5', $this->lot->getId()),
            'Approved',
            $this->region->getName(),
            null,
            $this->em,
        );

        self::assertSame([], $shortfalls);
    }

    /**
     * The aggregate shelf (100) covers 8 easily; only the SPECIFIC lot (5 available) cannot. That
     * this is measured at all — with plenty of aggregate stock sitting untouched — is the whole
     * point of the lot-scoped check existing alongside the aggregate one rather than folded into it.
     */
    public function testALineExceedingTheLotsAvailabilityRaisesAShortfallDespitePlentyOfAggregateStock(): void
    {
        $shortfalls = $this->validator->shortfallsFor(
            $this->postedLine('8', $this->lot->getId()),
            'Approved',
            $this->region->getName(),
            null,
            $this->em,
        );

        self::assertCount(1, $shortfalls);
        self::assertSame('5.0000', $shortfalls[0]->available);
        self::assertSame('3.0000', $shortfalls[0]->missing);
    }

    /** A line naming no lot at all is untouched by this check — every line today, on any untracked SKU. */
    public function testALineNamingNoLotIsNotMeasuredAgainstAnyLot(): void
    {
        $shortfalls = $this->validator->shortfallsFor(
            $this->postedLine('50', null),
            'Approved',
            $this->region->getName(),
            null,
            $this->em,
        );

        self::assertSame([], $shortfalls, 'covered by the aggregate check alone — 50 against 100 in stock');
    }

    /**
     * Re-saving an order that already holds 5 of the lot must not refuse itself for the quantity it
     * already holds — the same #214 add-back the aggregate check already gives, now for a lot.
     */
    public function testAnOrdersOwnExistingHoldOnTheLotIsAddedBackOnEdit(): void
    {
        $order = (new SalesOrder())->setCompany($this->company)->setOrderNumber('ORD-' . uniqid())
            ->setFulfillmentRegion($this->region->getName());
        $line = (new SalesOrderLine())->setProduct($this->product)->setName($this->product->getName())
            ->setQuantity('5')->setLotId($this->lot->getId());
        $order->addLine($line);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        // Re-saving the SAME order, unchanged, must resolve to no shortfall: the lot has exactly 5
        // available in the raw sum, all of which is this order's own hold added back.
        $shortfalls = $this->validator->shortfallsFor(
            $this->postedLine('5', $this->lot->getId()),
            'Approved',
            $this->region->getName(),
            $order,
            $this->em,
        );

        self::assertSame([], $shortfalls);
    }
}
