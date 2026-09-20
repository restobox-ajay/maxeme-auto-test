<?php

declare(strict_types=1);

namespace WooCommerceBundle\Tests\Service;

use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Service\WooCommerceConnectionPriceListResolver;

final class WooCommerceConnectionPriceListResolverTest extends DoctrineIntegrationTestCase
{
    private WooCommerceConnectionPriceListResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new WooCommerceConnectionPriceListResolver(
            self::getContainer()->get(WarehouseFulfillmentRegionService::class),
        );
    }

    private function connection(): WooCommerceConnection
    {
        $connection = (new WooCommerceConnection())
            ->setName('Store')->setSlug('store-' . uniqid())->setStoreUrl('https://x.example.com')
            ->setConsumerKey('ck')->setConsumerSecret('cs')->setWebhookSecret('whsec')->setActive(true);
        $this->em->persist($connection);

        return $connection;
    }

    public function testTheAdminsExplicitChoiceWins(): void
    {
        $picked = (new PriceList())->setName('Picked-' . uniqid());
        $this->em->persist($picked);
        $connection = $this->connection()->setDefaultPriceList($picked);
        $this->em->flush();

        self::assertSame($picked, $this->resolver->resolve($connection));
    }

    public function testWithNoChoiceItFallsBackToTheRegionsGuestPriceList(): void
    {
        $guest = (new PriceList())->setName('Guest-' . uniqid());
        $this->em->persist($guest);
        $region = (new FulfillmentRegion())->setName('West-' . uniqid())->setStatus('Active')->setGuestPriceList($guest);
        $this->em->persist($region);
        $warehouse = (new Warehouse())->setName('DC')->setStatus('Active');
        $this->em->persist($warehouse);
        $this->em->flush();
        self::getContainer()->get(WarehouseFulfillmentRegionService::class)->pair($warehouse, $region);
        $this->em->flush();

        $connection = $this->connection()->setDefaultWarehouse($warehouse);
        $this->em->flush();

        self::assertSame($guest, $this->resolver->resolve($connection));
    }

    public function testWithNoChoiceAndNoWarehouseItResolvesToNull(): void
    {
        $connection = $this->connection();
        $this->em->flush();

        self::assertNull($this->resolver->resolve($connection));
    }
}
