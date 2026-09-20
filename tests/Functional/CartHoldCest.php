<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\CartItem;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use CartHoldBundle\Entity\CartHold;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The most important functional test for this feature: exercises the full HTTP-level path —
 * real routing, real CartController, real CartHoldSweepSubscriber, real CartHoldService —
 * that PHPUnit's DoctrineIntegrationTestCase tests deliberately stop short of, since those
 * call CartHoldService's methods directly rather than going through /cart/add itself.
 */
final class CartHoldCest
{
    private FulfillmentRegion $region;
    private Warehouse $warehouse;
    private ProductCore $product;
    private ProductInventory $inventory;

    public function _before(FunctionalTester $I): void
    {
        $this->region = (new FulfillmentRegion())->setName('East Warehouse')->setStatus('Active');
        $I->haveInRepository($this->region);

        // Cart items name a REGION; the stock and the hold bucket belong to the warehouse
        // serving it (#546).
        $this->warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');
        $I->grabService(EntityManagerInterface::class)->flush();

        $company = (new Company())->setName('Acme Co')->setCode('ACME-' . uniqid());
        $I->haveInRepository($company);

        $companyRegion = (new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($this->region)
            ->setStatus('Active');
        $I->haveInRepository($companyRegion);

        $this->product = (new ProductCore())->setSku('CART-FUNC-SKU')->setName('Cart Hold Functional Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->product);

        $this->inventory = (new ProductInventory())
            ->setProduct($this->product)
            ->setWarehouse($this->warehouse)
            ->setQuantity(20);
        $I->haveInRepository($this->inventory);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())->setEmail('customer-functional-test@example.test')->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');
    }

    public function addingToCartReservesInventoryAndShowsTheHoldBanner(FunctionalTester $I): void
    {
        // The cart page's own `_token` field only renders once the cart has items (see
        // templates/customer/cart/index.html.twig), so grab the same 'customer_cart' CSRF
        // token from the product detail page instead — it always renders one, since it's
        // the "add to cart" entry point for a still-empty cart.
        $I->amOnPage('/product/detail/' . $this->product->getId());
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 5,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(CartItem::class, [
            'product' => $this->product,
            'fulfillmentRegion' => $this->region,
            'quantity' => 5,
        ]);

        /** @var CartItem $cartItem */
        $cartItem = $I->grabEntityFromRepository(CartItem::class, [
            'product' => $this->product,
            'fulfillmentRegion' => $this->region,
        ]);
        $I->seeInRepository(CartHold::class, ['cartItem' => $cartItem]);

        // grabEntityFromRepository (not $em->refresh()) since the object built in _before()
        // belongs to a different EntityManager instance than the one the HTTP request used —
        // it's never "managed" from this process's point of view, only its identity (the
        // product/region criteria) is reusable.
        /** @var ProductInventory $inventory */
        $inventory = $I->grabEntityFromRepository(ProductInventory::class, [
            'product' => $this->product,
            'warehouse' => $this->warehouse,
        ]);
        $I->assertSame('5.0000', $inventory->getCartHoldQuantity());
        $I->assertSame('15.0000', $inventory->getAvailableQuantity());

        // The cart page itself should now show the live countdown banner.
        $I->amOnPage('/cart');
        $I->seeElement('.cart-hold-banner');
        $I->see('held for you');
    }

    public function removingTheOnlyItemClearsTheHoldBanner(FunctionalTester $I): void
    {
        $I->amOnPage('/product/detail/' . $this->product->getId());
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 5,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/cart');
        $I->seeElement('.cart-hold-banner');

        $cartToken = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/cart/remove', [
            '_token' => $cartToken,
            'sku' => $this->product->getSku(),
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->dontSeeInRepository(CartItem::class, [
            'product' => $this->product,
            'fulfillmentRegion' => $this->region,
        ]);

        // The banner's own countdown must not still be ticking down for an empty cart — that
        // combination (empty cart + a still-live hold banner) is exactly what was reported: the
        // banner kept counting down after the last item was removed.
        $I->amOnPage('/cart');
        $I->dontSeeElement('.cart-hold-banner');
    }

    public function anExpiredHoldStopsShowingTheBannerOnCatalogAndProductPages(FunctionalTester $I): void
    {
        $I->amOnPage('/product/detail/' . $this->product->getId());
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 5,
        ]);
        $I->seeResponseCodeIsSuccessful();

        // grabEntityFromRepository() reads/writes through the Doctrine module's own
        // EntityManager instance, a separate one from the container's — mutating an entity it
        // returned and flushing the *container's* EntityManager (what every controller actually
        // uses) would silently no-op. Fetch and mutate through that same container EM instead so
        // the change is actually the one the next request sees.
        /** @var EntityManagerInterface $em */
        $em = $I->grabService(EntityManagerInterface::class);

        /** @var CartItem $cartItem */
        $cartItem = $em->getRepository(CartItem::class)->findOneBy([
            'product' => $this->product,
            'fulfillmentRegion' => $this->region,
        ]);
        /** @var CartHold $hold */
        $hold = $em->getRepository(CartHold::class)->findOneBy(['cartItem' => $cartItem]);
        $hold->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $em->flush();

        // CatalogController/CartHoldSweepSubscriber never call CartHoldService::syncForCurrentCart()
        // — they rely entirely on the sweep to keep Cart::$holdExpiresAt current. This is exactly
        // the scenario originally reported: after the hold's timer ran out, the catalog and
        // product-detail pages kept reloading forever, because that cache column was never
        // cleared once the hold actually expired.
        $I->amOnPage('/product/detail/' . $this->product->getId());
        $I->dontSeeInRepository(CartHold::class, ['cartItem' => $cartItem]);
        $I->dontSeeElement('.cart-hold-banner');

        $I->amOnPage('/product/index');
        $I->dontSeeElement('.cart-hold-banner');

        // Load the product page a second time — if Cart::$holdExpiresAt were still stuck at the
        // stale past timestamp, the banner (and the reload loop it drives client-side) would
        // reappear on every subsequent view too, not just the first one right after expiry.
        $I->amOnPage('/product/detail/' . $this->product->getId());
        $I->dontSeeElement('.cart-hold-banner');
    }
}
