<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\BackorderFulfillmentEntry;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Backorder end to end through the admin order form and the fulfilment queue (#548).
 *
 * The counterpart to AdminOrderStockCheckCest, which asserts the ceiling this relaxes. Both files
 * exercise the same door — AdminOrderStockValidator::shortfallsFor() — and between them they state
 * the whole rule: an over-quantity save is stopped unless the SKU has been opted in for that
 * warehouse and the cap can cover the shortfall. (Since #326's warn-and-override ruling it is also
 * stopped only while nobody has given a REASON — that half is conducted in AdminStockOverrideCest,
 * and no post in this file carries one.)
 *
 * The invariant is asserted here rather than only in the unit tests because it is a claim about the
 * REAL request path: with `allow_backorder` off, the message an admin reads names no cap at all and
 * the state the database ends in is the pre-#548 one.
 */
final class AdminBackorderCest
{
    private const REGION = 'Backorder Region';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-backorder-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I, string $name = 'Backorder Co'): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('BO-' . uniqid());
        $I->haveInRepository($company);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $region = $entityManager->getRepository(FulfillmentRegion::class)->findOneBy(['name' => self::REGION]);
        if (!$region instanceof FulfillmentRegion) {
            $region = (new FulfillmentRegion())->setName(self::REGION)->setStatus('Active');
            $I->haveInRepository($region);
            $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');
            $entityManager->flush();
        }

        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($company)
                ->setFulfillmentRegion($region)
                ->setStatus('Active')
        );

        return $company;
    }

    private function makeProductWithStock(FunctionalTester $I, int $quantity, string $sku = 'BO-SKU'): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku . '-' . uniqid())
            ->setName('Backorder Widget')
            ->setUnit('EA')
            ->setSalesTaxCode('E')
            ->setDefaultPrice('10.00')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $I->haveStockFor($product, $quantity, self::REGION);

        return $product;
    }

    /** Opt this product's warehouse row in. Nothing in the app does this by itself. */
    private function allowBackorder(FunctionalTester $I, ProductCore $product, ?int $cap = null): void
    {
        $inventory = $this->inventoryFor($I, $product);
        $inventory->setAllowBackorder(true)->setMaxBackorderQuantity($cap);
        // Grabbed AFTER the load, never before: the Symfony module reboots the kernel on every
        // page request, so a manager captured earlier in the test is a different object from the
        // one holding this entity, and its flush() would write nothing while reporting success.
        $I->grabService(EntityManagerInterface::class)->flush();
    }

    /** Stock arriving, written through a manager grabbed for this call and no other. */
    private function restockTo(FunctionalTester $I, ProductCore $product, int $quantity): void
    {
        $this->inventoryFor($I, $product)->setQuantity($quantity);
        $I->grabService(EntityManagerInterface::class)->flush();
    }

    private function inventoryFor(FunctionalTester $I, ProductCore $product): ProductInventory
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->warehouseForRegionName(self::REGION);
        $I->assertInstanceOf(Warehouse::class, $warehouse);

        $inventory = $entityManager->getRepository(ProductInventory::class)->findOneBy([
            'product' => $entityManager->find(ProductCore::class, $product->getId()),
            'warehouse' => $warehouse,
        ]);
        $I->assertInstanceOf(ProductInventory::class, $inventory);

        return $inventory;
    }

    /** @return array<string, mixed> */
    private function post(FunctionalTester $I, Company $company, ProductCore $product, string $qty): array
    {
        return [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => $qty, 'price' => '10.00', 'tax_code' => 'E', 'location' => self::REGION],
            ],
            'save_mode' => 'order',
        ];
    }

    // --- the invariant -------------------------------------------------------------------------

    /**
     * With the flag off, the notice an admin reads mentions no cap and no backorder at all.
     *
     * It used to assert that sentence "word for word", which it no longer can: #326's ruling moved
     * from REFUSING an oversell to stating it and taking a reason, so the sentence now names the
     * shortfall and offers the override (AdminStockOverrideCest conducts that). What this case is
     * actually about is untouched by that — a SKU nobody opted in must not be told about a capacity
     * it does not have — and the `dontSee` below is the half that says so.
     */
    public function withBackorderOffTheNoticeNamesNoCapAtAll(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->post($I, $company, $product, '20'));

        $I->see('20 requested in ' . self::REGION . ' but only 5 available — 15 short.');
        $I->see('Save as a draft, or reduce the quantity.');
        $I->dontSee('backorder capacity remaining');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertNull($entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]));
    }

    // --- accepting beyond stock ----------------------------------------------------------------

    /** The headline case: 20 units against 5 in stock, on a SKU configured to allow it. */
    public function anOptedInSkuAcceptsAnOrderBeyondStockAndSplitsTheLine(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);
        $this->allowBackorder($I, $product);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->post($I, $company, $product, '20'));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        $order = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);
        $I->assertInstanceOf(SalesOrder::class, $order, 'the save was accepted');

        $line = $order->getLines()->first();
        $I->assertSame('15.0000', $line->getBackorderedUnits(), 'five ship, fifteen wait');
        $I->assertSame('Partially Backordered', $line->getFulfillmentStatus());

        // The split is an attribution of one hold, not a second one: five held as stock, fifteen as
        // a promise, and availability exactly where a plain oversell of twenty would have left it.
        $inventory = $this->inventoryFor($I, $product);
        $I->assertSame('5.0000', $inventory->getSalesHoldQuantity());
        $I->assertSame('15.0000', $inventory->getBackorderedQuantity());
        $I->assertSame('-15.0000', $inventory->getAvailableQuantity());

        // And the episode is on the queue for someone to work.
        $entries = $entityManager->getRepository(BackorderFulfillmentEntry::class)->findBy(['order' => $order]);
        $I->assertCount(1, $entries);
        $I->assertSame(BackorderFulfillmentEntry::STATUS_OPEN, $entries[0]->getStatus());
    }

    /**
     * Beyond the cap still stops the save, and the message says why rather than only "5 available".
     *
     * Still refused HERE because this post carries no reason. Since #326's warn-and-override ruling
     * the cap is no longer the last word — an operator who says why goes past it, and the record
     * carries the capacity that was standing behind the line — but nothing about a full cap is
     * waved through silently, which is what this case has always been about.
     */
    public function askingForMoreThanTheCapAllowsIsStillRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);
        $this->allowBackorder($I, $product, cap: 3);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->post($I, $company, $product, '20'));

        $I->see('20 requested in ' . self::REGION . ' but only 5 available and 3 backorder capacity remaining — 12 short.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertNull($entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]));
        $I->assertSame('5.0000', $this->inventoryFor($I, $product)->getAvailableQuantity(), 'a refused save must leave availability alone');
    }

    /** Within the cap, the same order is accepted. */
    public function askingForExactlyWhatTheCapAllowsIsAccepted(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);
        $this->allowBackorder($I, $product, cap: 3);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->post($I, $company, $product, '8'));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        $order = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);
        $I->assertInstanceOf(SalesOrder::class, $order);
        $I->assertSame('3.0000', $order->getLines()->first()->getBackorderedUnits());
    }

    // --- the queue screen ----------------------------------------------------------------------

    public function theQueueListsAnOpenEpisodeAndReleasingItClosesIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);
        $this->allowBackorder($I, $product);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->post($I, $company, $product, '20'));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);
        $I->assertInstanceOf(SalesOrder::class, $order);

        $I->amOnPage('/admin/backorders');
        $I->seeResponseCodeIsSuccessful();
        $I->see($order->getOrderNumber());
        $I->see($product->getSku());

        // Fifteen arrive. Manual mode, so nothing moves until an admin says so.
        $this->restockTo($I, $product, 20);

        $I->amOnPage('/admin/backorders/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($product->getSku());

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entries = $entityManager->getRepository(BackorderFulfillmentEntry::class)->findBy(['order' => $order->getId()]);
        $I->assertCount(1, $entries);

        $I->sendFormPostRequest('/admin/backorders/' . $order->getId() . '/release', [
            '_token' => $I->csrfToken(),
            'release' => [(string) $entries[0]->getId() => '15'],
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $order = $entityManager->getRepository(SalesOrder::class)->find($order->getId());
        $I->assertSame('0.0000', $order->getLines()->first()->getBackorderedUnits());
        $I->assertSame('Fulfilled', $order->getLines()->first()->getFulfillmentStatus());

        $entries = $entityManager->getRepository(BackorderFulfillmentEntry::class)->findBy(['order' => $order->getId()]);
        $I->assertSame(BackorderFulfillmentEntry::STATUS_COMPLETE, $entries[0]->getStatus());

        // Twenty units, twenty held, nothing promised and nothing spare.
        $inventory = $this->inventoryFor($I, $product);
        $I->assertSame('20.0000', $inventory->getSalesHoldQuantity());
        $I->assertSame('0.0000', $inventory->getBackorderedQuantity());
        $I->assertSame('0.0000', $inventory->getAvailableQuantity());
    }

    // --- the queue's filters, conducted (#624) ------------------------------------------------

    /**
     * Two customers waiting, one filter, and the other customer is GONE from the grid.
     *
     * #624's shape applied to a list screen: two episodes are created through the real order
     * screen, the real grid is driven, and the assertion that matters is the row that must NOT be
     * there. A filter proved only by "the row I wanted is present" is a filter that could be
     * returning everything — which is exactly what the credit note grid's search box does, since it
     * has no handler behind it at all.
     *
     * The no-JavaScript half is asserted the way the rest of this suite asserts it: the controls
     * that make a plain submit possible are checked to BE on the page — a GET `<form>`, the field
     * joined to it by `form=`, and a real submit button, which is what makes Enter work — and then
     * the request that form would build is sent. (`submitForm()` cannot be used on an admin screen
     * at all: the crawler resolves the action against the admin.localhost Host header and the module
     * refuses it as external. Precedent and reasoning on AdminNoJsVendorMasterDataCest.) Nothing
     * here goes near the onchange handlers on the two selects, which are a convenience over the top
     * of the button and never the only way through.
     *
     * Every figure is read out of the cell it belongs to and compared, never `see()`n (#627) — this
     * grid is quantities and order numbers, and `see('15')` matches an id, a page number and any
     * '150' on the page.
     */
    public function filteringByCustomerLeavesTheOtherCustomersLineOut(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $waiting = $this->makeCompany($I, 'Waiting Hardware Ltd');
        $waitingProduct = $this->makeProductWithStock($I, 5, 'BO-WAIT');
        $this->allowBackorder($I, $waitingProduct);

        $other = $this->makeCompany($I, 'Unrelated Supplies Inc');
        $otherProduct = $this->makeProductWithStock($I, 5, 'BO-OTHER');
        $this->allowBackorder($I, $otherProduct);

        $waitingOrder = $this->placeBackorderedOrder($I, $waiting, $waitingProduct, '20');
        $otherOrder = $this->placeBackorderedOrder($I, $other, $otherProduct, '9');

        // Positive control, and it is doing two jobs: the screen renders at all, and BOTH episodes
        // are on it before anything is narrowed. Without this, "the other row is absent" below is
        // equally consistent with a page that rendered nothing.
        $I->amOnPage('/admin/backorders');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement($this->rowFor($waitingOrder));
        $I->seeElement($this->rowFor($otherOrder));

        $I->assertSame('15', $this->cell($I, $waitingOrder, 'Backordered'), 'twenty ordered against five in stock');
        $I->assertSame('4', $this->cell($I, $otherOrder, 'Backordered'), 'nine ordered against five in stock');

        // Everything a browser with scripting off needs in order to send the request below: the
        // GET form, the box joined to it by id, and a button to press.
        $I->seeElement('form#backorder-filters[method="get"]');
        $I->seeElement('thead tr.filter-row input[name="filters[customer]"][form="backorder-filters"]');
        $I->seeElement('form#backorder-filters button[type="submit"]');

        $I->amOnPage('/admin/backorders?filters%5Bcustomer%5D=Waiting+Hardware');
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement($this->rowFor($waitingOrder));
        $I->dontSeeElement($this->rowFor($otherOrder));
        $I->assertSame('Waiting Hardware Ltd', $this->cell($I, $waitingOrder, 'Customer'));
        $I->assertSame('15', $this->cell($I, $waitingOrder, 'Backordered'), 'the surviving row still reads its own quantity');

        // And the footer agrees with the grid rather than with the database: one of two. Read out
        // of its own element and compared whole, never see()n — 'of 1' is in 'of 12' (#627).
        $I->assertSame(
            'Showing 1 to 1 of 1 backorders',
            trim((string) preg_replace('/\s+/', ' ', $I->grabTextFrom('.table-footer .table-count'))),
        );
    }

    /**
     * The SKU filter is the other half of the same question — a pallet lands, who is waiting for it
     * — and it excludes the SKU nobody asked about.
     */
    public function filteringBySkuLeavesTheOtherSkuOut(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Two SKU Co');
        $wanted = $this->makeProductWithStock($I, 5, 'BO-WANTED');
        $ignored = $this->makeProductWithStock($I, 5, 'BO-IGNORED');
        $this->allowBackorder($I, $wanted);
        $this->allowBackorder($I, $ignored);

        $wantedOrder = $this->placeBackorderedOrder($I, $company, $wanted, '20');
        $ignoredOrder = $this->placeBackorderedOrder($I, $company, $ignored, '20');

        $I->amOnPage('/admin/backorders');
        $I->seeElement($this->rowFor($wantedOrder));
        $I->seeElement($this->rowFor($ignoredOrder));

        $I->amOnPage('/admin/backorders?filters%5Bsku%5D=BO-WANTED');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement($this->rowFor($wantedOrder));
        $I->dontSeeElement($this->rowFor($ignoredOrder));
        $I->assertSame('15', $this->cell($I, $wantedOrder, 'Backordered'));
    }

    /**
     * The status bar narrows to the work, and a resolved episode is not in it.
     *
     * Also pins the default: a bare /admin/backorders is the worklist, not the history. That was
     * this screen's behaviour before it had a filter bar and it is still its behaviour, which is
     * the thing most easily lost when a screen gains an All tab.
     */
    public function theStatusBarSeparatesTheWorklistFromTheHistory(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Status Bar Co');
        $resolved = $this->makeProductWithStock($I, 5, 'BO-RESOLVED');
        $stillOpen = $this->makeProductWithStock($I, 5, 'BO-STILLOPEN');
        $this->allowBackorder($I, $resolved);
        $this->allowBackorder($I, $stillOpen);

        $resolvedOrder = $this->placeBackorderedOrder($I, $company, $resolved, '20');
        $openOrder = $this->placeBackorderedOrder($I, $company, $stillOpen, '20');

        // Stock arrives for one of them and an admin hands it out, which closes that episode.
        $this->restockTo($I, $resolved, 20);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entries = $entityManager->getRepository(BackorderFulfillmentEntry::class)->findBy(['order' => $resolvedOrder->getId()]);
        $I->assertCount(1, $entries);

        $I->amOnPage('/admin/backorders/' . $resolvedOrder->getId());
        $I->sendFormPostRequest('/admin/backorders/' . $resolvedOrder->getId() . '/release', [
            '_token' => $I->csrfToken(),
            'release' => [(string) $entries[0]->getId() => '15'],
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entries = $entityManager->getRepository(BackorderFulfillmentEntry::class)->findBy(['order' => $resolvedOrder->getId()]);
        $I->assertSame(BackorderFulfillmentEntry::STATUS_COMPLETE, $entries[0]->getStatus(), 'the episode really did close');

        // The default: open work only. The resolved episode exists and is deliberately not here.
        $I->amOnPage('/admin/backorders');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement($this->rowFor($openOrder));
        $I->dontSeeElement($this->rowFor($resolvedOrder));
        $I->assertSame('Open', $this->cell($I, $openOrder, 'Status'));

        // The Complete tab is the mirror image, which is what says the first result was a filter
        // rather than a screen that has lost a row.
        $I->amOnPage('/admin/backorders?filters%5Bstatus%5D=Complete');
        $I->seeElement($this->rowFor($resolvedOrder));
        $I->dontSeeElement($this->rowFor($openOrder));
        $I->assertSame('Complete', $this->cell($I, $resolvedOrder, 'Status'));
        $I->assertSame('-', $this->cell($I, $resolvedOrder, 'Releasable now'), 'a resolved row draws on nothing');

        // And the All tab shows both, so neither of the two above was hiding a row by accident.
        $I->amOnPage('/admin/backorders?filters%5Bstatus%5D=');
        $I->seeElement($this->rowFor($openOrder));
        $I->seeElement($this->rowFor($resolvedOrder));
    }

    /**
     * Everything the grid needs to be driven: an order placed beyond stock on an opted-in SKU,
     * returned re-read from the database.
     */
    private function placeBackorderedOrder(FunctionalTester $I, Company $company, ProductCore $product, string $quantity): SalesOrder
    {
        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->post($I, $company, $product, $quantity));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        $orders = $entityManager->getRepository(SalesOrder::class)->findBy(['company' => $company], ['id' => 'DESC']);
        $I->assertNotEmpty($orders, 'the order screen accepted the save');

        return $orders[0];
    }

    /** The one row this order's episode renders as, by the order number in its Order cell. */
    private function rowFor(SalesOrder $order): string
    {
        return '//tbody/tr[td[@data-label="Order"]//a[normalize-space()="' . $order->getOrderNumber() . '"]]';
    }

    /**
     * One cell of that row, by the column's own `data-label` rather than by position.
     *
     * By label because a column inserted to the left of the one being asserted would otherwise move
     * the answer silently — the grid is twelve columns wide and every one of them is a number or a
     * date.
     */
    private function cell(FunctionalTester $I, SalesOrder $order, string $column): string
    {
        return trim($I->grabTextFrom($this->rowFor($order) . '/td[@data-label="' . $column . '"]'));
    }

    /**
     * The form is not trusted. Asking to release more than the warehouse can cover releases only
     * what it can, which is the same clamp the automatic path runs through.
     */
    public function releasingMoreThanTheWarehouseHasReleasesOnlyWhatItHas(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);
        $this->allowBackorder($I, $product);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->post($I, $company, $product, '20'));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);
        $I->assertInstanceOf(SalesOrder::class, $order);

        // Only four of the fifteen arrive.
        $this->restockTo($I, $product, 9);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entries = $entityManager->getRepository(BackorderFulfillmentEntry::class)->findBy(['order' => $order->getId()]);

        // The release form's token is scraped off the release page, so it has to be the page in
        // hand when the POST is built.
        $I->amOnPage('/admin/backorders/' . $order->getId());
        $I->sendFormPostRequest('/admin/backorders/' . $order->getId() . '/release', [
            '_token' => $I->csrfToken(),
            'release' => [(string) $entries[0]->getId() => '999'],
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $order = $entityManager->getRepository(SalesOrder::class)->find($order->getId());
        $I->assertSame('11.0000', $order->getLines()->first()->getBackorderedUnits(), 'four released, eleven still waiting');

        $entries = $entityManager->getRepository(BackorderFulfillmentEntry::class)->findBy(['order' => $order->getId()]);
        $I->assertSame(BackorderFulfillmentEntry::STATUS_OPEN, $entries[0]->getStatus(), 'the episode is not finished');
    }

    // --- #630: one submission, two entries, one pool of arrived stock ---------------------------

    /**
     * The three ProductInventory columns this file argues about, read back off the row itself.
     *
     * Raw SQL and not the entity, because the claim under test is about what was COMMITTED. Every
     * getter that matters here — getAvailableQuantity(), getStockAvailableToRelease() — is derived
     * arithmetic over these three, so asserting through them would let a wrong number in one column
     * be cancelled by a wrong number in another and still read correct. The same reason
     * AdminCreditMemoCreateEntryPointsCest reads its applied totals by column.
     *
     * @return array{quantity: int, sales_hold_quantity: int, backordered_quantity: int}
     */
    private function inventoryColumns(FunctionalTester $I, ProductCore $product): array
    {
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->warehouseForRegionName(self::REGION);
        $I->assertInstanceOf(Warehouse::class, $warehouse);

        $row = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT quantity, sales_hold_quantity, backordered_quantity FROM product_inventory WHERE product_id = ? AND warehouse_id = ?',
            [$product->getId(), $warehouse->getId()],
        );
        $I->assertIsArray($row, 'the product has an inventory row in this warehouse');

        return [
            'quantity' => (int) $row['quantity'],
            'sales_hold_quantity' => (int) $row['sales_hold_quantity'],
            'backordered_quantity' => (int) $row['backordered_quantity'],
        ];
    }

    /**
     * Two entries on one order, same product and warehouse, both waiting. Releasing against both in
     * a single submission must not draw on the same arrived stock twice: applyRelease() only touches
     * the SalesOrderLine, so the bucket figures behind getStockAvailableToRelease() do not move until
     * the request's one flush() — a second entry's check has to see what the first entry already
     * spent, not the still-unflushed database value (#630).
     *
     * ## Why two entries and not one
     *
     * releasingMoreThanTheWarehouseHasReleasesOnlyWhatItHas() above asserts the same clamp with one
     * entry, and it PASSES against the broken controller: with a single entry there is no second
     * read to go stale. The whole defect is the second entry in the same submission re-reading a
     * figure the first entry has already spent, so a one-entry test proves nothing about it.
     *
     * ## What is asserted, and against which numbers
     *
     * Five units on hand, forty ordered across two lines of one SKU, so thirty-five are promised.
     * Then four units arrive — a restock of 5 -> 9 — and the admin releases against BOTH entries in
     * one POST, which is what the screen's own suggested figures would send.
     *
     * Four arrived, so at most four may ever leave `backordered_quantity`. The unfixed controller
     * releases eight. The assertion that names the damage in one line is the last one: a warehouse
     * cannot hold more units than it physically has, and with eight released `sales_hold_quantity`
     * reaches thirteen against a `quantity` of nine — four units sold that do not exist.
     */
    public function releasingTwoEntriesOnOneSkuInOneSubmissionDoesNotDoubleSpendTheStock(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);
        $this->allowBackorder($I, $product);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '20', 'price' => '10.00', 'tax_code' => 'E', 'location' => self::REGION],
                ['product_id' => (string) $product->getId(), 'qty' => '20', 'price' => '10.00', 'tax_code' => 'E', 'location' => self::REGION],
            ],
            'save_mode' => 'order',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);
        $I->assertInstanceOf(SalesOrder::class, $order);

        // Five in stock, forty requested across the two lines: the pool is drawn down line by line,
        // so the first line takes what there is and the second gets none of the real stock.
        $lines = $order->getLines()->toArray();
        $I->assertSame('35.0000', QuantityScale::add($lines[0]->getBackorderedUnits(), $lines[1]->getBackorderedUnits()));

        // Only four more arrive — nowhere near enough to clear either line, let alone both.
        $this->restockTo($I, $product, 9);

        $entries = $entityManager->getRepository(BackorderFulfillmentEntry::class)->findBy(['order' => $order->getId()]);
        $I->assertCount(2, $entries, 'one episode per line');

        $before = $this->inventoryColumns($I, $product);
        $arrived = $before['quantity'] - 5;
        $I->assertSame(4, $arrived, 'four units arrived');
        $I->assertSame(5, $before['sales_hold_quantity'], 'the five that were already here are held');
        $I->assertSame(35, $before['backordered_quantity'], 'thirty-five promised and not yet covered');

        // Ask for everything the page would suggest against BOTH entries in the same submission —
        // exactly what an admin clicking through a two-line SKU would send.
        $I->amOnPage('/admin/backorders/' . $order->getId());
        $I->sendFormPostRequest('/admin/backorders/' . $order->getId() . '/release', [
            '_token' => $I->csrfToken(),
            'release' => [
                (string) $entries[0]->getId() => (string) $arrived,
                (string) $entries[1]->getId() => (string) $arrived,
            ],
        ]);

        $after = $this->inventoryColumns($I, $product);
        $released = $before['backordered_quantity'] - $after['backordered_quantity'];

        $I->assertSame(9, $after['quantity'], 'a release moves stock between buckets and never invents any');
        $I->assertLessThanOrEqual(
            $arrived,
            $released,
            sprintf('%d unit(s) arrived but %d were released across the two entries', $arrived, $released),
        );
        $I->assertSame(4, $released, 'the four that arrived left the backordered bucket, once');
        $I->assertSame(31, $after['backordered_quantity'], 'thirty-one still waiting on stock that has not arrived');

        // A released unit turns a promise into a real hold, so the two columns move together.
        $I->assertSame(
            $before['sales_hold_quantity'] + $released,
            $after['sales_hold_quantity'],
            'every unit that left `backordered` arrived in `sales_hold`',
        );

        // The whole point, in one column comparison: the warehouse cannot be holding more units
        // for customers than it physically has on the shelf.
        $I->assertLessThanOrEqual(
            $after['quantity'],
            $after['sales_hold_quantity'],
            sprintf(
                'sales_hold_quantity %d exceeds quantity %d — units have been sold that do not exist',
                $after['sales_hold_quantity'],
                $after['quantity'],
            ),
        );

        // And the lines agree with the row: thirty-five promised less the four released.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $order = $entityManager->getRepository(SalesOrder::class)->find($order->getId());
        $lines = $order->getLines()->toArray();
        $I->assertSame(
            '31.0000',
            QuantityScale::add($lines[0]->getBackorderedUnits(), $lines[1]->getBackorderedUnits()),
            'only the stock that actually arrived may be released, once',
        );
    }

    /**
     * The positive control for the clamp above: the pool is per inventory ROW, not per submission.
     *
     * The fix tracks what has been spent in a map keyed by ProductInventory id. A pool that was
     * global to the request instead would also make the test above pass — and would be wrong, by
     * starving the second SKU of stock that is sitting in its own warehouse row untouched. So the
     * same two-entries-one-POST shape is run against two DIFFERENT products, and each is asserted
     * to have released its own four units: eight in total, from two rows that each had four.
     *
     * Same element, opposite expectation — the released figure is read off `backordered_quantity`
     * for each product exactly as above, so a regression that collapses the two pools into one
     * shows up here as the second product releasing nothing.
     */
    public function releasingTwoEntriesOnDifferentSkusInOneSubmissionEachDrawOnItsOwnStock(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $first = $this->makeProductWithStock($I, 5, 'BO-POOL-A');
        $second = $this->makeProductWithStock($I, 5, 'BO-POOL-B');
        $this->allowBackorder($I, $first);
        $this->allowBackorder($I, $second);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'lines' => [
                ['product_id' => (string) $first->getId(), 'qty' => '20', 'price' => '10.00', 'tax_code' => 'E', 'location' => self::REGION],
                ['product_id' => (string) $second->getId(), 'qty' => '20', 'price' => '10.00', 'tax_code' => 'E', 'location' => self::REGION],
            ],
            'save_mode' => 'order',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);
        $I->assertInstanceOf(SalesOrder::class, $order);

        // Four arrive for each, in their own warehouse row.
        $this->restockTo($I, $first, 9);
        $this->restockTo($I, $second, 9);

        $beforeFirst = $this->inventoryColumns($I, $first);
        $beforeSecond = $this->inventoryColumns($I, $second);
        $I->assertSame(15, $beforeFirst['backordered_quantity'], 'fifteen promised on the first SKU');
        $I->assertSame(15, $beforeSecond['backordered_quantity'], 'fifteen promised on the second SKU');

        $entries = $entityManager->getRepository(BackorderFulfillmentEntry::class)->findBy(['order' => $order->getId()]);
        $I->assertCount(2, $entries, 'one episode per line');

        $I->amOnPage('/admin/backorders/' . $order->getId());
        $I->sendFormPostRequest('/admin/backorders/' . $order->getId() . '/release', [
            '_token' => $I->csrfToken(),
            'release' => [
                (string) $entries[0]->getId() => '4',
                (string) $entries[1]->getId() => '4',
            ],
        ]);

        $afterFirst = $this->inventoryColumns($I, $first);
        $afterSecond = $this->inventoryColumns($I, $second);

        $I->assertSame(
            4,
            $beforeFirst['backordered_quantity'] - $afterFirst['backordered_quantity'],
            'the first SKU released the four that arrived for it',
        );
        $I->assertSame(
            4,
            $beforeSecond['backordered_quantity'] - $afterSecond['backordered_quantity'],
            'the second SKU released its own four, not what the first one left',
        );

        // Neither row is over-held, on its own stock.
        $I->assertSame(9, $afterFirst['sales_hold_quantity']);
        $I->assertSame(9, $afterSecond['sales_hold_quantity']);
        $I->assertLessThanOrEqual($afterFirst['quantity'], $afterFirst['sales_hold_quantity']);
        $I->assertLessThanOrEqual($afterSecond['quantity'], $afterSecond['sales_hold_quantity']);
    }
}
