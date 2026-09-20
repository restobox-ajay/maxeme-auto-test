<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\FulfillmentRegion;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Repository\CartRepository;
use App\Service\CartService;
use App\Service\Inventory\BackorderSplitResolver;
use App\Tests\DoctrineIntegrationTestCase;
use App\Twig\CartExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\TwigFunction;

final class CartExtensionTest extends DoctrineIntegrationTestCase
{
    private FulfillmentRegion $region;
    private ProductCore $productA;
    private ProductCore $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = (new FulfillmentRegion())->setName('Region A');
        $this->em->persist($this->region);
        // Stock lives in the warehouse serving the region, not in the region (#546).
        $warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');

        $this->productA = (new ProductCore())->setSku('SKU-1')->setName('Product 1')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($this->productA);

        $this->productB = (new ProductCore())->setSku('SKU-2')->setName('Product 2')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($this->productB);

        $this->em->flush();

        foreach ([$this->productA, $this->productB] as $product) {
            $inventory = (new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity(50);
            $this->em->persist($inventory);
        }
        $this->em->flush();
    }

    private function makeCartExtension(string $sessionId): CartExtension
    {
        $requestStack = new RequestStack();
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $session->setId($sessionId);
        $request->setSession($session);
        $requestStack->push($request);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var CartRepository $cartRepository */
        $cartRepository = self::getContainer()->get(CartRepository::class);

        return new CartExtension(new CartService($requestStack, $em, $cartRepository, self::getContainer()->get(\Symfony\Bundle\SecurityBundle\Security::class), self::getContainer()->get(WarehouseFulfillmentRegionService::class), self::getContainer()->get(BackorderSplitResolver::class)));
    }

    public function testGetCartCountReturnsZeroForEmptyCart(): void
    {
        $extension = $this->makeCartExtension('session-empty');

        self::assertSame(0, $extension->getCartCount());
    }

    public function testGetCartCountSumsQuantitiesAcrossItems(): void
    {
        $requestStack = new RequestStack();
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $session->setId('session-sum');
        $request->setSession($session);
        $requestStack->push($request);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var CartRepository $cartRepository */
        $cartRepository = self::getContainer()->get(CartRepository::class);
        $cartService = new CartService($requestStack, $em, $cartRepository, self::getContainer()->get(\Symfony\Bundle\SecurityBundle\Security::class), self::getContainer()->get(WarehouseFulfillmentRegionService::class), self::getContainer()->get(BackorderSplitResolver::class));
        $cartService->add('SKU-1', 3, $this->region);
        $cartService->add('SKU-2', 4, $this->region);

        $extension = new CartExtension($cartService);

        self::assertSame(7, $extension->getCartCount());
    }

    public function testGetFunctionsRegistersTheCartFunctions(): void
    {
        $extension = $this->makeCartExtension('session-functions');

        $functions = $extension->getFunctions();

        self::assertCount(2, $functions);
        self::assertContainsOnlyInstancesOf(TwigFunction::class, $functions);
        self::assertSame(
            ['customer_cart_count', 'customer_cart_items'],
            array_map(static fn (TwigFunction $f): string => $f->getName(), $functions)
        );
        self::assertSame([$extension, 'getCartCount'], $functions[0]->getCallable());
        self::assertSame([$extension, 'getCartItems'], $functions[1]->getCallable());
    }

    public function testGetCartItemsIsEmptyForAnEmptyCart(): void
    {
        self::assertSame([], $this->makeCartExtension('session-items-empty')->getCartItems());
    }

    public function testGetCartItemsReturnsTheCartKeyedBySku(): void
    {
        $requestStack = new RequestStack();
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $session->setId('session-items');
        $request->setSession($session);
        $requestStack->push($request);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var CartRepository $cartRepository */
        $cartRepository = self::getContainer()->get(CartRepository::class);
        $cartService = new CartService($requestStack, $em, $cartRepository, self::getContainer()->get(\Symfony\Bundle\SecurityBundle\Security::class), self::getContainer()->get(WarehouseFulfillmentRegionService::class), self::getContainer()->get(BackorderSplitResolver::class));
        $cartService->add('SKU-1', 3, $this->region);
        $cartService->add('SKU-2', 4, $this->region);

        self::assertSame(['SKU-1' => '3.0000', 'SKU-2' => '4.0000'], (new CartExtension($cartService))->getCartItems());
    }
}
