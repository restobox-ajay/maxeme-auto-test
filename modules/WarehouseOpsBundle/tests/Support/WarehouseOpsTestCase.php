<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Support;

use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * A warehouse with two pick faces, a staging bin, a dimensional product and stock in it — the
 * fixture every pick and transfer test starts from.
 *
 * Stock is put there through StockMovementService rather than by writing rows, deliberately: a test
 * fixture that builds `inventory_detail` by hand is a second write path in the test suite, and the
 * first thing it would stop noticing is the invariant these tests exist to protect.
 */
abstract class WarehouseOpsTestCase extends DoctrineIntegrationTestCase
{
    protected StockMovementService $movements;
    protected InventoryDetailRepository $details;
    protected FulfillmentRegion $region;
    protected Warehouse $warehouse;
    protected ProductCore $product;
    protected Company $company;
    protected WarehouseLocation $binA;
    protected WarehouseLocation $binB;
    protected WarehouseLocation $staging;

    protected function setUp(): void
    {
        parent::setUp();

        $this->movements = self::getContainer()->get(StockMovementService::class);
        $this->details = self::getContainer()->get(InventoryDetailRepository::class);

        $this->region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($this->region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);

        $this->product = (new ProductCore())
            ->setSku('WIDGET-1')
            ->setName('Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);

        // sort_key is the pick path: A is walked before B, and staging is off the round entirely.
        $this->binA = $this->bin('A-01', 10, WarehouseLocation::TYPE_PICK);
        $this->binB = $this->bin('B-01', 20, WarehouseLocation::TYPE_PICK);
        $this->staging = $this->bin('STAGE-1', 900, WarehouseLocation::TYPE_STAGING);

        $this->em->flush();
    }

    protected function bin(string $code, int $sortKey, string $type): WarehouseLocation
    {
        $bin = (new WarehouseLocation())
            ->setWarehouse($this->warehouse)
            ->setCode($code)
            ->setSortKey($sortKey)
            ->setType($type);
        $this->em->persist($bin);

        return $bin;
    }

    protected function lot(string $code, ?string $expiry = null): InventoryLot
    {
        $lot = (new InventoryLot())
            ->setProduct($this->product)
            ->setCode($code)
            ->setExpiry($expiry === null ? null : new \DateTimeImmutable($expiry));
        $this->em->persist($lot);
        $this->em->flush();

        return $lot;
    }

    /**
     * Stock arrives the only way stock ever arrives: a movement.
     *
     * `$bin` is nullable because `inventory_detail.location_id` is: an opening balance nobody has
     * put away sits in the warehouse and in no bin, and a test about which shelf a pick came off has
     * to be able to build that row.
     */
    protected function receive(int $quantity, ?WarehouseLocation $bin, ?InventoryLot $lot = null, ?string $serial = null): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed-' . bin2hex(random_bytes(8)))
                ->receive($this->product, new DetailKey($this->warehouse, $bin, $lot, $serial), $quantity)
        );
    }

    /**
     * What a customer may still buy: the shelf less every claim and every bucket.
     *
     * Read off the entity rather than re-spelled here, so a bucket added later cannot leave this
     * helper quietly asserting an older invariant — which is what happened twice.
     *
     * NOT the same number as physicalTotal(), and the difference is the point: a test with an order
     * in it will see this drop by the hold while the stock has not moved an inch. Reach for
     * physicalTotal() when the question is whether stock moved, and this when the question is
     * whether it can still be sold.
     */
    protected function coreQuantity(?ProductCore $product = null, ?Warehouse $warehouse = null): int
    {
        return $this->wholeUnits($this->inventoryRow($product, $warehouse)?->getAvailableQuantity() ?? '0');
    }

    /**
     * A quantity column as an `int`, and a failure rather than a truncation if it is not one.
     *
     * Every fixture in this bundle's tests deals in whole units — a pallet, a carton, a serial — so
     * `assertSame(50, ...)` is the readable expectation and worth keeping now that the columns read
     * as decimal strings. What is NOT acceptable is a helper that quietly casts 49.6 to 49 and lets
     * a test about stock levels pass over a figure nobody intended, so the wholeness is asserted
     * here rather than assumed. See `App\Service\QuantityScale`.
     */
    protected function wholeUnits(string|int|float $quantity): int
    {
        self::assertTrue(
            QuantityScale::isWhole($quantity),
            sprintf('a fixture in this test holds %s, which is not a whole number of units', (string) $quantity),
        );

        // isWhole() above is what makes a plain cast safe: nothing here truncates a real fraction,
        // it only drops the trailing '.0000' a whole quantity already asserted it has none beyond.
        return (int) QuantityScale::canonical($quantity);
    }

    /**
     * The bucket row itself, for assertions about WHICH column carries a change.
     *
     * Availability collapses several columns into one number, so two different bookkeepings of the
     * same event produce the same figure — which is exactly the case a test about buckets has to be
     * able to tell apart.
     */
    protected function inventoryRow(?ProductCore $product = null, ?Warehouse $warehouse = null): ?ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product ?? $this->product,
            'warehouse' => $warehouse ?? $this->warehouse,
        ]);

        return $row instanceof ProductInventory ? $row : null;
    }

    /**
     * Everything this warehouse is holding for the product, before any claim against it:
     * the external system's count plus what this app has seen arrive or leave since.
     */
    protected function physicalTotal(?ProductCore $product = null, ?Warehouse $warehouse = null): int
    {
        $row = $this->inventoryRow($product, $warehouse);

        return $row instanceof ProductInventory
            ? $this->wholeUnits($row->getQuantity()) + $this->wholeUnits($row->getReceivedQuantity())
            : 0;
    }

    protected function salesHold(): int
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
        ]);

        if (!$row instanceof ProductInventory) {
            return 0;
        }

        $this->em->refresh($row);

        return $this->wholeUnits($row->getSalesHoldQuantity());
    }

    protected function inBin(WarehouseLocation $bin, ?InventoryLot $lot = null): int
    {
        return $this->wholeUnits($this->details->findExisting(
            $this->product,
            $this->warehouse,
            $bin,
            $lot,
            null,
            InventoryDetail::STATUS_AVAILABLE,
        )?->getQuantity() ?? '0');
    }

    /** An approved order for $quantity of the fixture product, holding sales_hold on all of it. */
    protected function approvedOrder(int $quantity, string $number = 'SO-1'): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber($number)
            ->setFulfillmentRegion($this->region->getName());

        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($this->product)
                ->setName($this->product->getName())
                ->setSku($this->product->getSku())
                ->setQuantity((string) $quantity)
        );

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }

    /** Invoices the whole order and issues it — which is what drops its sales hold to zero. */
    protected function invoiceInFull(SalesOrder $order): Invoice
    {
        $invoice = new Invoice();
        $order->addInvoice($invoice);
        $invoice
            ->setCompany($this->company)
            ->setDocumentNumber('INV-' . $order->getOrderNumber())
            ->setFulfillmentRegion($order->getFulfillmentRegion());

        foreach ($order->getLines() as $line) {
            $invoice->addLine(
                (new InvoiceLine())
                    ->setSalesOrderLine($line)
                    ->setProduct($line->getProduct())
                    ->setName($line->getName())
                    ->setQuantity($line->getQuantity())
            );
        }

        $invoice->issue(DocumentActor::system());
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }
}
