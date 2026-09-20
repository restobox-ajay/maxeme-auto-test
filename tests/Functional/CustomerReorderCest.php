<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers CartController::reorder (POST /cart/reorder). The Reorder buttons on the customer order
 * list/detail add a past order's lines back into the server-side cart in one POST — click, add,
 * redirect to /cart with the warnings flashed. There is no confirmation step and no JS.
 *
 * Regression cover for #143 (reorder wrote only to a dead client-side localStorage cart, so the
 * server-backed /cart page always came up empty) and for #230, where the route took the lines to
 * add straight from the request body. That meant it never referenced an order, so no ownership
 * check was possible and any SKU at any quantity could be posted to it; it also skipped lines
 * silently, claimed success for an entirely out-of-stock order, and turned a quantity-0 line into 1.
 *
 * The POSTs here go through sendFormPostRequest(), i.e. a plain no-JS <form> submit with no
 * X-Requested-With header, which is exactly what these buttons are (see CustomerCartJsTest).
 */
final class CustomerReorderCest
{
    private FulfillmentRegion $region;
    private Warehouse $warehouse;
    private Company $company;
    private ProductCore $productA;
    private ProductCore $productB;
    private string $cartToken = '';

    public function _before(FunctionalTester $I): void
    {
        $this->region = (new FulfillmentRegion())->setName('Reorder Warehouse')->setStatus('Active');
        $I->haveInRepository($this->region);

        // Stock sits in the warehouse serving the region, not in the region (#546).
        $this->warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');
        $I->grabService(EntityManagerInterface::class)->flush();

        $this->company = (new Company())->setName('Reorder Co')->setCode('REORD-' . uniqid());
        $I->haveInRepository($this->company);

        $I->haveInRepository((new CompanyFulfillmentRegion())
            ->setCompany($this->company)
            ->setFulfillmentRegion($this->region)
            ->setStatus('Active'));

        $this->productA = (new ProductCore())->setSku('REORDER-SKU-A')->setName('Reorder Product A')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->productA);
        $I->haveInRepository((new ProductInventory())->setProduct($this->productA)->setWarehouse($this->warehouse)->setQuantity(50));

