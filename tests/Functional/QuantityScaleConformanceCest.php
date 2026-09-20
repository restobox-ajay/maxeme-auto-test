<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\TrackingPolicy;
use App\Entity\UnitOfMeasure;
use App\Entity\Warehouse;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use App\Service\QuantityScale;
use App\Service\Uom\LineDenomination;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use InventoryDepthBundle\Entity\WarehouseLocation;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Tests\Support\FunctionalTester;

/**
 * The same buy-and-sell chain as {@see FractionalQuantityWalkthroughCest}, driven with the store's
 * quantity precision set to THREE, asserting that the stored columns and the rendered screens both
 * land on three and agree with each other.
 *
 * ## What this test is for
 *
 * `App\Service\QuantityScale` is a global setting, and a global setting is a claim: change one row
 * and every rounding, every comparison and every rendering in the application moves with it. This
 * file is what makes that claim falsifiable. Anything still showing four places — or two — is a
 * hardcode that survived the sweep, and naming it is this test's whole job.
 *
 * As in its sibling: **a failing assertion here is the deliverable**. Tuning a number in this file
 * so a step goes green would destroy the only thing the file is for. If a layer stores `12.3456`
 * where the store is configured for three places, this test must say so and stop.
 *
 * ## Why 12.3456 is the figure, and 3 the scale
 *
 * It is the one pairing that tells every wrong answer apart by inspection:
 *
 * ```
 *   configured 3, stored and shown   "12.346"     <- correct, half-up
 *   a 4-place hardcode               "12.3456"    <- the ceiling, ignoring the setting
 *   a 2-place hardcode               "12.35"      <- number_format($q, 2), the admin line editor's old rule
 *   a whole-unit hardcode            "12"         <- (int) round((float) $q), the reservation sites' old rule
 * ```
 *
 * None of those four strings is a substring of any other, so a page can be searched for the wrong
 * answers directly and a finding is unambiguous about WHICH hardcode produced it. 12.3456 also
 * rounds UP to three places, which pins the rounding mode at the same time: a half-down or a
 * truncating implementation stores 12.345 and fails here rather than passing quietly.
 *
 * ## It ENUMERATES; it holds no hand-kept list
 *
 * `docs/QUEUE.md`: "Conformance tests enumerate; they never hold a hand-kept list. When a rule
 * applies to a CLASS of things the test must discover its subjects from the application itself."
 * Both halves do:
 *
 *  - **The columns** come from Doctrine's own metadata — every field of every mapped entity whose
 *    type is `App\Doctrine\Type\QuantityType`, plus the `decimal` fields whose name says they carry
 *    a quantity. A quantity column added next month is checked by this test the day it is mapped,
 *    with nothing to remember.
 *  - **The screens** come from the router — every GET route under each document's own route prefix
 *    that takes nothing but an `{id}`. A new print view, a new tab, a new detail panel is visited
 *    automatically.
 *
 * A test naming its subjects in an array passes forever while the next thing added quietly skips
 * the rule, which is the failure mode that standard exists to prevent.
 *
 * ## House rules this file follows
 *
 * Conducted throughout (#624): real screens, plain form POSTs with no JavaScript, the CSRF token
 * scraped from the rendered form, its own data. Every stored figure is read back by raw SQL (#627)
 * rather than off an entity, because an entity read comes back through the very layer under test.
 */
final class QuantityScaleConformanceCest
{
    private const REGION = 'Conformance Region';

    /** The configured precision this whole file runs at. Deliberately NOT the ceiling. */
    private const SCALE = 3;

    /** The figure every step is driven with — four places entered, three places expected. */
    private const ENTERED = '12.3456';

    /** What {@see self::ENTERED} must become at {@see self::SCALE}: half-up, away from zero. */
    private const EXPECTED = '12.346';

    /**
     * The wrong answers, by the hardcode that produces each. A page carrying any of these strings
     * has a rounding rule of its own that the setting did not reach.
     *
     * @var array<string, string>
     */
    private const HARDCODES = [
        '12.3456' => 'four decimal places — the column ceiling, ignoring the configured scale',
        '12.35' => 'two decimal places — a number_format($quantity, 2) that survived the sweep',
        '12.345' => 'three places rounded the wrong way — truncation or half-down, not half-up',
    ];

    private ?Warehouse $warehouse = null;

    private ?WarehouseLocation $bin = null;

