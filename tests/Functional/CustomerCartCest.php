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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers CartController's own routes (index/add/update/remove/clear) over real HTTP — the
 * other cart-related Cests (CartHoldCest, CustomerCheckoutCouponConfigCest) only ever exercise
 * these routes incidentally while testing unrelated bundle behavior.
 *
 * @group bundle-agnostic
 *
 * Tagged for the bundles-off run (#562): these assertions must hold identically with the
 * optional inventory bundles Inactive. If a change here can only pass with them Active, the
 * change has leaked out of its bundle.
 */
final class CustomerCartCest
{
    private FulfillmentRegion $region;
    private Warehouse $warehouse;
    private Company $company;
    private ProductCore $product;

    public function _before(FunctionalTester $I): void
    {
        $this->region = (new FulfillmentRegion())->setName('East Warehouse')->setStatus('Active');
        $I->haveInRepository($this->region);

        // Stock sits in the warehouse serving the region, not in the region (#546).
        $this->warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');
        $I->grabService(EntityManagerInterface::class)->flush();

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME-' . uniqid());
        $I->haveInRepository($this->company);

        $companyRegion = (new CompanyFulfillmentRegion())
            ->setCompany($this->company)
            ->setFulfillmentRegion($this->region)
            ->setStatus('Active');
        $I->haveInRepository($companyRegion);

        $this->product = (new ProductCore())->setSku('CART-CTRL-SKU')->setName('Cart Controller Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->product);

        $inventory = (new ProductInventory())
            ->setProduct($this->product)
            ->setWarehouse($this->warehouse)
            ->setQuantity(50);
        $I->haveInRepository($inventory);
    }

    private function loginAsCustomer(FunctionalTester $I): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())->setEmail('cart-controller-functional-test@example.test')->setCompany($this->company);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');

        return $customer;
    }

    private function grabCartTokenFromProductPage(FunctionalTester $I): string
    {
        $I->amOnPage('/product/detail/' . $this->product->getId());

        return (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    /** Issue #197: the catalog button used to read a localStorage cart nothing writes any more,
     *  so it showed a frozen quantity while the real cart was empty. It now carries the server
     *  cart's quantity, which means an empty cart must render a 0 on every row. */
    public function theCatalogButtonReportsZeroForAnEmptyCart(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);

        $I->amOnPage('/product/index');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            '0',
            $I->grabAttributeFrom('.js-product-card-cart-btn[data-sku="' . $this->product->getSku() . '"]', 'data-cart-qty')
        );
    }

    public function theCatalogAndDetailButtonsCarryTheServerCartQuantity(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);

        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 8,
        ]);

        $I->amOnPage('/product/index');
        $I->assertSame(
            '8',
            $I->grabAttributeFrom('.js-product-card-cart-btn[data-sku="' . $this->product->getSku() . '"]', 'data-cart-qty')
        );

        $I->amOnPage('/product/detail/' . $this->product->getId());
        $I->assertSame('8', $I->grabAttributeFrom('.customer-product-cart-btn', 'data-cart-qty'));

        // Clearing the cart must clear the button too — the old localStorage reading never did.
        $I->sendAjaxPostRequest('/cart/clear', ['_token' => $token]);

        $I->amOnPage('/product/index');
        $I->assertSame(
            '0',
            $I->grabAttributeFrom('.js-product-card-cart-btn[data-sku="' . $this->product->getSku() . '"]', 'data-cart-qty')
        );
    }

    /**
     * Every other test here POSTs to /cart/add directly, which covers the controller but leaves the
     * markup that feeds it — the modal's sku, its CSRF token, the name of its quantity field —
     * completely uncovered: breaking any of them keeps the whole suite green. That gap was found
     * while retiring the localStorage cart (#200), since the add-to-cart flow is exactly what the
     * deleted JS used to own.
     *
     * The modal is deliberately static markup posting to /cart/add with no JS involved (see
     * customer/catalog/_cart_qty_modal.html.twig), so the functional suite can submit the real
     * rendered form. Nothing is overridden but the quantity — the sku and token come from the page.
     */
    public function theRenderedAddToCartFormAddsTheProductItNames(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);

        $I->amOnPage('/product/index');
        $I->seeResponseCodeIsSuccessful();

        $I->submitForm('#cart-modal-' . $this->product->getSku() . ' form', ['qty' => 4]);

        $I->seeInRepository(CartItem::class, ['product' => $this->product, 'quantity' => 4]);
    }

    /**
     * Same gap on the other side: /cart's "Update Cart" is a real form POST, and its per-row inputs
     * are named qty[<sku>]. Submitting the rendered form covers that naming, which a direct POST to
     * /cart/update cannot.
     */
    public function theRenderedCartPageFormUpdatesTheLineQuantity(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);

        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 2,
        ]);

        $I->amOnPage('/cart');
        $I->seeResponseCodeIsSuccessful();

        $I->submitForm('.cart-table-card form', ['qty' => [$this->product->getSku() => 7]]);

        $I->seeInRepository(CartItem::class, ['product' => $this->product, 'quantity' => 7]);
    }

    public function anEmptyCartShowsTheEmptyMessage(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);

        $I->amOnPage('/cart');
        $I->seeResponseCodeIs(200);
        $I->see('Your cart is empty');
    }

    public function aCartWithoutAnActiveFulfillmentRegionShowsTheBlockedMessage(FunctionalTester $I): void
    {
        // Flip the one active region set up in _before() to Inactive so the company has none.
        $em = $I->grabService(EntityManagerInterface::class);
        $companyRegion = $em->getRepository(CompanyFulfillmentRegion::class)->findOneBy(['company' => $this->company]);
        $companyRegion->setStatus('Inactive');
        $em->flush();

        $this->loginAsCustomer($I);

        $I->amOnPage('/cart');
        $I->seeResponseCodeIs(200);
        $I->see('No fulfillment region is currently enabled for your account.');
    }

    public function addingAValidSkuAddsTheItemAndShowsItInTheCart(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);

        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 3,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(CartItem::class, [
            'product' => $this->product,
            'quantity' => 3,
        ]);

        $I->amOnPage('/cart');
        $I->see($this->product->getName());
        $I->dontSee('Your cart is empty');
    }

    public function addingWithAnInvalidCsrfTokenDoesNotAddTheItem(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);

        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => 'not-a-real-token',
            'sku' => $this->product->getSku(),
            'qty' => 3,
        ]);
        $I->seeResponseCodeIs(403);
        $I->assertStringContainsString('Your session expired', $I->grabPageSource());

        $I->dontSeeInRepository(CartItem::class, ['product' => $this->product]);
    }

    public function addingWithABlankSkuIsSilentlyIgnored(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);

        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => '',
            'qty' => 3,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->dontSeeInRepository(CartItem::class, ['product' => $this->product]);
    }

    public function requestedQuantityIsClampedTo999(FunctionalTester $I): void
    {
        // Enough inventory that the 999 cap (CartService's own ceiling), not the region
        // availability cap, is what's actually under test here.
        $em = $I->grabService(EntityManagerInterface::class);
        $inventory = $em->getRepository(ProductInventory::class)->findOneBy(['product' => $this->product, 'warehouse' => $this->warehouse]);
        $inventory->setQuantity(2000);
        $em->flush();

        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);

        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 5000,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(CartItem::class, [
            'product' => $this->product,
            'quantity' => 999,
        ]);
    }

    /**
     * Issue #214. A cart's own hold is inside ProductInventory::getAvailableQuantity(), so checking
     * a cart's own line against that figure makes the customer compete with themselves. With 50 in
     * stock and 49 already held, availability read 1 and the line was capped down to it — adding one
     * more destroyed the 49 instead of making it 50.
     */
    public function aCartIsNotBlockedByTheStockItIsAlreadyHolding(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);

        // Stock is 50 (see _before).
        $I->sendAjaxPostRequest('/cart/add', ['_token' => $token, 'sku' => $this->product->getSku(), 'qty' => 49]);
        $I->seeInRepository(CartItem::class, ['product' => $this->product, 'quantity' => 49]);

        $I->sendAjaxPostRequest('/cart/add', ['_token' => $token, 'sku' => $this->product->getSku(), 'qty' => 1]);
        $I->seeInRepository(CartItem::class, ['product' => $this->product, 'quantity' => 50]);
    }

    /** Rendering the cart must not shrink a line that fills the whole of stock. */
    public function viewingTheCartDoesNotShrinkALineThatHoldsAllOfStock(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);

        $I->sendAjaxPostRequest('/cart/add', ['_token' => $token, 'sku' => $this->product->getSku(), 'qty' => 50]);
        $I->amOnPage('/cart');
        $I->amOnPage('/cart');

        $I->seeInRepository(CartItem::class, ['product' => $this->product, 'quantity' => 50]);
    }

    /** The "reduced to N" warning has to describe a write that survives the next request. */
    public function anOverLimitRequestIsClampedAndTheClampPersists(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);
        $I->sendAjaxPostRequest('/cart/add', ['_token' => $token, 'sku' => $this->product->getSku(), 'qty' => 2]);

        $I->amOnPage('/cart');
        $cartToken = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/cart/update', [
            '_token' => $cartToken,
            'qty' => [$this->product->getSku() => 500],
        ]);

        // Stock is 50, so the clamp lands on 50 — not on "50 minus what this cart already held".
        $I->amOnPage('/cart');
        $I->seeInRepository(CartItem::class, ['product' => $this->product, 'quantity' => 50]);
    }

    public function updatingTheQuantityChangesTheStoredQuantity(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);
        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 2,
        ]);

        $I->amOnPage('/cart');
        $cartToken = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/cart/update', [
            '_token' => $cartToken,
            'qty' => [$this->product->getSku() => 7],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(CartItem::class, [
            'product' => $this->product,
            'quantity' => 7,
        ]);
    }

    public function updatingToZeroQuantityRemovesTheItem(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);
        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 2,
        ]);

        $I->amOnPage('/cart');
        $cartToken = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/cart/update', [
            '_token' => $cartToken,
            'qty' => [$this->product->getSku() => 0],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->dontSeeInRepository(CartItem::class, ['product' => $this->product]);
    }

    public function updatingWithAnInvalidCsrfTokenLeavesTheQuantityUnchanged(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);
        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 2,
        ]);

        $I->sendAjaxPostRequest('/cart/update', [
            '_token' => 'not-a-real-token',
            'qty' => [$this->product->getSku() => 9],
        ]);

        $I->seeInRepository(CartItem::class, [
            'product' => $this->product,
            'quantity' => 2,
        ]);
    }

    public function removingAnItemDeletesItFromTheCart(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);
        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 2,
        ]);

        $I->amOnPage('/cart');
        $cartToken = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/cart/remove', [
            '_token' => $cartToken,
            'sku' => $this->product->getSku(),
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->dontSeeInRepository(CartItem::class, ['product' => $this->product]);
    }

    public function removingWithAnInvalidCsrfTokenLeavesTheItemInPlace(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);
        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 2,
        ]);

        $I->sendAjaxPostRequest('/cart/remove', [
            '_token' => 'not-a-real-token',
            'sku' => $this->product->getSku(),
        ]);

        $I->seeInRepository(CartItem::class, ['product' => $this->product]);
    }

    public function clearingTheCartRemovesAllItems(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);
        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 2,
        ]);

        $I->amOnPage('/cart');
        $cartToken = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/cart/clear', [
            '_token' => $cartToken,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->dontSeeInRepository(CartItem::class, ['product' => $this->product]);

        $I->amOnPage('/cart');
        $I->see('Your cart is empty');
    }

    public function clearingWithAnInvalidCsrfTokenLeavesTheCartIntact(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);
        $token = $this->grabCartTokenFromProductPage($I);
        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 2,
        ]);

        $I->sendAjaxPostRequest('/cart/clear', [
            '_token' => 'not-a-real-token',
        ]);

        $I->seeInRepository(CartItem::class, ['product' => $this->product]);
    }
}