        $this->productB = (new ProductCore())->setSku('REORDER-SKU-B')->setName('Reorder Product B')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->productB);
        $I->haveInRepository((new ProductInventory())->setProduct($this->productB)->setWarehouse($this->warehouse)->setQuantity(50));
    }

    private function loginAsCustomer(FunctionalTester $I, ?Company $company = null): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('reorder-' . uniqid() . '@example.test')
            ->setCompany($company ?? $this->company);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');

        // Grabbed once, here, rather than per POST: the product detail page only renders an
        // add-to-cart form (and so a customer_cart token) while the product is in stock, and
        // several tests below deliberately take stock to zero. The token is per session, so one
        // grab at login is good for the whole test either way.
        $I->amOnPage('/product/detail/' . $this->productA->getId());
        $this->cartToken = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        return $customer;
    }

    /**
     * An order for $company. $lines is a list of [sku, name, qty]; a null sku makes a note line.
     *
     * @param list<array{0: ?string, 1: string, 2: string}> $lines
     */
    private function makeOrder(FunctionalTester $I, Company $company, array $lines): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORD-REORDER-' . uniqid())
            ->setSubtotal('100.00')
            ->setTax('0.00')
            ->setTotal('100.00');

        foreach ($lines as [$sku, $name, $qty]) {
            $order->addLine((new SalesOrderLine())->setSku($sku)->setName($name)->setQuantity($qty));
        }

        $em = $I->grabService(EntityManagerInterface::class);
        $em->persist($order);
        foreach ($order->getLines() as $line) {
            $em->persist($line);
        }
        $em->flush();

        // A past order to reorder from is a live one, not a draft. Approved is where it settles:
        // the fixture creates no invoices, so the deriver leaves it there.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $em->flush();

        return $order;
    }

    /** @param array<string, string> $extra */
    private function reorder(FunctionalTester $I, SalesOrder|int|string $order, array $extra = []): void
    {
        $I->sendFormPostRequest('/cart/reorder', array_merge([
            '_token' => $this->cartToken,
            'order' => $order instanceof SalesOrder ? (string) $order->getId() : (string) $order,
        ], $extra));
    }

    private function setStock(FunctionalTester $I, ProductCore $product, int $quantity): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $inventory = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $this->warehouse,
        ]);
        $inventory->setQuantity($quantity);
        $em->flush();
    }

    /** How many lines the session's cart actually holds — the thing $added is supposed to count. */
    private function grabCartLineCount(FunctionalTester $I): int
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $lines = 0;
        foreach ($em->getRepository(Cart::class)->findAll() as $cart) {
            $lines += $cart->getItems()->count();
        }

        return $lines;
    }

    public function reorderAddsAllOrderLinesToTheServerCart(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [
            ['REORDER-SKU-A', 'Reorder Product A', '2.00'],
            ['REORDER-SKU-B', 'Reorder Product B', '3.00'],
        ]);
        $this->loginAsCustomer($I);

        $this->reorder($I, $order);
        $I->seeResponseCodeIsSuccessful();

        // The whole point of #143: the items land in the real (DB) cart, not a phantom one.
        $I->seeInRepository(CartItem::class, ['product' => $this->productA, 'quantity' => 2]);
        $I->seeInRepository(CartItem::class, ['product' => $this->productB, 'quantity' => 3]);
        $I->see('Items from your order were added to the cart.');

        $I->amOnPage('/cart');
        $I->see($this->productA->getName());
        $I->dontSee('Your cart is empty');
    }

    /**
     * One POST does the whole thing: the click lands on /cart with the items already in it. If an
     * interstitial "review your reorder" step were ever introduced, this redirect target would stop
     * being the cart and this would fail.
     */
    public function reorderIsASinglePostThatLandsStraightOnTheCart(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [['REORDER-SKU-A', 'Reorder Product A', '2.00']]);
        $this->loginAsCustomer($I);

        $this->reorder($I, $order);

        $I->seeCurrentUrlEquals('/cart');
        $I->seeInRepository(CartItem::class, ['product' => $this->productA, 'quantity' => 2]);
    }

    public function reorderWithAnInvalidCsrfTokenAddsNothing(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [['REORDER-SKU-A', 'Reorder Product A', '2.00']]);
        $this->loginAsCustomer($I);

        $I->sendFormPostRequest('/cart/reorder', [
            '_token' => 'not-a-real-token',
            'order' => (string) $order->getId(),
        ]);

        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productA]);
    }

    /**
     * #230, the reason the payload changed. The route used to take its lines from the request body
     * and never referenced an order, so there was nothing to check ownership against. Now it loads
     * the order scoped to the customer's own company, and another company's order is refused.
     */
    public function reorderRefusesAnOrderBelongingToAnotherCompany(FunctionalTester $I): void
    {
        $otherCompany = (new Company())->setName('Someone Else Ltd')->setCode('OTHER-' . uniqid());
        $I->haveInRepository($otherCompany);
        $I->haveInRepository((new CompanyFulfillmentRegion())
            ->setCompany($otherCompany)
            ->setFulfillmentRegion($this->region)
            ->setStatus('Active'));

        $theirOrder = $this->makeOrder($I, $otherCompany, [
            ['REORDER-SKU-A', 'Reorder Product A', '2.00'],
            ['REORDER-SKU-B', 'Reorder Product B', '3.00'],
        ]);

        // Logged in as our own company, posting their order id.
        $this->loginAsCustomer($I);
        $this->reorder($I, $theirOrder);
        $I->seeResponseCodeIsSuccessful();

        $I->see('That order could not be found.');
        $I->dontSee('Items from your order were added to the cart.');
        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productA]);
        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productB]);
        $I->assertSame(0, $this->grabCartLineCount($I), 'Another company\'s order must add nothing.');
    }

    /** Nor can a line be pulled out of someone else's order by pairing it with an id we do own. */
    public function reorderRefusesALineThatIsNotOnTheGivenOrder(FunctionalTester $I): void
    {
        $otherCompany = (new Company())->setName('Someone Else Ltd')->setCode('OTHER-' . uniqid());
        $I->haveInRepository($otherCompany);
        $theirOrder = $this->makeOrder($I, $otherCompany, [['REORDER-SKU-B', 'Reorder Product B', '3.00']]);
        $theirLineId = $theirOrder->getLines()->first()->getId();

        $ourOrder = $this->makeOrder($I, $this->company, [['REORDER-SKU-A', 'Reorder Product A', '2.00']]);

        $this->loginAsCustomer($I);
        $this->reorder($I, $ourOrder, ['line' => (string) $theirLineId]);

        $I->see('That order could not be found.');
        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productB]);
        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productA]);
        $I->assertSame(0, $this->grabCartLineCount($I));
    }

    /** An id that is not a number must be refused with a flash, not a 400/500 error page. */
    public function reorderRefusesANonNumericOrderIdCleanly(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);

        $this->reorder($I, 'not-an-id');
        $I->seeResponseCodeIsSuccessful();

        $I->see('That order could not be found.');
        $I->assertSame(0, $this->grabCartLineCount($I));
    }

    public function reorderRefusesAnUnknownOrderIdCleanly(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);

        $this->reorder($I, 987654321);
        $I->seeResponseCodeIsSuccessful();

        $I->see('That order could not be found.');
        $I->assertSame(0, $this->grabCartLineCount($I));
    }

    public function reorderRefusesAMissingOrderIdCleanly(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);

        $I->sendFormPostRequest('/cart/reorder', ['_token' => $this->cartToken]);
        $I->seeResponseCodeIsSuccessful();

        $I->see('That order could not be found.');
        $I->assertSame(0, $this->grabCartLineCount($I));
    }

    /** The per-line Reorder buttons add that line and only that line. */
    public function reorderOfASingleLineAddsOnlyThatLine(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [
            ['REORDER-SKU-A', 'Reorder Product A', '2.00'],
            ['REORDER-SKU-B', 'Reorder Product B', '3.00'],
        ]);
        $lineB = $order->getLines()->get(1);

        $this->loginAsCustomer($I);
        $this->reorder($I, $order, ['line' => (string) $lineB->getId()]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(CartItem::class, ['product' => $this->productB, 'quantity' => 3]);
        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productA]);
        $I->assertSame(1, $this->grabCartLineCount($I));
    }

    /**
     * #230 defect 1. A product deleted from the catalog since the order was placed used to be
     * skipped with a bare `continue`, and the only feedback fired solely when *every* line was
     * missing — so reorder five, lose two, and the customer was told nothing.
     */
    public function reorderNamesAProductThatIsNoLongerInTheCatalogAndStillAddsTheRest(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [
            ['REORDER-SKU-A', 'Reorder Product A', '2.00'],
            ['DISCONTINUED-SKU', 'Discontinued Widget', '4.00'],
            ['REORDER-SKU-B', 'Reorder Product B', '3.00'],
        ]);
        $this->loginAsCustomer($I);

        $this->reorder($I, $order);
        $I->seeResponseCodeIsSuccessful();

        // Named — SKU and the name the order line carries.
        $I->see('Discontinued Widget (DISCONTINUED-SKU) is no longer available, so it was not added to your cart.');

        // The good ones are untouched, at exactly the quantities the order carried.
        $I->seeInRepository(CartItem::class, ['product' => $this->productA, 'quantity' => 2]);
        $I->seeInRepository(CartItem::class, ['product' => $this->productB, 'quantity' => 3]);
        $I->see('Items from your order were added to the cart.');
        $I->assertSame(2, $this->grabCartLineCount($I), 'The missing line must not have created a cart line.');
    }

    /**
     * A product that really is deleted from the catalog after the order was placed, rather than a
     * SKU that never existed — the line keeps its snapshot SKU and name, and is named by them.
     */
    public function reorderNamesAProductDeletedAfterTheOrderWasPlaced(FunctionalTester $I): void
    {
        $doomed = (new ProductCore())->setSku('DOOMED-SKU')->setName('Doomed Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($doomed);

        $order = $this->makeOrder($I, $this->company, [
            ['REORDER-SKU-A', 'Reorder Product A', '1.00'],
            ['DOOMED-SKU', 'Doomed Product', '2.00'],
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->remove($em->getRepository(ProductCore::class)->findOneBy(['sku' => 'DOOMED-SKU']));
        $em->flush();

        $this->loginAsCustomer($I);
        $this->reorder($I, $order);

        $I->see('Doomed Product (DOOMED-SKU) is no longer available, so it was not added to your cart.');
        $I->seeInRepository(CartItem::class, ['product' => $this->productA, 'quantity' => 1]);
        $I->assertSame(1, $this->grabCartLineCount($I));
    }

    /** A line whose product is gone and which never had a SKU is named by whatever name it has. */
    public function reorderNamesAMissingLineThatHasNoSkuByItsName(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [
            ['REORDER-SKU-A', 'Reorder Product A', '1.00'],
            [null, 'Custom Engraving', '1.00'],
        ]);
        $this->loginAsCustomer($I);

        $this->reorder($I, $order);

        $I->see('Custom Engraving is no longer available, so it was not added to your cart.');
        $I->seeInRepository(CartItem::class, ['product' => $this->productA, 'quantity' => 1]);
        $I->assertSame(1, $this->grabCartLineCount($I));
    }

    /** Several missing lines are one warning listing them, not one flash each. */
    public function reorderAggregatesSeveralMissingProductsIntoOneWarning(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [
            ['REORDER-SKU-A', 'Reorder Product A', '1.00'],
            ['GONE-1', 'First Gone', '1.00'],
            ['GONE-2', 'Second Gone', '1.00'],
        ]);
        $this->loginAsCustomer($I);

        $this->reorder($I, $order);

        $I->see('2 items are no longer available, so they were not added to your cart: First Gone (GONE-1), Second Gone (GONE-2).');
        $I->dontSee('First Gone (GONE-1) is no longer available, so it was not added');
        $I->seeInRepository(CartItem::class, ['product' => $this->productA, 'quantity' => 1]);
    }

    /**
     * #230 defect 2. Every product still exists, so every line reached CartService::add() — but
     * add() calls capOrRemove(), which removes the line again when the region has none. $added was
     * a count of loop iterations, so this reported "added to the cart" with an empty cart.
     */
    public function reorderOfAnEntirelyOutOfStockOrderNeverClaimsSuccess(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [
            ['REORDER-SKU-A', 'Reorder Product A', '2.00'],
            ['REORDER-SKU-B', 'Reorder Product B', '3.00'],
        ]);
        $this->loginAsCustomer($I);
        $this->setStock($I, $this->productA, 0);
        $this->setStock($I, $this->productB, 0);

        $this->reorder($I, $order);
        $I->seeResponseCodeIsSuccessful();

        $I->dontSee('Items from your order were added to the cart.');
        $I->see('Nothing from that order was added to your cart.');

        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productA]);
        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productB]);
        $I->assertSame(0, $this->grabCartLineCount($I), 'Nothing was added, so the cart must hold no lines.');
    }

    /** One of two out of stock: a real success and a real removal, both reported. */
    public function reorderReportsTheRemovedLineAndStillConfirmsThePartialSuccess(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [
            ['REORDER-SKU-A', 'Reorder Product A', '2.00'],
            ['REORDER-SKU-B', 'Reorder Product B', '3.00'],
        ]);
        $this->loginAsCustomer($I);
        $this->setStock($I, $this->productB, 0);

        $this->reorder($I, $order);

        $I->see('Items from your order were added to the cart.');
        $I->see('REORDER-SKU-B was removed from your cart');
        $I->seeInRepository(CartItem::class, ['product' => $this->productA, 'quantity' => 2]);
        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productB]);
        $I->assertSame(1, $this->grabCartLineCount($I));
    }

    /** Partial stock: capped, the capping message names the item, and the capped quantity persists. */
    public function reorderCapsToTheAvailableQuantityAndSaysWhich(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [['REORDER-SKU-A', 'Reorder Product A', '10.00']]);
        $this->loginAsCustomer($I);
        $this->setStock($I, $this->productA, 3);

        $this->reorder($I, $order);

        $I->see('REORDER-SKU-A was reduced to 3');
        $I->see('Items from your order were added to the cart.');
        $I->seeInRepository(CartItem::class, ['product' => $this->productA, 'quantity' => 3]);
        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productA, 'quantity' => 10]);

        // ...and it is still 3 once the cart page has re-reconciled the line against the region,
        // rather than being capped again down to whatever is left after this cart's own hold (#217).
        $I->amOnPage('/cart');
        $I->seeInRepository(CartItem::class, ['product' => $this->productA, 'quantity' => 3]);
    }

    public function reorderQuantityIsClampedTo999(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [['REORDER-SKU-A', 'Reorder Product A', '5000.00']]);
        $this->loginAsCustomer($I);
        $this->setStock($I, $this->productA, 2000);

        $this->reorder($I, $order);

        $I->seeInRepository(CartItem::class, ['product' => $this->productA, 'quantity' => 999]);
    }

    /**
     * #230 defect 3. Quantity 0 is a legitimate entry on an order or a quote — the placeholder /
     * soft-note line of #229 — but a cart never holds a zero-quantity line. `max(1, ...)` used to
     * turn that placeholder into one real unit of a real product the customer never ordered. It has
     * to be skipped instead, and said out loud.
     */
    public function reorderSkipsAZeroQuantityLineRatherThanAddingOneOfIt(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [
            ['REORDER-SKU-A', 'Reorder Product A', '2.00'],
            ['REORDER-SKU-B', 'Reorder Product B', '0.00'],
        ]);
        $this->loginAsCustomer($I);

        $this->reorder($I, $order);
        $I->seeResponseCodeIsSuccessful();

        // Not added as 1 — not added at all.
        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productB]);
        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productB, 'quantity' => 1]);
        $I->see('Reorder Product B (REORDER-SKU-B) was ordered with a quantity of 0, so nothing was added to your cart for it.');

        // The other line on the same order is unaffected.
        $I->seeInRepository(CartItem::class, ['product' => $this->productA, 'quantity' => 2]);
        $I->see('Items from your order were added to the cart.');
        $I->assertSame(1, $this->grabCartLineCount($I), 'The zero-quantity line must not have created a cart line.');
    }

    /** A zero-quantity line on its own: nothing added, and no false success. */
    public function reorderOfOnlyAZeroQuantityLineAddsNothingAndDoesNotClaimSuccess(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [['REORDER-SKU-A', 'Reorder Product A', '0.00']]);
        $this->loginAsCustomer($I);

        $this->reorder($I, $order);
        $I->seeResponseCodeIsSuccessful();

        $I->dontSee('Items from your order were added to the cart.');
        $I->see('Reorder Product A (REORDER-SKU-A) was ordered with a quantity of 0, so nothing was added to your cart for it.');
        $I->see('Nothing from that order was added to your cart.');
        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productA]);
        $I->assertSame(0, $this->grabCartLineCount($I));
    }

    /** A note line reordered on its own is reported by name, not silently ignored. */
    public function reorderOfASingleNoteLineReportsItRatherThanSayingNothing(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [[null, 'Please gift wrap', '0.00']]);
        $noteLine = $order->getLines()->first();

        $this->loginAsCustomer($I);
        $this->reorder($I, $order, ['line' => (string) $noteLine->getId()]);

        $I->see('Please gift wrap was ordered with a quantity of 0, so nothing was added to your cart for it.');
        $I->see('Nothing from that order was added to your cart.');
        $I->assertSame(0, $this->grabCartLineCount($I));
    }

    /** A negative quantity is not a unit either — it must not be rounded up to 1 the same way. */
    public function reorderSkipsANegativeQuantityLineRatherThanAddingOneOfIt(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [['REORDER-SKU-A', 'Reorder Product A', '-5.00']]);
        $this->loginAsCustomer($I);

        $this->reorder($I, $order);

        $I->dontSeeInRepository(CartItem::class, ['product' => $this->productA]);
        $I->assertSame(0, $this->grabCartLineCount($I));
        $I->dontSee('Items from your order were added to the cart.');
    }

    /**
     * End to end through the markup a real customer submits: render the order pages, take the
     * reorder forms exactly as rendered, and post them. This is what proves the forms carry the
     * order id (and, per line, the line id) that the controller now resolves everything from, and
     * that they carry no line payload for a client to tamper with.
     */
    public function theOrderPagesOwnReorderFormsRoundTripThroughTheController(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I, $this->company, [
            ['REORDER-SKU-A', 'Reorder "Quoted" Product A', '2.00'],
            [null, 'Gift wrap note', '0.00'],
            ['VANISHED-SKU', 'Discontinued Widget', '1.00'],
        ]);
        $this->loginAsCustomer($I);

        // 1. The order-level Reorder form in the detail page header, taken verbatim.
        $I->amOnPage('/orders/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('.reorder-form input[name="order"]');
        $I->dontSeeElement('.reorder-form input[name="lines"]');

        $orderId = (string) $I->grabAttributeFrom('.reorder-form input[name="order"]', 'value');
        $token = (string) $I->grabAttributeFrom('.reorder-form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/cart/reorder', ['_token' => $token, 'order' => $orderId]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(CartItem::class, ['product' => $this->productA, 'quantity' => 2]);
        $I->assertSame(1, $this->grabCartLineCount($I));

        // Both lines that did not land were named — the note line by its name, since it has no SKU.
        $I->see('Gift wrap note was ordered with a quantity of 0, so nothing was added to your cart for it.');
        $I->see('Discontinued Widget (VANISHED-SKU) is no longer available, so it was not added to your cart.');
        $I->see('Items from your order were added to the cart.');

        // 2. The list page's Reorder form posts an order id too.
        $I->amOnPage('/orders');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('.reorder-form input[name="order"]');
        $I->dontSeeElement('.reorder-form input[name="lines"]');
    }
}
