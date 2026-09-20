<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
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
 * The admin order form's stock ceiling (#326).
 *
 * The customer side has always had this: CartService caps or removes a line that exceeds
 * availability. The admin side had nothing — ProductInventory was never consulted anywhere in order
 * create or edit — so an admin could save 500 units of a product with 2 in stock and the system
 * took it. Reconciliation then wrote those 500 into a hold bucket after the fact, because it records
 * what an order says rather than judging it, and
 * getAvailableQuantity() went negative. A negative availability makes the SKU unbuyable for every
 * customer afterwards, so this is not only a bad order — it is a self-inflicted denial of sale.
 *
 * Two rules:
 *
 *   draft            may hold any quantity — it reserves nothing, so there is nothing to protect
 *   anything else    every line must fit, or the save is refused before anything is written
 *
 * The refusal deliberately happens at the door, alongside the line and charge-row checks, because a
 * fee calculator flushes partway through the save: a quantity that cannot be honoured has to be
 * turned away before that, not discovered halfway through committing it.
 *
 * @group bundle-agnostic
 *
 * Tagged for the bundles-off run (#562): these assertions must hold identically with the
 * optional inventory bundles Inactive. If a change here can only pass with them Active, the
 * change has leaked out of its bundle.
 */
final class AdminOrderStockCheckCest
{
    /** The region the orders below actually use. */
    private const REGION = 'Stock Check Region';

    /**
     * Two decoys, and they are deliberately hostile rather than decorative.
     *
     * With a single region in the database every lookup finds the only row there is, so a bug that
     * ignored the region — or took the first one, or the company's rather than the line's — would
     * pass every test in this file. These two exist to make that impossible:
     *
     *   RICH is stocked far HIGHER than REGION, so code reading the wrong region would wrongly
     *   ALLOW an over-quantity save that must be refused.
     *   BARE is stocked at zero, so code reading the wrong region would wrongly REFUSE a save that
     *   must be allowed.
     *
     * One catches a false pass, the other a false failure. Both are assigned to the same company
     * and are alphabetically either side of REGION, so ordering cannot accidentally do the right
     * thing.
     */
    private const REGION_RICH = 'AAA Rich Decoy Region';
    private const REGION_BARE = 'ZZZ Bare Decoy Region';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-order-stock-check@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Stock Check Co')
            ->setCode('STOCK-' . uniqid());
        $I->haveInRepository($company);

        // All three, so the company genuinely has a choice and the code has to make the right one.
        foreach ([self::REGION_RICH, self::REGION, self::REGION_BARE] as $name) {
            $this->assignRegion($I, $company, $name);
        }

        return $company;
    }

    private function assignRegion(FunctionalTester $I, Company $company, string $name): FulfillmentRegion
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $region = $entityManager->getRepository(FulfillmentRegion::class)->findOneBy(['name' => $name]);
        if (!$region instanceof FulfillmentRegion) {
            $region = (new FulfillmentRegion())->setName($name)->setStatus('Active');
            $I->haveInRepository($region);
            $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');
            $I->grabService(EntityManagerInterface::class)->flush();
        }

        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($company)
                ->setFulfillmentRegion($region)
                ->setStatus('Active')
        );

        return $region;
    }

    /**
     * A product with exactly $quantity on hand in REGION — and deliberately different figures in the
     * two decoys, so every assertion below is really about the line's own region.
     *
     * The rich decoy is stocked at $quantity + 1000: any check that read it instead would let an
     * over-quantity save through. The bare decoy is stocked at zero: any check that read THAT would
     * refuse a save that should succeed. A test can only pass by consulting the right one.
     */
    private function makeProductWithStock(FunctionalTester $I, int $quantity): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('STOCK-SKU-' . uniqid())
            ->setName('Stock Check Widget')
            ->setUnit('EA')
            ->setSalesTaxCode('E')
            ->setDefaultPrice('10.00')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $I->haveStockFor($product, $quantity, self::REGION);
        $I->haveStockFor($product, $quantity + 1000, self::REGION_RICH);
        $I->haveStockFor($product, 0, self::REGION_BARE);

        return $product;
    }

    /**
     * $status is Draft or Approved — the only two an order can be PUT INTO since #539 stage 2, which
     * removed setStatus() in favour of the named actions. Approved is the successor to the Pending
     * these fixtures used: it reserves stock (OrderInventoryBucketResolver::PENDING_STATUSES), which
     * is the only property this file cares about.
     */
    private function makeOrder(FunctionalTester $I, Company $company, ProductCore $product, string $status, string $qty): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('STOCK-' . uniqid())
            ->setFulfillmentRegion(self::REGION)
            ->setSubtotal('10.00')
            ->setTotal('10.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setQuantity($qty)
                ->setPrice('10.00')
                ->setSubtotal('10.00')
                ->setTaxCode('E')
        );

        match ($status) {
            'Draft' => null,
            'Approved' => $order->setStatus('Approved', DocumentActor::system(), 'Order approved.'),
        };

        $I->haveInRepository($order);

        return $order;
    }

    private function post(FunctionalTester $I, Company $company, ProductCore $product, string $qty, string $saveMode): array
    {
        return [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => $qty, 'price' => '10.00', 'tax_code' => 'E', 'location' => self::REGION],
            ],
            'save_mode' => $saveMode,
        ];
    }

    private function availability(FunctionalTester $I, ProductCore $product): string
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        // The order names a REGION; its stock is the warehouse serving that region (#546).
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->warehouseForRegionName(self::REGION);
        $inventory = $entityManager->getRepository(ProductInventory::class)->findOneBy([
            'product' => $entityManager->find(ProductCore::class, $product->getId()),
            'warehouse' => $warehouse,
        ]);

        return $inventory?->getAvailableQuantity() ?? '0.0000';
    }

    // ---------------------------------------------------------------- create

    /** The headline case: 500 units of a product with 5 in stock, saved live. */
    public function creatingALiveOrderBeyondStockIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->post($I, $company, $product, '500', 'order'));

        $I->see('500 requested');
        $I->see('only 5 available');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertNull(
            $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]),
            'an order was created for more units than exist'
        );
        // The whole point: availability must not have been driven negative.
        $I->assertSame('5.0000', $this->availability($I, $product));
    }

    /** A draft reserves nothing, so it may hold anything — the issue is explicit about this. */
    public function creatingADraftBeyondStockIsAllowed(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->post($I, $company, $product, '500', 'draft_recalc'));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $created = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);
        $I->assertNotNull($created, 'a draft beyond stock should still be saveable');
        $I->assertSame('Draft', $created->getStatus());
        // A draft holds nothing, so availability is untouched.
        $I->assertSame('5.0000', $this->availability($I, $product));
    }

    public function creatingALiveOrderWithinStockIsAllowed(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->post($I, $company, $product, '5', 'order'));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $created = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);
        $I->assertNotNull($created);
        // Approved, not Invoiced: an admin-raised order carries no invoice until someone converts
        // it (#539), so nothing has been billed and the whole of it is still to invoice. Approved
        // is a stock-holding status exactly as the old live statuses were, which is what the
        // availability assertion below depends on.
        $I->assertSame('Approved', $created->getStatus());
        // Exactly consumed, never negative.
        $I->assertSame('0.0000', $this->availability($I, $product));
    }

    /**
     * A product with no ProductInventory row reads as zero, not as untracked. Reconciliation CREATES
     * the row when it is missing and applies the reservation to it, so an unstocked product on a
     * live order lands the inventory at a negative — exactly the defect being closed. Treating a
     * missing row as unlimited would leave that hole open through any unstocked SKU.
     */
    public function aProductWithNoInventoryRowIsTreatedAsZeroAvailable(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);

        $product = (new ProductCore())
            ->setSku('STOCK-SKU-NONE-' . uniqid())
            ->setName('Never Stocked Widget')
            ->setSalesTaxCode('E')
            ->setDefaultPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->post($I, $company, $product, '1', 'order'));

        $I->see('only 0 available');
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertNull($entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]));
    }

    /** Two lines of the same product compete for one pool — checked as a sum, not row by row. */
    public function twoLinesOfTheSameProductAreSummedAgainstOnePool(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 6);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '5', 'price' => '10.00', 'tax_code' => 'E', 'location' => self::REGION],
                ['product_id' => (string) $product->getId(), 'qty' => '5', 'price' => '10.00', 'tax_code' => 'E', 'location' => self::REGION],
            ],
            'save_mode' => 'order',
        ]);

        // 5 + 5 against 6 must fail; checking each row alone would pass both.
        $I->see('10 requested');
        $I->see('only 6 available');
    }

    // ---------------------------------------------------------------- edit

    /**
     * The trap this check has to avoid. An order already Approved for 5 units has those 5 inside
     * salesHoldQuantity, so getAvailableQuantity() already excludes them. Without adding the order's
     * own reservation back, re-saving it unchanged would compare 5 against an availability of 0 and
     * refuse a save that changes nothing — the same mistake #214 was on the cart side.
     */
    public function resavingALiveOrderUnchangedIsNotBlockedByItsOwnReservation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);
        $order = $this->makeOrder($I, $company, $product, 'Approved', '5.00');

        // Let reconciliation record the order's hold, so availability really is 0 before the edit.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->find(SalesOrder::class, $order->getId())->setPoNumber('PO-BEFORE');
        $entityManager->flush();
        $I->assertSame('0.0000', $this->availability($I, $product), 'the order should be holding all 5 units');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest(
            '/admin/order/edit/' . $order->getId(),
            $this->post($I, $company, $product, '5', 'order') + ['po_number' => 'PO-AFTER']
        );

        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());
        $I->assertSame('PO-AFTER', $saved->getPoNumber(), 'the order refused a save that changed nothing about its quantity');
        $I->assertSame(5.0, (float) $saved->getLines()->first()->getQuantity());
    }

    /** And it can still be raised as far as the stock its own hold frees up, but no further. */
    public function raisingALiveOrderBeyondItsOwnHoldPlusStockIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);
        $order = $this->makeOrder($I, $company, $product, 'Approved', '3.00');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->find(SalesOrder::class, $order->getId())->setPoNumber('PO-HOLD');
        $entityManager->flush();

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $this->post($I, $company, $product, '6', 'order'));

        $I->see('6 requested');
        $I->see('only 5 available');

        $entityManager->clear();
        $I->assertSame(3.0, (float) $entityManager->find(SalesOrder::class, $order->getId())->getLines()->first()->getQuantity());
    }

    /** Editing a DRAFT is never refused, however large the quantity. */
    public function editingADraftBeyondStockIsAllowed(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);
        $order = $this->makeOrder($I, $company, $product, 'Draft', '1.00');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $this->post($I, $company, $product, '500', 'draft_recalc'));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());
        $I->assertSame('Draft', $saved->getStatus());
        $I->assertSame(500.0, (float) $saved->getLines()->first()->getQuantity());
        $I->assertSame('5.0000', $this->availability($I, $product));
    }

    /** Promoting that same over-quantity draft to live IS refused — the transition is the gate. */
    public function promotingAnOverQuantityDraftToLiveIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);
        $order = $this->makeOrder($I, $company, $product, 'Draft', '500.00');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $this->post($I, $company, $product, '500', 'order'));

        $I->see('only 5 available');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertSame('Draft', $entityManager->find(SalesOrder::class, $order->getId())->getStatus());
        $I->assertSame('5.0000', $this->availability($I, $product), 'a refused promotion must not have reserved anything');
    }

    // ---------------------------------------------------------------- the RIGHT region, not any region

    /**
     * The line sits in the bare region, which has none of it, while the order header and two other
     * regions are richly stocked. Only a check that reads the LINE's region refuses this.
     *
     * Without it, every other test in this file would still pass on a build that ignored regions
     * entirely — which is the whole reason the decoys exist.
     */
    public function aLineIsCheckedAgainstItsOwnRegionNotTheOrderHeadersOrAnyOther(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 50);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            // Header says the well-stocked region...
            'fulfillment_region' => self::REGION,
            'lines' => [
                // ...but the line is fulfilled from the empty one, and the line wins.
                ['product_id' => (string) $product->getId(), 'qty' => '1', 'price' => '10.00', 'tax_code' => 'E', 'location' => self::REGION_BARE],
            ],
            'save_mode' => 'order',
        ]);

        $I->see('only 0 available');
        $I->see(self::REGION_BARE);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertNull($entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]));
    }

    /** And the inverse: a line in a rich region is allowed even though another region has nothing. */
    public function aLineInAWellStockedRegionIsNotRefusedBecauseAnotherRegionIsEmpty(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 50);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'lines' => [
                // 300 exceeds REGION's 50, but the rich decoy holds 1050 and that is where it ships.
                ['product_id' => (string) $product->getId(), 'qty' => '300', 'price' => '10.00', 'tax_code' => 'E', 'location' => self::REGION_RICH],
            ],
            'save_mode' => 'order',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertNotNull(
            $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]),
            'a line was refused against a region it is not being fulfilled from'
        );
    }

    /**
     * Two lines of the same product in DIFFERENT regions draw on different pools, so they must not
     * be summed together — 40 and 40 against 50 each is fine, while 40 and 40 in one region is not
     * (which twoLinesOfTheSameProductAreSummedAgainstOnePool pins).
     */
    public function linesInDifferentRegionsAreNotSummedAgainstOnePool(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 50);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '40', 'price' => '10.00', 'tax_code' => 'E', 'location' => self::REGION],
                ['product_id' => (string) $product->getId(), 'qty' => '40', 'price' => '10.00', 'tax_code' => 'E', 'location' => self::REGION_RICH],
            ],
            'save_mode' => 'order',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertNotNull(
            $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]),
            '80 units across two regions holding 50 and 1050 was refused as though one pool'
        );
    }

    // ---------------------------------------------------------------- the form shows the ceiling

    public function theEditFormShowsAvailableQuantityBesideTheLineQuantity(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 12);
        $order = $this->makeOrder($I, $company, $product, 'Draft', '2.00');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        // Read out of the hint, not off the page (#627): see('12 available') is a substring match,
        // so '112 available' and '1012 available' satisfied it. This is the figure the save path
        // ENFORCES, so a hint that silently drifted would tell an admin a ceiling the server does
        // not use.
        $I->assertSame(
            ['12 available'],
            array_map(static fn (string $t): string => trim(preg_replace('/\s+/', ' ', $t)), $I->grabMultiple('.line-stock-hint')),
            'product_inventory.quantity 12, one hint on the one line',
        );
    }

    /**
     * The figure shown must be the figure enforced. A live order's own hold is added back, so an
     * order holding all 5 units shows 5 rather than 0 — otherwise the form would tell an admin they
     * cannot save a quantity the server accepts.
     */
    public function theShownAvailabilityAddsBackTheOrdersOwnHold(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);
        $order = $this->makeOrder($I, $company, $product, 'Approved', '5.00');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->find(SalesOrder::class, $order->getId())->setPoNumber('PO-HOLD');
        $entityManager->flush();
        $I->assertSame('0.0000', $this->availability($I, $product), 'the order should be holding all 5 units');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        // Exact, out of the hint (#627): see('5 available') is satisfied by '15 available' and
        // '105 available' alike — and 0, the figure this test exists to rule out, is the one thing
        // it would have caught.
        $I->assertSame(
            ['5 available'],
            array_map(static fn (string $t): string => trim(preg_replace('/\s+/', ' ', $t)), $I->grabMultiple('.line-stock-hint')),
            'the order\'s own hold added back: 5, not the 0 the availability service reports',
        );
    }
}
