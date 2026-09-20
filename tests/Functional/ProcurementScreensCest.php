<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Service\AppSettings;
use App\Service\WarehouseFulfillmentRegionService;
use ProcurementBundle\Controller\Admin\AbstractProcurementController;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Every procurement screen renders, and the bundle's kill-switch actually switches it off (#555).
 *
 * This exists because a template is the one part of a bundle that unit tests cannot reach.
 * `lint:twig` proves the syntax parses; only a real render proves that `report.isAutoApprovable`,
 * `line.overReceived` and `address.toSnapshot` resolve to the methods they are supposed to, and
 * that every `path()` names a route that exists. A typo in any of those is a 500 on an admin
 * screen with a completely green unit suite.
 *
 * Lives in tests/Functional rather than modules/ProcurementBundle/tests because it drives the real
 * kernel through Codeception's Symfony module, which is what the Functional suite is for — the same
 * placement, and the same reason, as AdminMenuDefaultSidebarCest.
 */
final class ProcurementScreensCest
{
    private int $vendorId = 0;
    private int $orderId = 0;
    private int $billId = 0;

    public function _before(FunctionalTester $I): void
    {
        // AppSettings caches its rows in a pool outside the per-test transaction; the settings
        // screen reads prefixes and tolerances through it.
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('procurement-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * One vendor, one issued and partly received purchase order, one bill against it that disagrees
     * — so the detail and exception screens have something real to render rather than empty tables.
     */
    private function seed(FunctionalTester $I): void
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Screens Region ' . uniqid());
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = (new Vendor())->setName('Screens Supply')->setPaymentTerm('Net 30');
        $em->persist($vendor);

        $product = (new ProductCore())
            ->setSku('SCREENS-1')
            ->setName('Screens Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->flush();

        $order = (new PurchaseOrder())
            ->setPoNumber('PO-SCREENS-' . random_int(1000, 9999))
            ->setVendor($vendor)
            ->deriveTaxProvinceFrom($warehouse)
            ->setExpectedDate('2026-09-01');
        $em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setProduct($product)
            ->setName('Screens Widget')
            ->setSku('SCREENS-1')
            ->setVendorSku('THEIRS-1')
            ->setQuantityOrdered('240.00')
            ->setUnitCost('4.5000')
            ->setSubtotal('1080.00');
        $order->addLine($line);
        $em->persist($line);
        $em->flush();

        $order->setStatus('Issued', \App\Service\DocumentActor::system());
        // Set directly rather than through ReceivingService: this test is about rendering, and a
        // real receipt would drag the whole depth layer into a screen smoke test.
        $line->setQuantityReceived('210.00');
        $order->applyDerivedStatus(\App\Service\DocumentActor::system());
        $order->recalculateTotals();
        $em->flush();

        $bill = (new VendorBill())
            ->setBillNumber('BILL-SCREENS-' . random_int(1000, 9999))
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->setVendorInvoiceNo('THEIR-INV-1')
            ->setPurchaseOrder($order)
            ->setDueDate('2026-10-01');
        $em->persist($bill);

        // Billed for all 240 when only 210 arrived, and above the quoted price: two exceptions, so
        // the match table and the exception screen both have rows.
        $billLine = (new VendorBillLine())
            ->setPurchaseOrderLine($line)
            ->setProduct($product)
            ->setName('Screens Widget')
            ->setQuantity('240.00')
            ->setUnitCost('5.5000')
            ->setSubtotal('1320.00');
        $bill->addLine($billLine);
        $em->persist($billLine);
        $bill->recalculateTotals();
        $bill->approve(\App\Service\DocumentActor::system(), 'Seeded.');
        $em->flush();

        $this->vendorId = (int) $vendor->getId();
        $this->orderId = (int) $order->getId();
        $this->billId = (int) $bill->getId();
    }

    public function everyProcurementScreenRenders(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seed($I);

        $pages = [
            '/admin/bundles/procurement' => 'Procurement',
            '/admin/bundles/procurement/vendors' => 'Vendors',
            '/admin/bundles/procurement/vendors/' . $this->vendorId => 'Screens Supply',
            '/admin/bundles/procurement/purchase-orders' => 'Purchase Orders',
            '/admin/bundles/procurement/purchase-orders/expected' => 'Expected Arrivals',
            '/admin/bundles/procurement/purchase-orders/new' => 'New Purchase Order',
            '/admin/bundles/procurement/purchase-orders/' . $this->orderId => 'Screens Widget',
            '/admin/bundles/procurement/receiving' => 'Receiving',
            // #792: bare `/receiving/new` (no `po=`, no `nopo=`) is the Choose PO screen now —
            // "What arrived" only renders once a purchase order or the no-PO path is picked.
            '/admin/bundles/procurement/receiving/new' => 'Pick the purchase order this delivery is for.',
            '/admin/bundles/procurement/bills' => 'Vendor Bills',
            '/admin/bundles/procurement/bills/new' => 'Charges',
            '/admin/bundles/procurement/bills/' . $this->billId => 'Three-way match',
            '/admin/bundles/procurement/exceptions' => 'Exceptions',
            '/admin/bundles/procurement/settings' => 'Receiving rules',
            // #637/#638. Same reason every screen above is here: a template is the one part of a
            // bundle unit tests cannot reach, and a `path()` naming a route that does not exist is a
            // 500 with a green unit suite behind it.
            '/admin/bundles/procurement/vendor-prices' => 'Vendor Prices',
            '/admin/bundles/procurement/vendor-prices/new' => 'New vendor price',
            '/admin/bundles/procurement/rfqs' => 'Requests for Quotation',
            '/admin/bundles/procurement/rfqs/new' => 'New RFQ',
            '/admin/bundles/procurement/vendor-returns' => 'Vendor Returns',
            '/admin/bundles/procurement/vendor-returns/new' => 'New vendor return',
            '/admin/bundles/procurement/debit-memos' => 'Debit Memos',
            '/admin/bundles/procurement/debit-memos/new' => 'New debit memo',
        ];

        foreach ($pages as $url => $expected) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            $I->see($expected);
        }
    }

    /** The receiving form pre-filled from a purchase order — the screen with the most to get wrong. */
    public function theReceivingFormAgainstAPurchaseOrderShowsTheOutstandingQuantity(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seed($I);

        $I->amOnPage('/admin/bundles/procurement/receiving/new?po=' . $this->orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Screens Widget');
        $I->seeInField('lines[0][quantity]', '30');
    }

    /** The match screen must show the exceptions rather than quietly reporting a clean bill. */
    public function theBillScreenNamesItsMatchExceptions(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seed($I);

        $I->amOnPage('/admin/bundles/procurement/bills/' . $this->billId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('billed but not received');
        $I->see('price variance');
    }

    /**
     * Issuing a purchase order is what makes its goods expected — and what fills
     * `product_inventory.incoming_quantity` (#583).
     *
     * Driven through the form on the detail screen rather than by calling the service, because the
     * wiring is the thing under test: the reconciler ran in a unit test long before anything called
     * it. No JavaScript involved, and none available — the form posts and the page redirects.
     *
     * The column is read out of the database directly. `incoming` is deliberately absent from
     * availability, so nothing else in the app would contradict a wrong value; only the column says
     * whether the forecast was actually written.
     */
    public function issuingAPurchaseOrderFillsTheIncomingBucket(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Incoming Region ' . uniqid());
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = (new Vendor())->setName('Incoming Supply');
        $em->persist($vendor);

        $product = (new ProductCore())
            ->setSku('INCOMING-1')
            ->setName('Incoming Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);

        // Ten on the shelf already, so availability has a number that must not move.
        $inventory = (new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity(10);
        $em->persist($inventory);

        $order = (new PurchaseOrder())
            ->setPoNumber('PO-INCOMING-' . random_int(1000, 9999))
            ->setVendor($vendor)
            ->deriveTaxProvinceFrom($warehouse)
            ->setExpectedDate('2026-09-01');
        $em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setProduct($product)
            ->setName('Incoming Widget')
            ->setSku('INCOMING-1')
            ->setQuantityOrdered('40.00')
            ->setUnitCost('4.5000')
            ->setSubtotal('180.00');
        $order->addLine($line);
        $em->persist($line);
        $em->flush();

        $orderId = (int) $order->getId();
        $inventoryId = (int) $inventory->getId();

        $stored = static fn (): int => (int) $em->getConnection()->fetchOne(
            'SELECT incoming_quantity FROM product_inventory WHERE id = ?',
            [$inventoryId],
        );

        $I->amOnPage('/admin/bundles/procurement/purchase-orders/' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Issue to vendor');
        $I->assertSame(0, $stored(), 'a draft has been sent to nobody, so nothing is expected');

        // The token is scraped off the rendered form and posted, so this goes THROUGH the app's CSRF
        // check rather than round it. A relative-path form POST rather than submitForm() for the
        // reason AdminSalesReturnCest documents: with a custom Host header the crawler resolves the
        // form action as an absolute URL and the module refuses it as external.
        $token = $I->grabAttributeFrom('form[action$="/issue"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/purchase-orders/' . $orderId . '/issue', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(40, $stored(), 'issuing put the whole order on the dock forecast');

        $em->clear();
        $I->assertSame(
            '10.0000',
            $em->getRepository(ProductInventory::class)->find($inventoryId)->getAvailableQuantity(),
            'and availability did not move: a forecast is not stock',
        );
    }

    /**
     * The bundle's own Active/Inactive kill-switch.
     *
     * 404 rather than 403: an Inactive bundle's screens should read as absent, not forbidden. Worth
     * a test because enforcement in this codebase is per-seam rather than central — a route stays
     * reachable by URL after its nav link disappears, so each screen has to refuse for itself.
     */
    public function turningTheBundleInactiveHidesItsScreens(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        // Switched off through the one activation path. A fresh BundleStatus used to be safe here
        // because nothing had created one — absence of a row was the enabled default. Every
        // installed bundle now gets an explicit Active row before the suite (tests/_bootstrap.php),
        // so a second insert for the same source trips the UNIQUE index instead of switching
        // anything off.
        $I->grabService(BundleStatusRepository::class)->deactivate(AbstractProcurementController::SOURCE);

        $I->amOnPage('/admin/bundles/procurement');
        $I->seeResponseCodeIs(404);

        $I->amOnPage('/admin/bundles/procurement/vendors');
        $I->seeResponseCodeIs(404);

        $I->amOnPage('/admin/bundles/procurement/receiving/new');
        $I->seeResponseCodeIs(404);
    }
}
