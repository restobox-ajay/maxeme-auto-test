<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Accepting a quote and raising the documents behind it are the same moment for a CUSTOMER and
 * three separate moments for an ADMIN.
 *
 * The customer clicks Accept once: the quote becomes Accepted, the sales order is minted and the
 * invoice that bills it is raised, all in one transaction. They have committed to the purchase, so
 * there is nothing left for anyone to decide.
 *
 * An admin accepting on the customer's behalf (#392 — the customer phoned or emailed "go ahead")
 * is recording somebody else's decision, and the owner's ruling is that recording it must not mint
 * documents. So the admin side is three deliberate steps:
 *
 *   1. Accept            — the quote's status column reads Accepted. Nothing else exists.
 *   2. Convert to Sales Order — the order is raised. Still no invoice.
 *   3. Convert to Invoice     — the pre-existing admin_invoice_create (with order_id) path, on the order.
 *
 * ## Why this is asserted on columns and why the customer path is in here
 *
 * Every state assertion below reads the database column — COUNT(*) on `invoice` and `sales_order`,
 * the `status` and `converted_order_id` values on `estimate` — and never the rendered page. A
 * screen that says "no invoice" proves nothing about whether one was written; the row count does
 * (docs/QUEUE.md #624).
 *
 * customerAcceptStillRaisesTheOrderAndItsInvoice() is the positive control for the two admin cases
 * and is not optional. "No invoice exists" passes just as happily when invoicing is broken for
 * everybody as when it is correctly skipped for one path, so without a path in the same suite that
 * DOES produce an invoice from the same fixture shape, the admin assertions are worthless.
 */
final class AdminQuoteAcceptAndConvertCest
{
    private const REGION = 'Admin Accept Convert Region';

    private const ADMIN_EMAIL = 'admin-accept-convert@example.test';

    /**
     * Codeception builds ONE instance of a Cest and runs every method on it, so anything stashed on
     * $this outlives the test that set it while the database underneath has been rolled back —
     * leaving an id pointing at a row that no longer exists. Cleared in _before rather than merely
     * overwritten, so a method that forgets to build a fixture fails on null instead of quietly
     * asserting against the previous test's leftovers.
     */
    private ?Company $company = null;

    private ?ProductCore $product = null;

    public function _before(FunctionalTester $I): void
    {
        $this->company = null;
        $this->product = null;
    }

    // ------------------------------------------------------------------ 1. accept creates nothing

    /**
     * The headline of the owner's ruling: an admin accepting a Priced quote moves the status column
     * and writes no documents at all.
     *
     * Both counts are asserted, not just the invoice one. Accept used to be the conversion, so the
     * order is the thing most likely to come back by accident.
     */
    public function adminAcceptMarksTheQuoteAcceptedAndCreatesNothing(FunctionalTester $I): void
    {
        $estimate = $this->pricedQuote($I, stock: 100, quantity: '4.00');
        $this->loginAsAdmin($I);

        $this->postAccept($I, $estimate);

        $I->assertSame(
            'Accepted',
            $this->estimateStatusColumn($I, (int) $estimate->getId()),
            'the estimate.status column must read Accepted',
        );
        $I->assertNull(
            $this->convertedOrderIdColumn($I, (int) $estimate->getId()),
            'accepting must not link an order: estimate.converted_order_id stays NULL',
        );
        $I->assertSame(0, $this->orderCount($I), 'accepting must create no sales order');
        $I->assertSame(0, $this->invoiceCount($I), 'accepting must create no invoice');
    }

    // ---------------------------------------------------------- 2. convert makes the order only

    /**
     * Step two raises exactly one sales order and still no invoice.
     *
     * The order is APPROVED, as it always was — the owner changed what conversion bills, not what it
     * accepts. Status is read back off the column rather than off the entity the request built.
     */
    public function adminConvertRaisesOneOrderAndStillNoInvoice(FunctionalTester $I): void
    {
        $estimate = $this->pricedQuote($I, stock: 100, quantity: '4.00');
        $this->loginAsAdmin($I);

        $this->postAccept($I, $estimate);
        $I->assertSame(0, $this->orderCount($I), 'guard: nothing exists to convert yet');

        $this->postConvert($I, $estimate);

        $I->assertSame(1, $this->orderCount($I), 'converting must raise exactly one sales order');
        $I->assertSame(0, $this->invoiceCount($I), 'converting must raise no invoice');

        $orderId = $this->onlyOrderId($I);
        $I->assertSame(
            SalesOrderStatus::Approved->value,
            $this->orderStatusColumn($I, $orderId),
            'the order is still approved by the conversion, exactly as before',
        );
        $I->assertSame(
            $orderId,
            $this->convertedOrderIdColumn($I, (int) $estimate->getId()),
            'the quote must point at the order it produced',
        );
        $I->assertSame(0, $this->invoiceCountForOrder($I, $orderId), 'and that order carries no invoice');
    }

    // -------------------------------------------------- 3. the admin is not stranded without one

    /**
     * End to end: accept, convert, then bill the order through the invoicing path that already
     * existed (admin_invoice_create (with order_id)). Nothing new was built for step three, and this proves the
     * admin can still reach an invoice — the whole risk of taking the automatic one away.
     */
    public function theAdminCanStillInvoiceTheConvertedOrderThroughTheOrderScreen(FunctionalTester $I): void
    {
        $estimate = $this->pricedQuote($I, stock: 100, quantity: '4.00');
        $this->loginAsAdmin($I);

        $this->postAccept($I, $estimate);
        $this->postConvert($I, $estimate);
        $orderId = $this->onlyOrderId($I);
        $I->assertSame(0, $this->invoiceCountForOrder($I, $orderId), 'guard: nothing has billed this order yet');

        // The order screen offers the route, rather than the admin having to know the URL.
        $I->amOnPage('/admin/order/detail/' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeLink('Convert to Invoice', '/admin/invoice/create?order_id=' . $orderId);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var SalesOrder $order */
        $order = $entityManager->find(SalesOrder::class, $orderId);
        $lineId = (int) $order->getLines()->first()->getId();

        $I->amOnPage('/admin/invoice/create?order_id=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $orderId, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [['id' => (string) $lineId, 'qty' => '4.00']],
        ]);

        $I->assertSame(1, $this->invoiceCountForOrder($I, $orderId), 'the existing order path must still bill the order');
    }

    // ------------------------------------------------- 4. the positive control: the customer path

    /**
     * The customer's own accept is untouched: one order AND one invoice, from one click.
     *
     * Without this, cases 1 and 2 prove nothing — an assertion that no invoice exists would pass
     * just as well if invoicing had been broken everywhere. Same fixture shape as the admin cases,
     * deliberately, so the only difference between them is which screen was driven.
     */
    public function customerAcceptStillRaisesTheOrderAndItsInvoice(FunctionalTester $I): void
    {
        $estimate = $this->pricedQuote($I, stock: 100, quantity: '4.00');
        $this->loginAsCustomer($I);

        $this->postCustomerAccept($I, $estimate);

        $I->assertSame(
            'Accepted',
            $this->estimateStatusColumn($I, (int) $estimate->getId()),
        );
        $I->assertSame(1, $this->orderCount($I), 'the customer path still mints the order');
        $I->assertSame(1, $this->invoiceCount($I), 'and still bills it in the same breath');

        $orderId = $this->onlyOrderId($I);
        $I->assertSame(1, $this->invoiceCountForOrder($I, $orderId), 'the invoice belongs to that order');
        $I->assertSame(
            SalesOrderStatus::Invoiced->value,
            $this->orderStatusColumn($I, $orderId),
            'billed in full at creation, so the order derives to Invoiced — unchanged from before',
        );
    }

    // ------------------------------------------------------------ 5. the shortfall path, on admin

    /**
     * A quote for more than exists still converts, is still held back as a Draft (#326), and still
     * raises no invoice.
     *
     * The held-as-Draft half is the regression guard: the invoice call sits AFTER the shortfall
     * check inside convert() precisely so a held order cannot bill for stock that is not there, and
     * making the invoice optional must not have disturbed that ordering. Availability is asserted
     * too — a Draft reserves nothing, so the five stay five rather than going to -496.
     */
    public function adminConvertOfAShortQuoteHoldsTheOrderAsDraftAndStillRaisesNoInvoice(FunctionalTester $I): void
    {
        $estimate = $this->pricedQuote($I, stock: 5, quantity: '500.00');
        $this->loginAsAdmin($I);

        $this->postAccept($I, $estimate);
        $this->postConvert($I, $estimate);

        $I->assertSame(1, $this->orderCount($I), 'the conversion is never refused for stock');
        $orderId = $this->onlyOrderId($I);
        $I->assertSame(
            SalesOrderStatus::Draft->value,
            $this->orderStatusColumn($I, $orderId),
            'an unfillable order must be held back as a Draft',
        );
        $I->assertSame(0, $this->invoiceCountForOrder($I, $orderId), 'and a held order must carry no invoice at all');
        $I->assertSame(5, $this->availability($I), 'a Draft reserves nothing, so availability is untouched');

        // The shortfall is still written onto the order, which is the only durable record of why it
        // is a Draft — the email that also reports it may never arrive.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $comments = array_map(
            static fn (AuditLog $log): string => $log->getSummary(),
            $entityManager->getRepository(AuditLog::class)->findBy(
                ['entityType' => 'SalesOrder', 'entityId' => $orderId, 'actorType' => 'document'],
            ),
        );
        $I->assertNotEmpty(
            array_filter($comments, static fn (string $c): bool => str_contains($c, 'Held as Draft')),
            'the order should still say why it is a draft',
        );
        $I->assertNotEmpty(
            array_filter($comments, static fn (string $c): bool => str_contains($c, 'short 495')),
            'and should still name the arithmetic',
        );
    }

    // ------------------------------------------------------------------ 6. the server-side refusals

    /**
     * Convert refuses a quote that has not been accepted. The browser confirm on the button is UX
     * and nothing else — this POST never opened the page, so nothing client-side stood in its way.
     */
    public function convertIsRefusedOnAQuoteThatIsNotAccepted(FunctionalTester $I): void
    {
        $estimate = $this->pricedQuote($I, stock: 100, quantity: '4.00');
        $this->loginAsAdmin($I);

        // Straight to convert, skipping accept entirely.
        $this->postConvert($I, $estimate);

        $I->assertSame(0, $this->orderCount($I), 'an unaccepted quote must not become an order');
        $I->assertSame(0, $this->invoiceCount($I));
        $I->assertSame(
            'Priced',
            $this->estimateStatusColumn($I, (int) $estimate->getId()),
            'and the refusal must not have moved the status either',
        );
        $I->see('Only an accepted quote can be converted', '.flash-message');
    }

    /**
     * And it refuses the second press on a quote it already converted, rather than raising a second
     * order against one quote. Forwarded to the order that exists, which is what the equivalent
     * already-accepted branch has always done.
     */
    public function convertIsRefusedTwiceOnTheSameQuote(FunctionalTester $I): void
    {
        $estimate = $this->pricedQuote($I, stock: 100, quantity: '4.00');
        $this->loginAsAdmin($I);

        $this->postAccept($I, $estimate);
        $this->postConvert($I, $estimate);
        $firstOrderId = $this->onlyOrderId($I);

        $this->postConvert($I, $estimate);

        $I->assertSame(1, $this->orderCount($I), 'one quote, one order, however many times the button is pressed');
        $I->assertSame($firstOrderId, $this->onlyOrderId($I), 'and it is still the same order');
        $I->assertSame(0, $this->invoiceCount($I), 'the resubmit must not bill anything either');
        $I->seeCurrentUrlEquals('/admin/order/detail/' . $firstOrderId);
    }

    /**
     * Accept refuses its own second press, so the quote's timeline records one acceptance.
     *
     * This is the ORDINARY resubmit — the extra click, the stale tab — and it is settled by the
     * status check, which finds the quote already Accepted. The genuinely simultaneous case, where
     * two requests both read Priced before either writes, is settled a layer deeper by the
     * conditional UPDATE in claimAcceptance(); a functional test runs one request at a time and
     * cannot stage it.
     */
    public function acceptIsRefusedTwiceOnTheSameQuote(FunctionalTester $I): void
    {
        $estimate = $this->pricedQuote($I, stock: 100, quantity: '4.00');
        $this->loginAsAdmin($I);

        $this->postAccept($I, $estimate);
        $this->postAccept($I, $estimate);

        $I->assertSame(0, $this->orderCount($I), 'neither press creates anything');
        $I->assertSame(
            'Accepted',
            $this->estimateStatusColumn($I, (int) $estimate->getId()),
        );
        $I->see('has already been accepted', '.flash-message');
    }

    // --------------------------------------------------------------------- the control on screen

    /**
     * The button is on the quote screen, on an Accepted quote, and only there.
     *
     * Each absence is paired with the presence of the SAME element at the status where it belongs
     * (#627): a `dontSeeElement` that passes because the selector is wrong would pass at every
     * status, and the middle assertion is what catches that.
     */
    public function theConvertControlAppearsOnlyOnAnAcceptedUnconvertedQuote(FunctionalTester $I): void
    {
        $estimate = $this->pricedQuote($I, stock: 100, quantity: '4.00');
        $this->loginAsAdmin($I);
        $selector = 'form[action="/admin/estimate/convert/' . $estimate->getId() . '"] button';

        // Priced: not accepted yet, so nothing to convert.
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement($selector);

        // Accepted and unconverted: the one state that offers it.
        $this->postAccept($I, $estimate);
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->seeElement($selector);
        $I->see('Convert to Sales Order', $selector);

        // Converted: the quote offers its order instead, never a second conversion.
        $this->postConvert($I, $estimate);
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->dontSeeElement($selector);
        $I->seeLink('View Converted Order', '/admin/order/detail/' . $this->onlyOrderId($I));
    }

    // ------------------------------------------------------------------------------- the wording

    /**
     * The admin flash names the order AND says the invoice was not raised, and says where to get
     * one. Anchored to the flash element, so it cannot be satisfied by the words appearing anywhere
     * else on a page that is full of order and invoice vocabulary.
     */
    public function theAdminFlashesSayWhatWasAndWasNotCreated(FunctionalTester $I): void
    {
        $estimate = $this->pricedQuote($I, stock: 100, quantity: '4.00');
        $this->loginAsAdmin($I);

        $this->postAccept($I, $estimate);
        $I->see('No sales order or invoice was created', '.flash-message');
        $I->see('Convert to Sales Order', '.flash-message');

        $this->postConvert($I, $estimate);
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var SalesOrder $order */
        $order = $entityManager->find(SalesOrder::class, $this->onlyOrderId($I));

        $I->see('sales order ' . $order->getOrderNumber(), '.flash-message');
        $I->see('No invoice was raised', '.flash-message');
        $I->see('Convert to Invoice', '.flash-message');
    }

    /**
     * The customer flash names the invoice as well as the order. Wording only — this path's outcome
     * is asserted in customerAcceptStillRaisesTheOrderAndItsInvoice() above.
     *
     * Both numbers are read out of the database and asserted with their label attached
     * ("order SO-…", "invoice INV-…"), never as bare numbers: an invoice number and an order number
     * are both strings of digits and `see('7')` matches '17' (#627).
     */
    public function theCustomerFlashNamesTheInvoiceAsWellAsTheOrder(FunctionalTester $I): void
    {
        $estimate = $this->pricedQuote($I, stock: 100, quantity: '4.00');
        $this->loginAsCustomer($I);

        $this->postCustomerAccept($I, $estimate);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var SalesOrder $order */
        $order = $entityManager->find(SalesOrder::class, $this->onlyOrderId($I));
        $invoice = $order->getInvoices()->first();
        $I->assertNotFalse($invoice, 'guard: the customer path raised an invoice to be named');

        $I->see('order ' . $order->getOrderNumber(), '.flash-message');
        $I->see('invoice ' . $invoice->getDocumentNumber(), '.flash-message');
    }

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * A Priced, fully priced quote for one stocked product, plus the company, region, warehouse and
     * customer behind it. $stock is what the region holds; $quantity is what the quote asks for, so
     * the caller decides whether the conversion can be filled.
     */
    private function pricedQuote(FunctionalTester $I, int $stock, string $quantity): Estimate
    {
        $company = (new Company())
            ->setName('Admin Accept Convert Co')
            ->setCode('AAC-' . uniqid());
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company, self::REGION);
        $this->company = $company;

        $I->haveInRepository(
            (new CompanyAddress())
                ->setCompany($company)
                ->setLabel('Main')
                ->setAddressLine1('1 Wholesale Way')
                ->setCity('Vancouver')
                ->setProvince('BC')
                ->setPostalCode('V5K0A1')
                ->setCountry('CA')
                ->setIsDefaultShipping(true)
                ->setIsDefaultBilling(true)
        );

        $product = (new ProductCore())
            ->setSku('AAC-SKU-' . uniqid())
            ->setName('Accept Convert Widget')
            ->setUnit('EA')
            ->setSalesTaxCode('E')
            ->setCostPrice('4.00')
            ->setDefaultPrice('10.00')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $I->haveStockFor($product, $stock, self::REGION);
        $this->product = $product;

        $lineTotal = (float) $quantity * 10.0;

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('AAC-' . uniqid())
            ->setSource('Customer')
            ->setFulfillmentRegion(self::REGION)
            ->setFeeLines(json_encode([[
                'slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'E',
                'amount' => 0.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc',
            ]]))
            ->setSubtotal((string) $lineTotal)
            ->setTax('0.00')
            ->setTotal((string) $lineTotal);
        $estimate->setStatus('Priced', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setLocation(self::REGION)
                ->setQuantity($quantity)
                ->setPrice('10.00')
                ->setSubtotal((string) $lineTotal)
        );
        $I->haveInRepository($estimate);

        return $estimate;
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail(self::ADMIN_EMAIL);
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function loginAsCustomer(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('aac-buyer-' . uniqid() . '@example.test')
            ->setFirstName('Ada')
            ->setLastName('Buyer')
            ->setCompany($this->company);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');
    }

    // ------------------------------------------------------------------------------------ driving

    /** The real Accept button's POST, with the token scraped off the screen that carries it. */
    private function postAccept(FunctionalTester $I, Estimate $estimate): void
    {
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/accept/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
        ]);
    }

    /**
     * The real Convert button's POST.
     *
     * Deliberately a bare POST rather than a click: the browser confirm on that button is UX only,
     * and every refusal these tests assert has to hold for a request that never saw it.
     */
    private function postConvert(FunctionalTester $I, Estimate $estimate): void
    {
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/convert/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
        ]);
    }

    private function postCustomerAccept(FunctionalTester $I, Estimate $estimate): void
    {
        $I->amOnPage('/estimates/' . $estimate->getId());
        $I->sendFormPostRequest('/estimates/' . $estimate->getId() . '/accept', [
            '_token' => $I->csrfToken(),
        ]);
    }

    // ------------------------------------------------------------------------ reading the columns

    /**
     * Every count below is scoped to this test's own company, which is created fresh per test with
     * a unique code — so "zero orders" means zero for this quote rather than zero in a database
     * another test also wrote to.
     */
    private function connection(FunctionalTester $I): Connection
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        // Cleared so nothing below can be answered out of the identity map: these assertions are
        // about what reached the database, not about what this request happens to be holding.
        $entityManager->clear();

        return $entityManager->getConnection();
    }

    private function orderCount(FunctionalTester $I): int
    {
        return (int) $this->connection($I)->fetchOne(
            'SELECT COUNT(*) FROM sales_order WHERE company_id = ?',
            [(int) $this->company->getId()],
        );
    }

    private function invoiceCount(FunctionalTester $I): int
    {
        return (int) $this->connection($I)->fetchOne(
            'SELECT COUNT(*) FROM invoice WHERE company_id = ?',
            [(int) $this->company->getId()],
        );
    }

    private function invoiceCountForOrder(FunctionalTester $I, int $orderId): int
    {
        return (int) $this->connection($I)->fetchOne(
            'SELECT COUNT(*) FROM invoice WHERE sales_order_id = ?',
            [$orderId],
        );
    }

    private function onlyOrderId(FunctionalTester $I): int
    {
        $ids = $this->connection($I)->fetchFirstColumn(
            'SELECT id FROM sales_order WHERE company_id = ? ORDER BY id',
            [(int) $this->company->getId()],
        );

        $I->assertCount(1, $ids, 'expected exactly one sales order for this quote');

        return (int) $ids[0];
    }

    private function orderStatusColumn(FunctionalTester $I, int $orderId): string
    {
        return (string) $this->connection($I)->fetchOne('SELECT status FROM sales_order WHERE id = ?', [$orderId]);
    }

    private function estimateStatusColumn(FunctionalTester $I, int $estimateId): string
    {
        return (string) $this->connection($I)->fetchOne('SELECT status FROM estimate WHERE id = ?', [$estimateId]);
    }

    private function convertedOrderIdColumn(FunctionalTester $I, int $estimateId): ?int
    {
        $value = $this->connection($I)->fetchOne('SELECT converted_order_id FROM estimate WHERE id = ?', [$estimateId]);

        return $value === null || $value === false ? null : (int) $value;
    }

    /** What the region can still sell, read back through the same resolver the application uses. */
    private function availability(FunctionalTester $I): int
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        $warehouse = $I->grabService(\App\Service\WarehouseFulfillmentRegionService::class)
            ->warehouseForRegionNameOrCreate(self::REGION, 'BC', 'CA');
        $inventory = $entityManager->getRepository(\App\Entity\ProductInventory::class)->findOneBy([
            'product' => $entityManager->find(ProductCore::class, $this->product->getId()),
            'warehouse' => $warehouse,
        ]);

        return $inventory?->getAvailableQuantity() ?? 0;
    }
}
