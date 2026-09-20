<?php

declare(strict_types=1);

namespace CartHoldBundle\Tests\Service;

use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Repository\CartRepository;
use App\Service\CartService;
use App\Service\Inventory\BackorderSplitResolver;
use App\Tests\DoctrineIntegrationTestCase;
use CartHoldBundle\Entity\CartHold;
use CartHoldBundle\Service\CartHoldService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CartHoldServiceTest extends DoctrineIntegrationTestCase
{
    private CartHoldService $cartHoldService;
    private FulfillmentRegion $region;
    private Warehouse $warehouse;
    private ProductCore $product;
    private ProductInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cartHoldService = self::getContainer()->get(CartHoldService::class);

        $this->region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($this->region);

        // Cart items name a REGION; the hold bucket they feed belongs to the warehouse serving
        // it (#546).
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');

        $this->product = (new ProductCore())->setSku('SKU-1')->setName('Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($this->product);

        $this->inventory = (new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(100);
        $this->em->persist($this->inventory);

        $this->em->flush();
    }

    private function refreshInventory(): ProductInventory
    {
        $this->em->refresh($this->inventory);

        return $this->inventory;
    }

    /** @param array<string, int> $items */
    private function makeCartService(string $sessionId, array $items = []): CartService
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

        $cartService = new CartService($requestStack, $em, $cartRepository, self::getContainer()->get(\Symfony\Bundle\SecurityBundle\Security::class), self::getContainer()->get(WarehouseFulfillmentRegionService::class), self::getContainer()->get(BackorderSplitResolver::class));
        foreach ($items as $sku => $qty) {
            $cartService->add($sku, $qty, $this->region);
        }

        return $cartService;
    }

    public function testSyncCreatesHoldAndReservesInventory(): void
    {
        $cartService = $this->makeCartService('session-a', ['SKU-1' => 3]);

        $this->cartHoldService->syncForCurrentCart($cartService->getCart(), $this->region, 'session-a');

        self::assertSame('3.0000', $this->refreshInventory()->getCartHoldQuantity());

        $rows = $this->em->getRepository(CartHold::class)->findBySessionId('session-a');
        self::assertCount(1, $rows);
        self::assertSame('3.0000', $rows[0]->getQuantity());
        self::assertGreaterThan(new \DateTimeImmutable(), $rows[0]->getExpiresAt());
    }

    public function testSyncTwiceWithSameQuantityDoesNotDoubleCount(): void
    {
        $cartService = $this->makeCartService('session-a', ['SKU-1' => 3]);

        $this->cartHoldService->syncForCurrentCart($cartService->getCart(), $this->region, 'session-a');
        $this->cartHoldService->syncForCurrentCart($cartService->getCart(), $this->region, 'session-a');

        self::assertSame('3.0000', $this->refreshInventory()->getCartHoldQuantity());
    }

    public function testSyncRemovingItemReleasesItsHold(): void
    {
        $cartService = $this->makeCartService('session-a', ['SKU-1' => 3]);
        $this->cartHoldService->syncForCurrentCart($cartService->getCart(), $this->region, 'session-a');

        $cartService->remove('SKU-1');
        $this->cartHoldService->syncForCurrentCart($cartService->getCart(), $this->region, 'session-a');

        self::assertSame('0.0000', $this->refreshInventory()->getCartHoldQuantity());
        self::assertCount(0, $this->em->getRepository(CartHold::class)->findBySessionId('session-a'));
    }

    public function testReleaseExpiredOnlyReleasesExpiredRows(): void
    {
        $cartService = $this->makeCartService('session-a', ['SKU-1' => 3]);
        $this->cartHoldService->syncForCurrentCart($cartService->getCart(), $this->region, 'session-a');

        $rows = $this->em->getRepository(CartHold::class)->findBySessionId('session-a');
        self::assertCount(1, $rows);
        $row = $rows[0];
        $row->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $this->cartHoldService->releaseExpired();

        self::assertSame('0.0000', $this->refreshInventory()->getCartHoldQuantity());
        self::assertCount(0, $this->em->getRepository(CartHold::class)->findBySessionId('session-a'));
    }

    public function testReleaseExpiredClearsCartHoldExpiresAtCache(): void
    {
        $cartService = $this->makeCartService('session-a', ['SKU-1' => 3]);
        $this->cartHoldService->syncForCurrentCart($cartService->getCart(), $this->region, 'session-a');

        self::assertNotNull($cartService->getCart()?->getHoldExpiresAt());

        $rows = $this->em->getRepository(CartHold::class)->findBySessionId('session-a');
        $rows[0]->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $this->cartHoldService->releaseExpired();

        // Catalog/product pages read Cart::$holdExpiresAt straight off the cart, with no call
        // into this bundle to refresh it. If this cache column were left pointing at the
        // already-past timestamp, the cart-hold banner would keep rendering there and its JS
        // countdown would reload the page immediately, forever.
        $this->em->refresh($cartService->getCart());
        self::assertNull($cartService->getCart()?->getHoldExpiresAt());
    }

    public function testReconcileExpiredForSessionEvictsStaleCartItems(): void
    {
        // No CartHold row exists for this session at all — simulates a hold that already
        // expired and was swept (by this session's own request or another one's).
        $cartService = $this->makeCartService('session-b', ['SKU-1' => 2]);

        $evicted = $this->cartHoldService->reconcileExpiredForSession($cartService, 'session-b');

        self::assertSame(['SKU-1'], $evicted);
        self::assertSame([], $cartService->getItems());
    }

    public function testReconcileExpiredForSessionKeepsItemsWithAnActiveHold(): void
    {
        $cartService = $this->makeCartService('session-c', ['SKU-1' => 2]);
        $this->cartHoldService->syncForCurrentCart($cartService->getCart(), $this->region, 'session-c');

        $evicted = $this->cartHoldService->reconcileExpiredForSession($cartService, 'session-c');

        self::assertSame([], $evicted);
        self::assertSame(['SKU-1' => '2.0000'], $cartService->getItems());
    }
}
