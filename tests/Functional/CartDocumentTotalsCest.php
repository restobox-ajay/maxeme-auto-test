<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AbstractDocumentAddress;
use App\Entity\AdminUser;
use App\Entity\Cart;
use App\Entity\CartAddress;
use App\Entity\CartItem;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\ProductPricing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A cart is a document now, so the money it works out has somewhere to live (issue #165 step 5).
 *
 * Admin/CartController could already list carts and count their items but could not show a single
 * figure, because every number the cart page computed went straight into template variables and was
 * discarded. These cover the round trip over real HTTP: the customer's cart page writes the totals
 * back, and admin reads them.
 */
final class CartDocumentTotalsCest
{
    private FulfillmentRegion $region;
    private Company $company;
    private ProductCore $product;

    public function _before(FunctionalTester $I): void
    {
        $this->region = (new FulfillmentRegion())->setName('Totals Warehouse')->setStatus('Active');
        $I->haveInRepository($this->region);

        $priceList = (new PriceList())->setName('Totals list');
        $I->haveInRepository($priceList);

        $this->company = (new Company())->setName('Totals Co')->setCode('TOTALS-' . uniqid());
        $I->haveInRepository($this->company);

        $I->haveInRepository(
            (new CompanyAddress())
                ->setCompany($this->company)
                ->setLabel('Main')
                ->setAddressLine1('1 Dock Road')
                ->setCity('Vancouver')
                ->setProvince('BC')
                ->setCountry('CA')
                ->setPostalCode('V5K0A1')
                ->setIsDefaultBilling(true)
                ->setIsDefaultShipping(true)
        );

        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($this->company)
                ->setFulfillmentRegion($this->region)
                ->setPriceList($priceList)
                ->setStatus('Active')
        );

        $this->product = (new ProductCore())->setSku('TOTALS-SKU')->setName('Totals Product')->setDefaultPrice('100.00')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->product);

        $I->haveInRepository(
            (new ProductPricing())
                ->setProduct($this->product)
                ->setPriceList($priceList)
                ->setRuleType('Number')
                ->setRuleValue('25.00')
                ->setPrice('0.00')
        );

        $I->haveInRepository(
            (new ProductInventory())->setProduct($this->product)->setWarehouse($I->grabService(WarehouseFulfillmentRegionService::class)->warehouseForRegionNameOrCreate($this->region->getName(), 'BC', 'CA'))->setQuantity(50)
        );
    }

    private function loginAsCustomer(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())->setEmail('cart-totals-functional-test@example.test')
            ->setCompany($this->company);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');
    }

    private function addToCart(FunctionalTester $I, int $qty): void
    {
        $I->amOnPage('/product/detail/' . $this->product->getId());
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => $qty,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    public function viewingTheCartWritesItsTotalsBackOntoTheDocument(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $this->addToCart($I, 4);

        $I->amOnPage('/cart');
        $I->seeResponseCodeIs(200);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->getRepository(Cart::class)->findOneBy(['company' => $this->company]);

        $I->assertEquals(100.0, (float) $reloaded->getSubtotal(), '4 x $25 from the price list');
        $I->assertEquals(100.0, (float) $reloaded->getTotal());
        $I->assertSame('Totals Warehouse', $reloaded->getFulfillmentRegion());
    }

    public function loggingInLinksTheCartToTheCompanyAddressWithoutFreezingIt(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $this->addToCart($I, 1);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $cart = $em->getRepository(Cart::class)->findOneBy(['company' => $this->company]);

        $shipping = $cart->getShippingAddress();
        $I->assertNotNull($shipping, 'the cart names the address it is priced against');
        $I->assertTrue($shipping->isLinkOnly(), 'a cart links, it does not freeze — conversion freezes');
        $I->assertSame(AbstractDocumentAddress::TYPE_SHIPPING, $shipping->getType());

        // Resolved live through the link, which is what gives a cart a province at all.
        $I->assertSame('Vancouver', $cart->getEffectiveShippingAddress()?->getCity());
        $I->assertSame('BC', $cart->getProvince());
    }

    /**
     * The cart and checkout pages hand a shipping calculator a document since issue #165 step 9, and
     * because neither page prices the cart's own lines that document is built transient from the
     * priced rows (AbstractCustomerController::shippingDocumentFor()).
     *
     * It must never reach the database. FeeRepository::ensureBySlug() flushes partway through a
     * calculation and Cart's items and addresses cascade persist, so one stray persist() would leave
     * a second, phantom cart behind on every page view — and the customer's real cart is found by
     * session id, so the duplicate would be invisible until someone counted rows. This counts them.
     */
    public function renderingTheCartAndCheckoutPagesDoesNotLeaveASecondCartBehind(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $this->addToCart($I, 4);

        $em = $I->grabService(EntityManagerInterface::class);
        $count = static function () use ($em): array {
            $em->clear();
            $one = static fn (string $class): int => (int) $em->getRepository($class)
                ->createQueryBuilder('r')->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

            return ['carts' => $one(Cart::class), 'items' => $one(CartItem::class), 'addresses' => $one(CartAddress::class)];
        };

        $before = $count();
        $I->assertSame(1, $before['carts'], 'the customer has exactly one cart to start with');

        $I->amOnPage('/cart');
        $I->seeResponseCodeIs(200);
        $I->amOnPage('/checkout');
        $I->seeResponseCodeIsSuccessful();
        $I->amOnPage('/cart');
        $I->seeResponseCodeIs(200);

        $I->assertSame($before, $count(), 'a page render wrote a transient shipping document to the database');
    }

    public function theAdminCartPagesShowTheMoney(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $this->addToCart($I, 4);
        $I->amOnPage('/cart');
        $I->seeResponseCodeIs(200);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('cart-totals-admin@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');

        $cartId = $I->grabFromRepository(Cart::class, 'id', ['company' => $this->company]);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/carts');
        $I->seeResponseCodeIsSuccessful();
        $I->see('$100.00');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/carts/' . $cartId);
        $I->seeResponseCodeIsSuccessful();
        // The header figures are as of the customer's last render; the per-line ones are resolved
        // here and now against the cart's own company and region.
        $I->see('$25.00');
        $I->see('$100.00');
    }
}
