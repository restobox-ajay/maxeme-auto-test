<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin\EstimateController — /admin/estimate index (search/filter), create() success +
 *  validation, detail()/quote() rendering and not-found handling, addLog()/deleteLog(), the
 *  edit() flow (locked-status guard, full line editing — add/remove/re-point — price/shipping
 *  entry, send_pricing transition to Priced with customer notification), and updateStatus()'s
 *  guards. */
final class AdminEstimateCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-estimate-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeCompany(FunctionalTester $I, string $name = 'Estimate Test Co'): Company
    {
        $company = (new Company())->setName($name)->setCode('EST-' . uniqid());
        $I->haveInRepository($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I, string $sku, string $name): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName($name)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    /**
     * Quote CREATE is refused for a company with no active fulfillment region — there is no price
     * list, so there is no correct price to put on a new quote (#238). Only the create tests below
     * need one; the edit tests deliberately keep region-less companies, which is the case that must
     * stay editable.
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

    /** One shipping row at $amount, or no rows at all when the amount is null (TBD). */
    private static function shippingLinesJson(?string $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        return json_encode([[
            'slug' => 'shipping',
            'label' => 'Shipping (Ground)',
            'taxClass' => 'G',
            'amount' => (float) $amount,
            'placement' => 'main_line',
            'type' => 'shipping',
            'source' => 'auto-calc',
        ]]);
    }

    private function makeEstimate(FunctionalTester $I, Company $company, string $status, array $overrides = []): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber($overrides['documentNumber'] ?? ('EST-' . uniqid()))
            ->setSource($overrides['source'] ?? 'Admin')
            ->setSubtotal($overrides['subtotal'] ?? '100.00')
            // Shipping is a row, and the only shipping figure a document has is the sum of them —
            // see AbstractSalesDocument::getShippingTotal(). A null override means no row, i.e. TBD.
            // array_key_exists, not ??: an explicit ['shipping' => null] is the TBD case and must
            // not fall through to the default the way a missing key does.
            ->setFeeLines(self::shippingLinesJson(
                \array_key_exists('shipping', $overrides) ? $overrides['shipping'] : '10.00',
            ))
            ->setTax($overrides['tax'] ?? '5.00')
            ->setTotal($overrides['total'] ?? '115.00');
        $estimate->setStatus($status, DocumentActor::system());

        $line = (new EstimateLine())
            ->setName('Widget')
            ->setSku('WIDGET-1')
            ->setQuantity('2.00')
            ->setCost('40.00')
            ->setPrice($overrides['linePrice'] ?? '50.00')
            ->setSubtotal($overrides['lineSubtotal'] ?? '100.00');
        $estimate->addLine($line);

        $I->haveInRepository($estimate);

        return $estimate;
    }

    /** A Submitted (or, when given, another status) estimate with no header pricing and one unpriced line, for exercising edit()'s pricing flow itself. */
    private function makeUnpricedEstimate(FunctionalTester $I, Company $company, string $status = 'Submitted'): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('EST-' . uniqid())
            ->setSource('Admin');
        $estimate->setStatus($status, DocumentActor::system());

        $line = (new EstimateLine())
            ->setName('Widget')
            ->setSku('WIDGET-UNPRICED')
            ->setQuantity('2.00')
            ->setCost('40.00');
        $estimate->addLine($line);

        $I->haveInRepository($estimate);

        return $estimate;
    }

    public function indexListsEstimatesAndFiltersByStatusAndDocumentNumber(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);
        $submitted = $this->makeEstimate($I, $company, 'Submitted', ['documentNumber' => 'EST-INDEX-SUBMITTED']);
        $priced = $this->makeEstimate($I, $company, 'Priced', ['documentNumber' => 'EST-INDEX-PRICED']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate');
        $I->seeResponseCodeIsSuccessful();
        $I->see($submitted->getDocumentNumber());
        $I->see($priced->getDocumentNumber());

        $I->amOnPage('/admin/estimate?filters[status]=Priced');
        $I->seeResponseCodeIsSuccessful();
        $I->see($priced->getDocumentNumber());
        $I->dontSee($submitted->getDocumentNumber());

        $I->amOnPage('/admin/estimate?filters[documentNumber]=INDEX-SUBMITTED');
        $I->seeResponseCodeIsSuccessful();
        $I->see($submitted->getDocumentNumber());
        $I->dontSee($priced->getDocumentNumber());
    }

    public function indexShowsTheOrderNumberAndUserAndNoLongerBreaksOutSubtotalShippingOrTax(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);

        $converted = $this->makeEstimate($I, $company, 'Accepted', ['documentNumber' => 'EST-COLS-CONVERTED']);
        $converted->setUserName('Dana Requester');
        $order = (new SalesOrder())->setCompany($company)->setOrderNumber('SO-COLS-9001')->setTotal('115.00');
        $I->haveInRepository($order);
        $converted->setConvertedOrder($order);
        $I->haveInRepository($converted);

        $open = $this->makeEstimate($I, $company, 'Submitted', ['documentNumber' => 'EST-COLS-OPEN']);
        $open->setUserName('Sam Asker');
        $I->haveInRepository($open);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate');
        $I->seeResponseCodeIsSuccessful();

        // The converted quote names its order and links to it; the open one has nothing to link to.
        $I->see('SO-COLS-9001');
        $I->seeElement('a[href="/admin/order/detail/' . $order->getId() . '"]');
        $I->see('Dana Requester');
        $I->see('Sam Asker');

        // Total stays; the three columns it is built from are gone from the header.
        $I->seeElement('th[data-sort-field="total"]');
        $I->seeElement('th[data-sort-field="orderNumber"]');
        $I->seeElement('th[data-sort-field="userName"]');
        $I->dontSeeElement('th[data-sort-field="subtotal"]');
        $I->dontSeeElement('th[data-sort-field="shipping"]');
        $I->dontSeeElement('th[data-sort-field="tax"]');
    }

    public function indexFiltersAndSortsOnTheNewOrderNumberAndUserColumns(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);

        $withOrder = $this->makeEstimate($I, $company, 'Accepted', ['documentNumber' => 'EST-FILT-WITHORDER']);
        $withOrder->setUserName('Alice Filterer');
        $order = (new SalesOrder())->setCompany($company)->setOrderNumber('SO-FILT-4242')->setTotal('115.00');
        $I->haveInRepository($order);
        $withOrder->setConvertedOrder($order);
        $I->haveInRepository($withOrder);

        $withoutOrder = $this->makeEstimate($I, $company, 'Submitted', ['documentNumber' => 'EST-FILT-NOORDER']);
        $withoutOrder->setUserName('Bob Otherguy');
        $I->haveInRepository($withoutOrder);

        $I->haveHttpHeader('Host', 'admin.localhost');

        $I->amOnPage('/admin/estimate?filters[orderNumber]=FILT-4242');
        $I->see('EST-FILT-WITHORDER');
        $I->dontSee('EST-FILT-NOORDER');

        $I->amOnPage('/admin/estimate?filters[userName]=Bob');
        $I->see('EST-FILT-NOORDER');
        $I->dontSee('EST-FILT-WITHORDER');

        // Sorting on the joined order number must not drop the unconverted quote — the join is a
        // LEFT join precisely so a quote with no order still lists.
        $I->amOnPage('/admin/estimate?sort=orderNumber&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->see('EST-FILT-WITHORDER');
        $I->see('EST-FILT-NOORDER');

        $I->amOnPage('/admin/estimate?sort=userName&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->see('EST-FILT-WITHORDER');
        $I->see('EST-FILT-NOORDER');

        // A retired sort key an admin has bookmarked must fall back, not 500.
        $I->amOnPage('/admin/estimate?sort=shipping&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->see('EST-FILT-WITHORDER');
    }

    public function createRendersTheFormAndPersistsADraftEstimateWithALine(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Create Flow Co');
        $this->makeActiveRegion($I, $company, 'Create Flow Region');
        $product = $this->makeProduct($I, 'CREATE-FLOW-SKU', 'Create Flow Product');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Create Quote');

        $I->sendAjaxPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Create Flow Region',
            'po_number' => 'PO-CREATE-1',
            'lines' => [
                0 => ['product_id' => (string) $product->getId(), 'qty' => '3', 'price' => '25.00'],
            ],
            'save_mode' => 'draft',
        ]);

        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()]);
        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());

        $I->seeInRepository(Estimate::class, [
            'id' => $estimate->getId(),
            'status' => 'Draft',
            'poNumber' => 'PO-CREATE-1',
            'subtotal' => '75.00',
        ]);
        $I->seeInRepository(EstimateLine::class, [
            'sku' => 'CREATE-FLOW-SKU',
            'price' => '25.00',
            'subtotal' => '75.00',
        ]);
    }

    public function createWithoutACompanyFailsValidationAndDoesNotPersist(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $countBefore = (int) $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT COUNT(*) FROM estimate');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create');
        $I->sendAjaxPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),'save_mode' => 'draft']);
        $I->seeCurrentUrlEquals('/admin/estimate/create');
        $I->see('Please select a customer.');

        $I->assertSame(
            $countBefore,
            (int) $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT COUNT(*) FROM estimate'),
            'nothing was written without a company',
        );
    }

    /**
     * Zero line items is a valid quote (#full-parity, 2026-09-12) — create() has no existing lines
     * to lose, so an omitted `lines` key just means the quote is minted with none, same as a real
     * screen where every line was removed before the first save.
     */
    public function createWithACompanyButNoLinesStillPersistsAZeroLineQuote(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'No Lines Co');
        $this->makeActiveRegion($I, $company, 'No Lines Region');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/create');
        $I->sendAjaxPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'No Lines Region',
            'save_mode' => 'draft',
        ]);

        $I->seeInRepository(Estimate::class, ['company' => $company->getId(), 'status' => 'Draft']);
        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()]);
        $I->assertCount(0, $estimate->getLines());
    }

    public function detailRendersAnExistingEstimateAndAnUnknownIdIs404(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($estimate->getDocumentNumber());

        $I->amOnPage('/admin/estimate/detail/999999999');
        $I->seeResponseCodeIs(404);
    }

    /**
     * Shipping travels inside the fee_lines snapshot (EstimateController::recomputeFeesAndTax()
     * merges the shipping rows into it), so anything looping fee_lines has to exclude
     * type == 'shipping' or it picks shipping up a second time. Order detail and the admin quote
     * document both carry that guard; quote detail did not, so its Products table grew a phantom
     * shipping row and its Subtotal was overstated by the shipping amount — which then appeared
     * AGAIN on the Shipping Fee row below it (#256).
     */
    public function detailCountsShippingOnceRatherThanAsAProductRowAndAgainAsAShippingFee(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced', ['shipping' => '10.00']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        // The shipping row is not a product, so it gets no row in the Products table.
        $I->dontSeeElement('.order-products-table tbody tr.fee-line-row');
        $I->dontSee('Shipping (Ground)', '.order-products-table');

        // Subtotal is the goods alone — 100.00, not 110.00 with the shipping folded in.
        $I->see('$100.00');
        $I->dontSee('$110.00');
    }

    public function quotePdfRendersHtmlByDefaultAndAPdfOnDownloadAndRedirectsForAnUnknownId(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/quote/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($estimate->getDocumentNumber());
        // The "Download Quote" action must link to the server PDF (download=1), not a param-less
        // self-link that just re-renders this HTML page.
        $I->seeElement('a[href*="download=1"]');

        $I->amOnPage('/admin/estimate/quote/' . $estimate->getId() . '?download=1');
        $I->seeResponseCodeIsSuccessful();
        $I->assertStringStartsWith('%PDF', $I->grabPageSource());

        $I->amOnPage('/admin/estimate/quote/999999999');
        $I->seeCurrentUrlEquals('/admin/estimate');
        $I->see('Estimate could not be found.');
    }

    /**
     * #378: the quote PDF's header carried a leftover "ADMIN CONSOLE" tag next to the site name —
     * internal admin-tool chrome on a document handed to a customer. The order Invoice PDF never
     * had it, so the header now matches that one exactly (site name only).
     */
    public function quotePdfHeaderDoesNotCarryTheAdminConsoleTag(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/quote/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        // Scoped to the document itself: the surrounding admin site chrome legitimately says
        // "Admin Console" in its own nav label, and dontSee() is case-insensitive.
        $I->dontSee('ADMIN CONSOLE', '.invoice-document');
    }

    /**
     * The admin quote document printed `$0.00` for Product Subtotal and Total Tax on an unpriced
     * quote, because both went through `|default(0)` — turning "not yet priced" into "these goods
     * are free" on the one surface an admin hands to a customer, while the customer's own PDF of
     * the same quote said "Pricing pending". Two documents, the same quote, materially different
     * money (#254).
     */
    public function quotePdfPrintsTbdRatherThanZeroForAQuoteThatIsNotPricedYet(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Submitted', [
            'shipping' => null,
            'linePrice' => null,
            'lineSubtotal' => null,
        ]);
        // The state a save leaves behind once a line is unpriced — see
        // EstimateController::holdBackUnstatableTotals().
        $estimate->setSubtotal(null)->setTax(null)->setTotal(null);
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/quote/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->see('Product Subtotal:');
        $I->see('Total Tax:');
        $I->dontSee('$0.00');
    }

    /**
     * CLIENT ROUND 7 FEEDBACK, PART 26 — the Quote PDF must show fee lines the same way order's
     * Invoice PDF and the corrected estimate edit form (PART 24) do: a `main_line` fee gets its
     * own row in the product table and is folded into "Subtotal excl. taxes" (not re-listed as
     * its own totals-box row), while an `after_tax` fee only ever appears once, as a totals-box
     * row after Total Tax.
     */
    public function quotePdfShowsAMainLineFeeOnceInTheProductTableAndAnAfterTaxFeeOnceInTheTotalsBox(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Quote Fee Test Co');
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('EST-QUOTEFEE-' . uniqid())
            ->setSource('Admin')
            ->setSubtotal('175.00')
            ->setTax('5.00')
            ->setTotal('199.75');
        $estimate->setStatus('Priced', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())
                ->setName('Widget')
                ->setSku('QUOTEFEE-SKU')
                ->setQuantity('1.00')
                ->setCost('100.00')
                ->setPrice('175.00')
                ->setSubtotal('175.00')
        );
        $estimate->setFeeLines(json_encode([
            ['slug' => 'bc-environmental-fee', 'label' => 'BC Environmental Fee', 'taxClass' => 'none', 'amount' => 6.50, 'placement' => 'main_line'],
            ['slug' => 'bc-recycling-fee', 'label' => 'BC Recycling Fee', 'taxClass' => 'none', 'amount' => 3.25, 'placement' => 'after_tax_line'],
            ['slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'G', 'amount' => 10.0, 'placement' => 'main_line', 'type' => 'shipping'],
        ]));
        $I->haveInRepository($estimate);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/quote/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        // A shipping row is a fee row, but it is named in the totals box rather than listed with
        // the products, so it neither appears there nor is counted twice below.
        $I->dontSee('Shipping (Ground)', 'table.invoice-table');
        $I->see('Shipping (Ground)', 'table.totals-inner');

        // main_line fee: its own row in the product table, never a totals-box row.
        $I->see('BC Environmental Fee', 'table.invoice-table');
        $I->see('$6.50', 'table.invoice-table');
        $I->dontSee('BC Environmental Fee', 'table.totals-inner');

        // after_tax fee: a totals-box row after Total Tax, never a product-table row.
        $I->dontSee('BC Recycling Fee', 'table.invoice-table');
        $I->see('Total Tax:', 'table.totals-inner');
        $I->see('BC Recycling Fee', 'table.totals-inner');
        $I->see('$3.25', 'table.totals-inner');

        // The main_line fee is folded into "Subtotal excl. taxes" (175 + 10 shipping + 6.50 fee),
        // not double-counted alongside its own product-table row.
        $I->see('Subtotal excl. taxes:', 'table.totals-inner');
        $I->see('$191.50', 'table.totals-inner');
    }

    /**
     * CLIENT ROUND 8 FEEDBACK, PART 27 — the Quote PDF's totals block used to be gated wholesale on
     * isFullyPriced, collapsing to "Pricing pending — totals will be shown once every line is
     * priced." So a quote with one priced line and one TBD line showed no numbers at all, even
     * though that line's subtotal and fees are perfectly well known. Now the table always renders
     * and only the genuinely unresolved figures read TBD.
     */
    public function quotePdfShowsThePricedLinesTotalsWhileTheGrandTotalStaysTbd(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Quote Partial Test Co');
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('EST-QUOTEPARTIAL-' . uniqid())
            ->setSource('Admin')
            // What edit() now persists for a half-priced estimate: real subtotal/tax, no shipping,
            // no total.
            ->setSubtotal('175.00')
            ->setTax('5.00');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())
                ->setName('Priced Widget')
                ->setSku('QUOTEPARTIAL-SKU-1')
                ->setQuantity('1.00')
                ->setCost('100.00')
                ->setPrice('175.00')
                ->setSubtotal('175.00')
        );
        $estimate->addLine(
            (new EstimateLine())
                ->setName('Unpriced Widget')
                ->setSku('QUOTEPARTIAL-SKU-2')
                ->setQuantity('1.00')
                ->setCost('100.00')
        );
        $estimate->setFeeLines(json_encode([
            ['slug' => 'partial-environmental-fee', 'label' => 'Partial Environmental Fee', 'taxClass' => 'none', 'amount' => 6.50, 'placement' => 'main_line'],
        ]));
        $I->haveInRepository($estimate);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/quote/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        // The totals table renders at all, instead of the old all-or-nothing placeholder.
        $I->seeElement('table.totals-inner');
        $I->see('Product Subtotal:', 'table.totals-inner');
        $I->see('$175.00', 'table.totals-inner');
        $I->see('Total Tax:', 'table.totals-inner');
        $I->see('$5.00', 'table.totals-inner');
        // The priced line's main_line fee still gets its own product-table row (PART 26).
        $I->see('Partial Environmental Fee', 'table.invoice-table');
        $I->see('$6.50', 'table.invoice-table');

        // Shipping isn't resolved, so neither it nor the two figures built on it can be stated.
        $I->see('TBD', 'tr.shipping-fee-row');
        $I->see('TBD', 'tr.subtotal-excl-taxes-row');
        $I->see('TBD', 'tr.grand-total-row');
        $I->dontSee('$', 'tr.grand-total-row');
    }

    public function addLogAndDeleteLogRoundTripThroughJsonWithErrorGuards(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Submitted');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/log/add/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),'message' => 'Called the customer']);
        $I->seeResponseCodeIsSuccessful();
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
        $I->seeInRepository(AuditLog::class, ['entityType' => 'Estimate', 'entityId' => $estimate->getId(), 'summary' => 'Called the customer', 'actorType' => 'document']);

        $I->sendAjaxPostRequest('/admin/estimate/log/add/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),'message' => '   ']);
        $I->seeResponseCodeIs(400);

        $I->sendAjaxPostRequest('/admin/estimate/log/add/999999999', [
            '_token' => $I->csrfToken(),'message' => 'Orphan message']);
        $I->seeResponseCodeIs(404);

        $log = $I->grabEntityFromRepository(AuditLog::class, ['entityType' => 'Estimate', 'entityId' => $estimate->getId(), 'summary' => 'Called the customer', 'actorType' => 'document']);

        $I->sendAjaxPostRequest('/admin/estimate/log/delete/' . $estimate->getId() . '/999999999', ['_token' => $I->csrfToken()]);
        $I->seeResponseCodeIs(404);
        $I->seeInRepository(AuditLog::class, ['id' => $log->getId()]);

        $I->sendAjaxPostRequest('/admin/estimate/log/delete/' . $estimate->getId() . '/' . $log->getId(), ['_token' => $I->csrfToken()]);
        $I->seeResponseCodeIsSuccessful();
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
        $I->dontSeeInRepository(AuditLog::class, ['id' => $log->getId()]);
    }

    /**
     * #270: ticking "notify customer" wrote customer_notified = true and sent nothing, so the one
     * record staff read to decide whether a follow-up is still owed was a standing false positive
     * on quotes. The order's twin has always mailed the message.
     */
    public function aMessageMarkedNotifyCustomerIsActuallyEmailedToThem(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Notified Quote Co');
        $company->setPrimaryEmail('buyer@notified-quote.test');
        $I->haveInRepository($company);
        $estimate = $this->makeEstimate($I, $company, 'Priced');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/log/add/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'message' => 'Your pallets ship Monday.',
            'type' => 'For Client',
            'notify_client' => '1',
        ]);
        $I->seeResponseCodeIsSuccessful();

        // Named by its comment, because the quote's timeline is no longer a single row: the fixture
        // reached Priced through the seam's door, which writes one of its own.
        $log = $I->grabEntityFromRepository(AuditLog::class, [
            'entityType' => 'Estimate',
            'entityId' => $estimate->getId(),
            'summary' => 'Your pallets ship Monday.',
            'actorType' => 'document',
        ]);
        $I->assertTrue($log->isRecipientNotified());

        $I->seeEmailIsSent();
        $email = $I->grabLastSentEmail();
        $I->assertSame('buyer@notified-quote.test', $email->getTo()[0]->getAddress());
        $I->assertStringContainsString($estimate->getDocumentNumber(), (string) $email->getSubject());
        $I->assertStringContainsString('Your pallets ship Monday.', (string) $email->getHtmlBody());
    }

    /** An internal note is still internal — no mail, and the flag says so. */
    public function aMessageWithoutNotifyCustomerSendsNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Internal Note Co');
        $company->setPrimaryEmail('buyer@internal-note.test');
        $I->haveInRepository($company);
        $estimate = $this->makeEstimate($I, $company, 'Priced');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/log/add/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'message' => 'Chase accounts before pricing this.',
            'type' => 'For Internal',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $log = $I->grabEntityFromRepository(AuditLog::class, [
            'entityType' => 'Estimate',
            'entityId' => $estimate->getId(),
            'summary' => 'Chase accounts before pricing this.',
            'actorType' => 'document',
        ]);
        $I->assertFalse($log->isRecipientNotified());
        $I->dontSeeEmailIsSent();
    }

    public function editIsLockedOnceAcceptedAndRedirectsToDetailWithAnErrorFlash(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Accepted');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeCurrentUrlEquals('/admin/estimate/detail/' . $estimate->getId());
        $I->see('This estimate is locked and can no longer be edited.');
    }

    public function editPricingAllLinesAndSendingPricingMarksItPricedAndNotifiesTheCustomer(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);
        $estimate = $this->makeUnpricedEstimate($I, $company);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            // The quantity and price are posted explicitly, the way the form's own row does. A save
            // no longer reads a missing or blank quantity back off the stored line — that fallback
            // is what silently put a 1 on a brand-new quote line (#259) — so a post that means
            // "2 of these" has to say so.
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'qty' => '2', 'price' => '50.00'],
            ],
            'shipping' => '10.00',
            'shipping_method' => 'Ground',
            'action' => 'send_pricing',
        ]);
        $I->seeCurrentUrlEquals('/admin/estimate/detail/' . $estimate->getId());
        $I->see('Pricing sent to the customer.');

        $I->seeInRepository(Estimate::class, [
            'id' => $estimate->getId(),
            'status' => 'Priced',
            'subtotal' => '100.00',
        ]);
        // Shipping is not a column to match on any more: it is the sum of the quote's type=shipping
        // fee rows, which is what the legacy shipping/shipping_method post above became.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertSame(10.0, $entityManager->find(Estimate::class, $estimate->getId())->getShippingTotal());
        $I->seeInRepository(EstimateLine::class, ['id' => $line->getId(), 'price' => '50.00', 'subtotal' => '100.00']);
        $I->seeInRepository(AuditLog::class, ['entityType' => 'Estimate', 'entityId' => $estimate->getId(), 'actorType' => 'document']);
    }

    public function editSendingPricingWithAnUnpricedLineFailsAndLeavesTheEstimateUnpriced(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);
        $estimate = $this->makeUnpricedEstimate($I, $company);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'price' => ''],
            ],
            'action' => 'send_pricing',
        ]);
        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());
        $I->see('Fill in every line price and shipping before sending pricing to the customer.');

        $I->seeInRepository(Estimate::class, ['id' => $estimate->getId(), 'status' => 'Submitted']);
    }

    /**
     * #375 — Send Pricing and Make Visible to Customer are meant to differ only in the email: a
     * Draft that isn't fully priced yet still becomes Submitted either way, and Send Pricing is
     * simply the one that also emails once the total is actually known. Before this fix, an
     * incomplete Send Pricing left a Draft quote stuck on Draft — invisible to the customer, unlike
     * what the same click on Make Visible would have done.
     */
    public function editSendingIncompletePricingFromDraftStillMakesTheQuoteVisibleToTheCustomer(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);
        $estimate = $this->makeUnpricedEstimate($I, $company, 'Draft');
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'price' => ''],
            ],
            'action' => 'send_pricing',
        ]);
        $I->see('Fill in every line price and shipping before sending pricing to the customer.');

        $I->seeInRepository(Estimate::class, ['id' => $estimate->getId(), 'status' => 'Submitted']);
        $I->dontSeeEmailIsSent();
    }

    public function editRebuildsTheWholeLineSetAddingRemovingAndRepointingLines(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Line Editing Co');
        $keeper = $this->makeProduct($I, 'EDIT-KEEP-SKU', 'Edit Keep Product');
        $dropped = $this->makeProduct($I, 'EDIT-DROP-SKU', 'Edit Drop Product');
        $replacement = $this->makeProduct($I, 'EDIT-SWAP-SKU', 'Edit Swap Product');

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('EST-' . uniqid())
            ->setSource('Admin');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $firstLine = (new EstimateLine())
            ->setProduct($keeper)
            ->setName($keeper->getName())
            ->setSku($keeper->getSku())
            ->setQuantity('2.00')
            ->setPrice('50.00')
            ->setSubtotal('100.00');
        $secondLine = (new EstimateLine())
            ->setProduct($dropped)
            ->setName($dropped->getName())
            ->setSku($dropped->getSku())
            ->setQuantity('1.00')
            ->setPrice('30.00')
            ->setSubtotal('30.00');
        $estimate->addLine($firstLine);
        $estimate->addLine($secondLine);
        $I->haveInRepository($estimate);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        // The edit form now offers the same product picker the create form does.
        $I->seeElement('#estimate-line-rows select[name$="[product_id]"]');

        // Re-point the first line at another product (and change its qty), drop the second line
        // entirely by leaving it out, and append a brand new line.
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $firstLine->getId(), 'product_id' => (string) $replacement->getId(), 'qty' => '3', 'price' => '10.00'],
                1 => ['id' => '', 'product_id' => (string) $dropped->getId(), 'qty' => '2', 'price' => '5.00'],
            ],
            'shipping' => '10.00',
            'shipping_method' => 'Ground',
            'action' => 'save',
        ]);
        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());
        $I->see('Estimate saved.');

        $I->seeInRepository(EstimateLine::class, [
            'id' => $firstLine->getId(),
            'product' => $replacement->getId(),
            'sku' => 'EDIT-SWAP-SKU',
            'quantity' => '3.00',
            'price' => '10.00',
            'subtotal' => '30.00',
        ]);
        $I->dontSeeInRepository(EstimateLine::class, ['id' => $secondLine->getId()]);
        $I->seeInRepository(EstimateLine::class, [
            'estimate' => $estimate->getId(),
            'sku' => 'EDIT-DROP-SKU',
            'quantity' => '2.00',
            'price' => '5.00',
            'subtotal' => '10.00',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertCount(2, $saved->getLines());
        $I->assertSame(40.0, (float) $saved->getSubtotal());
        $I->assertNotNull($saved->getTax());
        $I->assertSame(
            round(40.0 + 10.0 + (float) $saved->getTax(), 2),
            round((float) $saved->getTotal() - $this->feeTotalOf($saved), 2),
        );
    }

    /** The line table's add/remove controls are type="button" and only work with JS, so the form
     *  also has to work the way order's does without it: spare blank rows rendered server-side,
     *  and a real submit-button Remove per row. Codeception never runs JS, so driving the page
     *  through plain form POSTs is exactly the no-JS path. */
    public function editWithoutJavaScriptAddsALineInASpareRowAndRemovesOneWithTheFallbackButton(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'No JS Estimate Co');
        $keeper = $this->makeProduct($I, 'NOJS-KEEP-SKU', 'No JS Keep Product');
        $dropped = $this->makeProduct($I, 'NOJS-DROP-SKU', 'No JS Drop Product');
        $added = $this->makeProduct($I, 'NOJS-ADD-SKU', 'No JS Add Product');

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('EST-' . uniqid())
            ->setSource('Admin');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $firstLine = (new EstimateLine())
            ->setProduct($keeper)
            ->setName($keeper->getName())
            ->setSku($keeper->getSku())
            ->setQuantity('2.00')
            ->setPrice('50.00')
            ->setSubtotal('100.00');
        $secondLine = (new EstimateLine())
            ->setProduct($dropped)
            ->setName($dropped->getName())
            ->setSku($dropped->getSku())
            ->setQuantity('1.00')
            ->setPrice('30.00')
            ->setSubtotal('30.00');
        $estimate->addLine($firstLine);
        $estimate->addLine($secondLine);
        $I->haveInRepository($estimate);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeNumberOfElements('#estimate-line-rows tr.no-js-row', 2);
        $I->seeElement('#estimate-line-rows button[name="remove_line"][value="0"]');
        $I->seeElement('#estimate-line-rows button[name="remove_line"][value="1"]');

        // What the browser posts with JS off: both existing rows, one spare row filled in, the
        // other left blank — so the spare rows are how a line gets added.
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $firstLine->getId(), 'product_id' => (string) $keeper->getId(), 'qty' => '2', 'price' => '50.00'],
                1 => ['id' => (string) $secondLine->getId(), 'product_id' => (string) $dropped->getId(), 'qty' => '1', 'price' => '30.00'],
                2 => ['id' => '', 'product_id' => (string) $added->getId(), 'qty' => '4', 'price' => '7.50'],
                3 => ['id' => '', 'product_id' => '', 'qty' => '1', 'price' => ''],
            ],
            'action' => 'save',
        ]);
        $I->see('Estimate saved.');
        $I->seeInRepository(EstimateLine::class, [
            'estimate' => $estimate->getId(),
            'sku' => 'NOJS-ADD-SKU',
            'quantity' => '4.00',
            'price' => '7.50',
            'subtotal' => '30.00',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $rows = $entityManager->find(Estimate::class, $estimate->getId())->getLines()->toArray();
        $I->assertCount(3, $rows);

        // Pressing the second row's Remove submits the whole form again with that row's index in
        // remove_line, and has to drop only that line.
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeElement('#estimate-line-rows button[name="remove_line"][value="1"]');
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            // The three rendered rows followed by the two spare no-JS ones, each posting its own
            // field group under its own index — what the browser sends for this form.
            'lines' => array_merge(
                array_map(static fn (EstimateLine $line): array => [
                    'id' => (string) $line->getId(),
                    'product_id' => (string) $line->getProduct()?->getId(),
                    'qty' => (string) $line->getQuantity(),
                    'price' => (string) $line->getPrice(),
                ], $rows),
                [
                    ['id' => '', 'product_id' => '', 'qty' => '1', 'price' => ''],
                    ['id' => '', 'product_id' => '', 'qty' => '1', 'price' => ''],
                ],
            ),
            'remove_line' => '1',
            'action' => 'save',
        ]);
        $I->see('Estimate saved.');
        $I->dontSeeInRepository(EstimateLine::class, ['id' => $rows[1]->getId()]);
        $I->seeInRepository(EstimateLine::class, ['id' => $rows[0]->getId()]);
        $I->seeInRepository(EstimateLine::class, ['id' => $rows[2]->getId()]);

        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertCount(2, $saved->getLines());
        $I->assertSame(
            ['NOJS-KEEP-SKU', 'NOJS-ADD-SKU'],
            array_map(static fn (EstimateLine $line): string => (string) $line->getSku(), $saved->getLines()->toArray()),
        );
    }

    public function editUnpricingALineByAddingANewOneClearsTheHeaderMoneyButKeepsThePricedLinesOwnFigures(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Unpricing Co');
        $product = $this->makeProduct($I, 'EDIT-TBD-SKU', 'Edit TBD Product');
        $estimate = $this->makeEstimate($I, $company, 'Priced');
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => '', 'qty' => '2', 'price' => '50.00'],
                1 => ['id' => '', 'product_id' => (string) $product->getId(), 'qty' => '1', 'price' => ''],
            ],
            'action' => 'save',
        ]);
        $I->see('Estimate saved.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertCount(2, $saved->getLines());
        // The product-less original line keeps its own snapshot rather than being dropped.
        $I->assertSame('Widget', $saved->getLines()->first()->getName());
        // The still-priced LINE keeps its own numbers — order shows real figures for whatever's
        // priced, and estimate does too.
        $I->assertEqualsWithDelta(100.0, (float) $saved->getLines()->first()->getSubtotal(), 0.001);

        // The DOCUMENT's figures do not: a subtotal of 100.00 here would be the priced line's
        // total presented as the whole quote's, understated by whatever the second line turns out
        // to cost. Subtotal, Tax and Total all go back to TBD together, so no surface can print a
        // definite figure — or a $0.00 — for a quote that isn't finished (#254).
        $I->assertNull($saved->getSubtotal());
        $I->assertNull($saved->getTax());
        $I->assertNull($saved->getTotal());
        $I->assertFalse($saved->isFullyPriced());
    }

    public function editWithNoSubmittedLinesIsRejectedInsteadOfWipingTheEstimate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'No Wipe Co');
        $estimate = $this->makeEstimate($I, $company, 'Submitted');
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),'action' => 'save']);
        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());
        $I->see('Lines were not submitted');

        $I->seeInRepository(EstimateLine::class, ['id' => $line->getId(), 'price' => '50.00']);
    }

    public function editShowsTheTaxFeeAndTotalsBreakdownWithATbdGrandTotalUntilFullyPriced(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Totals Display Co');
        $estimate = $this->makeUnpricedEstimate($I, $company);
        $line = $estimate->getLines()->first();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        // Since PART 27 the box itself always renders — it's Grand Total alone that reads TBD until
        // every line is priced and shipping is resolved.
        $I->see('Grand Total:');
        $I->see('TBD', '.estimate-total-grand-row');

        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'price' => '50.00'],
            ],
            'shipping' => '10.00',
            'shipping_method' => 'Ground',
            'action' => 'save',
        ]);
        $I->seeCurrentUrlEquals('/admin/estimate/edit/' . $estimate->getId());
        $I->see('Estimate saved.');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertNotNull($saved->getTotal());

        // Re-GET so the scoped assertions below read the saved page: the save answers with JSON,
        // which has none of the markup they look for.
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->see('$' . number_format((float) $saved->getTotal(), 2), '.estimate-total-grand-row');
        $I->dontSee('TBD', '.estimate-total-grand-row');
        $I->see('Subtotal:');
        $I->see('$' . number_format((float) $saved->getSubtotal(), 2));
        $I->see('Shipping:');
        $I->see('$' . number_format($saved->getShippingTotal() ?? 0.0, 2));
        $I->see('Total Tax:');
        $I->see('$' . number_format((float) $saved->getTax(), 2));
        $I->see('Grand Total:');
        $I->see('$' . number_format((float) $saved->getTotal(), 2));
    }

    /** Fee lines are stored as a JSON snapshot on the estimate; sum them so totals can be asserted. */
    /** Fee rows only — shipping rows share the snapshot but the assertions state shipping itself. */
    private function feeTotalOf(Estimate $estimate): float
    {
        $feeLines = json_decode((string) ($estimate->getFeeLines() ?? '[]'), true) ?: [];
        $feeLines = array_filter($feeLines, static fn (array $l): bool => ($l['type'] ?? 'fee') !== 'shipping');

        return (float) array_sum(array_column($feeLines, 'amount'));
    }

    public function updateStatusSucceedsAndGuardsAgainstAnAlreadyAcceptedEstimateAndAnInvalidStatus(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I);
        $submitted = $this->makeEstimate($I, $company, 'Submitted');
        $accepted = $this->makeEstimate($I, $company, 'Accepted');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/update-status/' . $submitted->getId(), [
            '_token' => $I->csrfToken(),'status' => 'Priced']);
        $I->seeResponseCodeIsSuccessful();
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
        $I->seeInRepository(Estimate::class, ['id' => $submitted->getId(), 'status' => 'Priced']);
        $I->seeInRepository(AuditLog::class, ['entityType' => 'Estimate', 'entityId' => $submitted->getId(), 'actorType' => 'document']);

        $I->sendAjaxPostRequest('/admin/estimate/update-status/' . $submitted->getId(), [
            '_token' => $I->csrfToken(),'status' => 'not-a-real-status']);
        $I->seeResponseCodeIs(400);

        $I->sendAjaxPostRequest('/admin/estimate/update-status/' . $accepted->getId(), [
            '_token' => $I->csrfToken(),'status' => 'Rejected']);
        $I->seeResponseCodeIs(403);
        $I->seeInRepository(Estimate::class, ['id' => $accepted->getId(), 'status' => 'Accepted']);

        $I->sendAjaxPostRequest('/admin/estimate/update-status/999999999', [
            '_token' => $I->csrfToken(),'status' => 'Priced']);
        $I->seeResponseCodeIs(404);
    }

    /**
     * #392: staff take a phone/email "go ahead" from the customer and record that acceptance here
     * on their behalf — and, unlike updateStatus(), leave a trace saying it was staff who did it.
     *
     * Accepting creates NOTHING now (owner decision): it moves the status column and stops there.
     * The sales order is the separate Convert to Sales Order action, and the invoice a third step on
     * the order. The whole three-step flow, and the row counts proving each step writes only what it
     * should, is AdminQuoteAcceptAndConvertCest; this test keeps its original subject, which is the
     * #392 audit trail.
     */
    public function acceptOnBehalfOfCustomerMarksThePricedEstimateAcceptedAndLogsTheAcceptingAdmin(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Admin Accept Co');
        $estimate = $this->makeEstimate($I, $company, 'Priced');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/accept/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
        ]);

        // Back to the quote, because there is no order to go to yet.
        $I->seeCurrentUrlEquals('/admin/estimate/detail/' . $estimate->getId());
        $I->see("accepted on the customer's behalf", '.flash-message');

        $I->seeInRepository(Estimate::class, [
            'id' => $estimate->getId(),
            'status' => 'Accepted',
            'convertedOrder' => null,
        ]);
        $I->dontSeeInRepository(SalesOrder::class, ['company' => $company->getId()]);

        // Logged against the quote itself with which admin did it, and that it was staff acting on
        // the customer's behalf rather than the customer's own click — the distinction #392 asks to
        // be able to see.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $logs = $entityManager->getRepository(AuditLog::class)->findBy(['entityType' => 'Estimate', 'entityId' => $estimate->getId(), 'actorType' => 'document']);
        $matching = array_filter($logs, static fn (AuditLog $log): bool => str_contains((string) $log->getActorName(), 'admin-estimate-functional-test@example.test')
            && str_contains((string) $log->getSummary(), "on the customer's behalf"));
        $I->assertNotEmpty($matching, "Expected an AuditLog entry naming the accepting admin and stating this was on the customer's behalf.");
    }

    /** The same Priced-only guard EstimateConversionService::convert() and the customer's own accept() enforce. */
    public function acceptRefusesANonPricedEstimateAndDoesNotCreateAnOrder(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Admin Accept Guard Co');
        $estimate = $this->makeEstimate($I, $company, 'Submitted');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/accept/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
        ]);

        $I->seeCurrentUrlEquals('/admin/estimate/detail/' . $estimate->getId());
        $I->see('This estimate is not ready to accept yet');
        $I->seeInRepository(Estimate::class, ['id' => $estimate->getId(), 'status' => 'Submitted', 'convertedOrder' => null]);
        $I->dontSeeInRepository(SalesOrder::class, ['company' => $company->getId()]);
    }

    /** A retried click after the quote already converted is forwarded to the order it made, not told to retry. */
    public function acceptOnAnAlreadyAcceptedEstimateForwardsToItsExistingOrderWithoutCreatingAnother(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Admin Accept Idempotent Co');
        $estimate = $this->makeEstimate($I, $company, 'Accepted');
        $order = (new SalesOrder())->setCompany($company)->setOrderNumber('SO-ADMINACCEPT-9001')->setTotal('115.00');
        $I->haveInRepository($order);
        $estimate->setConvertedOrder($order);
        $I->haveInRepository($estimate);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/estimate/accept/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
        ]);

        $I->seeCurrentUrlEquals('/admin/order/detail/' . $order->getId());
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $I->assertCount(1, $entityManager->getRepository(SalesOrder::class)->findBy(['company' => $company->getId()]));
    }
}
