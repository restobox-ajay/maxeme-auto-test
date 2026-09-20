<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use App\Entity\Invoice;
use App\Enum\InvoicePaymentStatus;
use App\Entity\PaymentMethod;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use App\Entity\SalesOrder;
use App\Enum\SalesOrderStatus;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Stage 1 of #539 promises that every sales order, in every environment, has exactly one invoice.
 * The migration makes that true of rows that already exist; this makes it true of every row created
 * afterwards, through the real screens rather than through the service in isolation.
 *
 * Asserted per creation path deliberately. OrderInvoicingServiceTest already proves what the copy
 * contains; what a unit test cannot prove is that each of the four screens actually calls it, and a
 * path that quietly does not is exactly how the invariant every later stage depends on would stop
 * being true without anything going red.
 *
 * @group bundle-agnostic
 *
 * Tagged for the bundles-off run (#562): these assertions must hold identically with the
 * optional inventory bundles Inactive. If a change here can only pass with them Active, the
 * change has leaked out of its bundle.
 */
final class EveryOrderPathRaisesAnInvoiceCest
{
    private Company $company;
    private ProductCore $product;
    private CustomerUser $customer;

    public function _before(FunctionalTester $I): void
    {
        // AppSettings caches through a pool that outlives the per-test transaction rollback, and
        // one of the tests below writes an invoice_number_prefix. Without this the row is gone but
        // the cached value is not, and the fallback test reads the previous test's prefix.
        $I->grabService(AppSettings::class)->clearCache();

        $this->company = (new Company())->setName('Invoiced Wholesale')->setCode('INV-' . uniqid());
        $I->haveInRepository($this->company);
        $I->haveActiveFulfillmentRegionFor($this->company);

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
                ->setIsDefaultBilling(true)
        );

        $this->product = (new ProductCore())
            ->setSku('INVOICED-SKU-' . uniqid())
            ->setName('Invoiced Product')
            ->setUnit('EA')
            ->setWeight('1.000')
            ->setSalesTaxCode('E')
            ->setCostPrice('10.00')
            ->setDefaultPrice('25.00')
            ->setOriginalPrice('25.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->product);
        $I->haveStockFor($this->product);

        $priceList = (new PriceList())->setName('Default')->setStatus('Active');
        $I->haveInRepository($priceList);
        $I->haveInRepository(
            (new ProductPricing())->setProduct($this->product)->setPriceList($priceList)->setPrice('25.00')
        );

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $this->customer = (new CustomerUser())
            ->setEmail('every-order-invoiced@example.test')
            ->setCompany($this->company);
        $this->customer->setPassword($hasher->hashPassword($this->customer, 'test-password-123'));
        $I->haveInRepository($this->customer);
    }

    /**
     * The settings this writes are rolled back with the test's transaction, but the cache entry
     * populated from them is not — it lives in a pool that outlives the rollback and is only
     * invalidated by an ORM write. Clearing on the way out keeps that stale map out of whatever
     * runs next, rather than leaving a suite-order-dependent failure behind.
     */
    public function _after(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    // ------------------------------------------------------------------------- customer checkout

    /**
     * A B2B customer on terms is legitimately unpaid for the length of their term, so their invoice
     * is issued outright — Pending, holding stock and fulfillable — rather than parked awaiting
     * money that is not due yet.
     *
     * The ORDER derives to Invoiced (#539 stage 2): checkout approves it and bills the whole of it
     * on one invoice from the outset, so there is no uninvoiced quantity left and the invoice is
     * unpaid. That is the plan's stated intent for an ecom order, not an accident of the fixture.
     */
    public function aTermsCheckoutIssuesItsInvoice(FunctionalTester $I): void
    {
        $slug = $this->manualPaymentMethod($I, 'Terms For Invoicing');
        $this->checkOutWith($I, $slug);

        /** @var SalesOrder $order */
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $this->company]);
        $I->assertSame(
            SalesOrderStatus::Invoiced->value,
            $order->getStatus(),
            'guard: a checkout order is approved and invoiced in full, so it derives to Invoiced',
        );

        $invoice = $this->theOnlyInvoiceFor($I, $order);
        $I->assertSame('Pending', $invoice->getStatus());
        $this->assertShadowsTheOrder($I, $invoice, $order);
    }

    /**
     * A card checkout the customer has not paid yet parks its invoice at On Hold — issued, because
     * the customer placed the order, but holding no stock and visible to the stale-unpaid sweep.
     *
     * Stage 2 is where the plan's shape arrives: "awaiting payment" is the INVOICE's claim, and the
     * order says only how much of it has been billed — the whole of it, on this one invoice, so it
     * derives to Invoiced exactly as the terms checkout above does. The two paths differ on the
     * invoice and nowhere else, which is the point of the intent being passed by the call site.
     */
    public function aCardCheckoutAwaitingPaymentIssuesItsInvoiceOnHold(FunctionalTester $I): void
    {
        $this->enableStripe($I);
        $this->checkOutWith($I, 'stripe');

        /** @var SalesOrder $order */
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $this->company]);
        $I->assertSame(
            SalesOrderStatus::Invoiced->value,
            $order->getStatus(),
            'guard: an unpaid card order is still placed and still fully billed — the waiting is the invoice\'s',
        );

        $invoice = $this->theOnlyInvoiceFor($I, $order);
        $I->assertSame('On Hold', $invoice->getStatus());
        // On Hold counts toward invoiced quantity even though it holds no bucket — the two rules
        // meeting is what makes an abandoned checkout hold nothing without an exclusion clause.
        $I->assertTrue($invoice->countsTowardInvoicedQuantity());
        $this->assertShadowsTheOrder($I, $invoice, $order);
    }

    /**
     * The invoice reaches the database in the same transaction as the order it bills. Checkout
     * commits both inside the transaction it opens for the stock re-check, so a failure between
     * them cannot leave an uninvoiced order behind — this asserts the observable half: the order
     * never exists without its invoice.
     */
    public function theInvoiceIsCommittedWithTheOrder(FunctionalTester $I): void
    {
        $slug = $this->manualPaymentMethod($I, 'Terms For Atomicity');
        $this->checkOutWith($I, $slug);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        $uninvoiced = $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM sales_order o WHERE NOT EXISTS (SELECT 1 FROM invoice i WHERE i.sales_order_id = o.id)',
        );
        $I->assertSame(0, (int) $uninvoiced);
    }

    // ------------------------------------------------------------------------- admin order form

    public function anAdminSaveOrderRaisesNoInvoice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->createOrderThroughTheAdminForm($I, 'order');

        // Approved, not Invoiced: Save Order accepts the order, and accepting an order is not
        // billing it. Nothing has been invoiced, so the whole of it is still to convert.
        $I->assertSame(
            SalesOrderStatus::Approved->value,
            $order->getStatus(),
            'an admin-raised order is approved but not billed',
        );

        $this->assertHasNoInvoices($I, $order);
    }

    /**
     * A draft order raises nothing either, for the simpler reason that nothing has happened to it
     * yet.
     */
    public function anAdminSaveDraftRaisesNoInvoice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->createOrderThroughTheAdminForm($I, 'draft_exit');

        $I->assertSame(SalesOrderStatus::Draft->value, $order->getStatus(), 'guard: Save Draft mints a Draft order');
        $this->assertHasNoInvoices($I, $order);
        $I->assertSame([], $order->getCountingInvoices());
    }

    // ------------------------------------------------------------------------------ clone order

    /**
     * A clone raises no invoice of its own, and takes none of the original's.
     *
     * The second half matters as much as the first: an invoice records goods actually billed, so
     * copying one onto a new order would assert a billing that never happened.
     */
    public function cloningAnOrderRaisesNoInvoiceAndTakesNoneFromTheOriginal(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $original = $this->createOrderThroughTheAdminForm($I, 'order');
        $this->assertHasNoInvoices($I, $original);

        $I->amOnPage('/admin/order/detail/' . $original->getId());
        $I->sendFormPostRequest('/admin/order/clone/' . $original->getId(), ['_token' => $I->csrfToken()]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $clone = $entityManager->getRepository(SalesOrder::class)
            ->findBy(['company' => $this->company->getId()], ['id' => 'DESC'], 1)[0] ?? null;
        $I->assertNotNull($clone);
        $I->assertNotSame($original->getId(), $clone->getId(), 'guard: the clone is a different order');

        $this->assertHasNoInvoices($I, $clone);
    }

    // ---------------------------------------------------------------------------------- numbering

    /** The configured prefix reaches the screens, not just the generator. */
    public function invoiceNumbersUseTheConfiguredPrefix(FunctionalTester $I): void
    {
        $I->haveInRepository(
            (new AppSetting())
                ->setSettingKey('invoice_number_prefix')
                ->setName('Invoice Number Prefix')
                ->setSettingValue('BILL-')
        );
        $I->grabService(AppSettings::class)->clearCache();

        // Through checkout, not the admin form: an admin-raised order carries no invoice to
        // number (#539). Checkout is the path that still bills in full at creation.
        $slug = $this->manualPaymentMethod($I, 'Terms For Numbering');
        $this->checkOutWith($I, $slug);

        /** @var SalesOrder $order */
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $this->company]);
        $I->assertStringStartsWith('BILL-', $this->theOnlyInvoiceFor($I, $order)->getDocumentNumber());
    }

    /** With no setting row the number falls back to INV-, matching the Document Prefixes screen. */
    public function invoiceNumbersFallBackToInv(FunctionalTester $I): void
    {
        $slug = $this->manualPaymentMethod($I, 'Terms For Fallback');
        $this->checkOutWith($I, $slug);

        /** @var SalesOrder $order */
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $this->company]);
        $I->assertStringStartsWith('INV-', $this->theOnlyInvoiceFor($I, $order)->getDocumentNumber());
    }

    // ------------------------------------------------------------------------------------ helpers

    /**
     * The invoice for $order, asserting on the way that there is exactly one of them — which is the
     * whole of what stage 1 promises, so it is checked wherever an invoice is fetched rather than
     * once in a test of its own.
     */
    private function theOnlyInvoiceFor(FunctionalTester $I, SalesOrder $order): Invoice
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $invoices = $entityManager->getRepository(Invoice::class)->findBy(['salesOrder' => $order->getId()]);

        $I->assertCount(1, $invoices, sprintf('order %s must have exactly one invoice', $order->getOrderNumber()));

        return $invoices[0];
    }

    /**
     * An admin-raised order has no invoice until someone converts it. Asserted through the same
     * repository query theOnlyInvoiceFor() uses, so the two cannot disagree about what "has an
     * invoice" means.
     */
    private function assertHasNoInvoices(FunctionalTester $I, SalesOrder $order): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $invoices = $entityManager->getRepository(Invoice::class)->findBy(['salesOrder' => $order->getId()]);

        $I->assertSame(
            [],
            $invoices,
            sprintf('order %s must carry no invoice until one is raised deliberately', $order->getOrderNumber()),
        );
    }

    /** The invoice is a faithful shadow of the order: same money, same rows, same addresses. */
    private function assertShadowsTheOrder(FunctionalTester $I, Invoice $invoice, SalesOrder $order): void
    {
        $I->assertNotEmpty($invoice->getDocumentNumber());
        $I->assertSame($order->getCompany()->getId(), $invoice->getCompany()->getId());
        $I->assertSame($order->getCompanySnapshot(), $invoice->getCompanySnapshot());
        $I->assertSame($order->getSubtotal(), $invoice->getSubtotal());
        $I->assertSame($order->getTax(), $invoice->getTax());
        $I->assertSame($order->getTotal(), $invoice->getTotal());
        $I->assertSame($order->getFeeLines(), $invoice->getFeeLines());
        $I->assertSame($order->getDocumentDate(), $invoice->getDocumentDate());
        $I->assertNotNull($invoice->getInvoiceDate());
        // Not copied from the order, which has no payment status since #539 stage 4 — derived
        // from the invoice's own payment rows, of which a newly raised invoice has none.
        $I->assertSame(InvoicePaymentStatus::NotPaid, $invoice->getPaymentStatus());

        $I->assertCount($order->getLines()->count(), $invoice->getLines());
        $I->assertSame(
            array_map(static fn ($line) => [$line->getSku(), $line->getQuantity(), $line->getPrice()], $order->getLines()->toArray()),
            array_map(static fn ($line) => [$line->getSku(), $line->getQuantity(), $line->getPrice()], $invoice->getLines()->toArray()),
        );

        $I->assertCount($order->getAddresses()->count(), $invoice->getAddresses());
        foreach ($order->getAddresses() as $orderAddress) {
            $invoiceAddress = $orderAddress->isBilling() ? $invoice->getBillingAddress() : $invoice->getShippingAddress();
            $I->assertNotNull($invoiceAddress);
            $I->assertSame($orderAddress->getAddressLine1(), $invoiceAddress->getAddressLine1());
            $I->assertSame($orderAddress->getPostalCode(), $invoiceAddress->getPostalCode());
        }
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('every-order-invoiced-admin@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** Raises an order through the real admin form and returns it, freshly loaded. */
    private function createOrderThroughTheAdminForm(FunctionalTester $I, string $saveMode): SalesOrder
    {
        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->company->getId(),
            'lines' => [
                ['product_id' => (string) $this->product->getId(), 'qty' => '2', 'price' => '25.00', 'tax_code' => 'E'],
            ],
            'save_mode' => $saveMode,
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->getRepository(SalesOrder::class)
            ->findBy(['company' => $this->company->getId()], ['id' => 'DESC'], 1)[0] ?? null;
        $I->assertNotNull($order, 'no order was created by the admin form');

        return $order;
    }

    /**
     * A manual payment method through the real admin screen, as CustomerCheckoutOrderFirstCest does.
     * Manual methods settle by arrangement, so they raise their invoice issued (Pending) rather than
     * On Hold.
     */
    private function manualPaymentMethod(FunctionalTester $I, string $name): string
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/payments/manual/new');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/bundles/payments/manual/new', ['_token' => $token, 'name' => $name]);

        /** @var PaymentMethod $created */
        $created = $I->grabEntityFromRepository(PaymentMethod::class, ['name' => $name]);

        return $created->getSlug();
    }

    /**
     * Two settings rows are all StripeConfigProvider needs to report the method as enabled, and
     * checkout takes no money — it persists the order and leaves settlement to the payment routes —
     * so the card path is exercisable here without a Stripe account or a network call.
     */
    private function enableStripe(FunctionalTester $I): void
    {
        foreach ([
            'payment_stripe_mode' => 'test',
            'payment_stripe_publishable_key_test' => 'pk_test_functional',
            'payment_stripe_secret_key_test' => 'sk_test_functional',
        ] as $key => $value) {
            $I->haveInRepository((new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($value));
        }

        $I->grabService(AppSettings::class)->clearCache();
    }

    /** Fills the cart with the fixture product and submits checkout under $paymentSlug. */
    private function checkOutWith(FunctionalTester $I, string $paymentSlug): void
    {
        $I->amLoggedInAs($this->customer, 'main');
        $I->haveHttpHeader('Host', '127.0.0.1');

        $I->amOnPage('/product/detail/' . $this->product->getId());
        $cartToken = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/cart/add', ['_token' => $cartToken, 'sku' => $this->product->getSku(), 'qty' => 2]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/checkout');
        $I->seeResponseCodeIsSuccessful();
        $checkoutToken = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/checkout/payment-method', ['_token' => $checkoutToken, 'payment_method' => $paymentSlug]);
        $I->sendAjaxPostRequest('/checkout', ['_token' => $checkoutToken]);
    }
}
