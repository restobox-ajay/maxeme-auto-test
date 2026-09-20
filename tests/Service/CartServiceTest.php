<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cart;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Repository\CartRepository;
use App\Service\CartService;
use App\Service\Inventory\BackorderSplitResolver;
use App\Tests\DoctrineIntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class CartServiceTest extends DoctrineIntegrationTestCase
{
    private FulfillmentRegion $regionA;
    private FulfillmentRegion $regionB;
    private ProductCore $product;
    private WarehouseFulfillmentRegionService $warehouses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->regionA = (new FulfillmentRegion())->setName('Region A');
        $this->em->persist($this->regionA);

        $this->regionB = (new FulfillmentRegion())->setName('Region B');
        $this->em->persist($this->regionB);

        // Stock lives in a warehouse, not in a region (#546). Every region gets the one that
        // serves it, which is what creating a region through the admin does too.
        $this->warehouses = self::getContainer()->get(WarehouseFulfillmentRegionService::class);
        $this->warehouses->createWarehouseForRegion($this->regionA, 'BC', 'CA');
        $this->warehouses->createWarehouseForRegion($this->regionB, 'BC', 'CA');

        $this->product = (new ProductCore())->setSku('SKU-1')->setName('Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($this->product);

        $this->em->flush();
    }

    private function setAvailable(FulfillmentRegion $region, int $quantity): void
    {
        $warehouse = $this->warehouses->warehouseForRegion($region);
        $inventory = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product,
            'warehouse' => $warehouse,
        ]) ?? (new ProductInventory())->setProduct($this->product)->setWarehouse($warehouse);
        $inventory->setQuantity($quantity);
        $this->em->persist($inventory);
        $this->em->flush();
    }

    private function makeCartService(string $sessionId): CartService
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

        return new CartService($requestStack, $em, $cartRepository, self::getContainer()->get(\Symfony\Bundle\SecurityBundle\Security::class), self::getContainer()->get(WarehouseFulfillmentRegionService::class), self::getContainer()->get(BackorderSplitResolver::class));
    }

    public function testAddGetRemoveClearRoundTrip(): void
    {
        $this->setAvailable($this->regionA, 50);
        $cartService = $this->makeCartService('session-1');

        $cartService->add('SKU-1', 5, $this->regionA);
        self::assertSame(['SKU-1' => '5.0000'], $cartService->getItems());
        self::assertFalse($cartService->isEmpty());

        $cartService->add('SKU-1', 2, $this->regionA);
        self::assertSame(['SKU-1' => '7.0000'], $cartService->getItems());

        $cartService->remove('SKU-1');
        self::assertSame([], $cartService->getItems());
        self::assertTrue($cartService->isEmpty());

        $cartService->add('SKU-1', 3, $this->regionA);
        $cartService->clear();
        self::assertTrue($cartService->isEmpty());
    }

    public function testAddStampsTheLoggedInCustomersCompanyOnTheCart(): void
    {
        $company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($company);
        $customer = (new CustomerUser())->setEmail('cart-company@example.test')->setPassword('x')->setCompany($company);
        $this->em->persist($customer);
        $this->em->flush();

        self::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($customer, 'customer', $customer->getRoles())
        );

        $this->setAvailable($this->regionA, 10);
        $cartService = $this->makeCartService('session-with-company');
        $cartService->add('SKU-1', 1, $this->regionA);

        $cart = $this->em->getRepository(Cart::class)->findOneBy(['sessionId' => 'session-with-company']);
        self::assertNotNull($cart);
        self::assertSame($company->getId(), $cart->getCompany()?->getId(), 'the cart should carry the logged-in customer\'s company');
    }

    public function testAddCapsAtAvailableQuantityWithMessage(): void
    {
        $this->setAvailable($this->regionA, 4);
        $cartService = $this->makeCartService('session-1');

        $message = $cartService->add('SKU-1', 10, $this->regionA);

        self::assertNotNull($message);
        self::assertSame(['SKU-1' => '4.0000'], $cartService->getItems());
    }

    public function testAddWithZeroAvailabilityAddsNothing(): void
    {
        $this->setAvailable($this->regionA, 0);
        $cartService = $this->makeCartService('session-1');

        $message = $cartService->add('SKU-1', 5, $this->regionA);

        self::assertNotNull($message);
        self::assertSame([], $cartService->getItems());
    }

    public function testSetQuantityCapsAtAvailableQuantityWithMessage(): void
    {
        $this->setAvailable($this->regionA, 3);
        $cartService = $this->makeCartService('session-1');
        $cartService->add('SKU-1', 2, $this->regionA);

        $message = $cartService->setQuantity('SKU-1', 9, $this->regionA);

        self::assertNotNull($message);
        self::assertSame(['SKU-1' => '3.0000'], $cartService->getItems());
    }

    public function testReconcileAgainstRegionCapsWhenSwitchingToLowerAvailability(): void
    {
        $this->setAvailable($this->regionA, 20);
        $this->setAvailable($this->regionB, 2);
        $cartService = $this->makeCartService('session-1');
        $cartService->add('SKU-1', 10, $this->regionA);

        $messages = $cartService->reconcileAgainstRegion($this->regionB);

        self::assertCount(1, $messages);
        self::assertSame(['SKU-1' => '2.0000'], $cartService->getItems());
    }

    public function testReconcileAgainstRegionRemovesItemUnavailableInNewRegion(): void
    {
        $this->setAvailable($this->regionA, 20);
        // No ProductInventory row at all for regionB — genuinely unavailable there.
        $cartService = $this->makeCartService('session-1');
        $cartService->add('SKU-1', 10, $this->regionA);

        $messages = $cartService->reconcileAgainstRegion($this->regionB);

        self::assertCount(1, $messages);
        self::assertSame([], $cartService->getItems());
    }

    public function testReconcileAgainstRegionIsANoOpWhenStillWithinAvailability(): void
    {
        $this->setAvailable($this->regionA, 20);
        $this->setAvailable($this->regionB, 20);
        $cartService = $this->makeCartService('session-1');
        $cartService->add('SKU-1', 10, $this->regionA);

        $messages = $cartService->reconcileAgainstRegion($this->regionB);

        self::assertSame([], $messages);
        self::assertSame(['SKU-1' => '10.0000'], $cartService->getItems());
    }
}
