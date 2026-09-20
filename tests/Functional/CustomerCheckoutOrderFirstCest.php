<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\SalesOrder;
use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\FulfillmentRegion;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\Invoice;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\PaymentMethod;
use App\Entity\ProductPricing;
use App\Enum\InvoicePaymentStatus;
use App\Enum\SalesOrderStatus;
use App\Repository\BundleStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * End-to-end cover for the order-first checkout, over real routing and real controllers.
 *
 * A manual payment method is used deliberately: it needs no card fields, no JavaScript and no
 * Stripe account, so the whole placement path is exercisable here. It is also the case that must
 * not regress for this business — a B2B customer settling on terms has to end up with an ISSUED
 * invoice (Pending) that can be fulfilled while still unpaid. Parking those at On Hold would hand
 * them to the sweeper, which cancels them an hour later.
 *
 * Since #539 stage 2 that claim lives on the invoice rather than on the order: the order says only
 * how much of it has been billed, so a checkout order derives to Invoiced either way and only the
 * invoice tells terms and card apart.
 *
 * The card path's placement half is identical (same controller, same branch); only the settlement
 * half differs, and that is covered by StripeOrderPaymentApplierTest and
 * StripeWebhookControllerTest.
 *
 * @group bundle-agnostic
 *
 * Tagged for the bundles-off run (#562): these assertions must hold identically with the
 * optional inventory bundles Inactive. If a change here can only pass with them Active, the
 * change has leaked out of its bundle.
 */
final class CustomerCheckoutOrderFirstCest
{
    private Company $company;
    private ProductCore $product;
    private ProductCore $unpricedProduct;
    private CustomerUser $customer;

    public function _before(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('East Warehouse')->setStatus('Active');
        $I->haveInRepository($region);

        $this->company = (new Company())->setName('Terms Wholesale')->setCode('TERMS-' . uniqid());
        $I->haveInRepository($this->company);

        $I->haveInRepository(
            (new CompanyFulfillmentRegion())->setCompany($this->company)->setFulfillmentRegion($region)->setStatus('Active')
        );

        $I->haveInRepository(
            (new CompanyAddress())
                ->setCompany($this->company)
                ->setLabel('Main')
                ->setAddressLine1('1 Wholesale Way')
                ->setCity('Vancouver')
                ->setProvince('BC')
                ->setPostalCode('V5K0A1')
                ->setCountry('CA')
                ->setIsDefaultShipping(true)
        );

        // defaultPrice is what actually resolves the customer price: the ProductPricing row below
        // carries no rule, so resolveCustomerPriceAmount() falls through to the product's base
        // price. Without it the line is unpriced and checkout produces a Quote, not an Order.
        $this->product = (new ProductCore())
            ->setSku('CHECKOUT-FUNC-SKU')
            ->setName('Checkout Functional Product')
            ->setDefaultPrice('25.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->product);

        $I->haveInRepository(
            (new ProductInventory())->setProduct($this->product)->setWarehouse($I->grabService(WarehouseFulfillmentRegionService::class)->warehouseForRegionNameOrCreate($region->getName(), 'BC', 'CA'))->setQuantity(50)
        );

        $priceList = (new PriceList())->setName('Default')->setStatus('Active');
        $I->haveInRepository($priceList);

        $I->haveInRepository(
            (new ProductPricing())->setProduct($this->product)->setPriceList($priceList)->setPrice('25.00')
        );

        // No pricing row and no base price: this is the "price is TBD" case the business wants
        // routed to a quote rather than an order.
        $this->unpricedProduct = (new ProductCore())->setSku('CHECKOUT-FUNC-NOPRICE')->setName('Unpriced Functional Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->unpricedProduct);

        $I->haveInRepository(
            (new ProductInventory())->setProduct($this->unpricedProduct)->setWarehouse($I->grabService(WarehouseFulfillmentRegionService::class)->warehouseForRegionNameOrCreate($region->getName(), 'BC', 'CA'))->setQuantity(50)
        );

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $this->customer = (new CustomerUser())
            ->setEmail('checkout-orderfirst-test@example.test')
            ->setCompany($this->company);
        $this->customer->setPassword($hasher->hashPassword($this->customer, 'test-password-123'));
        $I->haveInRepository($this->customer);
    }

    /**
     * Creates a manual payment method through the real admin screen, as an admin would.
     *
     * Posts directly rather than via submitForm(): the crawler resolves the form action against
     * the admin host, and Codeception's Symfony module refuses absolute URLs (same guard noted in
     * tests/Support/Helper/Functional.php).
     *
     * @return string the generated slug — the form only takes a name, the slug is 'manual-<uniqid>'
     */
    private function createManualPaymentMethodAsAdmin(FunctionalTester $I, string $name): string
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-checkout-orderfirst@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/bundles/payments/manual/new');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/payments/manual/new', ['_token' => $token, 'name' => $name]);

        /** @var PaymentMethod $created */
        $created = $I->grabEntityFromRepository(PaymentMethod::class, ['name' => $name]);

        return $created->getSlug();
    }

    /**
     * Fills the cart with $product, selects the given payment method, and submits checkout.
     *
     * $region, when given, is passed as ?region= on the first page visited — the same mechanism
     * AbstractCustomerController::resolveBrowsingRegion() stores into session for every later
     * request, including checkout's own currentRegionForPricing() call. Left null, whichever
     * region the resolver defaults to (the fixture's only active one, ordinarily) is used, exactly
     * as before this parameter existed.
     */
    private function placeOrderWith(FunctionalTester $I, string $paymentSlug, ?ProductCore $product = null, ?string $region = null): void
    {
        $product ??= $this->product;
        $I->amLoggedInAs($this->customer, 'main');
        $I->haveHttpHeader('Host', '127.0.0.1');

        $productUrl = '/product/detail/' . $product->getId();
        if ($region !== null) {
            $productUrl .= '?region=' . urlencode($region);
        }
        $I->amOnPage($productUrl);
        $cartToken = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/cart/add', ['_token' => $cartToken, 'sku' => $product->getSku(), 'qty' => 2]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/checkout');
        $I->seeResponseCodeIsSuccessful();
        $checkoutToken = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/checkout/payment-method', ['_token' => $checkoutToken, 'payment_method' => $paymentSlug]);
        $I->sendAjaxPostRequest('/checkout', ['_token' => $checkoutToken]);
    }

    /**
     * The invoice is what has to be Pending rather than On Hold — that is the difference between an
     * order the warehouse works and one the stale-unpaid sweep cancels an hour later. The order is
     * live (Invoiced, because checkout bills the whole of it at once) and still unpaid, which is
     * precisely the state a terms customer is entitled to.
     */
    public function manualPaymentPlacesALiveOrderThatIsWorkedWhileUnpaid(FunctionalTester $I): void
    {
        $slug = $this->createManualPaymentMethodAsAdmin($I, 'Bank Transfer On Terms');
        $this->placeOrderWith($I, $slug);

        /** @var SalesOrder $order */
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $this->company]);

        $I->assertSame(
            SalesOrderStatus::Invoiced->value,
            $order->getStatus(),
            'A placed order must be live, not left as a Draft holding nothing.',
        );
        $I->assertNotSame(SalesOrderStatus::Draft->value, $order->getStatus());
        $I->assertNotSame(SalesOrderStatus::Void->value, $order->getStatus());

        /** @var list<Invoice> $invoices */
        $invoices = $I->grabService(EntityManagerInterface::class)
            ->getRepository(Invoice::class)
            ->findBy(['salesOrder' => $order->getId()]);
        $I->assertCount(1, $invoices);
        $I->assertSame(
            'Pending',
            $invoices[0]->getStatus(),
            'A method settled on terms must issue its invoice outright so it can be fulfilled unpaid.',
        );
        $I->assertNotSame(
            'On Hold',
            $invoices[0]->getStatus(),
            'On Hold would hand a terms order to the stale-unpaid sweep.',
        );

        // Payment is the invoice's question since #539 stage 4, and it is derived: no payment
        // rows, so Not Paid, and checkout fabricates neither.
        $I->assertSame(InvoicePaymentStatus::NotPaid, $invoices[0]->getPaymentStatus());
        $I->assertCount(0, $invoices[0]->getApplications(), 'Checkout must not fabricate a payment row.');
    }

    public function theOrderExistsBeforeAnyMoneyChangesHands(FunctionalTester $I): void
    {
        $slug = $this->createManualPaymentMethodAsAdmin($I, 'Cheque On Terms');
        $this->placeOrderWith($I, $slug);

        /** @var SalesOrder $order */
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $this->company]);

        // The point of the whole change: a persisted order exists with a total that later payment
        // is verified against, rather than the total being re-derived from a mutable cart.
        $I->assertNotNull($order->getId());
        $I->assertGreaterThan(0, (float) $order->getTotal());
        $I->assertNotEmpty($order->getOrderNumber());
    }

    /**
     * A document may carry any number of shipping rows; a storefront may not offer that choice.
     * The customer picks one ShippingOption and gets exactly one row for it — pick-one is a rule of
     * the checkout flow, not of the model, which is why it is asserted here and not on the entity.
     */
    public function checkoutTurnsTheChosenShippingOptionIntoExactlyOneShippingRow(FunctionalTester $I): void
    {
        $slug = $this->createManualPaymentMethodAsAdmin($I, 'Terms For Shipping Rows');
        $this->placeOrderWith($I, $slug);

        /** @var SalesOrder $order */
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $this->company]);

        $shippingLines = $order->getShippingLines();
        $I->assertCount(1, $shippingLines);
        $I->assertStringStartsWith('Shipping (', $shippingLines[0]->label);
        // A calculator decided the amount, not an admin, and that is the whole of the difference.
        $I->assertSame('auto-calc', $shippingLines[0]->source);
        $I->assertSame($order->getShippingTotal(), $shippingLines[0]->amount);
        // The grand total counts the row once, and the row is the only place the figure lives.
        $I->assertEqualsWithDelta(
            (float) $order->getSubtotal() + $this->feeRowsTotalOf($order) + (float) $order->getTax(),
            (float) $order->getTotal(),
            0.011,
        );
    }

    /**
     * The payment panel on the order detail page is the one #checkout-form in the codebase, and the
     * numbers app.js prices it with come entirely off these data attributes. Retiring the
     * localStorage cart (#200) made that the only source — there is no browser-side cart left to
     * fall back to — and nothing was asserting it: replacing data-order-subtotal with a hardcoded 0
     * left the whole suite green while the panel would have quoted the customer $0.
     */
    public function theOrderPaymentPanelCarriesThePersistedOrdersNumbers(FunctionalTester $I): void
    {
        $slug = $this->createManualPaymentMethodAsAdmin($I, 'Terms For Payment Panel');
        $this->placeOrderWith($I, $slug);

        /** @var SalesOrder $order */
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $this->company]);

        $I->amOnPage('/orders/' . $order->getId() . '?payment=1');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#checkout-form');

        // app.js branches the entire checkout on this: it is what makes the page use the order's
        // totals instead of pricing a cart in the browser.
        $I->assertSame('1', $I->grabAttributeFrom('#checkout-form', 'data-order-payment'));
        $I->assertSame((string) $order->getId(), $I->grabAttributeFrom('#checkout-form', 'data-order-id'));

        $I->assertGreaterThan(0.0, (float) $order->getSubtotal(), 'The fixture order must have a real subtotal to assert against.');
        $I->assertEqualsWithDelta(
            (float) $order->getSubtotal(),
            (float) $I->grabAttributeFrom('#checkout-form', 'data-order-subtotal'),
            0.011,
        );
        $I->assertEqualsWithDelta(
            (float) $order->getShippingTotal(),
            (float) $I->grabAttributeFrom('#checkout-form', 'data-order-shipping'),
            0.011,
        );

        // orderPaymentTotals() in OrderController mirrors checkoutTotals() in app.js; the fee total
        // and tax rate it publishes have to be the ones the order was actually built from.
        $I->assertEqualsWithDelta(
            $this->feeRowsTotalOf($order) - (float) $order->getShippingTotal(),
            (float) $I->grabAttributeFrom('#checkout-form', 'data-order-fee-total'),
            0.011,
        );
        $I->assertGreaterThanOrEqual(0.0, (float) $I->grabAttributeFrom('#checkout-form', 'data-order-tax-rate'));

        // The grand total app.js recomputes from those attributes must land on the persisted total.
        $subtotal = (float) $I->grabAttributeFrom('#checkout-form', 'data-order-subtotal');
        $shipping = (float) $I->grabAttributeFrom('#checkout-form', 'data-order-shipping');
        $feeTotal = (float) $I->grabAttributeFrom('#checkout-form', 'data-order-fee-total');
        $taxRate = (float) $I->grabAttributeFrom('#checkout-form', 'data-order-tax-rate');
        $preTax = $subtotal + $shipping + $feeTotal;

        $I->assertEqualsWithDelta(
            (float) $order->getTotal(),
            $preTax + ($preTax * ($taxRate / 100)),
            0.011,
            'The payment panel would charge a different amount than the order was placed for.',
        );
    }

    /** Every charge row on the order, shipping included — the total is the sum of the rows. */
    private function feeRowsTotalOf(SalesOrder $order): float
    {
        return (float) array_sum(array_map(static fn ($line): float => $line->amount, $order->getFeeLineRows()));
    }

    public function anUnpricedLineBecomesAQuoteRatherThanAnOrder(FunctionalTester $I): void
    {
        $slug = $this->createManualPaymentMethodAsAdmin($I, 'Terms For Quote');
        $this->placeOrderWith($I, $slug, $this->unpricedProduct);

        // Intended behaviour, not a fallback: when a price is TBD the customer gets a quote
        // request for the team to price, instead of an order at a total nobody has agreed.
        /** @var Estimate $estimate */
        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $this->company]);

        $I->assertNotEmpty($estimate->getDocumentNumber());
        $I->assertSame('Submitted', $estimate->getStatus());
        $I->dontSeeInRepository(SalesOrder::class, ['company' => $this->company]);
    }

    /**
     * The quote a TBD checkout produces must carry the region the customer was actually shopping
     * in — CheckoutController::buildAndPersistEstimate() sets it from the same
     * currentRegionForPricing() call the cart/catalog priced against, not a default or a blank.
     *
     * The fixture's other tests all shop in the company's one and only active region, which cannot
     * prove this: a build that ignored the customer's actual selection and always stamped
     * whichever region happens to load first would still pass every one of them. This company
     * gets a second active region, and the customer explicitly shops in it, so only a build that
     * genuinely reads the session's selected region can pass.
     */
    public function anUnpricedCheckoutQuoteCarriesTheRegionTheCustomerWasActuallyShoppingIn(FunctionalTester $I): void
    {
        $otherRegion = (new FulfillmentRegion())->setName('West Distribution Center')->setStatus('Active');
        $I->haveInRepository($otherRegion);
        $I->haveInRepository(
            (new CompanyFulfillmentRegion())->setCompany($this->company)->setFulfillmentRegion($otherRegion)->setStatus('Active')
        );
        $I->haveInRepository(
            (new ProductInventory())->setProduct($this->unpricedProduct)->setWarehouse($I->grabService(WarehouseFulfillmentRegionService::class)->warehouseForRegionNameOrCreate($otherRegion->getName(), 'BC', 'CA'))->setQuantity(50)
        );

        $slug = $this->createManualPaymentMethodAsAdmin($I, 'Terms For Region Quote Test');
        $this->placeOrderWith($I, $slug, $this->unpricedProduct, $otherRegion->getName());

        /** @var Estimate $estimate */
        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $this->company]);
        $I->assertSame(
            $otherRegion->getName(),
            $estimate->getFulfillmentRegion(),
            'the quote must carry the region the customer was actually shopping in, not whichever region the resolver defaults to'
        );
    }

    /**
     * #271: the customer-side creation paths opened with an empty activity log, so an order or
     * quote that arrived through checkout had no record of who placed it until somebody in the
     * admin touched it. The admin paths have always written this entry.
     */
    public function aCheckoutOrderOpensItsActivityLogWithACreationEntry(FunctionalTester $I): void
    {
        $slug = $this->createManualPaymentMethodAsAdmin($I, 'Terms For Order Log');
        $this->placeOrderWith($I, $slug);

        /** @var SalesOrder $order */
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $this->company]);
        $orderLogs = $I->grabService(EntityManagerInterface::class)->getRepository(AuditLog::class)->findBy(
            ['entityType' => 'SalesOrder', 'entityId' => $order->getId(), 'actorType' => 'document'],
        );

        // The creation entry is picked out by its wording rather than by being the only one. Since
        // #539 stage 2 the same checkout also writes "Order approved." (the named action signing the
        // customer's acceptance) and the deriver's "Order status changed from Approved to Invoiced.",
        // and asserting a count of one would fail on entries that are the point of that change
        // rather than a regression in this one.
        $creationEntries = array_values(array_filter(
            $orderLogs,
            static fn (AuditLog $log): bool => str_contains($log->getSummary(), 'created by'),
        ));

        $I->assertCount(1, $creationEntries, 'exactly one creation entry, whatever else the timeline carries');
        $log = $creationEntries[0];
        $I->assertStringContainsString($order->getOrderNumber(), $log->getSummary());
        $I->assertStringContainsString('created by checkout-orderfirst-test@example.test', $log->getSummary());
        $I->assertStringContainsString('checkout-orderfirst-test@example.test', (string) $log->getActorName());
        $I->assertFalse($log->isRecipientNotified());

        // And the acceptance really is on the timeline, attributed to the customer who placed it —
        // the entry the action writes, which nothing else in this file would notice going missing.
        $approvals = array_values(array_filter(
            $orderLogs,
            static fn (AuditLog $entry): bool => $entry->getSummary() === 'Order approved.',
        ));
        $I->assertCount(1, $approvals);
        $I->assertStringContainsString('checkout-orderfirst-test@example.test', (string) $approvals[0]->getActorName());
    }

    public function aCustomerSubmittedQuoteOpensItsActivityLogWithACreationEntry(FunctionalTester $I): void
    {
        $slug = $this->createManualPaymentMethodAsAdmin($I, 'Terms For Quote Log');
        $this->placeOrderWith($I, $slug, $this->unpricedProduct);

        /** @var Estimate $estimate */
        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $this->company]);
        $estimateLogs = $I->grabService(EntityManagerInterface::class)->getRepository(AuditLog::class)->findBy(
            ['entityType' => 'Estimate', 'entityId' => $estimate->getId(), 'actorType' => 'document'],
        );

        // Two rows, exactly as the ORDER path above produces two: the creation entry, and the row
        // the Draft -> Submitted move writes through the status seam. Before the seam the submit
        // moved the status and recorded nobody, which is the hole setStatus()'s required actor
        // closes. Asserted by content rather than by position — both land in the same flush.
        $comments = array_map(static fn (AuditLog $log): string => $log->getSummary(), $estimateLogs);
        $I->assertCount(2, $comments);

        $creation = array_values(array_filter(
            $estimateLogs,
            static fn (AuditLog $log): bool => str_contains($log->getSummary(), 'created by'),
        ));
        $I->assertCount(1, $creation);
        $I->assertStringContainsString($estimate->getDocumentNumber(), $creation[0]->getSummary());
        $I->assertStringContainsString('created by checkout-orderfirst-test@example.test', $creation[0]->getSummary());

        $submitted = array_values(array_filter(
            $estimateLogs,
            static fn (AuditLog $log): bool => $log->getSummary() === 'Quote requested at checkout.',
        ));
        $I->assertCount(1, $submitted, 'the submit itself is on the timeline now');
        $I->assertStringContainsString('checkout-orderfirst-test@example.test', (string) $submitted[0]->getActorName());
    }

    /**
     * #301: checkout used to commit an order against whatever the cart said, with no re-check of
     * current stock. This simulates the actual failure mode — availability changing between the
     * cart being filled and checkout being submitted (a concurrent sale, a stock correction) —
     * without needing genuine multi-process concurrency to prove the checkout-time gate exists.
     */
    public function checkoutRefusesRatherThanOversellingWhenStockDropsAfterTheCartWasFilled(FunctionalTester $I): void
    {
        // CartHoldBundle would otherwise credit this cart's own hold back onto availability, masking
        // the exact scenario under test — a merchant not using cart holds at all, where a stranger's
        // checkout in the meantime is the only thing standing between "5 in stock" and an oversold
        // order. So it is switched off.
        //
        // Switched off through the one activation path. A fresh BundleStatus used to be safe here
        // because nothing had created one — absence of a row was the enabled default. ffe6c5fd
        // inverted that: absence now means INACTIVE, and every installed bundle gets an explicit
        // Active row before the suite (tests/_bootstrap.php), so a second insert for the same source
        // trips the UNIQUE index on `source` instead of switching anything off.
        $I->grabService(BundleStatusRepository::class)->deactivate('CartHoldBundle');

        $slug = $this->createManualPaymentMethodAsAdmin($I, 'Terms For Oversell Guard');

        $I->amLoggedInAs($this->customer, 'main');
        $I->haveHttpHeader('Host', '127.0.0.1');

        $I->amOnPage('/product/detail/' . $this->product->getId());
        $cartToken = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/cart/add', ['_token' => $cartToken, 'sku' => $this->product->getSku(), 'qty' => 2]);
        $I->seeResponseCodeIsSuccessful();

        // Loaded and payment method chosen while stock still covers it — both touch the checkout
        // page (setPaymentMethod() redirects back to it), which reconciles on every view, so this
        // confirms the cart is fine (qty stays 2) and is not what this test is about.
        $I->amOnPage('/checkout');
        $checkoutToken = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/checkout/payment-method', ['_token' => $checkoutToken, 'payment_method' => $slug]);

        // Stock drops to 1 AFTER checkout was already confirmed to fit 2, in the window between
        // that and submitting — a stranger's concurrent checkout, or an inventory correction. Only
        // a re-check at submit time (not one at page-view time, which already ran above) can catch
        // this; submit() itself does not re-render the checkout page first, unlike every other
        // checkout action, so this is the one path where a stale cart can reach persistence.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $inventory = $entityManager->getRepository(ProductInventory::class)->findOneBy(['product' => $this->product]);
        $inventory->setQuantity(1);
        $entityManager->flush();

        $I->sendAjaxPostRequest('/checkout', ['_token' => $checkoutToken]);
        $I->see('Please review your cart and try again.');

        $I->dontSeeInRepository(SalesOrder::class, ['company' => $this->company]);

        $entityManager->clear();
        $reloaded = $entityManager->getRepository(ProductInventory::class)->find($inventory->getId());
        $I->assertSame('1.0000', $reloaded->getQuantity(), 'the stock correction itself must survive — this test is about the order, not about inventory being untouchable');
    }
}
