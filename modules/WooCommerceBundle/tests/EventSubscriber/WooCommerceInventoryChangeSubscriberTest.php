<?php

declare(strict_types=1);

namespace WooCommerceBundle\Tests\EventSubscriber;

use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Entity\FulfillmentRegion;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommercePushQueueItem;
use WooCommerceBundle\Entity\WooCommerceProductMapping;
use WooCommerceBundle\Repository\WooCommercePushQueueItemRepository;

/**
 * WooCommerceInventoryChangeSubscriber's own onFlush hook (#739) — exercised through real
 * ProductInventory flushes rather than called directly, since its whole job is reading the
 * UnitOfWork's changeset, which only exists mid-flush.
 */
final class WooCommerceInventoryChangeSubscriberTest extends DoctrineIntegrationTestCase
{
    private Warehouse $warehouse;
    private WooCommerceConnection $connection;
    private ProductCore $product;

    protected function setUp(): void
    {
        parent::setUp();

        $region = (new FulfillmentRegion())->setName('BC Lower Mainland')->setStatus('Active');
        $this->em->persist($region);

        $this->warehouse = (new Warehouse())->setName('Main DC')->setStatus('Active');
        $this->em->persist($this->warehouse);
        $this->em->flush();

        self::getContainer()->get(WarehouseFulfillmentRegionService::class)->pair($this->warehouse, $region);
        $this->em->flush();

        $this->connection = (new WooCommerceConnection())
            ->setName('Main Store')->setSlug('main-store')->setStoreUrl('https://x.example.com')
            ->setConsumerKey('ck')->setConsumerSecret('cs')->setWebhookSecret('whsec')
            ->setActive(true)->setDefaultWarehouse($this->warehouse);
        $this->em->persist($this->connection);

        $this->product = (new ProductCore())->setSku('WIDGET-1')->setName('Widget');
        $this->em->persist($this->product);
        $this->em->flush();
    }

    private function queueItem(): ?WooCommercePushQueueItem
    {
        return self::getContainer()->get(WooCommercePushQueueItemRepository::class)
            ->findOneForConnectionAndProduct($this->connection, $this->product);
    }

    public function testAStockChangeOnAMappedProductQueuesAPushForItsConnection(): void
    {
        $mapping = (new WooCommerceProductMapping())
            ->setConnection($this->connection)->setWooSku('WIDGET-1')->setProduct($this->product)
            ->setWooProductId(900);
        $this->em->persist($mapping);
        $this->em->flush();

        $inventory = (new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(10);
        $this->em->persist($inventory);
        $this->em->flush();

        $item = $this->queueItem();
        self::assertInstanceOf(WooCommercePushQueueItem::class, $item);
        self::assertTrue($item->isPending());
    }

    public function testAStockChangeOnAnUnmappedProductQueuesNothing(): void
    {
        // No WooCommerceProductMapping written for this product/connection — Woo has never heard of it.
        $inventory = (new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(10);
        $this->em->persist($inventory);
        $this->em->flush();

        self::assertNull($this->queueItem());
    }

    public function testAStockChangeOnAnInactiveConnectionQueuesNothing(): void
    {
        $mapping = (new WooCommerceProductMapping())
            ->setConnection($this->connection)->setWooSku('WIDGET-1')->setProduct($this->product)
            ->setWooProductId(900);
        $this->em->persist($mapping);
        $this->connection->setActive(false);
        $this->em->flush();

        $inventory = (new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(10);
        $this->em->persist($inventory);
        $this->em->flush();

        self::assertNull($this->queueItem());
    }

    /** Re-dirtying an already-Pending row updates it in place rather than duplicating. */
    public function testRepeatedStockChangesCollapseIntoOneRow(): void
    {
        $mapping = (new WooCommerceProductMapping())
            ->setConnection($this->connection)->setWooSku('WIDGET-1')->setProduct($this->product)
            ->setWooProductId(900);
        $this->em->persist($mapping);
        $this->em->flush();

        $inventory = (new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(10);
        $this->em->persist($inventory);
        $this->em->flush();

        $inventory->setQuantity(7);
        $this->em->flush();

        $items = self::getContainer()->get(WooCommercePushQueueItemRepository::class)->findAllPending();
        self::assertCount(1, $items);
    }

    /** A previously-Pushed row goes back to Pending on a fresh change, so a later drain picks it up again. */
    public function testAFreshChangeReopensAnAlreadyPushedRow(): void
    {
        $mapping = (new WooCommerceProductMapping())
            ->setConnection($this->connection)->setWooSku('WIDGET-1')->setProduct($this->product)
            ->setWooProductId(900);
        $this->em->persist($mapping);
        $this->em->flush();

        $inventory = (new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(10);
        $this->em->persist($inventory);
        $this->em->flush();

        $item = $this->queueItem();
        $item->markPushed();
        $this->em->flush();

        $inventory->setQuantity(3);
        $this->em->flush();

        self::assertTrue($this->queueItem()->isPending());
    }
}
