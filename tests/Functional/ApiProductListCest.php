<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Api\ProductPresenter;
use App\Entity\ApiCredential;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValueProduct;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * GET /api/v1/products — the whole point of which is that it decides nothing.
 *
 * So these tests do not assert prices or visibility rules directly. They assert that the API's
 * answer IS the website's answer: the same key logs in as a real CustomerUser, the request is
 * forwarded to Customer\CatalogController::index(), and what comes back is the rows that controller
 * built. Anywhere the two could differ is a place the API has started reimplementing the site.
 */
final class ApiProductListCest
{
    private const API_KEY = 'thin-wrapper-key-0123456789';
    private const REGION = 'West';

    public function _before(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
    }

    /** @return array{company: Company, owner: CustomerUser, category: ProductCategory, priceList: PriceList} */
    private function seed(FunctionalTester $I): array
    {
        $priceList = (new PriceList())->setName('Wholesale ' . uniqid());
        $I->haveInRepository($priceList);

        $region = (new FulfillmentRegion())->setName(self::REGION);
        $I->haveInRepository($region);

        $company = (new Company())->setName('Acme Co')->setCode('ACME-' . uniqid())->setApiEnabled(true);
        $I->haveInRepository($company);

        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($company)->setFulfillmentRegion($region)
                ->setPriceList($priceList)->setStatus('Active')
        );

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $owner = (new CustomerUser())
            ->setEmail('owner-' . uniqid() . '@example.test')
            ->setFirstName('Pat')->setLastName('Owner')
            ->setCompany($company)->setStatus('Active')
            ->setRoles(['ROLE_COMPANY_OWNER'])
            ->setApiEnabled(true);
        $owner->setPassword($hasher->hashPassword($owner, 'pw-123456789'));
        $I->haveInRepository($owner);

        $category = (new ProductCategory())->setName('Tires ' . uniqid())->setStatus('Visible');
        $I->haveInRepository($category);

        $I->haveInRepository(
            (new ApiCredential())->setCustomerUser($owner)->setApiKey(self::API_KEY)->setStatus(ApiCredential::STATUS_ACTIVE)
        );

