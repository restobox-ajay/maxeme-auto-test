<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The "js-order-product-select" dropdown on the admin order create/edit page was a plain
 * <select>, unsearchable once the product catalog grows past a handful of items. It should
 * reuse the existing homegrown searchable-select widget (js-searchable-select), which this
 * suite can only verify at the markup level since it has no WebDriver/JS execution.
 */
final class AdminSalesOrderFormCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-order-form-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeOrder(FunctionalTester $I, ?string $regionName = null): SalesOrder
    {
        $company = (new Company())
            ->setName('Order Form Test Co')
            ->setCode('ORDFORM-' . uniqid())
            ->setPrimaryEmail('buyer@order-form.example');
        $I->haveInRepository($company);
        // Deliberately NO fulfillment region here: two tests in this file are about the "company
        // has none configured" and "company has exactly one" branches and set their own up. The
        // one test that CREATES an order adds an active region itself, which #237 now requires.

        $product = (new ProductCore())->setSku('ORDFORM-SKU-1')->setName('Order Form Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        // A non-draft order reserves stock, and since #326 a save into a reserving status is
        // refused unless the line is actually covered. Unstocked here would have driven
        // ProductInventory negative, which is the defect that check exists to stop.
        $I->haveStockFor($product);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORDFORM-' . uniqid())
            ->setFulfillmentRegion($regionName)
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName('Order Form Test Product')
                ->setSku('ORDFORM-SKU-1')
                ->setQuantity('2')
                ->setPrice('20.00')
                ->setSubtotal('40.00')
        );
        $I->haveInRepository($order);

        // The fixture wants a live order, which is now an action on a persisted Draft rather than a
        // status string. With no invoices against it the deriver settles it at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    public function editPageProductDropdownIsSearchableAndOptionsCarrySku(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select.js-order-product-select.js-searchable-select');
        $I->seeElement('select.js-order-product-select.order-line-product-select');
        $I->seeElement('select.js-order-product-select option[data-sku="ORDFORM-SKU-1"]');
    }

    public function editPageProductRowLinksToTheProductEditPageInANewTab(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $productId = $order->getLines()->first()->getProduct()->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('button.js-order-search-product');
        $I->seeElement('a.js-order-search-product[target="_blank"]', [
            'href' => '/admin/product/inventory/update/' . $productId,
        ]);
    }

    public function createPageProductRowLinkStartsHiddenUntilAProductIsPicked(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $order->getCompany()->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('button.js-order-search-product');
        $I->dontSeeElement('a.js-order-search-product[href]');
        $I->seeElement('a.js-order-search-product[target="_blank"]', [
            'data-product-edit-url-template' => '/admin/product/inventory/update/__PRODUCT_ID__',
        ]);
    }

    public function createPageProductDropdownIsSearchableAndOptionsCarrySku(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $order->getCompany()->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select.js-order-product-select.js-searchable-select');
        $I->seeElement('select.js-order-product-select.order-line-product-select');
        $I->seeElement('select.js-order-product-select option[data-sku="ORDFORM-SKU-1"]');
    }

    /**
     * The Price field a picked product populates comes from the option's data-price. A product with
     * no ProductPricing row at all used to render data-price="", leaving the line's Price (and the
     * subtotal recalculated from it) blank — it should fall back to the product's original price.
     */
    public function productWithoutPricingFallsBackToItsOriginalPrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $product = (new ProductCore())
            ->setSku('ORDFORM-SKU-NOPRICING')
            ->setName('Order Form Unpriced Product')
            ->setOriginalPrice('79.99')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        // A non-draft order reserves stock, and since #326 a save into a reserving status is
        // refused unless the line is actually covered. Unstocked here would have driven
        // ProductInventory negative, which is the defect that check exists to stop.
        $I->haveStockFor($product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $order->getCompany()->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select.js-order-product-select option[data-sku="ORDFORM-SKU-NOPRICING"]', [
            'data-original-price' => '79.99',
            'data-price' => '79.99',
        ]);
    }

    /**
     * Tier 1 of the price precedence: the price list the order's company is on for the order's
     * fulfillment region. A ProductPricing row on some *other* price list the company isn't on
     * must not leak into the order form — that's a different customer's price.
     */
    public function companyRegionPriceListWinsOverEveryOtherPrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I, 'Order Form West');

        $product = (new ProductCore())
            ->setSku('ORDFORM-SKU-REGION-PRICED')
            ->setName('Order Form Region Priced Product')
            ->setDefaultPrice('65.25')
            ->setOriginalPrice('79.99')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        // A non-draft order reserves stock, and since #326 a save into a reserving status is
        // refused unless the line is actually covered. Unstocked here would have driven
        // ProductInventory negative, which is the defect that check exists to stop.
        $I->haveStockFor($product);

        // Persisted first, so it has the lower id an "any pricing row" lookup would have picked.
        $otherPriceList = (new PriceList())->setName('Order Form Other List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($otherPriceList);
        $I->haveInRepository((new ProductPricing())->setProduct($product)->setPriceList($otherPriceList)->setPrice('12.34'));

        $priceList = (new PriceList())->setName('Order Form West List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);
        $I->haveInRepository((new ProductPricing())->setProduct($product)->setPriceList($priceList)->setPrice('54.75'));
        $this->makeActiveRegion($I, $order->getCompany(), 'Order Form West', $priceList);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select.js-order-product-select option[data-sku="ORDFORM-SKU-REGION-PRICED"]', [
            'data-original-price' => '79.99',
            'data-price' => '54.75',
        ]);
    }

    /**
     * Tier 2: the company's region price list carries no row for this product, so the product's
     * own Default Price applies before its (higher, retail) Original Price does.
     */
    public function productMissingFromTheCompanyPriceListFallsBackToItsDefaultPrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $priceList = (new PriceList())->setName('Order Form Empty List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);
        $this->makeActiveRegion($I, $order->getCompany(), 'Order Form East', $priceList);

        $product = (new ProductCore())
            ->setSku('ORDFORM-SKU-DEFAULT-PRICED')
            ->setName('Order Form Default Priced Product')
            ->setDefaultPrice('65.25')
            ->setOriginalPrice('79.99')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $order->getCompany()->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select.js-order-product-select option[data-sku="ORDFORM-SKU-DEFAULT-PRICED"]', [
            'data-original-price' => '79.99',
            'data-price' => '65.25',
        ]);
    }

    /**
     * The header "Fulfillment Region" field used to not exist on this form at all — an
     * admin-created order could never have order.fulfillmentRegion set, which left the
     * customer-facing order detail page showing a blank region and starved
     * InventoryReservationReconciler::reconcile() of a region to resolve inventory
     * against whenever a line had no per-line location either. The select must offer the
     * company's own active regions (what CompanyFulfillmentRegionService::
     * priceListForCompanyRegion() actually matches against), not every region system-wide.
     */
    public function editPageOffersTheCompanysActiveRegionsAndPreselectsTheOrdersCurrentOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I, 'Order Form East');

        $priceList = (new PriceList())->setName('Order Form Region Select East List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);
        $this->makeActiveRegion($I, $order->getCompany(), 'Order Form East', $priceList);

        $otherPriceList = (new PriceList())->setName('Order Form Region Select West List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($otherPriceList);
        $this->makeActiveRegion($I, $order->getCompany(), 'Order Form West', $otherPriceList);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeOptionIsSelected('select[name="fulfillment_region"]', 'Order Form East');
        $I->seeElement('select[name="fulfillment_region"] option[value="Order Form West"]');
    }

    /**
     * A company with exactly one active region has an unambiguous answer even for a legacy
     * order saved before this field existed — the select should default to it rather than
     * forcing the admin to notice and pick it manually.
     */
    public function editPagePreselectsTheSingleActiveRegionForALegacyOrderWithNoRegionSaved(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $priceList = (new PriceList())->setName('Order Form Region Select Only List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);
        $this->makeActiveRegion($I, $order->getCompany(), 'Order Form Only Region', $priceList);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeOptionIsSelected('select[name="fulfillment_region"]', 'Order Form Only Region');
    }

    /**
     * A company with no active fulfillment regions configured at all predates the feature —
     * the form must not render a required-but-empty select that would block every save.
     */
    public function editPageShowsNoRegionsMessageWhenTheCompanyHasNoneConfigured(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('select[name="fulfillment_region"]');
        $I->seeElement('input[value="No fulfillment regions configured for this customer"][disabled]');
    }

    /**
     * Root-cause coverage: picking a region on save must actually persist onto
     * order.fulfillmentRegion, since that's what both the customer detail page and
     * InventoryReservationReconciler read.
     */
    public function savingTheEditFormPersistsTheChosenFulfillmentRegion(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $product = $order->getLines()->first()->getProduct();

        $priceList = (new PriceList())->setName('Order Form Region Save List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);
        $this->makeActiveRegion($I, $order->getCompany(), 'Order Form Saved Region', $priceList);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $order->getCompany()->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'fulfillment_region' => 'Order Form Saved Region',
            'lines' => [[
                'product_id' => (string) $product->getId(),
                'qty' => '2',
                'price' => '20.00',
            ]],
            'save_mode' => 'draft_recalc',
        ]);

        $I->seeInRepository(SalesOrder::class, [
            'id' => $order->getId(),
            'fulfillmentRegion' => 'Order Form Saved Region',
        ]);
    }

    /**
     * #775 (OE-4, "regression or incomplete fix of #395"): a line field meant to be a single
     * scalar — price/cost/weight/batch/unit/sku — turned into a NESTED array by a tampered post
     * (`lines[0][price][0][0]=1`, one level deeper than #395's own `lines[0][price][]=1`). Every
     * one of the six is guarded the same way (TextInput::nullableString() / AbstractAdminController
     * ::rawLineAmount(), both `is_scalar()` checks that don't care how deep the array goes), so
     * this never reproduced against current code when investigated — filed here anyway as the
     * permanent coverage the original #395 fix never got: a real POST to the real route, not just
     * the narrow TextInputTest unit test that only proved the helper itself was safe.
     */
    public function editSaveWithDeeplyNestedArrayLineFieldsDoesNotServerError(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $order->getCompany()->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'lines' => [[
                'name' => 'Nested Array Line',
                'qty' => '2',
                'price' => [[1]],
                'cost' => [[1]],
                'weight' => [[1]],
                'batch' => [[1]],
                'unit' => [[1]],
                'sku' => [[1]],
            ]],
            'save_mode' => 'draft_recalc',
        ]);

        // The save is accepted, not refused — every malformed field is silently treated as
        // absent, per TextInput::nullableString()'s own documented behaviour — but the bar #775
        // is actually about is that it does not crash.
        $I->seeResponseCodeIsSuccessful();

        $reloaded = $I->grabService(EntityManagerInterface::class)->getRepository(SalesOrder::class)->find($order->getId());
        $line = $reloaded->getLines()->last();
        $I->assertSame('Nested Array Line', $line->getName());
        $I->assertNull($line->getWeight(), 'a malformed weight is dropped, not stored as an array-derived value');
    }

    /**
     * Root-cause coverage for the CREATE path specifically: OrderController::
     * createOrderFromRequest() reads fulfillment_region independently of edit()'s POST
     * handler (two separate code paths, see OrderController.php), so a regression that broke
     * only order creation — e.g. reverting just the setFulfillmentRegion() call added to
     * createOrderFromRequest() — would slip past every other test above, which all exercise
     * editing an order that already exists rather than creating a brand new one.
     */
    public function savingTheCreateFormPersistsTheChosenFulfillmentRegion(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = (new Company())
            ->setName('Order Form Create Test Co')
            ->setCode('ORDFORM-CREATE-' . uniqid())
            ->setPrimaryEmail('buyer@order-form-create.example');
        $I->haveInRepository($company);

        $product = (new ProductCore())->setSku('ORDFORM-SKU-CREATE-REGION')->setName('Order Form Create Region Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $priceList = (new PriceList())->setName('Order Form Create Region List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);
        $this->makeActiveRegion($I, $company, 'Order Form Create Region', $priceList);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendAjaxPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'fulfillment_region' => 'Order Form Create Region',
            'lines' => [[
                'product_id' => (string) $product->getId(),
                'qty' => '2',
                'price' => '20.00',
            ]],
            'save_mode' => 'draft_recalc',
        ]);

        $newOrder = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $I->seeInRepository(SalesOrder::class, [
            'id' => $newOrder->getId(),
            'fulfillmentRegion' => 'Order Form Create Region',
        ]);
    }

    /**
     * A type=tax charge row is an admin-entered tax adjustment. It used to be summed into the
     * order's tax figure and left sitting in the extra_charges column, which meant no reporting
     * could ever see it. It is a tax line now — no rate, marked manual, keyed on its sluggified
     * label — while the type=shipping row beside it becomes a shipping line. There is no third
     * place for either to land: the column is gone (issue #165 step 8).
     */
    public function anAdHocTaxRowIsSavedAsAManualTaxLine(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $product = $order->getLines()->first()->getProduct();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $order->getCompany()->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'lines' => [['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '20.00']],
            'charge_lines' => [
                ['label' => 'Custom Shipping', 'amount' => '15.00', 'type' => 'shipping'],
                ['label' => 'Border Levy', 'amount' => '4.00', 'type' => 'tax', 'slug' => ''],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $entityManager = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());

        $I->assertCount(1, $saved->getShippingLines());
        $I->assertSame('Custom Shipping', $saved->getShippingLines()[0]->label);
        $I->assertSame(15.0, $saved->getShippingTotal());

        $lines = json_decode((string) $saved->getTaxLines(), true)['lines'];
        $manual = array_values(array_filter($lines, static fn (array $l) => $l['source'] === 'manual'));
        $I->assertCount(1, $manual);
        $I->assertSame('Border Levy', $manual[0]['label']);
        $I->assertSame('border-levy', $manual[0]['slug']);
        $I->assertNull($manual[0]['rate']);

        // The figure the customer pays is unchanged by where the adjustment is kept.
        $I->assertEqualsWithDelta(4.0, (float) $saved->getTax(), 0.001);
        $I->assertEqualsWithDelta(59.0, (float) $saved->getTotal(), 0.011);

        // And the edit form gets it back as a charge row, or the next save would drop it.
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('form.js-order-form[data-order-charges*="Border Levy"]');
    }

    /**
     * The capability the scalar could not express: an invoice that needs a carrier charge, a fuel
     * surcharge and a tailgate fee gets three rows, each labelled as typed, and the order's shipping
     * figure is their sum rather than whichever one happened to be written last.
     */
    public function anAdminCanPutSeveralShippingRowsOnOneOrder(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $product = $order->getLines()->first()->getProduct();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $order->getCompany()->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'lines' => [['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '20.00']],
            'charge_lines' => [
                ['label' => 'Shipping (Canada Post)', 'amount' => '22.50', 'type' => 'shipping'],
                ['label' => 'Fuel surcharge', 'amount' => '6.25', 'type' => 'shipping'],
                ['label' => 'Tailgate delivery', 'amount' => '15.00', 'type' => 'shipping'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $entityManager = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());

        $I->assertSame(
            ['Shipping (Canada Post)', 'Fuel surcharge', 'Tailgate delivery'],
            array_map(static fn ($line): string => $line->label, $saved->getShippingLines()),
        );
        $I->assertEqualsWithDelta(43.75, $saved->getShippingTotal(), 0.001);
        $I->assertEqualsWithDelta(40.0 + 43.75 + (float) $saved->getTax(), (float) $saved->getTotal(), 0.011);

        // Every row comes back to the form, or the next save would keep only the first.
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        foreach (['Shipping (Canada Post)', 'Fuel surcharge', 'Tailgate delivery'] as $label) {
            $I->seeElement('form.js-order-form[data-order-charges*="' . $label . '"]');
        }

        // And the detail page names each one rather than collapsing them into a single figure.
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Fuel surcharge');
        $I->see('Tailgate delivery');
    }

    /**
     * A type=fee charge row is the one-off charge that is neither tax nor shipping and isn't worth a
     * Fee definition. It becomes a manual fee line in the same fee_lines snapshot the calculators
     * write, so the two have to coexist — and coexisting is the hard part: that snapshot is rebuilt
     * from the calculators on every save, and no calculator has ever heard of this row. It survives
     * only because the form is handed it back and posts it again.
     *
     * The proof is byte-identity. The rows the form was given are read off it, posted back
     * untouched, and the snapshot the second save writes must be the same string as the first.
     */
    public function anAdHocFeeRowBecomesAFeeLineAndSurvivesBeingSavedAgainUntouched(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $product = $order->getLines()->first()->getProduct();
        $this->makeUserDefinedFee($I, 'Eco Fee', 1.50);

        $post = fn (array $charges): array => [
            'company_id' => (string) $order->getCompany()->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'lines' => [['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '20.00']],
            'charge_lines' => $charges,
            'save_mode' => 'draft_recalc',
        ];

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), $post([
            // Blank slug: it derives from the label, the way a manual tax row's does.
            ['label' => 'Crating', 'amount' => '40.00', 'type' => 'fee', 'slug' => '', 'taxClass' => 'E', 'placement' => 'main_line'],
        ]) + ['_token' => $I->csrfToken()]);

        $entityManager = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());

        $feeLines = json_decode((string) $saved->getFeeLines(), true);
        $I->assertCount(2, $feeLines);
        $I->assertSame('auto-calc', $feeLines[0]['source']);
        $I->assertSame('Crating', $feeLines[1]['label']);
        $I->assertSame('crating', $feeLines[1]['slug']);
        $I->assertSame('manual', $feeLines[1]['source']);
        $I->assertSame('fee', $feeLines[1]['type']);
        $I->assertSame('E', $feeLines[1]['taxClass']);
        $I->assertSame('main_line', $feeLines[1]['placement']);
        $I->assertEqualsWithDelta(40.0, (float) $feeLines[1]['amount'], 0.001);

        // It is a fee, not a tax: it moves the fee total and leaves the tax figure alone. 40 in
        // lines + 3 of eco fee + the 40 typed here, all of it exempt.
        $I->assertEqualsWithDelta(0.0, (float) $saved->getTax(), 0.001);
        $I->assertSame([], $saved->getShippingLines());
        $I->assertEqualsWithDelta(83.0, (float) $saved->getTotal(), 0.011);

        // The form is handed the row back with everything the line needs to be rebuilt.
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $charges = json_decode((string) $I->grabAttributeFrom('form.js-order-form', 'data-order-charges'), true);
        // assertEquals, not assertSame: json_decode reads the stored 40.0 back as int 40, which is
        // the same number the browser would post.
        $I->assertEquals([
            ['label' => 'Crating', 'amount' => 40.0, 'type' => 'fee', 'slug' => 'crating', 'taxClass' => 'E', 'placement' => 'main_line'],
        ], $charges);

        // Re-saving those rows untouched — what a save that only changed something else does — must
        // leave the snapshot byte-identical, calculated sibling included.
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), $post($charges) + ['_token' => $I->csrfToken()]);

        $entityManager->clear();
        $resaved = $entityManager->find(SalesOrder::class, $order->getId());
        $I->assertSame($saved->getFeeLines(), $resaved->getFeeLines());
        $I->assertSame($saved->getTotal(), $resaved->getTotal());
        $I->assertSame($saved->getTax(), $resaved->getTax());
    }

    /**
     * A taxable fee row is taxed by its own class, like any other fee line — which is the whole
     * point of the row carrying one. This is also what a fee row landing in the tax bucket would
     * get wrong twice over: the amount would be added to tax instead of being taxed.
     */
    public function aTaxableFeeRowIsTaxedByItsOwnTaxClassRatherThanBecomingTax(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $product = $order->getLines()->first()->getProduct();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $order->getCompany()->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'lines' => [['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '20.00']],
            'charge_lines' => [
                ['label' => 'Crating', 'amount' => '40.00', 'type' => 'fee', 'slug' => '', 'taxClass' => 'G', 'placement' => 'main_line'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $entityManager = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());

        // Not one of the order's tax lines: every one of those came from a calculator.
        $taxLines = json_decode((string) $saved->getTaxLines(), true)['lines'];
        $I->assertSame([], array_values(array_filter($taxLines, static fn (array $l) => $l['source'] === 'manual')));

        // Whatever the province's rates make of a $40 G-class charge, the total is subtotal plus the
        // fee plus that tax — and the fee is in the fee half of it, not the tax half.
        $I->assertEqualsWithDelta(40.0 + 40.0 + (float) $saved->getTax(), (float) $saved->getTotal(), 0.011);
    }

    /**
     * The fee row's own Tax Code and Tax $ cells state the fee's real figures rather than always
     * dashing them out (#667). Read off the RENDERED page, not the entity: the claim is what an
     * admin sees next to the fee, and the tax code/amount were computed correctly all along — they
     * were simply never passed into the row that displays them.
     *
     * A resolver-produced fee (makeUserDefinedFee()'s pattern, but with a real taxClass rather than
     * 'E') is what appears here, not a typed ad-hoc one — an ad-hoc "Crating"-style row with no Fee
     * behind it saves with source 'manual' (see anAdHocFeeRowBecomesAFeeLineAndSurvivesBeingSaved-
     * AgainUntouched above), and this row's own loop deliberately SKIPS manual fee lines, since
     * those are already on screen as an editable charge row. Only an auto-calc fee renders here.
     *
     * Compared against the SAVED breakdown's own perFeeLineTax figure rather than an amount typed
     * into this test — whatever the tax calculator registered in this test environment makes of the
     * fee's amount (nothing requires it to be nonzero), that figure, and not a dash, is what the row
     * must state. That is the actual regression this fix closes: before it, this cell read "—"
     * regardless of what OrderTaxBreakdownService had already computed.
     */
    public function theFeeRowStatesItsOwnTaxCodeAndTaxAmountInsteadOfDashingThemOut(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $product = $order->getLines()->first()->getProduct();

        $I->haveInRepository(
            (new \App\Entity\Fee())
                ->setSlug('ordform-taxable-fee-' . uniqid())
                ->setName('Taxable Handling Fee')
                ->setSource('FeeUserDefinedBundle')
                ->setTaxClass('G')
                ->setPlacement('main_line')
                ->setDefaultValue(15.0),
        );

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $order->getCompany()->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'lines' => [['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '20.00']],
            'charge_lines' => [],
            'save_mode' => 'draft_recalc',
        ]);

        $entityManager = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());

        $feeLines = json_decode((string) $saved->getFeeLines(), true);
        $I->assertCount(1, $feeLines);
        $I->assertSame('auto-calc', $feeLines[0]['source'], 'the fixture must produce an auto-calc fee, not a manual one, or the row never renders');
        $I->assertSame('G', $feeLines[0]['taxClass']);

        $snapshot = json_decode((string) $saved->getTaxLines(), true);
        $I->assertArrayHasKey('perFeeLineTax', $snapshot, 'the snapshot never got a perFeeLineTax figure to display');
        $expectedFeeTax = (float) ($snapshot['perFeeLineTax'][0] ?? null);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $dom = new \DOMDocument();
        @$dom->loadHTML($I->grabPageSource());
        $xpath = new \DOMXPath($dom);

        $feeRows = $xpath->query('//tr[contains(concat(" ", normalize-space(@class), " "), " fee-line-row ")]');
        $I->assertSame(1, $feeRows->length, 'expected exactly one fee row on the page');

        $cells = $xpath->query('.//td', $feeRows->item(0));
        // orderLineColumns, in order: Name(0) Location(1) SKU(2) Qty(3) Weight(4) U/M(5)
        // Tax Code(6) Tax $(7) Cost(8) Original Price(9) Price(10) Subtotal(11) Batch(12) Actions(13).
        $I->assertGreaterThan(7, $cells->length, 'the fee row has fewer cells than the column list expects');

        $I->assertSame('G', trim($cells->item(6)->textContent), 'Tax Code cell should name the fee\'s own tax class, not a dash');
        $I->assertSame(
            '$' . number_format($expectedFeeTax, 2),
            trim($cells->item(7)->textContent),
            'Tax $ cell should be the already-computed perFeeLineTax figure, not a dash',
        );
    }

    /**
     * A line's location is not actually mandatory server-side — OrderController stores whatever
     * nullableString() returns for it, including null — so a blank line (a service with no
     * location) must be allowed to save with none, rather than the picker silently forcing "Main"
     * onto it (#669).
     */
    public function aLineMaySaveWithNoLocationAtAll(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $product = $order->getLines()->first()->getProduct();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $order->getCompany()->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '20.00', 'location' => ''],
                ['name' => 'On-site labour', 'qty' => '0', 'location' => ''],
            ],
            'save_mode' => 'draft_recalc',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $entityManager = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());
        $lines = $saved->getLines()->toArray();

        $I->assertCount(2, $lines, 'a blank location must not be treated as an invalid post that drops the line');
        foreach ($lines as $line) {
            $I->assertNull($line->getLocation(), 'a blank location must persist as none, not be coerced to a warehouse');
        }
    }

    /**
     * The JS-cloned row templates (used when scripting adds a product or blank line) must not
     * force-select "Main" on a location nobody has chosen yet — the server-rendered row and both
     * `<template>`s have to agree, or "Add Blank Line" reintroduces the defect from the other side
     * (#669).
     *
     * Both templates now render through sales_line_row.html.twig itself (#full-parity,
     * 2026-09-13: "same file" — the JS-cloned row is the same include the server-rendered spare
     * rows use, not a hand-rolled copy), which is what actually "agrees" looks like: the blank
     * option is explicitly selected when there is no location to fall back to, the exact same way
     * a server-rendered spare row's location select already does. No REAL location may ever be
     * preselected; the blank option being marked selected is the correct, no-Main answer.
     */
    public function theJsRowTemplatesDoNotPreselectMainForLocation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $dom = new \DOMDocument();
        @$dom->loadHTML($I->grabPageSource());
        $xpath = new \DOMXPath($dom);

        foreach (['order-product-line-template', 'order-blank-line-template'] as $templateId) {
            $templates = $xpath->query('//template[@id="' . $templateId . '"]');
            $I->assertSame(1, $templates->length, $templateId . ' is missing from the page');

            // A <template>'s content lives in its own document fragment, invisible to a plain
            // //select search from the outer document — codeception's DOMDocument parse (like a
            // real browser) never promotes it into the live tree, so the raw HTML is read back out
            // and re-parsed as its own fragment instead.
            $fragment = new \DOMDocument();
            @$fragment->loadHTML('<table><tbody><tr>' . $templates->item(0)->ownerDocument->saveHTML($templates->item(0)) . '</tr></tbody></table>');
            $fragmentXpath = new \DOMXPath($fragment);

            $locationSelects = $fragmentXpath->query('//select[contains(@name, "[location]")]');
            $I->assertSame(1, $locationSelects->length, $templateId . ' has no location select');

            $selectedRealLocations = $fragmentXpath->query('.//option[@selected][@value!=""]', $locationSelects->item(0));
            $I->assertSame(0, $selectedRealLocations->length, $templateId . ' pre-selects a real location instead of defaulting to none');

            $blankOptions = $fragmentXpath->query('.//option[@value=""]', $locationSelects->item(0));
            $I->assertSame(1, $blankOptions->length, $templateId . ' offers no blank/"None" location option');
        }
    }

    /**
     * The rule Fee::assertValid() enforces for a defined fee, on a line that has no Fee behind it:
     * an after-tax charge is added once tax is settled, so it cannot be taxable. It has to be
     * refused before the save starts — a fee calculator flushes partway through one
     * (FeeRepository::ensureBySlug()), so a bad row discovered mid-save would leave half an order
     * written.
     */
    public function aTaxableAfterTaxFeeRowIsRefusedWithAMessageAndNothingIsWritten(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $product = $order->getLines()->first()->getProduct();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $order->getCompany()->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'lines' => [['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '20.00']],
            'charge_lines' => [
                ['label' => 'COD Fee', 'amount' => '12.00', 'type' => 'fee', 'slug' => '', 'taxClass' => 'G', 'placement' => 'after_tax_line'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $I->seeResponseCodeIs(422);
        $I->assertStringContainsString('After Tax fees cannot be taxable', $I->grabPageSource());

        $entityManager = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertNull($entityManager->find(SalesOrder::class, $order->getId())->getFeeLines());
    }

    /**
     * A type nothing recognises is refused rather than filed somewhere. Reading it as tax is exactly
     * what turned a fee row into a tax line and is not a mistake worth repeating for the next kind
     * someone invents.
     */
    public function aChargeRowNamingAnUnknownTypeIsRefusedRatherThanTreatedAsTax(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $product = $order->getLines()->first()->getProduct();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $order->getCompany()->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'lines' => [['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '20.00']],
            'charge_lines' => [['label' => 'Deposit', 'amount' => '25.00', 'type' => 'deposit']],
            'save_mode' => 'draft_recalc',
        ]);

        $I->seeResponseCodeIs(422);
        $I->assertStringContainsString('unknown line type', $I->grabPageSource());

        $entityManager = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(SalesOrder::class, $order->getId());
        $I->assertNull($saved->getFeeLines());
        $I->assertNull($saved->getTaxLines());
    }

    /**
     * The Empty Fee Line option, the row template's tax-class select, and the add-line bar's own
     * placement picker (#669) — placement is asked once there, before the row exists, rather than
     * on the row template itself, which no longer carries one.
     */
    public function theFormOffersAnEmptyFeeLineAndARowTemplateThatCanCarryOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Custom Fee (Type Anything)', 'select.js-order-charge-type option');
        $I->seeElement('#order-charge-row-template select.js-order-charge-tax-class');
        $I->dontSeeElement('#order-charge-row-template select.js-order-charge-placement');
        $I->seeElement('select.js-order-charge-placement-choice');
    }

    /**
     * The bug #669 was actually filed over: a manual fee's "After Tax" placement never put its row
     * after tax anywhere — both the no-JS charge rows and the JS hydration anchored every non-tax
     * charge in the same spot, above Subtotal, no matter what its placement said. This saves an
     * After Tax fee (Exempt, since a taxable one is refused outright — see
     * aTaxableAfterTaxFeeRowIsRefusedWithAMessageAndNothingIsWritten) and checks its row is
     * rendered between "Total Tax:" and "Grand Total:", not between the line table and "Subtotal:".
     */
    public function anAfterTaxManualFeeRowRendersAfterTotalTaxNotBeforeSubtotal(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $product = $order->getLines()->first()->getProduct();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'save_mode' => 'draft_recalc',
            'company_id' => (string) $order->getCompany()->getId(),
            'charge_lines_present' => '1',
            'charge_lines' => [[
                'label' => 'Rush Handling', 'amount' => '9.00', 'type' => 'fee',
                'slug' => '', 'taxClass' => 'E', 'placement' => 'after_tax_line',
            ]],
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '20.00'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[type="text"][name="charge_lines[0][label]"][value="Rush Handling"]');

        $source = $I->grabPageSource();
        $subtotalPos = strpos($source, 'Subtotal:');
        $rowPos = strpos($source, 'name="charge_lines[0][label]" value="Rush Handling"');
        $totalTaxPos = strpos($source, 'Total Tax:');
        $grandTotalPos = strpos($source, 'Grand Total:');

        $I->assertNotFalse($subtotalPos);
        $I->assertNotFalse($rowPos);
        $I->assertNotFalse($totalTaxPos);
        $I->assertNotFalse($grandTotalPos);
        $I->assertGreaterThan($totalTaxPos, $rowPos, 'the After Tax fee row rendered before "Total Tax:" instead of after it');
        $I->assertLessThan($grandTotalPos, $rowPos, 'the After Tax fee row rendered after "Grand Total:" instead of before it');
        $I->assertGreaterThan($subtotalPos, $rowPos, 'the After Tax fee row rendered before "Subtotal:"');
    }

    /**
     * #669: the add-line bar (the [line type] select and its Add Line control) used to sit under
     * the line table, beside the totals box it feeds — this moves it above the table, beside Add
     * Product/Add Blank Line, since a shipping/tax/fee charge is added the same way a product or
     * blank line is. The category and placement pickers travel with it, since both are part of the
     * same add-line step now (#669).
     */
    public function theAddLineBarSitsAboveTheLineTableBesideAddProductAndAddBlankLine(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $source = $I->grabPageSource();
        $addProductPos = strpos($source, 'js-order-add-product');
        $addLineBarPos = strpos($source, 'order-add-line-bar');
        $tablePos = strpos($source, 'order-lines-table');

        $I->assertNotFalse($addProductPos);
        $I->assertNotFalse($addLineBarPos);
        $I->assertNotFalse($tablePos);
        $I->assertGreaterThan($addProductPos, $addLineBarPos, 'the add-line bar rendered before Add Product/Add Blank Line');
        $I->assertLessThan($tablePos, $addLineBarPos, 'the add-line bar rendered below the line table instead of above it');

        $I->seeElement('select.js-order-charge-category');
        $I->seeElement('select.js-order-charge-placement-choice[hidden]');
    }

    /**
     * #669: "Custom Shipping" (a fixed, uneditable label) and "Empty Shipping Line" (free text) were
     * two options for the same thing on the order form — one way to add an unnamed shipping charge
     * is enough, and it says so now.
     */
    public function theOrderFormDropsTheFixedLabelCustomShippingOptionAndRenamesTheFreeTextOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->dontSeeElement('select.js-order-charge-type option[value="shipping:Custom Shipping"]');
        $I->seeElement('select.js-order-charge-type option[value="empty-shipping:"]');
        $I->see('Custom Shipping (Type anything)', 'select.js-order-charge-type option');
        $I->dontSee('Empty Shipping Line (Type Anything)', 'select.js-order-charge-type option');

        // The tax and fee free-text options read the same way now: a named "Custom ..." choice an
        // admin fills in, not an "Empty ... Line" that reads like a blank placeholder.
        $I->see('Custom Tax (Type Anything)', 'select.js-order-charge-type option');
        $I->dontSee('Empty Tax Line (Type Anything)', 'select.js-order-charge-type option');
        $I->see('Custom Fee (Type Anything)', 'select.js-order-charge-type option');
        $I->dontSee('Empty Fee Line (Type Anything)', 'select.js-order-charge-type option');
    }

    /** A fee the resolver produces on its own, so a manual line has something to sit beside. */
    private function makeUserDefinedFee(FunctionalTester $I, string $name, float $perUnit): void
    {
        $I->haveInRepository(
            (new \App\Entity\Fee())
                ->setSlug('ordform-fee-' . uniqid())
                ->setName($name)
                ->setSource('FeeUserDefinedBundle')
                ->setTaxClass('E')
                ->setPlacement('main_line')
                ->setDefaultValue($perUnit)
        );
    }

    private function makeActiveRegion(FunctionalTester $I, Company $company, string $regionName, PriceList $priceList): void
    {
        $region = (new FulfillmentRegion())->setName($regionName)->setStatus('Active');
        $I->haveInRepository($region);

        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($company)
                ->setFulfillmentRegion($region)
                ->setStatus('Active')
                ->setPriceList($priceList)
        );

        // The orders here are Approved, and since #326 a save into a reserving status is refused
        // unless the line is covered by stock in the region the line resolves to. These tests are
        // about price-list precedence, not scarcity, so the region is simply stocked.
        $I->haveStockInRegionForAllProducts($regionName);
    }
}
