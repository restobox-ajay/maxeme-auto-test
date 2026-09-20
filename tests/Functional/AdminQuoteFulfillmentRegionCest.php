<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use App\Entity\SalesOrder;
use App\Service\DocumentActor;
use App\Service\EstimateConversionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The quote form's fulfillment region, and the price list it resolves (#238).
 *
 * The quote form used to suggest prices from whichever ProductPricing row had the lowest id —
 * no company, no region, no price list. A company on a premium list was quoted from a wholesale
 * one, and the order the quote converted into then priced correctly through
 * CompanyFulfillmentRegionService::priceListForCompanyRegion(). The customer was quoted one figure
 * and invoiced another, with nothing in between saying so, on a document sent out for approval.
 *
 * Fixing that needs the quote to know its region, which is why everything below is one story: the
 * region field, the rules it is saved under (#237's, carried onto quotes), and the price list it
 * picks. The rules that matter and are easy to get wrong:
 *
 *   - an ABSENT fulfillment_region means UNCHANGED, never cleared — #237's core bug
 *   - a SUBMITTED region must be one the company has, or the one the quote already holds
 *   - a STALE stored region is kept, pre-selected and warned about, never forced to change
 *   - CREATE is refused when the company has no active region; EDIT is not
 *   - conversion re-prices nothing and checks nothing
 *
 * Enforcement is server-side throughout. The two forms' native `required` attributes now agree with
 * each other and with the server — #248 removed the order form's novalidate, which was switching
 * its copy off — but none of this rests on them, so every POST below is a plain form post that a
 * browser with JavaScript off would send.
 */
final class AdminQuoteFulfillmentRegionCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-quote-region-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('QREGION-' . uniqid())
            ->setPrimaryEmail('buyer@quote-region.example');
        $I->haveInRepository($company);

        return $company;
    }

    private function makePriceList(FunctionalTester $I, string $name): PriceList
    {
        $priceList = (new PriceList())->setName($name . ' ' . uniqid())->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        return $priceList;
    }

    private function makeActiveRegion(FunctionalTester $I, Company $company, string $regionName, ?PriceList $priceList = null): CompanyFulfillmentRegion
    {
        $region = (new FulfillmentRegion())->setName($regionName)->setStatus('Active');
        $I->haveInRepository($region);

        $row = (new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($region)
            ->setStatus('Active')
            ->setPriceList($priceList ?? $this->makePriceList($I, $regionName . ' List'));
        $I->haveInRepository($row);

        return $row;
    }

    /** The company keeps the row (and so the price list) but stops being serviced there. */
    private function deactivateRegion(FunctionalTester $I, CompanyFulfillmentRegion $row): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->find(CompanyFulfillmentRegion::class, $row->getId())->setStatus('Inactive');
        $entityManager->flush();
        $entityManager->clear();
    }

    private function makeProduct(FunctionalTester $I, string $sku, ?string $defaultPrice = null, ?string $originalPrice = null): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Quote Region ' . $sku)
            ->setDefaultPrice($defaultPrice)
            ->setOriginalPrice($originalPrice)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    private function makeQuote(FunctionalTester $I, Company $company, ?string $regionName, ?ProductCore $product = null): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('QREGION-' . uniqid())
            ->setSource('Admin');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $estimate->setFulfillmentRegion($regionName);

        $line = (new EstimateLine())
            ->setName($product?->getName() ?? 'Widget')
            ->setSku($product?->getSku() ?? 'QREGION-WIDGET')
            ->setQuantity('2.00');
        if ($product instanceof ProductCore) {
            $line->setProduct($product);
        }
        $estimate->addLine($line);

        $I->haveInRepository($estimate);

        return $estimate;
    }

    private function reloadQuote(FunctionalTester $I, int $id): Estimate
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Estimate::class, $id);
    }

    /* ── The price list ─────────────────────────────────────────────────────────────────── */

    /**
     * The bug itself. The other price list is persisted FIRST so it holds the lower id — which is
     * exactly what the old findOneBy(['product' => $product], ['id' => 'ASC']) would have returned.
     */
    public function theQuoteFormPricesFromTheCompanysOwnPriceListNotWhicheverRowHasTheLowestId(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Region Priced Co');
        $product = $this->makeProduct($I, 'QREGION-SKU-PRICED', '65.25', '79.99');

        $someoneElsesList = $this->makePriceList($I, 'Quote Region Other List');
        $I->haveInRepository((new ProductPricing())->setProduct($product)->setPriceList($someoneElsesList)->setPrice('12.34'));

        $ourList = $this->makePriceList($I, 'Quote Region Premium List');
        $I->haveInRepository((new ProductPricing())->setProduct($product)->setPriceList($ourList)->setPrice('54.75'));
        $this->makeActiveRegion($I, $company, 'Quote Region Premium', $ourList);

        $estimate = $this->makeQuote($I, $company, 'Quote Region Premium', $product);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select.js-estimate-product-select option[data-sku="QREGION-SKU-PRICED"]', [
            'data-price' => '54.75',
            'data-original-price' => '79.99',
        ]);
    }

    /** Two companies, two lists, one product — the whole point of a price list. */
    public function twoCompaniesOnDifferentPriceListsAreQuotedDifferentPricesForTheSameProduct(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->makeProduct($I, 'QREGION-SKU-TWOCO', '65.25', '79.99');

        $wholesale = $this->makePriceList($I, 'Quote Region Wholesale List');
        $I->haveInRepository((new ProductPricing())->setProduct($product)->setPriceList($wholesale)->setPrice('20.75'));
        $premium = $this->makePriceList($I, 'Quote Region Two Co Premium List');
        $I->haveInRepository((new ProductPricing())->setProduct($product)->setPriceList($premium)->setPrice('90.25'));

        $wholesaleCompany = $this->makeCompany($I, 'Quote Region Wholesale Co');
        $this->makeActiveRegion($I, $wholesaleCompany, 'Quote Region Wholesale Zone', $wholesale);
        $wholesaleQuote = $this->makeQuote($I, $wholesaleCompany, 'Quote Region Wholesale Zone', $product);

        $premiumCompany = $this->makeCompany($I, 'Quote Region Premium Co');
        $this->makeActiveRegion($I, $premiumCompany, 'Quote Region Premium Zone', $premium);
        $premiumQuote = $this->makeQuote($I, $premiumCompany, 'Quote Region Premium Zone', $product);

        $I->amOnPage('/admin/estimate/edit/' . $wholesaleQuote->getId());
        $I->seeElement('select.js-estimate-product-select option[data-sku="QREGION-SKU-TWOCO"]', ['data-price' => '20.75']);

        $I->amOnPage('/admin/estimate/edit/' . $premiumQuote->getId());
        $I->seeElement('select.js-estimate-product-select option[data-sku="QREGION-SKU-TWOCO"]', ['data-price' => '90.25']);
    }

    /**
     * Order's fallback chain, which the quote form now shares verbatim: price list → product
     * default → product original. The company IS on a list; the product simply is not on it.
     */
    public function aProductMissingFromTheCompanysPriceListFallsBackToItsDefaultThenOriginalPrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Region Fallback Co');
        $emptyList = $this->makePriceList($I, 'Quote Region Empty List');
        $this->makeActiveRegion($I, $company, 'Quote Region Fallback Zone', $emptyList);

        $withDefault = $this->makeProduct($I, 'QREGION-SKU-DEFAULT', '65.25', '79.99');
        $originalOnly = $this->makeProduct($I, 'QREGION-SKU-ORIGINAL', null, '49.95');
        $estimate = $this->makeQuote($I, $company, 'Quote Region Fallback Zone', $withDefault);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select.js-estimate-product-select option[data-sku="QREGION-SKU-DEFAULT"]', ['data-price' => '65.25']);
        $I->seeElement('select.js-estimate-product-select option[data-sku="' . $originalOnly->getSku() . '"]', ['data-price' => '49.95']);
    }

    /* ── The field ──────────────────────────────────────────────────────────────────────── */

    public function theCreateFormOffersTheCompanysActiveRegionsAndPersistsTheChosenOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Region Create Co');
        $this->makeActiveRegion($I, $company, 'Quote Region Create East');
        $this->makeActiveRegion($I, $company, 'Quote Region Create West');
        $product = $this->makeProduct($I, 'QREGION-SKU-CREATE', '10.00');

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#estimate-form select[name="fulfillment_region"] option[value="Quote Region Create East"]');
        $I->seeElement('#estimate-form select[name="fulfillment_region"] option[value="Quote Region Create West"]');

        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Quote Region Create West',
            'lines' => [
                0 => ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '10.00'],
            ],
            'save_mode' => 'draft',
        ]);

        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()]);
        $I->seeInRepository(Estimate::class, [
            'id' => $estimate->getId(),
            'fulfillmentRegion' => 'Quote Region Create West',
        ]);
        // …and the edit page it lands on comes back with that region selected.
        $I->seeOptionIsSelected('#estimate-form select[name="fulfillment_region"]', 'Quote Region Create West');
    }

    /**
     * #237's core bug, on quotes: a POST that never carried the field must leave the stored region
     * exactly as it was. It is the API/no-JS save, and the one a company with no regions produces —
     * and a wiped region silently re-prices the document off a different list.
     */
    public function aSaveOmittingTheRegionFieldLeavesTheStoredOneAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Region Absent Co');
        $this->makeActiveRegion($I, $company, 'Quote Region Absent Zone');
        $product = $this->makeProduct($I, 'QREGION-SKU-ABSENT', '10.00');
        $estimate = $this->makeQuote($I, $company, 'Quote Region Absent Zone', $product);
        $line = $estimate->getLines()->first();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '10.00'],
            ],
            'po_number' => 'PO-ABSENT-REGION',
            'action' => 'save',
        ]);

        $I->see('Estimate saved.');
        $saved = $this->reloadQuote($I, (int) $estimate->getId());
        $I->assertSame('Quote Region Absent Zone', $saved->getFulfillmentRegion());
        $I->assertSame('PO-ABSENT-REGION', $saved->getPoNumber());
    }

    /**
     * "Required" only proves the box was non-empty. A region the company has no assignment for
     * resolves to no price list at all, so the next save would price the quote off the product
     * default — the same silent wrong figure this whole issue is about.
     */
    public function aSubmittedRegionTheCompanyDoesNotHaveIsRefusedAndNothingIsSaved(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Region Foreign Co');
        $this->makeActiveRegion($I, $company, 'Quote Region Foreign Home');
        $product = $this->makeProduct($I, 'QREGION-SKU-FOREIGN', '10.00');
        $estimate = $this->makeQuote($I, $company, 'Quote Region Foreign Home', $product);
        $line = $estimate->getLines()->first();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '10.00'],
            ],
            'fulfillment_region' => 'Some Region This Company Never Had',
            'po_number' => 'PO-SHOULD-NOT-STICK',
            'action' => 'save',
        ]);

        $I->see('is not an active fulfillment region for Quote Region Foreign Co');
        // Refused at the door, before applyLinesFromRequest() and the flush inside
        // recomputeFeesAndTax(): nothing about the quote was written.
        $saved = $this->reloadQuote($I, (int) $estimate->getId());
        $I->assertSame('Quote Region Foreign Home', $saved->getFulfillmentRegion());
        $I->assertNull($saved->getPoNumber());
    }

    /** Where a region can be picked, one has to be — that is the requirement being real. */
    public function aBlankRegionIsRefusedWhileTheCompanyHasActiveRegions(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Region Blank Co');
        $this->makeActiveRegion($I, $company, 'Quote Region Blank Zone');
        // TWO active regions, so there is no unambiguous answer for the server to fall back on.
        // With exactly one it picks that one — the same rule the order form applies, and the same
        // one the region field itself uses when it pre-selects a lone option.
        $this->makeActiveRegion($I, $company, 'Quote Region Blank Zone Two');
        $product = $this->makeProduct($I, 'QREGION-SKU-BLANK', '10.00');
        // A legacy quote saved before the field existed: nothing stored, so nothing to fall back on.
        $estimate = $this->makeQuote($I, $company, null, $product);
        $line = $estimate->getLines()->first();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '10.00'],
            ],
            'fulfillment_region' => '',
            'action' => 'save',
        ]);

        $I->see('Select a fulfillment region for Quote Region Blank Co before saving this quote.');
        $I->assertNull($this->reloadQuote($I, (int) $estimate->getId())->getFulfillmentRegion());
    }

    /* ── The stale region ───────────────────────────────────────────────────────────────── */

    /**
     * The case where "required" and "keep the status quo" would otherwise collide. Offering only
     * the live regions would leave nothing selected, and the requirement would then force an admin
     * to re-point a quote in flight before they could correct a PO number. So the stale value is an
     * option, stays selected, satisfies the requirement by itself, and is explained rather than
     * enforced.
     */
    public function aStaleRegionIsPreselectedWarnedAboutAndSavedUnchanged(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Region Stale Co');
        $retired = $this->makeActiveRegion($I, $company, 'Quote Region Retired Zone');
        $this->makeActiveRegion($I, $company, 'Quote Region Live Zone');
        $product = $this->makeProduct($I, 'QREGION-SKU-STALE', '10.00');
        $estimate = $this->makeQuote($I, $company, 'Quote Region Retired Zone', $product);
        $line = $estimate->getLines()->first();

        $this->deactivateRegion($I, $retired);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeOptionIsSelected('#estimate-form select[name="fulfillment_region"]', 'Quote Region Retired Zone');
        $I->seeElement('#estimate-form select[name="fulfillment_region"] option[value="Quote Region Live Zone"]');
        $I->see(
            'Fulfillment Region Quote Region Retired Zone is no longer configured for this customer. This quote can be saved as usual — check whether it is still correct.',
            '.form-warning-banner',
        );

        // Advisory, not a refusal: the save goes through with the stale value posted straight back.
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '10.00'],
            ],
            'fulfillment_region' => 'Quote Region Retired Zone',
            'po_number' => 'PO-STALE-KEPT',
            'action' => 'save',
        ]);

        $I->see('Estimate saved.');
        $saved = $this->reloadQuote($I, (int) $estimate->getId());
        $I->assertSame('Quote Region Retired Zone', $saved->getFulfillmentRegion());
        $I->assertSame('PO-STALE-KEPT', $saved->getPoNumber());
    }

    /**
     * The condition is "stored region is set AND not among the company's active ones" — narrower
     * than both "the company has none" and "the quote has none", either of which would put the
     * warning on quotes it says nothing true about.
     */
    public function theStaleWarningIsAbsentForALiveRegionAndForAQuoteThatHasNoneAtAll(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Region No Warning Co');
        $this->makeActiveRegion($I, $company, 'Quote Region No Warning Zone');
        $product = $this->makeProduct($I, 'QREGION-SKU-NOWARN', '10.00');

        $live = $this->makeQuote($I, $company, 'Quote Region No Warning Zone', $product);
        $I->amOnPage('/admin/estimate/edit/' . $live->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('.form-warning-banner');
        $I->dontSee('is no longer configured for this customer');

        $regionless = $this->makeQuote($I, $company, null, $product);
        $I->amOnPage('/admin/estimate/edit/' . $regionless->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('.form-warning-banner');
        $I->dontSee('is no longer configured for this customer');
    }

    /**
     * The admin these forms are built for has JavaScript off, so the warning has to be in the
     * document the server sent. A banner injected by app.js would pass every assertion above except
     * this one.
     */
    public function theStaleWarningIsServerRenderedRatherThanInjectedByAppJs(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Region No JS Co');
        $retired = $this->makeActiveRegion($I, $company, 'Quote Region No JS Zone');
        $estimate = $this->makeQuote($I, $company, 'Quote Region No JS Zone', $this->makeProduct($I, 'QREGION-SKU-NOJS', '10.00'));
        $this->deactivateRegion($I, $retired);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->assertStringContainsString(
            'is no longer configured for this customer',
            $I->grabPageSource(),
            'the warning must be in the server-rendered HTML, not painted afterwards',
        );

        $appJs = file_get_contents(\dirname(__DIR__, 2) . '/public/assets/js/app.js');
        $I->assertStringNotContainsString('is no longer configured for this customer', (string) $appJs);
    }

    /* ── Create is refused, edit is not ─────────────────────────────────────────────────── */

    /**
     * There is no price list, so there is no correct price to give a new quote. Refusing costs
     * nothing here — nothing is in flight yet.
     */
    public function quoteCreateIsRefusedWhenTheCompanyHasNoActiveRegion(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Region Unserviced Co');
        $product = $this->makeProduct($I, 'QREGION-SKU-REFUSED', '10.00');

        // The page says so before a whole quote has been typed…
        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Quote Region Unserviced Co has no active fulfillment region', '.form-error-banner');

        // …and the save itself is what actually refuses, which is the half that cannot be bypassed.
        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                0 => ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '10.00'],
            ],
            'save_mode' => 'draft',
        ]);

        $I->see('Quote Region Unserviced Co has no active fulfillment region, so there is no price list to quote from.');
        $I->dontSeeInRepository(Estimate::class, ['company' => $company->getId()]);
    }

    /**
     * The other half of the rule, and the one that was withdrawn as a blanket refusal in #237: a
     * quote already in flight stays correctable after its company's regions are deactivated
     * underneath it. It keeps the region it holds, and it is warned about.
     */
    public function quoteEditIsNotRefusedWhenTheCompanyHasNoActiveRegion(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Region Frozen Co');
        $onlyRegion = $this->makeActiveRegion($I, $company, 'Quote Region Frozen Zone');
        $product = $this->makeProduct($I, 'QREGION-SKU-EDITABLE', '10.00');
        $estimate = $this->makeQuote($I, $company, 'Quote Region Frozen Zone', $product);
        $line = $estimate->getLines()->first();

        $this->deactivateRegion($I, $onlyRegion);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('is no longer configured for this customer', '.form-warning-banner');

        // The form posts the stale value back, since it is still the selected option.
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '10.00'],
            ],
            'fulfillment_region' => 'Quote Region Frozen Zone',
            'po_number' => 'PO-STILL-EDITABLE',
            'action' => 'save',
        ]);

        $I->see('Estimate saved.');
        $saved = $this->reloadQuote($I, (int) $estimate->getId());
        $I->assertSame('PO-STILL-EDITABLE', $saved->getPoNumber());
        $I->assertSame('Quote Region Frozen Zone', $saved->getFulfillmentRegion());

        // And a save that omits the field entirely — what a form rendering no select would post —
        // still leaves the region alone rather than wiping it.
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $saved->getLines()->first()->getId(), 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '10.00'],
            ],
            'action' => 'save',
        ]);

        $I->see('Estimate saved.');
        $I->assertSame('Quote Region Frozen Zone', $this->reloadQuote($I, (int) $estimate->getId())->getFulfillmentRegion());
    }

    /* ── Conversion ─────────────────────────────────────────────────────────────────────── */

    /**
     * The end the whole issue exists for: what the customer was quoted is what the order says. The
     * quote is built through the form (so its prices are the ones the price list suggested), priced,
     * and converted — and the order's region, line price and total all have to match.
     */
    public function theQuotedTotalAndTheConvertedOrdersTotalAgreeAndTheRegionRidesAlong(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Region Conversion Co');
        $priceList = $this->makePriceList($I, 'Quote Region Conversion List');
        $product = $this->makeProduct($I, 'QREGION-SKU-CONVERT', '65.25', '79.99');
        $I->haveInRepository((new ProductPricing())->setProduct($product)->setPriceList($priceList)->setPrice('54.75'));
        $this->makeActiveRegion($I, $company, 'Quote Region Conversion Zone', $priceList);

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        // The price the form suggests is the price list's, and it is what gets quoted below.
        $I->seeElement('select.js-estimate-product-select option[data-sku="QREGION-SKU-CONVERT"]', ['data-price' => '54.75']);

        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Quote Region Conversion Zone',
            'lines' => [
                0 => ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '54.75'],
            ],
            'charge_lines_present' => '1',
            'charge_lines' => [['label' => 'Custom Shipping', 'amount' => '30.00', 'type' => 'shipping']],
            'save_mode' => 'draft',
        ]);

        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()]);
        $quoted = $this->reloadQuote($I, (int) $estimate->getId());
        $I->assertSame('Quote Region Conversion Zone', $quoted->getFulfillmentRegion());
        $I->assertEqualsWithDelta(109.5, (float) $quoted->getSubtotal(), 0.001);
        $I->assertNotNull($quoted->getTotal());

        $quoted->setStatus('Priced', DocumentActor::system());
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->flush();

        $order = $I->grabService(EstimateConversionService::class)->convert($quoted, $entityManager);
        $entityManager->flush();
        $orderId = $order->getId();
        $entityManager->clear();

        $converted = $entityManager->find(SalesOrder::class, $orderId);
        $I->assertSame('Quote Region Conversion Zone', $converted->getFulfillmentRegion());
        $I->assertSame($quoted->getTotal(), $converted->getTotal());
        $I->assertSame($quoted->getSubtotal(), $converted->getSubtotal());
        $I->assertEqualsWithDelta(54.75, (float) $converted->getLines()->first()->getPrice(), 0.001);
    }

    /**
     * Conversion is a faithful copy and must stay one: no region check, no re-pricing. An accepted
     * quote becomes an order recording what the customer agreed to, whatever has changed about the
     * company since — including losing the region the quote was priced under.
     */
    public function conversionKeepsTheRegionAndThePricesEvenAfterTheCompanyLosesThatRegion(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Region Faithful Co');
        $priceList = $this->makePriceList($I, 'Quote Region Faithful List');
        $product = $this->makeProduct($I, 'QREGION-SKU-FAITHFUL', '65.25', '79.99');
        $I->haveInRepository((new ProductPricing())->setProduct($product)->setPriceList($priceList)->setPrice('54.75'));
        $row = $this->makeActiveRegion($I, $company, 'Quote Region Faithful Zone', $priceList);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('QREGION-' . uniqid())
            ->setSource('Admin')
            ->setSubtotal('109.50')
            ->setTax('0.00')
            ->setTotal('139.50');
        $estimate->setStatus('Priced', DocumentActor::system());
        $estimate->setFulfillmentRegion('Quote Region Faithful Zone');
        $estimate->setFeeLines(json_encode([[
            'slug' => 'custom-shipping', 'label' => 'Custom Shipping', 'taxClass' => 'G',
            'amount' => 30.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'manual',
        ]]));
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setQuantity('2.00')
                ->setPrice('54.75')
                ->setSubtotal('109.50')
        );
        $I->haveInRepository($estimate);

        $this->deactivateRegion($I, $row);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $stored = $entityManager->find(Estimate::class, $estimate->getId());
        $order = $I->grabService(EstimateConversionService::class)->convert($stored, $entityManager);
        $entityManager->flush();
        $orderId = $order->getId();
        $entityManager->clear();

        $converted = $entityManager->find(SalesOrder::class, $orderId);
        $I->assertSame('Quote Region Faithful Zone', $converted->getFulfillmentRegion());
        // Stored decimals come back from sqlite without their trailing zero, so the comparison is
        // on the figure rather than its spelling.
        $I->assertEqualsWithDelta(139.50, (float) $converted->getTotal(), 0.001);
        $I->assertEqualsWithDelta(54.75, (float) $converted->getLines()->first()->getPrice(), 0.001);
        $I->assertEqualsWithDelta(109.50, (float) $converted->getLines()->first()->getSubtotal(), 0.001);
    }
}