        return ['company' => $company, 'owner' => $owner, 'category' => $category, 'priceList' => $priceList];
    }

    private function makeProduct(FunctionalTester $I, ProductCategory $category, string $sku, string $price = '100.00'): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku)->setName('Product ' . $sku)->setCategory($category)
            ->setDefaultPrice($price)->activate()->setVisible(true)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    private function priceRule(FunctionalTester $I, ProductCore $product, PriceList $priceList, ?string $ruleType, ?string $ruleValue = null): void
    {
        $I->haveInRepository(
            (new ProductPricing())->setProduct($product)->setPriceList($priceList)
                ->setRuleType($ruleType)->setRuleValue($ruleValue)->setPrice('0.00')
        );
    }

    /** @return array<string, mixed> */
    private function apiGet(FunctionalTester $I, string $path, ?string $key = self::API_KEY): array
    {
        if ($key !== null) {
            $I->haveHttpHeader('X-Api-Key', $key);
        }
        $I->amOnPage($path);

        return json_decode($I->grabPageSource(), true) ?? [];
    }

    /** @return list<string> */
    private function skus(array $response): array
    {
        return array_map(static fn (array $row): string => $row['sku'], $response['products'] ?? []);
    }

    /**
     * The headline claim. The same account, asked the same question two ways — once as a logged-in
     * browser hitting the catalog, once as an API key — must produce the same products at the same
     * prices. If this ever fails, something in the API has started deciding for itself.
     */
    public function theApiReturnsExactlyWhatTheWebsiteReturnsForTheSameCustomer(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $plain = $this->makeProduct($I, $seed['category'], 'PLAIN-1', '200.00');
        $discounted = $this->makeProduct($I, $seed['category'], 'DISCOUNTED-1', '200.00');
        $this->priceRule($I, $discounted, $seed['priceList'], 'Discount%', '25');

        // What the website gives this customer, straight from the catalog controller.
        $I->amLoggedInAs($seed['owner'], 'main');
        $I->sendAjaxGetRequest('/product/index?region=' . self::REGION);
        $webHtml = json_decode($I->grabPageSource(), true)['html'];

        // What the API gives the same customer.
        $api = $this->apiGet($I, '/api/v1/products?region=' . self::REGION);
        $I->seeResponseCodeIsSuccessful();

        $bySku = [];
        foreach ($api['products'] as $row) {
            $bySku[$row['sku']] = $row;
        }

        $skus = array_keys($bySku);
        sort($skus);
        $I->assertSame(['DISCOUNTED-1', 'PLAIN-1'], $skus);

        // The prices the API reports are the ones the page rendered — asserted against the page's
        // own markup rather than recomputed here, since recomputing is exactly the sin under test.
        // The API emits numbers and the page prints currency, so the number is formatted the way
        // the page formats it purely to look it up; nothing about the amount is re-derived.
        foreach (['PLAIN-1', 'DISCOUNTED-1'] as $sku) {
            $value = $bySku[$sku]['companyPrice'];
            // int OR float: json_encode drops a zero fraction, so 200.0 arrives as 200. What matters
            // is that it is a JSON number at all — the defect was a string that casts to 0.0.
            $I->assertTrue(is_int($value) || is_float($value), 'Money must be a JSON number, not a display string.');
            $I->assertStringContainsString('$' . number_format((float) $value, 2), $webHtml);
        }

        $I->assertEquals(150.0, $bySku['DISCOUNTED-1']['companyPrice']);
        // retailPrice is the Suggested-Price-adjusted figure, a deliberately different number from
        // what this customer pays — it is not derived from companyPrice and must not equal it here.
        $I->assertEquals(200.0, $bySku['DISCOUNTED-1']['retailPrice']);

        // The payload is exactly the declared contract: equality, not subset, so a field silently
        // appearing OR silently vanishing both fail.
        $I->assertSame(ProductPresenter::FIELDS, array_keys($bySku['PLAIN-1']));
    }

    /**
     * "Hide" and "No Price" are the two rules the old hand-rolled endpoint got backwards, in
     * opposite directions. Neither is implemented in the API any more — they come out of the
     * catalog controller — so this is a check that the forward really is reaching it.
     */
    public function hideAndNoPriceBehaveAsTheyDoOnTheWebsite(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $this->makeProduct($I, $seed['category'], 'NORMAL-1');

        $hidden = $this->makeProduct($I, $seed['category'], 'HIDDEN-1');
        $this->priceRule($I, $hidden, $seed['priceList'], 'Hide');

        $noPrice = $this->makeProduct($I, $seed['category'], 'NOPRICE-1');
        $this->priceRule($I, $noPrice, $seed['priceList'], 'No Price');

        $api = $this->apiGet($I, '/api/v1/products?region=' . self::REGION);
        $skus = $this->skus($api);

        $I->assertNotContains('HIDDEN-1', $skus, '"Hide" withholds the product, as on the website.');
        $I->assertContains('NOPRICE-1', $skus, '"No Price" lists the product, as on the website.');

        $byS = [];
        foreach ($api['products'] as $row) {
            $byS[$row['sku']] = $row;
        }
        // The page prints "TBD"; the API says null. Null is the one representation that cannot be
        // mistaken for a price — 0.0, which is what the old display string cast to, very much can.
        $I->assertNull($byS['NOPRICE-1']['companyPrice']);
    }

    public function aProductPrivateToAnotherCompanyIsNotReturned(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $other = (new Company())->setName('Other')->setCode('OTHER-' . uniqid());
        $I->haveInRepository($other);

        $this->makeProduct($I, $seed['category'], 'MINE-1');
        $private = $this->makeProduct($I, $seed['category'], 'THEIRS-1');
        $private->addPrivateCompany($other);
        $I->haveInRepository($private);

        $I->assertSame(['MINE-1'], $this->skus($this->apiGet($I, '/api/v1/products?region=' . self::REGION)));
    }

    /**
     * Search and category are forwarded, not reimplemented — CatalogParams only renames them into
     * the `ProductSearch[...]` shape the storefront form posts.
     *
     * The search assertion is deliberately made against what the website returns for the same term
     * rather than against a hand-written expectation, because the catalog's search is not a plain
     * LIKE: TireNumberSearchMatcher also ORs in digit-substring matches, so "WINTER" and "SUMMER"
     * both match a query of "1". Hardcoding an expectation here would be this test asserting its
     * own idea of search instead of the site's — the exact mistake the whole design is avoiding.
     */
    public function searchAndCategoryParamsAreForwardedToTheCatalog(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $this->makeProduct($I, $seed['category'], 'WINTER-1');
        $this->makeProduct($I, $seed['category'], 'SUMMER-2');

        $other = (new ProductCategory())->setName('Rims ' . uniqid())->setStatus('Visible');
        $I->haveInRepository($other);
        $this->makeProduct($I, $other, 'RIM-3');

        $apiSearch = $this->skus($this->apiGet($I, '/api/v1/products?region=' . self::REGION . '&q=WINTER'));

        $I->amLoggedInAs($seed['owner'], 'main');
        $I->sendAjaxGetRequest('/product/index?region=' . self::REGION . '&q=WINTER');
        $webHtml = json_decode($I->grabPageSource(), true)['html'];

        $I->assertSame(['WINTER-1'], $apiSearch);
        $I->assertStringContainsString('WINTER-1', $webHtml);
        $I->assertStringNotContainsString('SUMMER-2', $webHtml);

        $inCategory = $this->skus($this->apiGet($I, '/api/v1/products?region=' . self::REGION . '&category=' . $seed['category']->getId()));
        $I->assertContains('WINTER-1', $inCategory);
        $I->assertNotContains('RIM-3', $inCategory);
    }

    /**
     * A region outside the customer's allowed set is refused — by the catalog controller's own
     * resolveBrowsingRegion() guard, not by anything in the API. Asserted precisely because the API
     * must NEVER grow its own idea of a valid region: a second opinion here could disagree with
     * allowedRegionNames(), which is the failure mode this whole design exists to remove.
     */
    public function aRegionTheCustomerIsNotAllowedIsRefusedByTheCatalogsOwnRule(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $this->makeProduct($I, $seed['category'], 'ANY-1');

        $body = $this->apiGet($I, '/api/v1/products?region=Nowhere');
        $I->seeResponseCodeIs(403);
        $I->assertTrue($body['blocked']);

        // And the website blocks the same request for the same customer, which is the actual claim.
        $I->amLoggedInAs($seed['owner'], 'main');
        $I->sendAjaxGetRequest('/product/index?region=Nowhere');
        $I->seeResponseCodeIs(403);
    }

    /**
     * A filter that does not filter is worse than one that errors: F6 shipped because
     * `filters[<slug>]` was written a level above where the controller reads, so the API answered
     * 200 with the whole catalog and a `total` covering all of it. An integrator paging through what
     * they believed was a filtered result silently received everything.
     *
     * It survived a green suite because only `q` and `category` were ever tested, and those take a
     * different path. So this asserts the thing that was missing: a filter reaching the query.
     */
    public function aFacetFilterActuallyReachesTheQuery(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $match = $this->makeProduct($I, $seed['category'], 'FILTER-HIT');
        $this->makeProduct($I, $seed['category'], 'FILTER-MISS');

        $definition = (new CustomFieldDefinition())
            ->setObjectType(CustomFieldDefinition::OBJECT_TYPE_PRODUCT)
            ->setSlug('rim_pcd')->setLabel('PCD')->setFieldType('text')
            ->setVisibleOnListing(true)->setSearchable(true);
        $I->haveInRepository($definition);
        $I->haveInRepository(
            (new CustomFieldValueProduct())->setDefinition($definition)->setProduct($match)->setValue('5x112')
        );

        $api = $this->apiGet($I, '/api/v1/products?region=' . self::REGION . '&cf[rim_pcd]=5x112');
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(['FILTER-HIT'], $this->skus($api), 'The filter must narrow the result.');
        // total has to agree with the filter too — the old bug reported the unfiltered count, which
        // is what makes an integrator page through data they were never shown.
        $I->assertSame(1, $api['total']);
    }

    /**
     * Only declared parameters are accepted. An undeclared key must be ignored outright rather than
     * copied into the controller's internal array, where it could shadow a real one — the old loop
     * let `filters[category_id]` beat the declared `category`.
     */
    public function undeclaredParametersCannotShadowDeclaredOnes(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $this->makeProduct($I, $seed['category'], 'IN-CATEGORY');

        $other = (new ProductCategory())->setName('Other ' . uniqid())->setStatus('Visible');
        $I->haveInRepository($other);
        $this->makeProduct($I, $other, 'OTHER-CATEGORY');

        $api = $this->apiGet(
            $I,
            '/api/v1/products?region=' . self::REGION
                . '&category=' . $seed['category']->getId()
                . '&filters[category_id]=' . $other->getId()
        );
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(['IN-CATEGORY'], $this->skus($api), 'The declared category must win.');
    }

    /**
     * Filters legitimately arrive as arrays (`cf[slug][]=x`), and they are read with
     * `query->all()`, which — unlike `query->get()` — does not reject a nested one. So this is the
     * one place a non-scalar can actually reach our code, and the guard that drops it rather than
     * string-casting it is ours to test.
     *
     * The scalar parameters are deliberately not covered here: Symfony's query bag refuses an array
     * on those before any of this code runs, so a test would be asserting the framework.
     */
    public function aNestedArrayInsideAFilterIsDroppedRatherThanCast(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $this->makeProduct($I, $seed['category'], 'NESTED-1');

        // cf[rim_pcd][][] — one level deeper than the facet checkboxes ever submit.
        $api = $this->apiGet($I, '/api/v1/products?region=' . self::REGION . '&cf[rim_pcd][][]=5x112');
        $I->seeResponseCodeIsSuccessful();

        // Dropped, so the request behaves as though no filter was given at all. The alternative —
        // casting it — is the TypeError the old pass-through would have hit.
        $I->assertSame(['NESTED-1'], $this->skus($api));
    }

    /**
     * A single-region company needs no parameter — there is nothing to disambiguate. This is the
     * half of D3 that must stay effortless, or every integration for a one-warehouse customer pays
     * for a distinction that does not apply to them.
     */
    public function aSingleRegionCompanyNeedsNoRegionParameter(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $this->makeProduct($I, $seed['category'], 'ONLY-1');

        $api = $this->apiGet($I, '/api/v1/products');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(['ONLY-1'], $this->skus($api));
        $I->assertSame(self::REGION, $api['region']);
    }

    /**
     * A multi-region company must say which one. Guessing "first allowed" would quote a price for
     * whichever region happened to sort first, silently disagreeing with what that same account
     * sees on the site — the API deliberately refuses where the website falls back, because
     * returning less is safe and returning the wrong price is not.
     */
    public function aMultiRegionCompanyMustSpecifyRegionAndIsToldHow(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $this->makeProduct($I, $seed['category'], 'MULTI-1');

        $east = (new FulfillmentRegion())->setName('East');
        $I->haveInRepository($east);
        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($seed['company'])->setFulfillmentRegion($east)
                ->setPriceList($seed['priceList'])->setStatus('Active')
        );

        $body = $this->apiGet($I, '/api/v1/products');
        $I->seeResponseCodeIs(400);
        $I->assertSame('region', $body['parameter'], 'The response must name the parameter that is missing.');
        $I->assertContains(self::REGION, $body['allowedValues']);
        $I->assertContains('East', $body['allowedValues'], 'And the values that would work.');

        // Naming one resolves it.
        $I->assertSame(['MULTI-1'], $this->skus($this->apiGet($I, '/api/v1/products?region=East')));
    }

    /**
     * The /api/ firewall is declared stateless. Serving the catalog used to write the browsing
     * region into the session, which starts one — so every call emitted a Set-Cookie the client
     * never returns and left an orphan session file behind, forever.
     */
    public function anApiRequestNeverStartsASession(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $this->makeProduct($I, $seed['category'], 'NOSESSION-1');

        $this->apiGet($I, '/api/v1/products?region=' . self::REGION);
        $I->seeResponseCodeIsSuccessful();

        // Asserted on the RESPONSE HEADER, because the session object is unreachable by the time a
        // test runs and the probe that used to stand here could not fail (#594). HttpKernel::handle()
        // pops the request off RequestStack in a `finally`, so `getSession()` on the finished stack
        // always throws SessionNotFoundException, the catch always ran, and `$started` was always
        // false — the try branch was dead code. Putting the region back into the session left this
        // green.
        //
        // A started session is emitted as a Set-Cookie for the session id, which is exactly the
        // orphan-cookie symptom this test is named after, and it is still in the headers afterwards.
        $I->assertStringNotContainsString(
            'MOCKSESSID',
            $I->grabResponseHeader('Set-Cookie'),
            'A stateless API request must not start a session: starting one emits a session cookie the client never returns.',
        );
    }

    // --- auth ---------------------------------------------------------------------------------

    public function aRequestWithNoKeyIsRejected(FunctionalTester $I): void
    {
        $this->seed($I);
        $this->apiGet($I, '/api/v1/products?region=' . self::REGION, null);
        $I->seeResponseCodeIs(401);
    }

    public function anUnknownKeyIsRejected(FunctionalTester $I): void
    {
        $this->seed($I);
        $this->apiGet($I, '/api/v1/products?region=' . self::REGION, 'nope');
        $I->seeResponseCodeIs(401);
    }

    /**
     * The key IS the user, so anything that stops them logging in stops the key. No separate "is
     * this key still allowed" state to keep in step with the account.
     */
    public function aKeyBelongingToAnInactiveUserIsRejected(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $seed['owner']->setStatus('Inactive');
        $I->haveInRepository($seed['owner']);

        $this->apiGet($I, '/api/v1/products?region=' . self::REGION);
        $I->seeResponseCodeIs(401);
    }

    /**
     * The two admin gates, each answering 403 with its own reason. A valid key being refused is a
     * different problem from an invalid key, and the company case is a different problem from the
     * user case — support cannot act on "authentication failed".
     */
    public function aDisabledCompanyIsRefusedWithItsOwnReason(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $seed['company']->setApiEnabled(false);
        $I->haveInRepository($seed['company']);

        $body = $this->apiGet($I, '/api/v1/products?region=' . self::REGION);
        $I->seeResponseCodeIs(403);
        $I->assertStringContainsString("Your company's API access has been disabled", $body['error']);
    }

    public function aDisabledUserInsideAnEnabledCompanyIsRefusedSeparately(FunctionalTester $I): void
    {
        $seed = $this->seed($I);
        $seed['owner']->setApiEnabled(false);
        $I->haveInRepository($seed['owner']);

        $body = $this->apiGet($I, '/api/v1/products?region=' . self::REGION);
        $I->seeResponseCodeIs(403);
        $I->assertStringContainsString('Your API access has been disabled', $body['error']);
        // Distinct from the company refusal, so the caller knows who to ask.
        $I->assertStringNotContainsString("Your company's API access", $body['error']);
    }

    /** A key its owner revoked is 401, not 403 — a third distinguishable failure. */
    public function aRevokedKeyIsRejectedAsInvalidRatherThanDisabled(FunctionalTester $I): void
    {
        $this->seed($I);
        $credential = $I->grabEntityFromRepository(ApiCredential::class, ['apiKey' => self::API_KEY]);
        $credential->setStatus(ApiCredential::STATUS_REVOKED);
        $I->haveInRepository($credential);

        $body = $this->apiGet($I, '/api/v1/products?region=' . self::REGION);
        $I->seeResponseCodeIs(401);
        $I->assertStringContainsString('revoked', $body['error']);
    }
}
