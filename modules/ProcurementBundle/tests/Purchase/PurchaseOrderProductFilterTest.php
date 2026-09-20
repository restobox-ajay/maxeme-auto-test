<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Purchase;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use App\Entity\Warehouse;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Repository\PurchaseOrderRepository;

/**
 * Product Inventory Hub build order step 1: `admin_bundle_procurement_purchase_orders` had no
 * product filter at all before this. Straight addition on `PurchaseOrderRepository::search()`, an
 * exact id match via `p.lines`/`PurchaseOrderLine.product`.
 */
final class PurchaseOrderProductFilterTest extends DoctrineIntegrationTestCase
{
    private PurchaseOrderRepository $repository;
    private Vendor $vendor;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var PurchaseOrderRepository $repo */
        $repo = $this->em->getRepository(PurchaseOrder::class);
        $this->repository = $repo;

        $region = (new FulfillmentRegion())->setName('PO Filter Region');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->vendor = (new Vendor())->setName('PO Filter Vendor')->setCurrency('CAD');
        $this->em->persist($this->vendor);
        $this->em->flush();
    }

    public function testFiltersPurchaseOrdersDownToTheOneProduct(): void
    {
        $matching = $this->orderFor($this->product('PO-MATCH-SKU'));
        $this->orderFor($this->product('PO-OTHER-SKU'));

        $productId = (string) $matching->getLines()->first()->getProduct()->getId();

        $result = $this->repository->search(['product' => $productId], 1, 100, 'number', 'desc');

        self::assertSame(1, $result['total']);
        self::assertSame($matching->getPoNumber(), $result['rows'][0]->getPoNumber());
    }

    public function testAnEmptyProductFilterMatchesEverything(): void
    {
        $this->orderFor($this->product('PO-A-SKU'));
        $this->orderFor($this->product('PO-B-SKU'));

        $result = $this->repository->search(['product' => ''], 1, 100, 'number', 'desc');

        self::assertSame(2, $result['total']);
    }

    private function product(string $sku): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Buyable Thing')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function orderFor(ProductCore $product): PurchaseOrder
    {
        $order = (new PurchaseOrder())
            ->setPoNumber('PO-FILTER-' . uniqid())
            ->setVendor($this->vendor)
            ->setWarehouse($this->warehouse)
            ->setCurrency('CAD');
        $this->em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setQuantityOrdered('1.00')
            ->setUnitCost('1.00')
            ->setSubtotal('1.00');
        $order->addLine($line);
        $this->em->persist($line);
        $this->em->flush();

        return $order;
    }
}
