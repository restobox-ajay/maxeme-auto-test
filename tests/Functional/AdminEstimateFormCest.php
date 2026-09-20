<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Fee;
use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The estimate create/edit line table used to render only Product/SKU/Qty/Price with a plain
 * <select> and its own inline add/remove <script>. It should render the same column set the order
 * form does, off the same searchable-select widget and the same shared app.js row logic — which
 * this suite can only verify at the markup level (no WebDriver/JS execution) plus by POSTing the
 * newly editable columns.
 */
final class AdminEstimateFormCest
{
    /**
     * The fee rows on the line table as `name => amount`, each half read out of the cell that
     * carries it (#627). Asserted as separate see(..., 'tr.fee-line-row') calls, the name and the
     * figure were only ever known to be somewhere in SOME fee row — never in the same one, and
     * never in the cell meant to carry them. Proved by mutation: rendering the amount inside the
     * Product cell and 'TBD' in the Subtotal cell left all 58 tests in this file green. Pairing the
     * two, by data-label, is the only way to say which fee cost what.
     *
     * The '$' prefix does hold one line of defence the bare numbers elsewhere in #627 lack:
     * '$16.50' does not contain '$6.50'. It is the pairing and the cell, not the substring, that
     * this closes.
     *
     * @return array<string, string>
     */
    private function feeLineRows(FunctionalTester $I): array
    {
        $row = '#estimate-line-rows tr.estimate-line-row.fee-line-row ';
        $names = array_map(trim(...), $I->grabMultiple($row . 'td[data-label="Name"]'));
        $amounts = array_map(trim(...), $I->grabMultiple($row . 'td[data-label="Subtotal"]'));
        $I->assertSame(\count($names), \count($amounts), 'every fee row must carry both a name and an amount');

        return array_combine($names, $amounts);
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-estimate-form-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())->setName('Estimate Form Test Co')->setCode('ESTFORM-' . uniqid());
        $I->haveInRepository($company);