    private ?Vendor $vendor = null;

    private ?Company $company = null;

    /** @var array<string, ProductCore> shape ('lot'|'untracked') => product */
    private array $products = [];

    private string $lotCode = '';

    public function _before(FunctionalTester $I): void
    {
        $this->warehouse = null;
        $this->bin = null;
        $this->vendor = null;
        $this->company = null;
        $this->products = [];
        $this->lotCode = '';

        $this->forgetCachedSettings($I);
    }

    /**
     * The settings cache lives on the filesystem under `var/cache/test` and is NOT undone by the
     * transaction rollback that isolates these tests, so a scale of 3 written here would outlive
     * the row that justified it and poison every test that ran afterwards.
     *
     * `AppSettingCacheInvalidationSubscriber` drops the entry on any ORM write, which covers the
     * setting going IN. Nothing can cover it coming back out again, because the removal is a
     * rollback and no listener sees one — so it is dropped by hand, at both ends.
     */
    public function _after(FunctionalTester $I): void
    {
        $this->forgetCachedSettings($I);
    }

    // ===================================================================== the chain, at scale 3

    /**
     * The whole chain with the store configured for three decimal places: purchase order, receipt,
     * quote, order, invoice, shipment. Every quantity entered as 12.3456 and every one of them
     * expected to be 12.346 wherever it lands.
     */
    public function everyStoredQuantityAndEveryScreenLandsOnTheConfiguredScale(FunctionalTester $I): void
    {
        $this->configureScale($I, self::SCALE);
        $this->loginAsAdmin($I);
        $this->seedWarehouseAndProducts($I);
        $this->seedVendor($I);

        // The guard the rest of the file rests on. If the helper is not actually reading the row,
        // every assertion below would be measuring the default and would pass for the wrong reason.
        $scale = $I->grabService(QuantityScale::class);
        $I->assertSame(self::SCALE, $scale->decimals(), 'guard: the configured scale must actually be in force');
        $I->assertSame(self::EXPECTED, $scale->round(self::ENTERED), 'guard: and must round the test figure half-up');

        // ---- Buy side: a purchase order for 12.3456, then a receipt of the same.
        $poId = $this->createAndIssuePurchaseOrder($I);

        foreach (['lot', 'untracked'] as $shape) {
            $I->assertSame(
                self::EXPECTED,
                $this->column($I, 'purchase_order_line', 'quantity_ordered', 'purchase_order_id = ? AND product_id = ?', [$poId, (int) $this->products[$shape]->getId()]),
                sprintf('%s: a purchase order line must store the configured three places, not the column ceiling', $shape),
            );
        }

        $this->receive($I, $poId, self::ENTERED);

        foreach (['lot', 'untracked'] as $shape) {
            $productId = (int) $this->products[$shape]->getId();

            $I->assertSame(
                self::EXPECTED,
                $this->column($I, 'purchase_order_line', 'quantity_received', 'purchase_order_id = ? AND product_id = ?', [$poId, $productId]),
                sprintf('%s: and must be credited the receipt at the same three places', $shape),
            );
            $I->assertSame(
                self::EXPECTED,
                $this->detailTotal($I, $productId),
                sprintf('%s: receiving 12.3456 into a three-place store puts 12.346 on the shelf', $shape),
            );
            $I->assertSame(
                self::EXPECTED,
                $this->column($I, 'product_inventory', 'received_quantity', 'product_id = ? AND warehouse_id = ?', [$productId, (int) $this->warehouse->getId()]),
                sprintf('%s: and credits the received bucket the same figure', $shape),
            );
        }

        // ---- Sell side: a quote for the same quantity, converted, invoiced, shipped.
        $orderId = $this->seedAcceptAndConvertEstimate($I);
        $invoiceId = $this->createAndIssueInvoice($I, $orderId);

        foreach (['lot', 'untracked'] as $shape) {
            $productId = (int) $this->products[$shape]->getId();

            $I->assertSame(
                self::EXPECTED,
                $this->column($I, 'sales_order_line', 'quantity', 'order_id = ? AND product_id = ?', [$orderId, $productId]),
                sprintf('%s: converting a quote must store the configured three places', $shape),
            );
            $I->assertSame(
                self::EXPECTED,
                $this->column($I, 'invoice_line', 'quantity', 'invoice_id = ? AND product_id = ?', [$invoiceId, $productId]),
                sprintf('%s: and so must the invoice line raised from it', $shape),
            );
        }

        $this->ship($I, $invoiceId, 'untracked', self::ENTERED);

        $I->assertSame(
            self::EXPECTED,
            $this->shipmentLineTotal($I, $invoiceId, (int) $this->products['untracked']->getId()),
            'untracked: a shipment line must store the configured three places',
        );

        // ---- The two conformance sweeps, over everything the chain has written and rendered.
        $this->assertEveryStoredQuantityIsExpressibleAtTheConfiguredScale($I);
        $this->assertEveryScreenRendersTheConfiguredScale($I, $poId, $orderId, $invoiceId);
    }