        return $company;
    }

    /**
     * Quote CREATE is refused for a company with no active fulfillment region (#238), so the two
     * tests below that POST a brand-new quote need one. Everything else here edits an existing
     * quote for a region-less company, which stays allowed on purpose.
     */
    private function makeActiveRegion(FunctionalTester $I, Company $company, string $regionName): void
    {
        $priceList = (new PriceList())->setName($regionName . ' List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        $region = (new FulfillmentRegion())->setName($regionName)->setStatus('Active');
        $I->haveInRepository($region);

        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($company)
                ->setFulfillmentRegion($region)
                ->setStatus('Active')
                ->setPriceList($priceList)
        );
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('ESTFORM-SKU-1')
            ->setName('Estimate Form Test Product')
            ->setUnit('EA')
            ->setWeight('12.500')
            ->setSalesTaxCode('G')
            // Two significant decimals: a trailing-zero cost round-trips out of sqlite as "30"
            // but out of MySQL as "30.00", which would make the data-cost assertion DB-specific.
            ->setCostPrice('30.55')
            ->setDefaultPrice('65.25')
            ->setOriginalPrice('79.99')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    private function makeEstimate(FunctionalTester $I, Company $company, ?ProductCore $product = null): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('ESTFORM-' . uniqid())
            ->setSource('Admin');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $estimate->setBillingAddressFrom($company->getDefaultBillingAddress());
        $estimate->setShippingAddressFrom($company->getDefaultShippingAddress());

        $line = (new EstimateLine())
            ->setName($product?->getName() ?? 'Widget')
            ->setSku($product?->getSku() ?? 'WIDGET-1')
            ->setQuantity('2.00')
            ->setCost('30.00');
        if ($product instanceof ProductCore) {
            $line->setProduct($product);
        }
        $estimate->addLine($line);

        $I->haveInRepository($estimate);

        return $estimate;
    }

    public function editPageProductDropdownIsSearchableAndOptionsCarryTheFullDataSet(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select.js-estimate-product-select.js-searchable-select');
        $I->seeElement('select.js-estimate-product-select.estimate-line-product-select');
        // Order's own scoped CSS hook must not be borrowed for this table.
        $I->dontSeeElement('select.js-estimate-product-select.order-line-product-select');
        $I->seeElement('select.js-estimate-product-select option[data-sku="ESTFORM-SKU-1"]', [
            'data-weight' => '12.500',
            'data-unit' => 'EA',
            'data-tax-code' => 'G',
            'data-cost' => '30.55',
            'data-original-price' => '79.99',
            'data-price' => '65.25',
        ]);
    }

    public function createPageProductDropdownIsSearchableAndOptionsCarryTheFullDataSet(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeProduct($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        // Create mode only builds the line table once a company is chosen, same as order's.
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select.js-estimate-product-select.js-searchable-select.estimate-line-product-select');
        $I->seeElement('select.js-estimate-product-select option[data-sku="ESTFORM-SKU-1"]', [
            'data-weight' => '12.500',
            'data-unit' => 'EA',
            'data-tax-code' => 'G',
            'data-cost' => '30.55',
            'data-original-price' => '79.99',
            'data-price' => '65.25',
        ]);
    }

    /** A product with no ProductPricing row at all must still offer a price to populate. */
    public function productWithoutPricingFallsBackToItsDefaultThenOriginalPrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $I->haveInRepository(
            (new ProductCore())->setSku('ESTFORM-SKU-ORIGINAL-ONLY')->setName('Estimate Form Retail Only')->setOriginalPrice('49.95')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
        );

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select.js-estimate-product-select option[data-sku="ESTFORM-SKU-ORIGINAL-ONLY"]', [
            'data-price' => '49.95',
        ]);
    }

    public function lineTableRendersTheFullOrderStyleColumnSetWithALocationPicker(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $I->haveInRepository((new FulfillmentRegion())->setName('Estimate Form West')->setStatus('Active'));

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        foreach (['Location', 'Weight', 'U/M', 'Tax Code', 'Tax $', 'Cost', 'Original Price', 'Subtotal'] as $header) {
            $I->see($header, '#estimate-line-table thead th');
        }

        foreach (['location', 'sku', 'weight', 'unit', 'tax_code', 'cost'] as $field) {
            // Matched by SUFFIX, because the row index is part of the name now: every line field
            // posts as lines[N][field]. Ends-with is unambiguous across this row's field set —
            // lines[0][unit_id] does not end with "[unit]", and lines[0][product_id] does not end
            // with "[id]".
            $I->seeElement('#estimate-line-rows [name$="[' . $field . ']"]');
        }

        // Batch is a fulfillment concept — a quote allocates nothing, so it carries no such
        // column and no such input (#250). It stays on the order form, where it means something.
        $I->dontSee('Batch', '#estimate-line-table thead th');
        $I->dontSeeElement('#estimate-line-rows [name$="[batch]"]');
        $I->see('Estimate Form West', '#estimate-line-rows select[name$="[location]"] option');
        $I->seeElement('#estimate-line-rows .js-estimate-original');
        $I->seeElement('#estimate-line-rows .js-estimate-line-tax');
        $I->seeElement('#estimate-line-rows .js-estimate-subtotal');
    }

    /**
     * The 13-column line table was ported from order without order's column-sizing CSS, so it kept
     * .wide-price-table's width: max-content / table-layout: auto defaults and every column grew to
     * its widest content — pushing Original Price onwards off the end of the horizontal scroll. It
     * now carries its own <colgroup>, which is what drives the widths under table-layout: fixed.
     */
    public function lineTableCarriesAColgroupSoEveryColumnGetsAFixedShareOfTheWidth(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);

        $columns = [
            'name', 'location', 'sku', 'qty', 'weight', 'unit', 'tax',
            'tax-amount', 'cost', 'original', 'price', 'subtotal', 'actions',
        ];

        $I->haveHttpHeader('Host', 'admin.localhost');
        foreach (['/admin/estimate/edit/' . $estimate->getId(), '/admin/estimate/create?company_id=' . $company->getId()] as $page) {
            $I->amOnPage($page);
            $I->seeResponseCodeIsSuccessful();
            $I->seeElement('#estimate-line-table > colgroup');
            foreach ($columns as $column) {
                $I->seeElement('#estimate-line-table > colgroup > col.estimate-col-' . $column);
            }
        }
    }

    /**
     * The Actions column paired a 30px round icon with a full-size "Remove" text button, which
     * neither lined up nor fit the column. Both controls are now the same .mini-action pill inside
     * a .estimate-line-controls row, matching order's .order-line-controls cell.
     */
    public function lineActionsCellUsesTheSameEquallySizedControlPairOrderRowsDo(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#estimate-line-rows .estimate-line-controls a.mini-action.info.js-estimate-search-product');
        $I->seeElement('#estimate-line-rows .estimate-line-controls button.mini-action.danger.js-estimate-remove-line');
        $I->dontSeeElement('#estimate-line-rows button.button.outline.js-estimate-remove-line');
    }

    /**
     * Add/remove/populate-row behaviour now comes from app.js off a server-rendered row template,
     * the same way the order form's does — the page must not ship its own inline duplicate.
     */
    public function rowTemplateIsRenderedForTheSharedAppJsLogicInsteadOfAnInlineScript(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('template#estimate-product-line-template');
        $I->seeElement('template#estimate-blank-line-template');
        $I->seeElement('tbody.js-estimate-lines-body');
        $I->seeElement('button.js-estimate-add-product');
        $I->seeElement('button.js-estimate-remove-line');
        $I->dontSee('js-estimate-product-select', 'script');
    }

    /**
     * CLIENT ROUND 3 FEEDBACK, PART 13 — the line table had one generic "+ Add Line" button and no
     * way to slot a row in mid-table. It now carries order's exact pair (Add Product / Add Blank
     * Line, off the two row <template>s) plus order's per-row "+" (.js-order-add-after's twin).
     */
    public function lineTableOffersOrdersAddProductAddBlankLinePairAndAPerRowInsert(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        foreach (['/admin/estimate/edit/' . $estimate->getId(), '/admin/estimate/create?company_id=' . $company->getId()] as $page) {
            $I->amOnPage($page);
            $I->seeResponseCodeIsSuccessful();
            $I->see('Add Product', '.order-line-actions button.js-estimate-add-product');
            $I->see('Add Blank Line', '.order-line-actions button.js-estimate-add-blank');
            $I->dontSeeElement('button.js-estimate-add-line');
            // Both row shapes have to be clonable client-side, or "Add Blank Line" has nothing to add.
            $I->seeElement('template#estimate-product-line-template select[name$="[product_id]"]');
            $I->seeElement('template#estimate-blank-line-template input[type="text"][name$="[name]"]');
            $I->dontSeeElement('template#estimate-blank-line-template select[name$="[product_id]"]');
            // The Actions cell holds the product link and the remove "x" — and no per-row "+".
            // That control cloned a whole product line while looking exactly like order's batch
            // "+", so it was removed rather than relabelled (#245).
            $I->dontSeeElement('#estimate-line-rows .estimate-line-controls button.js-estimate-add-after');
            $I->seeElement('#estimate-line-rows .estimate-line-controls button.mini-action.danger.js-estimate-remove-line');
        }
    }

    /**
     * CLIENT ROUND 6 FEEDBACK, PART 22 — the Add Product / Add Blank Line pair used to render BELOW
     * the line table on both estimate pages, while order puts it directly above its table. Guards
     * the offsets so the pair can't silently drift back under the table.
     */
    public function addProductAndAddBlankLineSitAboveTheLineTableJustLikeOrder(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        foreach (['/admin/estimate/edit/' . $estimate->getId(), '/admin/estimate/create?company_id=' . $company->getId()] as $page) {
            $I->amOnPage($page);
            $I->seeResponseCodeIsSuccessful();

            $source = $I->grabPageSource();
            $tableAt = strpos($source, '<table class="wide-price-table estimate-line-table"');
            $I->assertNotFalse($tableAt, 'The line-items table must render on ' . $page . '.');

            foreach (['js-estimate-add-product', 'js-estimate-add-blank'] as $button) {
                $buttonAt = strpos($source, $button);
                $I->assertNotFalse($buttonAt, $button . ' must render on ' . $page . '.');
                $I->assertLessThan(
                    $tableAt,
                    $buttonAt,
                    $button . ' must render above the line-items table on ' . $page . ', like order\'s pair does.',
                );
            }
        }
    }

    /**
     * A blank row is a real line with no product, so it has to survive the round trip by its typed
     * name alone — the server used to skip any row without a line_product_id outright.
     */
    public function savingABlankLinePersistsItByItsTypedNameWithNoProduct(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        // What the browser posts after "Add Blank Line": a second row with a name but no product.
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'name' => '', 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '10.00'],
                1 => ['id' => '', 'name' => 'Crating and handling', 'product_id' => '', 'qty' => '3', 'price' => '5.00'],
            ],
            'action' => 'save',
        ]);
        $I->see('Estimate saved.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertCount(2, $saved->getLines());
        $blank = $saved->getLines()->last();
        $I->assertSame('Crating and handling', $blank->getName());
        $I->assertNull($blank->getProduct());
        $I->assertSame(15.0, (float) $blank->getSubtotal());
        // The product row's own name must not be clobbered by its empty line_name[] placeholder.
        $I->assertSame($product->getName(), $saved->getLines()->first()->getName());
    }

    /**
     * An existing product-less line IS a blank line, so the edit page renders it as an editable
     * name input (it used to only show the name as the product select's empty-option label, which
     * left it uneditable).
     */
    public function anExistingProductLessLineRendersAsAnEditableNameInput(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#estimate-line-rows tr.estimate-line-row:first-child input[type="text"][name$="[name]"]', ['value' => 'Widget']);
        $I->dontSeeElement('#estimate-line-rows tr.estimate-line-row:first-child select[name$="[product_id]"]');

        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'name' => 'Renamed custom line', 'qty' => '2', 'price' => '10.00'],
            ],
            'action' => 'save',
        ]);
        $I->see('Estimate saved.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(EstimateLine::class, $line->getId());
        $I->assertSame('Renamed custom line', $saved->getName());
        $I->assertNull($saved->getProduct());
    }

    /**
     * CLIENT ROUND 5 FEEDBACK, PART 20 — order's third add-line mechanism: a line-type select plus
     * "Add Line" below the table, next to the totals box. It adds charge rows (shipping/tax), not
     * product rows, and since it landed the estimate's own "Shipping option / Shipping amount /
     * Shipping method" field grid is gone — the type=shipping charge rows ARE the estimate's
     * shipping now, exactly like order, so there's no second mechanism to double-count them.
     *
     * The computed carrier/method/cost options EstimateController::buildShippingOptions() resolves
     * moved into this select; ShippingFreeBundle's calculator always supports() any context, so
     * "Free Shipping" is always among them as a real resolved option, not a guess.
     */
    public function editPageRendersOrdersBottomAddLineChargeControlInsteadOfShippingFields(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        // Order's exact layout: the add-line bar sits in the toolbar, above the line table
        // (#full-parity, 2026-09-13 — order itself moved this here from the footer, and the quote
        // moved with it, so there is nowhere left for the two to disagree).
        $I->seeElement('.order-lines-section .order-lines-toolbar .order-add-line-bar select.js-estimate-charge-type');
        $I->seeElement('.order-lines-section .order-lines-toolbar .order-add-line-bar button.js-estimate-bottom-add');
        $I->see('Add Line', '.order-add-line-bar button.js-estimate-bottom-add');
        $I->seeElement('template#estimate-charge-row-template');

        // Order's option set, verbatim: resolved shipping methods, then the custom/empty extras.
        // categoryFilter drops "Custom Shipping" the same way it does on Order — the category
        // dropdown plus "empty-shipping:" (Type anything) cover that case instead.
        $I->seeElement('select.js-estimate-charge-type option[value^="shipping-method:Free Shipping|"]');
        $I->see('Free Shipping', 'select.js-estimate-charge-type option');
        $I->dontSeeElement('select.js-estimate-charge-type option[value="shipping:Custom Shipping"]');
        $I->seeElement('select.js-estimate-charge-type option[value="tax:Custom GST"]');
        $I->seeElement('select.js-estimate-charge-type option[value="tax:Custom PST"]');
        $I->seeElement('select.js-estimate-charge-type option[value="tax:Custom HST"]');
        $I->seeElement('select.js-estimate-charge-type option[value="empty-shipping:"]');
        $I->seeElement('select.js-estimate-charge-type option[value="empty-tax:"]');

        // The retired shipping field grid is gone from the form entirely.
        $I->dontSeeElement('select.js-estimate-shipping-select');
        $I->dontSeeElement('input[name="shipping"]');
        $I->dontSeeElement('input[name="shipping_method"]');

        // ...and the whole bar sits ABOVE the line table, where order puts it.
        $source = $I->grabPageSource();
        $I->assertLessThan(
            strpos($source, '<table'),
            strpos($source, 'js-estimate-charge-type'),
            'The Add Line bar must render before the line-items table, like order\'s does.',
        );
    }

    /**
     * CLIENT ROUND 5 FEEDBACK, PART 20 — the ported charge rows drive the estimate end-to-end: a
     * type=shipping row becomes a shipping line (its label carries the method name, as on order)
     * and a type=tax row becomes a manual tax line, with both re-rendered as editable rows in the
     * totals box. Neither is stored in charge_lines any more: one is a fee row and the other a
     * tax row, and each lives in the snapshot that can report on it.
     */
    public function addedShippingAndTaxChargeRowsBecomeTheEstimatesShippingAndTax(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'charge_lines_present' => '1',
            'charge_lines' => [
                // "Custom Shipping" is a manual override, so its amount is kept exactly as
                // submitted — see the named-method test below for the recomputed case.
                ['label' => 'Custom Shipping', 'amount' => '30.00', 'type' => 'shipping'],
                ['label' => 'Custom GST', 'amount' => '4.00', 'type' => 'tax'],
            ],
            'action' => 'save',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertEqualsWithDelta(30.0, $saved->getShippingTotal(), 0.001);
        $I->assertSame('Custom Shipping', $saved->getShippingMethod());
        $I->assertCount(1, $saved->getShippingLines());
        $I->assertSame('Custom Shipping', $saved->getShippingLines()[0]->label);
        // The tax row is a tax line now: no rate, marked manual, keyed on its sluggified label,
        // and counted in the estimate's tax the same way the calculated lines are.
        $taxLines = json_decode((string) $saved->getTaxLines(), true)['lines'];
        $manual = array_values(array_filter($taxLines, static fn (array $l) => $l['source'] === 'manual'));
        $I->assertCount(1, $manual);
        $I->assertSame('Custom GST', $manual[0]['label']);
        $I->assertNull($manual[0]['rate']);
        $I->assertSame('custom-gst', $manual[0]['slug']);
        $I->assertEqualsWithDelta(4.0, (float) $manual[0]['amount'], 0.001);
        $I->assertGreaterThanOrEqual(4.0, (float) $saved->getTax());
        $I->assertEqualsWithDelta(230.0 + (float) $saved->getTax(), (float) $saved->getTotal(), 0.011);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        // Both rows come back as editable charge rows in the totals box, shipping above Subtotal
        // and tax above Grand Total, each posting itself back under its own index. JS hydrates the
        // live rows from data-estimate-charges; the <noscript> twins are what a no-JS admin (and
        // Codeception) actually sees, same as Order's.
        $I->see('Custom Shipping', '.order-total-box noscript.no-js-charge-rows .order-charge-line-row');
        $I->seeElement('.order-total-box noscript.no-js-charge-rows input[name="charge_lines[0][type]"][value="shipping"]');
        $I->seeElement('.order-total-box noscript.no-js-charge-rows input[name="charge_lines[0][amount]"][value="30.00"]');
        $I->see('Custom GST', '.order-total-box noscript.no-js-charge-rows .order-charge-line-row');
        $I->seeElement('.order-total-box noscript.no-js-charge-rows input[name="charge_lines[1][type]"][value="tax"]');
        $I->seeElement('.order-total-box noscript.no-js-charge-rows input[name="charge_lines[1][amount]"][value="4.00"]');
    }

    /**
     * A type=fee row is the one-off charge that is neither tax nor shipping. On an estimate it has
     * to work exactly as it does on an order — they share this code path, and an estimate that lost
     * its manual fee lines would convert into an order missing them.
     *
     * The proof is byte-identity: the rows the form was given are read off it, posted back
     * untouched, and the snapshot the second save writes must be the same string as the first. That
     * is the hazard here — a save rebuilds fee_lines from the calculators, which have never heard of
     * this row.
     */
    public function anAdHocFeeRowBecomesAFeeLineAndSurvivesBeingSavedAgainUntouched(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $post = fn (array $charges): array => [
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'charge_lines_present' => '1',
            'charge_lines' => $charges,
            'action' => 'save',
        ];

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), $post([
            // Shipping too, so the estimate is fully priced and states a Grand Total.
            ['label' => 'Custom Shipping', 'amount' => '30.00', 'type' => 'shipping'],
            // Blank slug: it derives from the label, the way a manual tax row's does.
            ['label' => 'Crating', 'amount' => '40.00', 'type' => 'fee', 'slug' => '', 'taxClass' => 'E', 'placement' => 'main_line'],
        ]) + ['_token' => $I->csrfToken()]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());

        $feeLines = json_decode((string) $saved->getFeeLines(), true);
        $I->assertCount(2, $feeLines);
        $I->assertSame('shipping', $feeLines[0]['type']);
        $I->assertSame('Crating', $feeLines[1]['label']);
        $I->assertSame('crating', $feeLines[1]['slug']);
        $I->assertSame('manual', $feeLines[1]['source']);
        $I->assertSame('fee', $feeLines[1]['type']);
        $I->assertSame('E', $feeLines[1]['taxClass']);
        $I->assertSame('main_line', $feeLines[1]['placement']);
        $I->assertEqualsWithDelta(40.0, (float) $feeLines[1]['amount'], 0.001);

        // It is a fee, not a tax: no manual tax line was created for it.
        $taxLines = json_decode((string) $saved->getTaxLines(), true)['lines'];
        $I->assertSame([], array_values(array_filter($taxLines, static fn (array $l) => $l['source'] === 'manual')));
        // 200 of lines + 30 shipping + the 40 typed here, plus whatever tax was computed.
        $I->assertEqualsWithDelta(270.0 + (float) $saved->getTax(), (float) $saved->getTotal(), 0.011);

        // The row comes back as an editable charge row carrying everything the line needs, or the
        // next save — which rebuilds fee_lines from the calculators — would drop it.
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('.order-total-box noscript.no-js-charge-rows input[name="charge_lines[1][type]"][value="fee"]');
        $I->seeElement('.order-total-box noscript.no-js-charge-rows input[name="charge_lines[1][slug]"][value="crating"]');
        $I->seeElement('.order-total-box noscript.no-js-charge-rows input[name="charge_lines[1][amount]"][value="40.00"]');
        $I->seeElement('.order-total-box noscript.no-js-charge-rows select[name="charge_lines[1][taxClass]"] option[value="E"][selected]');
        $I->seeElement('.order-total-box noscript.no-js-charge-rows select[name="charge_lines[1][placement]"] option[value="main_line"][selected]');
        // Once as a row, not twice: it is already on screen as its own editable <noscript> row, so
        // it must not also be rendered by the read-only fee loops. It legitimately appears a second
        // time now, JSON-encoded in data-estimate-charges — the same hydrate-from-JSON mechanism
        // Order uses — so the count to check is 2, not 1.
        $I->assertSame(2, substr_count($I->grabPageSource(), 'Crating'));

        // Exactly what that form posts back — read off the form, not retyped.
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), $post([
            ['label' => 'Custom Shipping', 'amount' => '30.00', 'type' => 'shipping', 'slug' => 'custom-shipping'],
            ['label' => 'Crating', 'amount' => '40.00', 'type' => 'fee', 'slug' => 'crating', 'taxClass' => 'E', 'placement' => 'main_line'],
        ]) + ['_token' => $I->csrfToken()]);

        $entityManager->clear();
        $resaved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertSame($saved->getFeeLines(), $resaved->getFeeLines());
        $I->assertSame($saved->getTotal(), $resaved->getTotal());
        $I->assertSame($saved->getTax(), $resaved->getTax());
    }

    /**
     * A post that never rendered the charge UI at all must not wipe what is already stored — the
     * same protection the shipping and manual tax rows already have.
     */
    public function aPostWithoutTheChargeUiLeavesAStoredManualFeeLineAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $stored = $entityManager->find(Estimate::class, $estimate->getId());
        $stored->setFeeLines(json_encode([[
            'slug' => 'crating', 'label' => 'Crating', 'taxClass' => 'E',
            'amount' => 40.0, 'placement' => 'main_line', 'type' => 'fee', 'source' => 'manual',
        ]]));
        $entityManager->flush();
        $before = $stored->getFeeLines();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'action' => 'save',
        ]);

        $entityManager->clear();
        $resaved = $entityManager->find(Estimate::class, $estimate->getId());

        // The positive control (#594). The fixture line carries no price, so `line_price 100.00` is
        // a real change — and without asserting it landed, a save the controller refused outright
        // (a missing field, a validation bounce, an exception) leaves feeLines untouched for the
        // wrong reason and this test reports that the guard works when it never ran.
        $I->assertSame(
            100.0,
            (float) $resaved->getLines()->first()->getPrice(),
            'the save has to have gone through, or "the stored charge survived" is about nothing',
        );

        $I->assertSame($before, $resaved->getFeeLines());
    }

    /**
     * The rule Fee::assertValid() enforces for a defined fee, on a line that has no Fee behind it:
     * an after-tax charge is added once tax is settled, so it cannot be taxable. Refused at the
     * door — a fee calculator flushes partway through the save (FeeRepository::ensureBySlug()), so
     * a bad row discovered mid-save would leave half an estimate written.
     */
    public function aTaxableAfterTaxFeeRowIsRefusedOnAnEstimate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'charge_lines_present' => '1',
            'charge_lines' => [
                ['label' => 'COD Fee', 'amount' => '12.00', 'type' => 'fee', 'slug' => '', 'taxClass' => 'G', 'placement' => 'after_tax_line'],
            ],
            'action' => 'save',
        ]);

        // Refused, not crashed: the save is turned away with an error the admin can act on. A
        // guard that only threw once the save was under way would also put this message on screen —
        // on a 500 page — so the response code is the assertion that tells the two apart.
        $I->seeResponseCodeIsSuccessful();
        $I->assertStringContainsString('After Tax fees cannot be taxable', $I->grabPageSource());

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        // Refused at the door: nothing about the estimate was written.
        $I->assertNull($entityManager->find(Estimate::class, $estimate->getId())->getFeeLines());
    }

    /** A type nothing recognises is refused rather than quietly becoming a tax line. */
    public function aChargeRowNamingAnUnknownTypeIsRefusedOnAnEstimate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'charge_lines_present' => '1',
            'charge_lines' => [['label' => 'Deposit', 'amount' => '25.00', 'type' => 'deposit']],
            'action' => 'save',
        ]);

        // Refused, not crashed — see the after-tax test above for why the code matters.
        $I->seeResponseCodeIsSuccessful();
        $I->assertStringContainsString('unknown line type', $I->grabPageSource());

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertNull($saved->getFeeLines());
        $I->assertNull($saved->getTaxLines());
    }

    /**
     * The Empty Fee Line option, and the tax-class select plus placement label the row it adds
     * needs — the same shared charge-row template order's own uses (#full-parity, 2026-09-13),
     * where placement is a hidden field + plain-text label rather than an editable select; see
     * applySellDocChargeExtraInputs()'s own note on why an editable one never belonged there.
     */
    public function theEstimateFormOffersAnEmptyFeeLineAndARowTemplateThatCanCarryOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, $this->makeProduct($I));

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        // categoryFilter shares Order's wording too — see sales_add_line_bar.html.twig.
        $I->see('Custom Fee (Type Anything)', 'select.js-estimate-charge-type option');
        $I->seeElement('#estimate-charge-row-template select.js-estimate-charge-tax-class');
        $I->seeElement('#estimate-charge-row-template span.order-charge-placement-label');
    }

    /**
     * CLIENT ROUND 5 FEEDBACK, PART 20 — a charge row naming a resolved shipping method never
     * keeps the browser's amount: it's recomputed from the estimate's own lines/address on save,
     * exactly like OrderController::recalculateShippingCharge() does for an order.
     */
    public function aNamedShippingMethodChargeRowHasItsAmountRecomputedByTheResolver(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'charge_lines_present' => '1',
            'charge_lines' => [['label' => 'Shipping (Free Shipping)', 'amount' => '999.00', 'type' => 'shipping']],
            'action' => 'save',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        // ShippingFreeBundle resolves "Free Shipping" at $0.00, so the posted 999.00 is discarded.
        $I->assertEqualsWithDelta(0.0, $saved->getShippingTotal(), 0.001);
        // Only the bare method name is stored, the way OrderController strips "Shipping (...)".
        $I->assertSame('Free Shipping', $saved->getShippingMethod());
        $I->assertEqualsWithDelta(0.0, $saved->getShippingLines()[0]->amount, 0.001);
    }

    /**
     * A stored shipping line comes back to the form as an editable charge row. The form rebuilds
     * the estimate from what it posts, so a row it is never shown is a row the next save drops.
     */
    public function aStoredShippingLineComesBackAsAnEditableChargeRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $stored = $entityManager->find(Estimate::class, $estimate->getId());
        $stored->setFeeLines(json_encode([[
            'slug' => 'shipping-legacy-courier', 'label' => 'Shipping (Legacy Courier)', 'taxClass' => 'G',
            'amount' => 12.5, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'manual',
        ]]))->setShippingMethod('Legacy Courier');
        $entityManager->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->see('Shipping (Legacy Courier)', '.order-total-box noscript.no-js-charge-rows .order-charge-line-row');
        $I->seeElement('.order-total-box noscript.no-js-charge-rows input[name="charge_lines[0][amount]"][value="12.50"]');
        $I->seeElement('.order-total-box noscript.no-js-charge-rows input[name="charge_lines[0][label]"][value="Shipping (Legacy Courier)"]');
    }

    /**
     * CLIENT ROUND 5 FEEDBACK, PART 20 — removing the last shipping charge row is how an estimate
     * goes back to TBD shipping, which is exactly why the form marks its posts with
     * charge_lines_present: without that marker the post looks like one that simply never
     * carried charges (an API/no-JS post of just the lines), which must leave them alone.
     */
    public function removingEveryShippingChargeRowPutsTheEstimateBackToTbdShipping(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'charge_lines_present' => '1',
            'charge_lines' => [['label' => 'Custom Shipping', 'amount' => '18.00', 'type' => 'shipping']],
            'action' => 'save',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertEqualsWithDelta(18.0, $saved->getShippingTotal(), 0.001);

        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'charge_lines_present' => '1',
            'action' => 'save',
        ]);

        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertSame([], $saved->getShippingLines());
        $I->assertNull($saved->getShippingTotal(), 'an estimate with no shipping row is TBD, not $0');
        $I->assertNull($saved->getShippingMethod());
        // No shipping means no Grand Total again, the estimate-only TBD state — the line's own
        // subtotal is still known and (since PART 27) still shown.
        $I->assertNull($saved->getTotal());
        $I->assertEqualsWithDelta(200.0, (float) $saved->getSubtotal(), 0.001);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->see('TBD', '.order-total-box');
        $I->dontSeeElement('.order-total-box noscript.no-js-charge-rows .order-charge-line-row');
    }

    /**
     * The charge rows post as `charge_lines[]`, named for the line each row becomes, and the
     * retired `extra_charges[]` is not a second accepted spelling (issue #165 step 8).
     *
     * Worth a test rather than trusting a rename: a form array the controller no longer reads fails
     * silently — the save succeeds, and the admin's shipping row is simply not there afterwards.
     * The old name has to be a post that changes nothing, and the marker field has to be renamed
     * with it or an old-name post would look like one that never rendered the control at all and
     * keep the stored rows by accident.
     */
    public function theOldExtraChargesPostNameIsNotASecondWayToSetShipping(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'extra_charges_present' => '1',
            'extra_charges' => [['label' => 'Sneaky Shipping', 'amount' => '99.00', 'type' => 'shipping']],
            'action' => 'save',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertSame([], $saved->getShippingLines(), 'the retired post name buys nothing');
        $I->assertNull($saved->getShippingTotal());

        // The current name does what the old one used to.
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'charge_lines_present' => '1',
            'charge_lines' => [['label' => 'Sneaky Shipping', 'amount' => '99.00', 'type' => 'shipping']],
            'action' => 'save',
        ]);

        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertCount(1, $saved->getShippingLines());
        $I->assertSame(99.0, $saved->getShippingTotal());

        // And the form it renders posts under that name, not the old one.
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeElement('input[name="charge_lines[0][amount]"]');
        $I->seeElement('input[type="hidden"][name="charge_lines_present"][value="1"]');
        $I->dontSeeElement('input[name="extra_charges[0][amount]"]');
        $I->dontSeeElement('input[name="extra_charges_present"]');
    }

    /**
     * CLIENT ROUND 6 FEEDBACK, PART 21 — PART 20's control only landed on the edit branch, so the
     * create page still ended at a static "calculated when the estimate is saved" line with no way
     * to add a charge at all. Order renders its line-items section once for both modes; this
     * template still branches, so both branches now render the one shared macro.
     */
    public function createPageRendersTheSameBottomAddLineChargeControlEditDoes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeProduct($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('.order-lines-section .order-lines-toolbar .order-add-line-bar select.js-estimate-charge-type');
        $I->seeElement('.order-lines-section .order-lines-toolbar .order-add-line-bar button.js-estimate-bottom-add');
        $I->see('Add Line', '.order-add-line-bar button.js-estimate-bottom-add');
        // The row template the handler clones, and the marker that tells create() this post came
        // from the charge UI, both have to ship here too or the control is inert.
        $I->seeElement('template#estimate-charge-row-template');
        $I->seeElement('input[type="hidden"][name="charge_lines_present"][value="1"]');

        // Edit's exact option set, resolved for the company alone (there's no estimate yet).
        // Quote now shares Order's category-filtered charge picker, so "Custom Shipping" is
        // gone the same way it is on Order — the category dropdown plus "Type anything" cover it.
        $I->seeElement('select.js-estimate-charge-type option[value^="shipping-method:Free Shipping|"]');
        $I->dontSeeElement('select.js-estimate-charge-type option[value="shipping:Custom Shipping"]');
        $I->seeElement('select.js-estimate-charge-type option[value="tax:Custom GST"]');
        $I->seeElement('select.js-estimate-charge-type option[value="tax:Custom PST"]');
        $I->seeElement('select.js-estimate-charge-type option[value="tax:Custom HST"]');
        $I->seeElement('select.js-estimate-charge-type option[value="empty-shipping:"]');
        $I->seeElement('select.js-estimate-charge-type option[value="empty-tax:"]');

        // The live totals box now carries the same <noscript> containers Order's does — JS
        // hydrates the real rows from data-estimate-charges, so with JS off (as Codeception sees
        // it) the server-rendered rows are what's actually in the markup.
        $I->seeElement('.order-lines-footer .order-total-box noscript.no-js-charge-rows');

        // ...and the whole bar sits ABOVE the line table, where order puts it.
        $source = $I->grabPageSource();
        $I->assertLessThan(
            strpos($source, '<table'),
            strpos($source, 'js-estimate-charge-type'),
            'The Add Line bar must render before the line-items table, like order\'s does.',
        );
    }

    /**
     * CLIENT ROUND 6 FEEDBACK, PART 21 — the control is only real if the initial POST that creates
     * the estimate keeps the rows, the way OrderController::createOrderFromRequest() takes
     * charge_lines[] on order's first save. The type=shipping row becomes the new estimate's
     * shipping line, and with every line priced that's enough for create() to compute its totals.
     */
    public function chargeRowsAddedBeforeTheFirstSavePersistOnTheNewEstimate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeActiveRegion($I, $company, 'Estimate Form Charge Region');
        $product = $this->makeProduct($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Estimate Form Charge Region',
            'save_mode' => 'draft',
            'lines' => [
                0 => ['id' => '', 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'charge_lines_present' => '1',
            'charge_lines' => [
                ['label' => 'Custom Shipping', 'amount' => '30.00', 'type' => 'shipping'],
                ['label' => 'Custom GST', 'amount' => '4.00', 'type' => 'tax'],
            ],
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->getRepository(Estimate::class)->findOneBy(['company' => $company->getId()], ['id' => 'DESC']);
        $I->assertNotNull($saved);
        $I->assertEqualsWithDelta(30.0, $saved->getShippingTotal(), 0.001);
        $I->assertSame('Custom Shipping', $saved->getShippingMethod());
        $I->assertCount(1, $saved->getShippingLines());
        $I->assertSame('Custom Shipping', $saved->getShippingLines()[0]->label);
        $manual = array_values(array_filter(
            json_decode((string) $saved->getTaxLines(), true)['lines'],
            static fn (array $l) => $l['source'] === 'manual',
        ));
        $I->assertSame('Custom GST', $manual[0]['label']);
        // Priced lines plus a known shipping means create() can compute the totals right away,
        // instead of leaving the admin to hit "Save & Recalculate" on the edit page it lands on.
        $I->assertGreaterThanOrEqual(4.0, (float) $saved->getTax());
        $I->assertEqualsWithDelta(230.0 + (float) $saved->getTax(), (float) $saved->getTotal(), 0.011);
    }

    /**
     * Mirrors order's .js-order-search-product link (AdminSalesOrderFormCest::editPageProductRowLinksToTheProductEditPageInANewTab) —
     * the estimate line table had no equivalent product-edit shortcut at all before this.
     */
    public function editPageProductRowLinksToTheProductEditPageInANewTab(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('a.js-estimate-search-product[target="_blank"]', [
            'href' => '/admin/product/inventory/update/' . $product->getId(),
        ]);
    }

    public function createPageProductRowLinkStartsHiddenUntilAProductIsPicked(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeProduct($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('a.js-estimate-search-product[href]');
        $I->seeElement('a.js-estimate-search-product[target="_blank"]', [
            'data-product-edit-url-template' => '/admin/product/inventory/update/__PRODUCT_ID__',
        ]);
    }

    /**
     * The totals box only existed in edit mode, so an admin building a new estimate saw no
     * subtotal at all until the first save. Create mode now renders the exact same box Order's
     * does (sales_totals_footer.html.twig, #full-parity 2026-09-13) — a single Subtotal figure
     * with no separate Shipping row. A brand-new quote starts with one unpriced blank line, so
     * that figure is honestly TBD from the first paint, same as Total Tax and Grand Total.
     */
    public function createPageRendersALiveTotalsBoxStartingAtZero(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeProduct($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('.order-total-box strong.js-estimate-total-before-tax');
        $I->see('TBD', '.order-total-box strong.js-estimate-total-before-tax');
        $I->see('Subtotal:', '.order-total-box');

        // Tax/fees are only known after EstimateController's server-side recompute on save.
        $I->see('TBD', '.order-total-box strong.js-estimate-total-tax');
        $I->see('TBD', '.order-total-box strong.js-estimate-total-grand');

        // The "still TBD" counter starts collapsed; app.js reveals it once a line has no price.
        $I->seeElement('.order-total-box div.js-estimate-total-pending[hidden] strong.js-estimate-total-pending-count');
    }

    /**
     * The page used to be one flat "Line Items" .table-card with a .table-footer. It now uses
     * order's form chrome — an .order-workspace-card holding an .order-workspace-grid of panels
     * starting with "Estimate Info", then the .order-lines-section table below (the Billing/Shipping
     * panels fill the rest of that grid with the company/address work).
     */
    public function editPageUsesOrdersMultiCardWorkspaceLayoutInsteadOfOneFlatTableCard(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $estimate->setPoNumber('PO-LAYOUT-1');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('.order-create-shell form#estimate-form.order-workspace-card');
        // Edit mode's one action row is the page's panel-actions, posting the form from outside it
        // exactly like order/edit's "Save Order" button does via form="order-form".
        $I->seeElement('.panel-actions button[type="submit"][form="estimate-form"][value="send_pricing"]');

        $I->seeElement('.order-workspace-grid section.order-form-panel');
        // CLIENT ROUND 4 FEEDBACK, PART 17 — the card heading reads "Quote Info", matching the
        // page title's "Edit Quote" wording rather than the Estimate-named routes/classes.
        $I->see('Quote Info', '.order-workspace-grid section.order-form-panel h2');
        $I->dontSee('Estimate Info');
        $I->see($company->getName(), '.order-form-panel .order-field-row.static strong');
        $I->see($estimate->getDocumentNumber(), '.order-form-panel .order-field-row.static strong');
        $I->seeElement('.order-form-panel .order-field-row input[name="po_number"]', ['value' => 'PO-LAYOUT-1']);
        $I->seeElement('.order-form-panel .order-field-row input[name="special_instructions"]');

        // The line table moved into order's lines section, and the old flat footer is gone.
        $I->see('Line Items', 'form#estimate-form section.order-lines-section h2');
        $I->seeElement('section.order-lines-section table.estimate-line-table');
        $I->seeElement('section.order-lines-section .order-bottom-actions');
        $I->dontSeeElement('form#estimate-form .table-footer');
    }

    public function createPageUsesTheSameWorkspaceLayoutWithAnEstimateInfoCard(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeProduct($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('.order-create-shell form#estimate-form.order-workspace-card');
        $I->seeElement('form#estimate-form .order-workspace-actions button[value="draft"]');
        // PART 17 — same "Quote Info" heading on the create branch of the template.
        $I->see('Quote Info', '.order-workspace-grid section.order-form-panel h2');
        $I->dontSee('Estimate Info');
        // The company/PO#/instructions moved out of a bare .form-grid into that card, and the
        // company reads the way order's Order Info card renders it: a hidden field plus a static
        // name row, not a second re-pointable <select>.
        $I->seeElement('form#estimate-form input[type="hidden"][name="company_id"]', ['value' => (string) $company->getId()]);
        $I->see($company->getName(), '.order-form-panel .order-field-row.static strong');
        $I->seeElement('.order-form-panel .order-field-row input[name="po_number"]');
        $I->seeElement('section.order-lines-section table.estimate-line-table');
        $I->dontSeeElement('form#estimate-form .table-footer');
    }

    /**
     * The Estimate Info card's editable fields have to actually round-trip — edit()'s POST handler
     * only ever read the shipping/line fields before the card existed.
     */
    public function editSavePersistsTheEstimateInfoCardsPoNumberAndInstructions(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2'],
            ],
            'po_number' => 'PO-FROM-CARD',
            'special_instructions' => 'Leave at the loading dock.',
            'action' => 'save',
        ]);
        $I->see('Estimate saved.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertSame('PO-FROM-CARD', $saved->getPoNumber());
        $I->assertSame('Leave at the loading dock.', $saved->getSpecialInstructions());
    }

    /**
     * po_number is a single-line reference, unlike special_instructions — TextInput::oneLineStringMax()
     * strips embedded CR/LF rather than keeping it, matching the fix already applied to
     * OrderController's po_number call sites.
     */
    public function editSaveStripsAnEmbeddedLineBreakFromPoNumber(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2'],
            ],
            'po_number' => "PO\r\n123",
            'action' => 'save',
        ]);
        $I->see('Estimate saved.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertSame('PO123', $saved->getPoNumber(), 'the embedded CRLF should be stripped, not kept in the middle of the value');
    }

    /** Every newly rendered column has to actually round-trip through edit()'s save. */
    public function savingTheNewColumnsPersistsThemOntoTheLine(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'location' => 'Estimate Form West', 'sku' => 'ESTFORM-SKU-OVERRIDE', 'qty' => '4', 'weight' => '3.250', 'unit' => 'CS', 'tax_code' => 'E', 'cost' => '21.5', 'price' => '12.25'],
            ],
            'action' => 'save',
        ]);
        $I->see('Estimate saved.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(EstimateLine::class, $line->getId());
        $I->assertSame('Estimate Form West', $saved->getLocation());
        $I->assertSame('ESTFORM-SKU-OVERRIDE', $saved->getSku());
        $I->assertSame('3.250', $saved->getWeight());
        $I->assertSame('CS', $saved->getUnit());
        $I->assertSame('E', $saved->getTaxCode());
        $I->assertSame(21.5, (float) $saved->getCost());
        $I->assertSame(49.0, (float) $saved->getSubtotal());
    }

    /**
     * CLIENT ROUND 2 FEEDBACK, PART 6 — the estimate edit page used to show no company/billing/
     * shipping information at all, unlike order's edit page. It now renders the same Billing
     * Detail/Shipping Detail cards order's form does, pre-filled from the estimate's own effective
     * addresses (falling back to the company's address-book defaults, same as
     * AbstractSalesDocument::getEffectiveBillingAddress()/getEffectiveShippingAddress()).
     */
    public function editPageRendersBillingAndShippingDetailCardsPrefilledFromTheEstimatesAddresses(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $billingAddress = (new CompanyAddress())
            ->setCompany($company)
            ->setCompanyName('Estimate Form Test Co')
            ->setFirstName('Billie')
            ->setLastName('Ing')
            ->setAddressLine1('100 Billing St')
            ->setCity('Toronto')
            ->setCountry('CA')
            ->setProvince('ON')
            ->setPostalCode('M1M1M1')
            ->setIsDefaultBilling(true);
        $I->haveInRepository($billingAddress);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('.order-workspace-grid section.order-form-panel[data-address-panel="billing"]');
        $I->seeElement('.order-workspace-grid section.order-form-panel[data-address-panel="shipping"]');
        $I->see('Billing Detail', '.order-form-panel h2');
        $I->see('Shipping Detail', '.order-form-panel h2');
        $I->seeElement('input[name="billing_address_1"]', ['value' => '100 Billing St']);
        $I->seeElement('input[name="billing_city"]', ['value' => 'Toronto']);
        $I->seeElement('input[name="billing_first_name"]', ['value' => 'Billie']);
    }

    /**
     * Create mode used to have no company-select-first step and no address cards at all. Now
     * picking a company (via ?company_id=X, the same redirect-then-reload OrderController::create()
     * uses) loads the Billing/Shipping Detail cards for that company.
     */
    public function createPageWithACompanyChosenRendersBillingAndShippingDetailCards(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeProduct($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('.order-workspace-grid section.order-form-panel[data-address-panel="billing"]');
        $I->seeElement('.order-workspace-grid section.order-form-panel[data-address-panel="shipping"]');
        $I->seeElement('form#estimate-form input[type="hidden"][name="company_id"]', ['value' => (string) $company->getId()]);
        $I->see($company->getName(), '.order-form-panel .order-field-row.static strong');
    }

    /**
     * CLIENT ROUND 4 FEEDBACK, PART 16(a) — "Change Company" used to be a submit button sitting in
     * its own .order-field-row inside the Estimate Info card, below the company dropdown. Order puts
     * that affordance in the page's top action-bar instead (a link back to the bare picker, next to
     * "List Orders"), and keeps no company <select> in the info card at all.
     */
    public function changeCompanyLivesInThePageActionBarNotInsideTheEstimateInfoCard(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();

        // Up in the action-bar, pointing back at the chooser — order's "Change company" link.
        $I->see('Change Customer', '.panel-title-row .panel-actions a.back-button');
        $I->seeElement('.panel-actions a[href="/admin/estimate/create"]');
        $I->see('List Estimates', '.panel-title-row .panel-actions a');

        // ...and gone from the info card, along with the dropdown it used to re-point.
        $I->dontSeeElement('.order-form-panel select[name="company_id"]');
        $I->dontSeeElement('.order-form-panel .order-field-row button');

        // Edit mode never had one and, like order's edit page, still must not grow one.
        $estimate = $this->makeEstimate($I, $company, $product);
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('Change Customer');
    }

    /**
     * CLIENT ROUND 4 FEEDBACK, PART 16(c) — the page rendered TWO near-identical Back+Save rows
     * stacked on each other: the page-level .panel-actions block and the form's own
     * .order-workspace-actions. Order has exactly one per mode — create's inside the form, edit's up
     * in .panel-actions (its `{% if mode != 'Edit' %}` guard drops the inner row).
     */
    public function eachModeRendersExactlyOneTopActionButtonRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        // Create: the saves live in the form's row only — nothing submits from .panel-actions.
        $I->seeNumberOfElements('form#estimate-form .order-workspace-actions', 1);
        $I->dontSeeElement('.panel-actions button[type="submit"]');
        $I->dontSeeElement('.panel-actions button[form="estimate-form"]');

        $estimate = $this->makeEstimate($I, $company, $product);
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        // Edit: the inverse — .panel-actions is the one row, and the inner one is gone.
        $I->dontSeeElement('form#estimate-form .order-workspace-actions');
        $I->seeElement('.panel-actions a.back-button');
        $I->seeElement('.panel-actions button[type="submit"][form="estimate-form"][value="save"]');
    }

    /**
     * CLIENT ROUND 4 FEEDBACK, PART 16(b) — the Estimate Info card was missing most of what order's
     * Order Info card shows. The meaningful ones for a quote are Company Name, Primary Email,
     * Company Phone and the document date; Payment Status/Method/Term are deliberately absent —
     * nothing has been paid on a quote and Estimate has no column to persist them on.
     */
    public function estimateInfoCardRendersOrdersMeaningfulFieldsAndNoPaymentFields(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I)->setPrimaryEmail('buyer@estimate-info.test')->setPhoneNumber('604-555-0142');
        $I->grabService(EntityManagerInterface::class)->flush();
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        foreach (['/admin/estimate/create?company_id=' . $company->getId(), '/admin/estimate/edit/' . $estimate->getId()] as $page) {
            $I->amOnPage($page);
            $I->seeResponseCodeIsSuccessful();

            $I->see($company->getName(), '.order-form-panel .order-field-row.static strong');
            $I->seeElement('.order-form-panel .order-field-row input[name="primary_email"]', ['value' => 'buyer@estimate-info.test']);
            $I->seeElement('.order-form-panel .order-field-row input[name="company_phone"]', ['value' => '604-555-0142']);
            $I->seeElement('.order-form-panel .order-field-row input[type="date"][name="quote_date"]');

            $I->dontSeeElement('select[name="payment_status"]');
            $I->dontSeeElement('select[name="payment_method"]');
            $I->dontSeeElement('input[name="payment_term"]');
        }
    }

    /**
     * The new Estimate Info fields have to round-trip. Primary Email/Company Phone land on the
     * estimate's OWN frozen company identity, NOT on the live Company row order's form rewrites —
     * quoting one customer must not silently edit their record.
     */
    public function editSavePersistsTheNewEstimateInfoFieldsOntoTheDocumentSnapshot(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I)->setPrimaryEmail('before@estimate-info.test')->setPhoneNumber('604-555-0100');
        $I->grabService(EntityManagerInterface::class)->flush();
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2'],
            ],
            'primary_email' => 'after@estimate-info.test',
            'company_phone' => '250-555-0199',
            'quote_date' => '2026-03-04',
            'action' => 'save',
        ]);
        $I->see('Estimate saved.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertSame('after@estimate-info.test', $saved->getCompanyIdentity()->getEmail());
        $I->assertSame('250-555-0199', $saved->getCompanyIdentity()->getPhone());
        $I->assertSame('2026-03-04', $saved->getDocumentDate());
        // The live company is untouched — that's the whole point of the snapshot.
        $I->assertSame('before@estimate-info.test', $entityManager->find(Company::class, $company->getId())->getPrimaryEmail());
    }

    /**
     * CLIENT ROUND 4 FEEDBACK, PART 15 — the "choose a company first" step only ever gated the
     * Billing/Shipping cards: the Estimate Info fields, the whole Line Items table, Add
     * Product/Add Blank Line, the totals box and both save-button rows all rendered next to the
     * empty company dropdown. Nothing but the chooser may render now, exactly like order's
     * `{% if not company %}` create page.
     */
    public function createPageWithNoCompanyChosenRendersNothingButTheCompanyChooser(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeProduct($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create');
        $I->seeResponseCodeIsSuccessful();

        // Order's bare picker: a "Choose Company" select plus "Next", and a Back button.
        $I->seeElement('.order-company-card form.order-company-picker select[name="company_id"]');
        $I->see($company->getName(), '.order-company-picker select[name="company_id"] option');
        $I->see('Next', '.order-company-picker button[type="submit"]');
        $I->seeElement('.panel-actions a.back-button');

        // ...and nothing else: no workspace, no info card, no line table, no totals, no saves.
        $I->dontSeeElement('form#estimate-form');
        $I->dontSeeElement('section.order-form-panel');
        $I->dontSeeElement('section.order-form-panel[data-address-panel]');
        $I->dontSee('Quote Info');
        $I->dontSee('Line Items');
        $I->dontSeeElement('table.estimate-line-table');
        $I->dontSeeElement('button.js-estimate-add-product');
        $I->dontSeeElement('button.js-estimate-add-blank');
        $I->dontSeeElement('input[name="po_number"]');
        $I->dontSeeElement('input[name="special_instructions"]');
        $I->dontSeeElement('.order-total-box');
        $I->dontSeeElement('.order-bottom-actions');
        $I->dontSeeElement('.panel-actions button[type="submit"][form="estimate-form"]');
        $I->dontSeeElement('template#estimate-product-line-template');
    }

    /**
     * The admin has to be able to edit/confirm the billing address per-estimate, not just silently
     * inherit the company default (EstimateController::applyEstimateAddressCard()).
     */
    public function createSavePersistsTheEditedBillingAddressOntoTheEstimate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $this->makeActiveRegion($I, $company, 'Estimate Form Billing Region');
        $product = $this->makeProduct($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->sendAjaxPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Estimate Form Billing Region',
            'billing_address_id' => '',
            'billing_company_name' => 'Custom Billing Co',
            'billing_first_name' => 'Bea',
            'billing_last_name' => 'Ling',
            'billing_address_1' => '55 Confirm Ave',
            'billing_city' => 'Ottawa',
            'billing_country' => 'CA',
            'billing_province' => 'ON',
            'billing_postal_code' => 'K1K1K1',
            'shipping_address_id' => '',
            'lines' => [
                0 => ['product_id' => (string) $product->getId(), 'qty' => '1', 'price' => '10.00'],
            ],
            'save_mode' => 'draft',
        ]);

        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()]);
        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());

        $billingAddress = $estimate->getBillingAddress();
        $I->assertNotNull($billingAddress);
        $I->assertSame('Custom Billing Co', $billingAddress->getCompanyName());
        $I->assertSame('55 Confirm Ave', $billingAddress->getAddressLine1());
        $I->assertSame('Ottawa', $billingAddress->getCity());
        $I->assertSame('Bea Ling', $estimate->getBillingName());
    }

    /**
     * Order's line table lists computed Main Line Items fees as real dash-filled rows next to the
     * products, not only as lines in the totals box — the estimate table must do the same. Fees
     * placed after tax stay totals-box-only on both.
     */
    public function computedMainLineFeesRenderAsRowsInTheLineTableNotOnlyInTheTotalsBox(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        // The snapshot EstimateController::recomputeFeesAndTax() writes once an estimate is fully
        // priced with a known shipping amount.
        $estimate->setFeeLines(json_encode([
            ['slug' => 'bc-environmental-fee', 'label' => 'BC Environmental Fee', 'taxClass' => 'none', 'amount' => 6.5, 'placement' => 'main_line'],
            ['slug' => 'bc-recycling-fee', 'label' => 'BC Recycling Fee', 'taxClass' => 'none', 'amount' => 3.25, 'placement' => 'main_line'],
            ['slug' => 'admin-after-tax-fee', 'label' => 'Admin After Tax Fee', 'taxClass' => 'none', 'amount' => 1.75, 'placement' => 'after_tax_line'],
        ]));
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        // Each fee's name PAIRED with its amount, read out of the row's own cells (#627). Asserting
        // them as four separate see(..., 'tr.fee-line-row') calls proved only that the two names and
        // the two figures were somewhere among the fee rows — not that this fee cost this much, and
        // not that the Subtotal cell carried anything at all. See feeLineRows().
        $I->assertSame(
            ['BC Environmental Fee' => '$6.50', 'BC Recycling Fee' => '$3.25'],
            $this->feeLineRows($I),
            'estimate_line rows of type fee, name => subtotal',
        );
        // An after-tax fee is a totals-box line only — it must not become a table row.
        $I->dontSee('Admin After Tax Fee', '#estimate-line-rows');
        // A fee row is computed, never editable/removable, so it carries no line_* inputs.
        $I->dontSeeElement('#estimate-line-rows tr.fee-line-row input');
    }

    /**
     * CLIENT ROUND 5 FEEDBACK, PART 19 — the same fee rendering, driven end-to-end through the
     * gate it actually hangs off: pricing every line and filling in shipping makes
     * EstimateController::recomputeFeesAndTax() run, and the re-rendered edit page must then show
     * resolver-computed fee rows in the line table AND a fully populated totals box instead of the
     * "Complete pricing and shipping to see totals." placeholder, exactly like order's form.
     */
    public function pricingEveryLineAndFillingInShippingRendersFeeRowsAndAFullTotalsBox(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $I->haveInRepository(
            (new CompanyAddress())
                ->setCompany($company)
                ->setCompanyName('Estimate Form Test Co')
                ->setAddressLine1('9 Fee Row Rd')
                ->setCity('Toronto')
                ->setCountry('CA')
                ->setProvince('ON')
                ->setPostalCode('M1M1M1')
                ->setIsDefaultShipping(true),
        );
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        // Real fees, resolved by FeeUserDefinedBundle's province-agnostic calculator — this exercises
        // recomputeFeesAndTax() itself rather than hand-writing the feeLines snapshot.
        $I->haveInRepository(
            (new Fee())
                ->setSlug('estform-eco-fee')
                ->setName('Estimate Form Eco Fee')
                ->setSource('FeeUserDefinedBundle')
                ->setTaxClass('E')
                ->setDefaultValue(2.5)
                ->setPlacement('main_line'),
        );
        $I->haveInRepository(
            (new Fee())
                ->setSlug('estform-admin-fee')
                ->setName('Estimate Form Admin Fee')
                ->setSource('FeeUserDefinedBundle')
                ->setTaxClass('E')
                ->setDefaultValue(1.25)
                ->setPlacement('after_tax_line'),
        );

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        // Unpriced/unshipped: no fees computed yet, and since PART 27 the totals box still renders
        // with its unresolvable figures reading TBD instead of being hidden wholesale.
        $I->seeElement('.order-total-box');
        $I->see('Grand Total:', '.order-total-box');
        $I->dontSeeElement('#estimate-line-rows tr.fee-line-row');

        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            // Since PART 20 shipping is a charge row, not a field pair; "Custom Shipping" is the
            // manual-amount option, kept exactly as submitted.
            'charge_lines_present' => '1',
            'charge_lines' => [['label' => 'Custom Shipping', 'amount' => '25.00', 'type' => 'shipping']],
            'action' => 'save',
        ]);
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        // Compared as floats: a trailing-zero decimal round-trips out of sqlite as "200" but out of
        // MySQL as "200.00", same DB-specific formatting makeProduct()'s cost comment calls out.
        $I->assertEqualsWithDelta(200.0, (float) $saved->getSubtotal(), 0.001);

        // Fee rows in the line table: main_line only, after_tax stays a totals-box line.
        $I->assertSame(
            ['Estimate Form Eco Fee for Estimate Form Test Product (ESTFORM-SKU-1)' => '$5.00'],
            $this->feeLineRows($I),
            'the one main_line fee, name => subtotal — the pair, not two loose see() calls (#627)',
        );
        $I->dontSee('Estimate Form Admin Fee', '#estimate-line-rows');
        $I->dontSeeElement('#estimate-line-rows tr.fee-line-row input');

        // Totals box: every row order's box has is filled in, TBD nowhere in sight.
        $I->seeElement('.order-total-box');
        $I->dontSee('TBD', '.order-total-box');
        $I->see('Subtotal:', '.order-total-box');
        // Same box Order's own template renders (#full-parity, 2026-09-13): Subtotal is the whole
        // pre-tax figure — lines (200) + the main_line fee (5.00) + shipping (25.00) + the
        // after_tax fee (2.50), since there is no separate Shipping row to fold it out of — with
        // every one of those amounts still broken out in its own row below for transparency, the
        // same way order never double-counts a fee between the line table and the totals box.
        $I->see('$232.50', '.order-total-box');
        // The shipping charge is its own editable row now (Order's <noscript>+hydrate pattern),
        // so its amount is an input value, not plain text.
        $I->seeElement('.order-total-box input[name="charge_lines[0][amount]"][value="25.00"]');
        $I->dontSee('Estimate Form Eco Fee', '.order-total-box');
        $I->see('Total Tax:', '.order-total-box');
        $I->see('Estimate Form Admin Fee', '.order-total-box');
        $I->see('$2.50', '.order-total-box');
        $I->see('Grand Total:', '.order-total-box');
        // 200 subtotal + 25 shipping + 7.50 of fees + whatever tax the province's rates produce.
        $expectedTotal = 232.50 + (float) $saved->getTax();
        $I->assertEqualsWithDelta($expectedTotal, (float) $saved->getTotal(), 0.001);
        $I->see('$' . number_format($expectedTotal, 2), '.order-total-box');
    }

    /**
     * CLIENT ROUND 8 FEEDBACK, PART 27 — pricing one of two lines has to surface that line's own
     * subtotal, its fees and the tax on it right away, the way order does, instead of the form
     * falling back to a bare "Complete pricing and shipping to see totals." line.
     *
     * What that never licensed was the DOCUMENT claiming those partial figures as its own. Only
     * `total` was held back, so `subtotal` and `tax` went out as definite numbers covering the
     * priced lines alone — understated, and printed as a flat $0.00 by the admin quote PDF's
     * `|default(0)` whenever they happened to be null (#254). The per-line and per-fee snapshots
     * stay real here; the three header columns go back to TBD together.
     */
    public function pricingOnlyOneOfTwoLinesShowsThatLinesTotalsWhileTheHeaderFiguresStayTbd(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $I->haveInRepository(
            (new CompanyAddress())
                ->setCompany($company)
                ->setCompanyName('Estimate Form Test Co')
                ->setAddressLine1('11 Partial Price Rd')
                ->setCity('Toronto')
                ->setCountry('CA')
                ->setProvince('ON')
                ->setPostalCode('M1M1M1')
                ->setIsDefaultShipping(true),
        );
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $secondLine = (new EstimateLine())
            ->setName($product->getName())
            ->setSku($product->getSku())
            ->setQuantity('2.00')
            ->setCost('30.00')
            ->setProduct($product);
        $estimate->addLine($secondLine);
        $I->grabService(EntityManagerInterface::class)->flush();
        $firstLine = $estimate->getLines()->first();

        // Same per-unit fees PART 19's fully-priced case uses, so the amounts below double as proof
        // that only the priced line fed the resolver.
        $I->haveInRepository(
            (new Fee())
                ->setSlug('estform-partial-eco-fee')
                ->setName('Estimate Form Partial Eco Fee')
                ->setSource('FeeUserDefinedBundle')
                ->setTaxClass('E')
                ->setDefaultValue(2.5)
                ->setPlacement('main_line'),
        );
        $I->haveInRepository(
            (new Fee())
                ->setSlug('estform-partial-admin-fee')
                ->setName('Estimate Form Partial Admin Fee')
                ->setSource('FeeUserDefinedBundle')
                ->setTaxClass('E')
                ->setDefaultValue(1.25)
                ->setPlacement('after_tax_line'),
        );

        $I->haveHttpHeader('Host', 'admin.localhost');
        // Line 1 priced, line 2 left TBD, and no shipping charge row at all.
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $firstLine->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
                1 => ['id' => (string) $secondLine->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => ''],
            ],
            'charge_lines_present' => '1',
            'action' => 'save',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        // The priced LINE's numbers survive the save instead of being wiped...
        $I->assertEqualsWithDelta(200.0, (float) $saved->getLines()->first()->getSubtotal(), 0.001);
        // ...and so does the tax snapshot, which is per-row and is what lets the priced line show
        // real tax beside the unpriced one's TBD (#255).
        $I->assertNotNull($saved->getTaxLines());
        // But none of the three header figures can be stated while a line is unpriced: a subtotal
        // of 200.00 would be one line's total passed off as the whole quote's.
        $I->assertNull($saved->getSubtotal());
        $I->assertNull($saved->getTax());
        $I->assertNull($saved->getShippingTotal());
        $I->assertNull($saved->getTotal());

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        // Only the priced line's product fed the fee resolver: 2 units × $2.50, not 4 units × $2.50.
        $I->assertSame(
            ['Estimate Form Partial Eco Fee for Estimate Form Test Product (ESTFORM-SKU-1)' => '$5.00'],
            $this->feeLineRows($I),
            '2 priced units x $2.50, not the 4 units on the document (#627)',
        );

        // Totals box still breaks out every charge that IS settled — the after_tax fee and the
        // per-row tax rows come off the snapshots, not off the header columns.
        $I->seeElement('.order-total-box');
        $I->see('Estimate Form Partial Admin Fee', '.order-total-box');
        $I->see('$2.50', '.order-total-box');
        $I->see('Total Tax:', '.order-total-box');

        // Unresolved figures read TBD rather than a made-up number — the document Subtotal among
        // them now, so "200 product + 5.00 fee" is not offered as this quote's subtotal (#254).
        // Subtotal IS the shared box's single pre-tax figure (no separate Shipping row), so it is
        // the one that has to read TBD here, exactly like Grand Total.
        $I->dontSee('$205.00', '.order-total-box');
        $I->see('TBD', '.js-estimate-total-before-tax');
        $I->see('TBD', '.js-estimate-total-grand');
        $I->dontSee('$', '.js-estimate-total-grand');
    }

    /**
     * CLIENT ROUND 5 FEEDBACK, PART 18 — the single "Save" button (which stayed on the edit page)
     * is now the pair order's edit page has: "Save & Recalculate" keeps the old stay-here behavior
     * so refreshed fee/tax totals show, and "Save & Exit" leaves for the estimate's detail page.
     */
    public function editRendersBothSaveButtonsInEveryActionRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        // Top action bar and the bottom action row both carry the same pair.
        $I->seeElement('.panel-actions button[type="submit"][name="action"][value="save"]');
        $I->seeElement('.panel-actions button[type="submit"][name="action"][value="save_exit"]');
        $I->seeElement('.order-bottom-actions button[type="submit"][name="action"][value="save"]');
        $I->seeElement('.order-bottom-actions button[type="submit"][name="action"][value="save_exit"]');
        $I->see('Save & Recalculate', '.panel-actions');
        $I->see('Save & Exit', '.panel-actions');
        $I->see('Save & Recalculate', '.order-bottom-actions');
        $I->see('Save & Exit', '.order-bottom-actions');
        // The old bare "Save" label is gone; the other two saves are untouched.
        $I->see('Save & Send Pricing to Customer', '.panel-actions');
    }

    /**
     * A Draft quote's plain save really does keep it a Draft (the promotion added above only
     * fires from Submitted), unlike every later status where the same button leaves the quote
     * wherever it already was. The label has to say so — "Save & Recalculate" alone reads as
     * "this makes it live" to an admin who just saved a fresh Draft and landed back here.
     */
    public function editOfADraftQuoteLabelsThePlainSavesAsDraftSaves(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->find(Estimate::class, $estimate->getId())->setStatus('Draft', DocumentActor::system());
        $entityManager->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->see('Save as Draft & Recalculate', '.panel-actions');
        $I->see('Save as Draft & Exit', '.panel-actions');
        $I->see('Save as Draft & Recalculate', '.order-bottom-actions');
        $I->see('Save as Draft & Exit', '.order-bottom-actions');
        $I->dontSee('Save & Recalculate', '.panel-actions');
        $I->dontSee('Save & Exit', '.panel-actions');
    }

    public function saveExitPersistsTheEditThenLeavesForTheDetailPage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '4'],
            ],
            'po_number' => 'PO-SAVE-EXIT',
            'action' => 'save_exit',
        ]);

        $I->seeCurrentUrlEquals('/admin/estimate/detail/' . $estimate->getId());

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertSame('PO-SAVE-EXIT', $saved->getPoNumber());
        $I->assertSame('4', $saved->getLines()->first()->getQuantity());
    }

    public function saveRecalculatePersistsTheEditAndStaysOnTheEditPage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '3'],
            ],
            'po_number' => 'PO-SAVE-RECALC',
            'action' => 'save',
        ]);

        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertSame('PO-SAVE-RECALC', $saved->getPoNumber());
        $I->assertSame('3', $saved->getLines()->first()->getQuantity());
    }

    /**
     * CLIENT ROUND 7 FEEDBACK, PART 25 — the edit page had no tab nav at all, unlike detail's
     * View / Edit / Price / Quote PDF / Reject Quote bar and order/edit's equivalent
     * View / Edit / Invoice / Payments / Log nav. It now renders the same set detail shows, with
     * "Edit / Price" as the current tab.
     */
    public function editPageRendersTheSameTabNavDetailPageHas(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('nav.company-action-links.order-tab-nav[aria-label="Quote edit actions"]');
        $I->seeElement('.order-tab-nav a[href="/admin/estimate/detail/' . $estimate->getId() . '"]');
        $I->seeElement('.order-tab-nav a.is-current[href="/admin/estimate/edit/' . $estimate->getId() . '"]');
        $I->see('Edit / Price', '.order-tab-nav a.is-current');
        $I->seeElement('.order-tab-nav a[href="/admin/estimate/quote/' . $estimate->getId() . '"]');
        $I->see('Quote PDF', '.order-tab-nav a');
        $I->seeElement('.order-tab-nav button.js-estimate-reject[data-url="/admin/estimate/update-status/' . $estimate->getId() . '"]');
        $I->see('Reject Quote', '.order-tab-nav button.js-estimate-reject');

        // Create mode has no estimate yet, so the nav must not render there.
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('.order-tab-nav');
    }

    // ------------------------------------------------- the no-JS quote save path

    private function reloadEstimate(FunctionalTester $I, int $id): Estimate
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Estimate::class, $id);
    }

    /** @return list<string> the quote's narrative timeline comments */
    private function estimateNarrativeComments(FunctionalTester $I, int $estimateId): array
    {
        $logs = $I->grabService(EntityManagerInterface::class)->getRepository(AuditLog::class)->findBy(
            ['entityType' => 'Estimate', 'entityId' => $estimateId, 'actorType' => 'document'],
        );

        return array_map(static fn (AuditLog $log): string => $log->getSummary(), $logs);
    }

    /**
     * #252 — the four columns only the browser's product-select handler ever fills in were written
     * through blank, nulling the snapshot the controller had taken two lines earlier. `tax_code` is
     * the one that costs money: null maps to 'E' (TaxContext::mapTaxCode()), so the line quoted
     * tax-free and the document's highest tax class fell with it, untaxing shipping too. The order
     * flow has always backfilled these from the catalog (OrderController::productLineDefaults()).
     */
    public function aNoJsSaveBackfillsBlankSkuWeightUnitAndTaxCodeFromTheProduct(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        // Exactly what the form posts with JavaScript off: every column rendered, and the four the
        // change handler would have populated left empty.
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'name' => '', 'sku' => '', 'weight' => '', 'unit' => '', 'tax_code' => '', 'qty' => '2', 'price' => '50.00'],
            ],
            'action' => 'save',
        ]);

        $saved = $this->reloadEstimate($I, (int) $estimate->getId())->getLines()->first();
        $I->assertSame('ESTFORM-SKU-1', $saved->getSku());
        $I->assertSame('12.500', $saved->getWeight());
        $I->assertSame('EA', $saved->getUnit());
        $I->assertSame('G', $saved->getTaxCode(), 'a blank tax code nulled the line into being quoted exempt');
    }

    /**
     * #279 — a document line is a SNAPSHOT, not a live pointer at the catalog. The blank-row test
     * asked "does this product still exist?" instead of "did the admin leave this row empty?", so a
     * row naming a product that had gone was thrown away silently: four lines submitted, three
     * saved. Order's isBlankOrderLine() judges the raw posted strings, and so does this now.
     */
    public function aRowNamingAProductThatLeftTheCatalogSurvivesWithEveryStoredValueIntact(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        // Row 1 is the stored line, re-posted against an id that no longer resolves. Row 2 is a
        // brand new row naming the same vanished product — the case that used to disappear.
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => '99999999', 'name' => '', 'sku' => '', 'qty' => '2', 'price' => '50.00'],
                1 => ['id' => '', 'product_id' => '99999999', 'name' => '', 'sku' => 'GONE-SKU', 'qty' => '3', 'price' => '12.00'],
            ],
            'action' => 'save',
        ]);

        $lines = $this->reloadEstimate($I, (int) $estimate->getId())->getLines()->toArray();
        $I->assertCount(2, $lines, 'a row was dropped because its product no longer resolves');

        // The stored line kept its whole snapshot — nothing about it was blanked or re-read.
        $I->assertSame('Estimate Form Test Product', $lines[0]->getName());
        $I->assertSame('ESTFORM-SKU-1', $lines[0]->getSku());
        $I->assertSame(50.0, (float) $lines[0]->getPrice());

        // The new row survived with what was actually submitted, and with no product behind it —
        // named the way order's lineName() names a row that never carried one.
        $I->assertNull($lines[1]->getProduct());
        $I->assertSame('Custom line', $lines[1]->getName());
        $I->assertSame('GONE-SKU', $lines[1]->getSku());
        $I->assertSame(12.0, (float) $lines[1]->getPrice());
        $I->assertSame(3.0, (float) $lines[1]->getQuantity());
    }

    /**
     * #262 — the quote checked the RAW post at the door instead of the rows the pressed no-JS
     * button leaves behind, so a quote carrying an invalid charge row could never lose it: the ✕
     * re-posts the offending row, errorFor() sees it before the removal is applied, and the save is
     * refused forever. Order has always routed this through postedChargeRows() first.
     */
    public function theNoJsRemoveButtonCanDeleteAChargeRowThatWouldFailValidation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00'],
            ],
            'charge_lines_present' => '1',
            'charge_lines' => [
                ['label' => 'Freight', 'amount' => '15.00', 'type' => 'shipping'],
                // The row assertValid() rejects: an after-tax fee cannot be taxable.
                ['label' => 'COD Fee', 'amount' => '12.00', 'type' => 'fee', 'slug' => '', 'taxClass' => 'G', 'placement' => 'after_tax_line'],
            ],
            // ...and its own ✕, which is the only way a no-JS admin can get rid of it.
            'remove_charge_line' => '1',
        ]);

        $I->dontSee('After Tax fees cannot be taxable');

        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $labels = array_column(json_decode((string) $saved->getFeeLines(), true) ?: [], 'label');
        $I->assertNotContains('COD Fee', $labels, 'the invalid row could not be removed');
        $I->assertContains('Freight', $labels, 'removing the invalid row took the good one with it');
    }

    /**
     * #263 — Estimate::$lines had no ORDER BY at all, so a row inserted in the middle came back at
     * the bottom, and the positional indicators (the per-line Tax $ figure and the negative-quantity
     * highlight, both recorded by SUBMISSION position) then pointed at whichever row happened to be
     * rendered there. sort_order is what makes the two orders the same order.
     */
    public function aLineInsertedInTheMiddleStaysThereAndTheQuantityWarningPaintsItsOwnRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $first = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        // Append a second stored line first, so the insert below genuinely lands between two
        // existing rows rather than merely ahead of one.
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $first->getId(), 'product_id' => (string) $product->getId(), 'name' => '', 'qty' => '2', 'price' => '50.00'],
                1 => ['id' => '', 'product_id' => '', 'name' => 'Last', 'qty' => '1', 'price' => '10.00'],
            ],
            'action' => 'save',
        ]);

        $stored = $this->reloadEstimate($I, (int) $estimate->getId())->getLines()->toArray();
        $I->assertCount(2, $stored);

        // Now a brand new row between them, with the bad quantity, so the warning has a row of its
        // own to name and cannot be right by accident.
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $stored[0]->getId(), 'product_id' => (string) $product->getId(), 'name' => '', 'qty' => '2', 'price' => '50.00'],
                1 => ['id' => '', 'product_id' => '', 'name' => 'Middle', 'qty' => '-5', 'price' => '20.00'],
                2 => ['id' => (string) $stored[1]->getId(), 'product_id' => '', 'name' => 'Last', 'qty' => '1', 'price' => '10.00'],
            ],
            'action' => 'save',
        ]);

        $lines = $this->reloadEstimate($I, (int) $estimate->getId())->getLines()->toArray();
        $I->assertSame(
            ['Estimate Form Test Product', 'Middle', 'Last'],
            array_map(static fn ($line): string => $line->getName(), $lines),
            'the inserted line jumped to the bottom',
        );
        $I->assertSame(0.0, (float) $lines[1]->getQuantity());

        // Row 2 is where the admin put it and row 2 is what the form paints red.
        $I->see('Line 2: negative quantity was saved as 0.', '.line-warning-banner');
        $I->seeElement('#estimate-line-rows tr.estimate-line-row:nth-child(2) td.line-cell-error input.line-input-error');
        $I->seeNumberOfElements('#estimate-line-rows input.line-input-error', 1);
    }

    /**
     * The mirror image of #276's demotion, below: a Submitted quote that an admin fully prices
     * through an ordinary Save (not the explicit "Save & Send Pricing to Customer" button) used to
     * stay Submitted forever — nothing but that one specific button ever promoted it, so a quote
     * could sit fully priced and never reach the customer's Accept/Decline buttons.
     */
    public function anEditThatFullyPricesASubmittedQuoteThroughAnOrdinarySavePromotesItToPriced(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->assertSame('Submitted', $this->reloadEstimate($I, (int) $estimate->getId())->getStatus());

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'charge_lines_present' => '1',
            'charge_lines' => [['label' => 'Custom Shipping', 'amount' => '25.00', 'type' => 'shipping']],
            // Deliberately the plain Save action, not send_pricing — the fix under test is that
            // this ordinary save promotes the status on its own once every figure is known.
            'action' => 'save',
        ]);

        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertSame('Priced', $saved->getStatus());
        $I->assertNotNull($saved->getTotal());

        $comments = $this->estimateNarrativeComments($I, (int) $saved->getId());
        $I->assertContains('Estimate status changed from Submitted to Priced.', $comments);
    }

    /**
     * An admin sometimes needs a quote to stop being customer-visible without deleting it — e.g.
     * pricing was sent by mistake, or the customer shouldn't see this particular revision yet.
     * "Save Back to Draft" is the explicit, admin-only way back to Draft (not customer-visible,
     * per EstimateStatus's own doc comment) from either Submitted or Priced.
     */
    public function saveBackToDraftMovesAPricedQuoteToDraftAndOffersTheButtonOnlyFromSubmittedOrPriced(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->find(Estimate::class, $estimate->getId())->setStatus('Priced', DocumentActor::system());
        $entityManager->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeElement('.panel-actions button[type="submit"][name="action"][value="save_draft"]');
        $I->see('Save Back to Draft', '.panel-actions');

        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '100.00'],
            ],
            'action' => 'save_draft',
        ]);

        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertSame('Draft', $saved->getStatus());

        $comments = $this->estimateNarrativeComments($I, (int) $saved->getId());
        $I->assertContains('Estimate status changed from Priced to Draft.', $comments);

        // A freshly-drafted quote no longer offers the button that just fired — there is nothing
        // further back than Draft to save to.
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->dontSeeElement('.panel-actions button[type="submit"][name="action"][value="save_draft"]');
    }

    /**
     * #276 — edit() only ever promoted. An admin could blank a price on an already-Priced quote and
     * it stayed Priced while setTotal(null) ran two lines later: a document claiming a price with no
     * total behind it, which is exactly what the customer's Accept button keys off.
     */
    public function anEditThatLeavesAQuoteUnpricedDemotesItFromPricedBackToSubmitted(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->find(Estimate::class, $estimate->getId())->setStatus('Priced', DocumentActor::system());
        $entityManager->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            // The blank price is the edit that takes the grand total away again.
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => ''],
            ],
            'action' => 'save',
        ]);

        $saved = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertSame('Submitted', $saved->getStatus());
        $I->assertNull($saved->getTotal(), 'the status and the total disagree');

        // Demotions are logged like every other transition edit() makes, rather than happening
        // silently under a customer who was looking at a priced quote.
        $comments = $this->estimateNarrativeComments($I, (int) $saved->getId());
        $I->assertContains('Estimate status changed from Priced to Submitted.', $comments);
    }

    /**
     * #276, second half — updateStatus() accepted any status with no pricing check at all, so the
     * status dropdown was a way into Priced that the send_pricing button's isFullyPriced() guard
     * could not see.
     */
    public function theStatusEndpointRefusesToMarkAnUnpricedQuotePriced(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeEstimate($I, $company, $product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/update-status/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Priced',
        ]);

        $I->seeResponseCodeIs(400);
        $I->assertSame('Submitted', $this->reloadEstimate($I, (int) $estimate->getId())->getStatus());
    }

    /**
     * #283 — the product snapshot seeded cost but never price, and only the browser's change
     * handler ever copied the catalog figure into the box. So a no-JS "Add Line & Save" minted a
     * permanently unpriced line, which then read as a deliberate "No pricing" and dragged the whole
     * quote out of isFullyPriced().
     */
    public function attachingAProductToALineSeedsItsCatalogPriceWhenThePostCarriesNone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        // No product on this line yet — the moment the save attaches one is the moment that seeds.
        $estimate = $this->makeEstimate($I, $company);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => ''],
            ],
            'action' => 'save',
        ]);

        $saved = $this->reloadEstimate($I, (int) $estimate->getId())->getLines()->first();
        // Seeded from the product's original price (#375) — the same figure the dropdown's
        // data-original-price carries and the browser's change handler now copies into the box.
        $I->assertSame(79.99, (float) $saved->getPrice());
        $I->assertSame(159.98, (float) $saved->getSubtotal());
    }

    /** ...and blanking it afterwards is still the quote-only "No pricing", not a re-seed. */
    public function clearingThePriceOnALineThatAlreadyHasItsProductStillMeansNoPricing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        // Product already attached and priced — nothing is being attached by this save.
        $estimate = $this->makeEstimate($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => ''],
            ],
            'action' => 'save',
        ]);

        $saved = $this->reloadEstimate($I, (int) $estimate->getId())->getLines()->first();
        $I->assertNull($saved->getPrice(), 'a deliberately cleared price was re-seeded from the catalog');
        $I->assertNull($saved->getSubtotal());
    }
}