    // ================================================== sweep 1: the columns, from the ORM metadata

    /**
     * Every quantity column in the database, holding a value the configured scale can express.
     *
     * The columns are discovered from Doctrine's own metadata rather than listed here — see the
     * class docblock. Two kinds qualify and both are read off the mapping:
     *
     *  - fields whose Doctrine type is `quantity` (`App\Doctrine\Type\QuantityType`), which is every
     *    inventory and reservation column;
     *  - `decimal` fields whose name says quantity, which is how a document LINE carries one.
     *
     * The property asserted is expressibility, not a literal: a chain derives figures from other
     * figures, so what matters is that nothing anywhere holds a precision the store said it does not
     * keep. `QuantityScale::isExpressible()` asks exactly that, at the column's own scale, so a value
     * that really was stored at four places still fails.
     */
    private function assertEveryStoredQuantityIsExpressibleAtTheConfiguredScale(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $connection = $entityManager->getConnection();
        $scale = $I->grabService(QuantityScale::class);

        $checked = 0;

        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            /** @var ClassMetadata<object> $metadata */
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }

            $table = $metadata->getTableName();
            if (!$connection->createSchemaManager()->tablesExist([$table])) {
                continue;
            }

            foreach ($this->quantityColumnsOf($metadata) as $column) {
                $values = $connection->fetchFirstColumn(
                    sprintf('SELECT %s FROM %s WHERE %s IS NOT NULL', $column, $table, $column),
                );

                foreach ($values as $value) {
                    ++$checked;
                    $I->assertTrue(
                        $scale->isExpressible((string) $value),
                        sprintf(
                            '%s.%s holds %s, which cannot be expressed at the store\'s configured %d decimal places.'
                            . ' Something on the write path to this column has a rounding rule of its own.',
                            $table,
                            $column,
                            (string) $value,
                            self::SCALE,
                        ),
                    );
                }
            }
        }

        // The sweep is worthless if it swept nothing, and a metadata query that silently returns no
        // subjects is exactly the way a conformance test rots into a no-op.
        $I->assertGreaterThan(
            20,
            $checked,
            'guard: the metadata sweep must actually have found quantity columns with values in them',
        );
    }

    /**
     * The quantity fields of one mapping, by what the mapping says rather than by a list kept here.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return list<string>
     */
    private function quantityColumnsOf(ClassMetadata $metadata): array
    {
        $columns = [];

        foreach ($metadata->getFieldNames() as $field) {
            $type = $metadata->getTypeOfField($field);

            // `quantity` is the custom type; a `decimal` field only qualifies when its own NAME says
            // it carries a quantity, because the same type carries prices, costs and money totals,
            // which have scales of their own and are not this setting's business.
            $isQuantity = $type === 'quantity'
                || ($type === 'decimal' && preg_match('/quantit(y|ies)$/i', $field) === 1);

            if ($isQuantity) {
                $columns[] = $metadata->getColumnName($field);
            }
        }

        return $columns;
    }

    // ================================================ sweep 2: the screens, from the route collection

    /**
     * Every screen the chain's documents have, rendering the configured scale and none of the wrong
     * ones.
     *
     * The screens are discovered from the router — every GET route under a document's own route
     * prefix that takes nothing but an `{id}` — so a print view or a detail tab added later is
     * visited by this test with nothing to remember. A route that does not answer 200, or whose page
     * never mentions the product at all, is skipped rather than failed: this is a rounding test and
     * not a smoke test, and a screen that does not show the line has nothing to say about its scale.
     */
    private function assertEveryScreenRendersTheConfiguredScale(FunctionalTester $I, int $poId, int $orderId, int $invoiceId): void
    {
        $documents = [
            'admin_bundle_procurement_purchase_order' => $poId,
            'admin_order_detail' => $orderId,
            'admin_invoice_detail' => $invoiceId,
        ];

        $sku = $this->products['untracked']->getSku();
        $visited = 0;

        foreach ($documents as $prefix => $id) {
            foreach ($this->screenPathsFor($I, $prefix, $id) as $routeName => $path) {
                $I->amOnPage($path);

                if (!$this->lastResponseWasSuccessful($I)) {
                    continue;
                }

                $html = $I->grabPageSource();
                if (!str_contains($html, $sku) && !str_contains($html, self::EXPECTED) && !$this->containsAnyHardcode($html)) {
                    // Nothing of this chain on the page — a list filtered elsewhere, an empty tab.
                    continue;
                }

                ++$visited;

                $I->assertStringContainsString(
                    self::EXPECTED,
                    $html,
                    sprintf('%s (%s) must show the quantity at the store\'s configured three places', $routeName, $path),
                );

                foreach (self::HARDCODES as $wrong => $why) {
                    $I->assertStringNotContainsString(
                        $wrong,
                        $html,
                        sprintf('%s (%s) renders %s — %s', $routeName, $path, $wrong, $why),
                    );
                }
            }
        }

        $I->assertGreaterThan(
            2,
            $visited,
            'guard: the router sweep must actually have reached the documents\' screens',
        );
    }

    /**
     * The GET screens one document has, read off the route collection.
     *
     * A route qualifies when its name starts with $prefix, it answers GET, and every placeholder in
     * its path is `{id}` — so `/purchase-orders/{id}` and `/purchase-orders/{id}/print` are in, and
     * `/purchase-orders/{id}/log/delete/{logId}` (two ids, and a POST besides) is out.
     *
     * @return array<string, string> route name => path
     */
    private function screenPathsFor(FunctionalTester $I, string $prefix, int $id): array
    {
        $paths = [];

        foreach ($I->grabService(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            $methods = $route->getMethods();
            if ($methods !== [] && !\in_array('GET', $methods, true)) {
                continue;
            }

            preg_match_all('/\{!?([a-zA-Z_][a-zA-Z0-9_]*)\}/', $route->getPath(), $placeholders);
            if (array_unique($placeholders[1]) !== ['id']) {
                continue;
            }

            $paths[$name] = str_replace(['{id}', '{!id}'], (string) $id, $route->getPath());
        }

        return $paths;
    }

    private function containsAnyHardcode(string $html): bool
    {
        foreach (array_keys(self::HARDCODES) as $wrong) {
            if (str_contains($html, $wrong)) {
                return true;
            }
        }

        return false;
    }

    private function lastResponseWasSuccessful(FunctionalTester $I): bool
    {
        try {
            $I->seeResponseCodeIsSuccessful();
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    // ======================================================================= the setting itself

    /**
     * Writes the store-wide scale through the ORM, which is what makes
     * `AppSettingCacheInvalidationSubscriber` drop the cached table — the same path the settings
     * screen takes, rather than raw SQL the listener cannot see.
     */
    private function configureScale(FunctionalTester $I, int $decimals): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);

        $setting = $entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => QuantityScale::SETTING_KEY])
            ?? (new AppSetting())
                ->setSettingKey(QuantityScale::SETTING_KEY)
                ->setName('Quantity Decimal Places')
                ->setCategory('General')
                ->setVisibility(AppSetting::VISIBILITY_STORE);

        $setting->setSettingValue((string) $decimals);
        $entityManager->persist($setting);
        $entityManager->flush();

        $I->assertLessThanOrEqual(
            LineDenomination::QUANTITY_SCALE,
            $decimals,
            'guard: this file is about a scale BELOW the ceiling; above it the clamp would be what is measured',
        );
    }

    private function forgetCachedSettings(FunctionalTester $I): void
    {
        $I->grabService('cache.app')->delete(AppSettings::CACHE_KEY_ALL);
    }

    // ======================================================================================= seeding

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('conformance-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** A warehouse, a bin and two products based in a unit that measures ten-thousandths. */
    private function seedWarehouseAndProducts(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);

        $region = (new FulfillmentRegion())->setName(self::REGION);
        $entityManager->persist($region);
        $this->warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $this->bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('C-01')->setSortKey(1);
        $entityManager->persist($this->bin);
        $entityManager->flush();

        $I->haveSeededReferenceData();

        // The UNIT accepts four places on purpose. The store's setting is what has to narrow the
        // figure to three — if the unit did the narrowing, this test would be measuring
        // UnitOfMeasure::accepts() and not the global scale at all.
        $kilogram = (new UnitOfMeasure())
            ->setCode('KG-CONF-' . strtoupper(substr(uniqid(), -5)))
            ->setName('Kilogram')
            ->setFamily(UnitOfMeasure::FAMILY_WEIGHT)
            ->setFactorToFamilyBase('1')
            ->setRoundingPrecision('0.0001');
        $entityManager->persist($kilogram);

        $I->assertTrue($kilogram->accepts(self::ENTERED), 'guard: the unit itself must accept all four places');

        $policies = [
            'lot' => (new TrackingPolicy())->setName('Conformance Lot ' . uniqid())->setMode(TrackingPolicy::MODE_LOT)->setTrackIn(true)->setTrackOut(true),
            'untracked' => (new TrackingPolicy())->setName('Conformance None ' . uniqid())->setMode(TrackingPolicy::MODE_NONE),
        ];

        foreach ($policies as $shape => $policy) {
            $entityManager->persist($policy);

            $product = (new ProductCore())
                ->setSku('CONF-' . strtoupper($shape) . '-' . strtoupper(substr(uniqid(), -6)))
                ->setName('Conformance ' . ucfirst($shape) . ' Powder')
                ->setUnit($kilogram->getCode())
                ->setBaseUnit($kilogram)
                ->setSalesTaxCode('E')
                ->setCostPrice('4.00')
                ->setDefaultPrice('10.00')
                ->setOriginalPrice('10.00')
                ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
                ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
                ->setTrackingPolicy($policy);
            $entityManager->persist($product);
            $entityManager->persist((new ProductInventory())->setProduct($product)->setWarehouse($this->warehouse));

            $this->products[$shape] = $product;
        }

        $entityManager->flush();
    }

    private function seedVendor(FunctionalTester $I): void
    {
        $this->vendor = (new Vendor())->setName('Conformance Vendor ' . uniqid());
        $I->haveInRepository($this->vendor);
    }

    // ================================================================================== buy side

    private function createAndIssuePurchaseOrder(FunctionalTester $I): int
    {
        $I->amOnPage('/admin/bundles/procurement/purchase-orders/new');
        $token = $I->csrfToken();

        $lines = [];
        $i = 0;
        foreach (['lot', 'untracked'] as $shape) {
            $lines[$i] = [
                'product_id' => (string) $this->products[$shape]->getId(),
                'qty' => self::ENTERED,
                'unit_cost' => '4.00',
            ];
            ++$i;
        }

        $I->sendFormPostRequest('/admin/bundles/procurement/purchase-orders/save', [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $this->vendor->getId(),
            'warehouse_id' => (string) $this->warehouse->getId(),
            'lines' => $lines,
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $poId = (int) $entityManager->getConnection()->fetchOne(
            'SELECT id FROM purchase_order WHERE vendor_id = ? ORDER BY id DESC LIMIT 1',
            [(int) $this->vendor->getId()],
        );
        $I->assertGreaterThan(0, $poId, 'guard: the save must have created a purchase order');

        $I->amOnPage('/admin/bundles/procurement/purchase-orders/' . $poId);
        $I->sendFormPostRequest('/admin/bundles/procurement/purchase-orders/' . $poId . '/issue', ['_token' => $I->csrfToken()]);

        return $poId;
    }

    private function receive(FunctionalTester $I, int $poId, string $quantity): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var PurchaseOrder $order */
        $order = $entityManager->find(PurchaseOrder::class, $poId);

        $lineIdByProduct = [];
        foreach ($order->getLines() as $line) {
            $lineIdByProduct[(int) $line->getProduct()->getId()] = (string) $line->getId();
        }

        $I->amOnPage('/admin/bundles/procurement/receiving/new?po=' . $poId);
        $token = $I->csrfToken();

        $lines = [];
        $i = 0;
        foreach (['lot', 'untracked'] as $shape) {
            $product = $this->products[$shape];
            $row = [
                'purchase_order_line_id' => $lineIdByProduct[(int) $product->getId()],
                'product_id' => (string) $product->getId(),
                'quantity' => $quantity,
                'location_id' => (string) $this->bin->getId(),
                'unit_cost' => '4.00',
            ];

            if ($shape === 'lot') {
                $this->lotCode = 'CONF-LOT-' . strtoupper(substr(uniqid(), -8));
                $row['lot_code'] = $this->lotCode;
            }

            $lines[$i] = $row;
            ++$i;
        }

        $I->sendFormPostRequest('/admin/bundles/procurement/receiving/new', [
            '_token' => $token,
            'purchase_order_id' => (string) $poId,
            'packing_slip' => 'CONF-PS-' . uniqid(),
            'received_by' => 'conformance-cest',
            'lines' => $lines,
        ]);
    }

    // ================================================================================= sell side

    private function seedAcceptAndConvertEstimate(FunctionalTester $I): int
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);

        $this->company = (new Company())->setName('Conformance Buyer Co')->setCode('CONF-' . uniqid());
        $entityManager->persist($this->company);
        $entityManager->persist(
            (new CompanyAddress())
                ->setCompany($this->company)->setLabel('Main')
                ->setAddressLine1('1 Wholesale Way')->setCity('Vancouver')->setProvince('BC')->setPostalCode('V5K0A1')->setCountry('CA')
                ->setIsDefaultShipping(true)->setIsDefaultBilling(true)
        );
        $entityManager->flush();

        $I->haveActiveFulfillmentRegionFor($this->company, self::REGION);

        $subtotal = (float) self::EXPECTED * 10.0 * 2;

        $estimate = (new Estimate())
            ->setCompany($this->company)
            ->setDocumentNumber('CONF-' . uniqid())
            ->setSource('Customer')
            ->setFulfillmentRegion(self::REGION)
            ->setFeeLines(json_encode([[
                'slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'E',
                'amount' => 0.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc',
            ]]))
            ->setSubtotal(number_format($subtotal, 2, '.', ''))
            ->setTax('0.00')
            ->setTotal(number_format($subtotal, 2, '.', ''));
        $estimate->setStatus('Priced', DocumentActor::system());

        foreach (['lot', 'untracked'] as $shape) {
            $freshProduct = $entityManager->find(ProductCore::class, $this->products[$shape]->getId());
            $estimate->addLine(
                (new EstimateLine())
                    ->setProduct($freshProduct)
                    ->setName($freshProduct->getName())
                    ->setSku($freshProduct->getSku())
                    ->setLocation(self::REGION)
                    // At the configured scale, because this quote is a FIXTURE — built directly,
                    // the same shortcut FractionalQuantityWalkthroughCest takes and for the same
                    // reason: the accept/convert write path is what matters here and the quote
                    // create form is AdminEstimateFormCest's coverage. A figure written straight
                    // onto a column by a test is not the application rounding anything, so writing
                    // four places here would make this file fail on its own fixture and say nothing
                    // about the app. The four-place ENTRY is exercised where it belongs: the
                    // purchase order form, the receiving form, the invoice form and the shipment
                    // form, all posted with 12.3456 and all expected to store 12.346.
                    ->setQuantity(self::EXPECTED)
                    ->setPrice('10.00')
                    ->setSubtotal(number_format((float) self::EXPECTED * 10.0, 2, '.', ''))
            );
        }

        $entityManager->persist($estimate);
        $entityManager->flush();
        $estimateId = (int) $estimate->getId();

        $I->amOnPage('/admin/estimate/detail/' . $estimateId);
        $I->sendFormPostRequest('/admin/estimate/accept/' . $estimateId, ['_token' => $I->csrfToken()]);

        $I->amOnPage('/admin/estimate/detail/' . $estimateId);
        $I->sendFormPostRequest('/admin/estimate/convert/' . $estimateId, ['_token' => $I->csrfToken()]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $orderId = (int) $entityManager->getConnection()->fetchOne(
            'SELECT id FROM sales_order WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [(int) $this->company->getId()],
        );
        $I->assertGreaterThan(0, $orderId, 'guard: the conversion must have raised a sales order');

        return $orderId;
    }

    private function createAndIssueInvoice(FunctionalTester $I, int $orderId): int
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var SalesOrder $order */
        $order = $entityManager->find(SalesOrder::class, $orderId);

        $lotProductId = (int) $this->products['lot']->getId();

        $lines = [];
        foreach ($order->getLines() as $line) {
            $productId = (int) $line->getProduct()->getId();

            $row = [
                'sales_order_line_id' => (string) $line->getId(),
                'product_id' => (string) $productId,
                'qty' => self::ENTERED,
                // The order already holds this stock in `sales_hold`, so availability reads zero and
                // the screen asks for a reason before it will bill beyond it (#326, warn-and-override).
                // Given rather than worked around: the override is a real, supported path, and the
                // row it writes — `invoice_line_stock_override.requested_quantity` — is one more
                // quantity column for the metadata sweep below to hold to the configured scale.
                'stock_override_reason' => 'Conformance run: the order itself is holding these units.',
            ];

            if ($productId === $lotProductId) {
                $row['lot_id'] = (string) $this->lotIdFor($I, $lotProductId);
            }

            $lines[] = $row;
        }

        $I->amOnPage('/admin/order/detail/' . $orderId);
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $orderId, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'fulfillment_region' => self::REGION,
            'lines' => $lines,
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $invoiceId = (int) $entityManager->getConnection()->fetchOne(
            'SELECT id FROM invoice WHERE sales_order_id = ? ORDER BY id DESC LIMIT 1',
            [$orderId],
        );
        $I->assertGreaterThan(0, $invoiceId, 'guard: creating the invoice must have written one');

        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/action/start-processing', ['_token' => $I->csrfToken()]);

        return $invoiceId;
    }

    private function ship(FunctionalTester $I, int $invoiceId, string $shape, string $quantity): void
    {
        $lineId = $this->invoiceLineId($I, $invoiceId, (int) $this->products[$shape]->getId());

        $I->amOnPage('/admin/bundles/inventory-depth/shipments/new?invoice[]=' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest('/admin/bundles/inventory-depth/shipments/new', [
            '_token' => $I->csrfToken(),
            'invoice' => [(string) $invoiceId],
            'lines' => [(string) $lineId => $quantity],
        ]);
    }

    // ============================================================================ database reads

    /**
     * One quantity column, normalised to the CONFIGURED scale for comparison.
     *
     * SQLite hands `NUMERIC(14, 4)` back in its shortest form, so a column genuinely holding 12.346
     * reads back as `12.346` and one holding 12.3460 reads back the same — the normalisation is
     * about punctuation and nothing else. A value that really was stored at four places still fails,
     * because `round()` at three cannot produce `12.3456` from anything.
     */
    private function column(FunctionalTester $I, string $table, string $column, string $where, array $params): string
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        $raw = (string) $entityManager->getConnection()->fetchOne(
            sprintf('SELECT %s FROM %s WHERE %s', $column, $table, $where),
            $params,
        );

        $scale = $I->grabService(QuantityScale::class);
        $I->assertTrue(
            $scale->isExpressible($raw),
            sprintf('%s.%s holds %s, which the configured scale cannot express', $table, $column, $raw),
        );

        return $scale->round($raw);
    }

    private function detailTotal(FunctionalTester $I, int $productId): string
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $I->grabService(QuantityScale::class)->round((string) $entityManager->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = 'available'",
            [$productId],
        ));
    }

    private function shipmentLineTotal(FunctionalTester $I, int $invoiceId, int $productId): string
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $I->grabService(QuantityScale::class)->round((string) $entityManager->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(sl.quantity), 0) FROM shipment_line sl'
            . ' JOIN shipment s ON s.id = sl.shipment_id'
            . ' JOIN invoice_line il ON il.id = sl.invoice_line_id'
            . ' WHERE il.invoice_id = ? AND sl.product_id = ? AND s.voided_at IS NULL',
            [$invoiceId, $productId],
        ));
    }

    private function invoiceLineId(FunctionalTester $I, int $invoiceId, int $productId): int
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        $id = (int) $entityManager->getConnection()->fetchOne(
            'SELECT id FROM invoice_line WHERE invoice_id = ? AND product_id = ?',
            [$invoiceId, $productId],
        );
        $I->assertGreaterThan(0, $id, 'guard: the invoice line must exist to be shipped against');

        return $id;
    }

    private function lotIdFor(FunctionalTester $I, int $productId): int
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        $lotId = (int) $entityManager->getConnection()->fetchOne(
            'SELECT id FROM inventory_lot WHERE product_id = ? ORDER BY id DESC LIMIT 1',
            [$productId],
        );
        $I->assertGreaterThan(0, $lotId, 'guard: the lot this line was received under must be findable');

        return $lotId;
    }
}
