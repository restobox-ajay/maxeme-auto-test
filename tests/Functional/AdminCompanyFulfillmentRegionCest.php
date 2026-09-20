<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin\CompanyFulfillmentRegionController — the /admin/company-fulfillment-region
 *  grid (index, including its filters and XHR/JSON mode) and the per-row status/price-list
 *  update() action, guarded by CompanyFulfillmentRegionService::validateActivation(). */
final class AdminCompanyFulfillmentRegionCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-cfr-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())->setName($name)->setCode(strtoupper(substr(md5(uniqid()), 0, 8)));
        $I->haveInRepository($company);

        return $company;
    }

    private function makeRegion(FunctionalTester $I, string $name): FulfillmentRegion
    {
        $region = (new FulfillmentRegion())->setName($name)->setStatus('Active');
        $I->haveInRepository($region);

        return $region;
    }

    private function makePriceList(FunctionalTester $I, string $name): PriceList
    {
        $priceList = (new PriceList())->setName($name)->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        return $priceList;
    }

    private function makeRow(
        FunctionalTester $I,
        Company $company,
        FulfillmentRegion $region,
        string $status = 'Inactive',
        ?PriceList $priceList = null
    ): CompanyFulfillmentRegion {
        $row = (new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($region)
            ->setStatus($status)
            ->setPriceList($priceList);
        $I->haveInRepository($row);

        return $row;
    }

    public function indexListsRowsAndAppliesCompanyFilter(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $companyA = $this->makeCompany($I, 'CFR Index Co Alpha');
        $companyB = $this->makeCompany($I, 'CFR Index Co Beta');
        $region = $this->makeRegion($I, 'CFR Index Region');
        $priceList = $this->makePriceList($I, 'CFR Index Price List');
        $this->makeRow($I, $companyA, $region, 'Active', $priceList);
        $this->makeRow($I, $companyB, $region, 'Inactive');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company-fulfillment-region');
        $I->seeResponseCodeIsSuccessful();
        $I->see('CFR Index Co Alpha');
        $I->see('CFR Index Co Beta');
        $I->see('CFR Index Price List');

        $I->amOnPage('/admin/company-fulfillment-region?filters[company]=Alpha');
        $I->seeResponseCodeIsSuccessful();
        $I->see('CFR Index Co Alpha');
        $I->dontSee('CFR Index Co Beta');
    }

    public function indexAsXhrReturnsJsonWithRenderedRowsAndPagination(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'CFR Xhr Co');
        $region = $this->makeRegion($I, 'CFR Xhr Region');
        $this->makeRow($I, $company, $region, 'Inactive');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxGetRequest('/admin/company-fulfillment-region?filters[company]=CFR+Xhr+Co');
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertStringContainsString('CFR Xhr Co', $response['html']);
        $I->assertSame(1, $response['total']);
        $I->assertSame(1, $response['page']);
        $I->assertSame(1, $response['pages']);
    }

    public function updatingWithAValidTokenAndPriceListActivatesTheRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'CFR Update Co');
        $region = $this->makeRegion($I, 'CFR Update Region');
        $priceList = $this->makePriceList($I, 'CFR Update Price List');
        $row = $this->makeRow($I, $company, $region, 'Inactive');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company-fulfillment-region');
        $token = $I->grabAttributeFrom('input.js-cfr-token[form="cfr-form-' . $row->getId() . '"]', 'value');

        $I->sendAjaxPostRequest('/admin/company-fulfillment-region/' . $row->getId() . '/update', [
            '_token' => $token,
            'status' => 'Active',
            'price_list_id' => (string) $priceList->getId(),
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);
        $I->assertSame('Active', $response['status']);
        $I->assertSame('CFR Update Price List', $response['priceListName']);

        $I->seeInRepository(CompanyFulfillmentRegion::class, [
            'id' => $row->getId(),
            'status' => 'Active',
            'priceList' => $priceList->getId(),
        ]);
    }

    public function activatingWithoutAPriceListFailsValidationAndLeavesRowInactive(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'CFR NoPriceList Co');
        $region = $this->makeRegion($I, 'CFR NoPriceList Region');
        $row = $this->makeRow($I, $company, $region, 'Inactive');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company-fulfillment-region');
        $token = $I->grabAttributeFrom('input.js-cfr-token[form="cfr-form-' . $row->getId() . '"]', 'value');

        $I->sendAjaxPostRequest('/admin/company-fulfillment-region/' . $row->getId() . '/update', [
            '_token' => $token,
            'status' => 'Active',
            'price_list_id' => '',
        ]);
        $I->seeResponseCodeIs(422);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
        $I->assertStringContainsString('CFR NoPriceList Region', $response['message']);

        $I->seeInRepository(CompanyFulfillmentRegion::class, [
            'id' => $row->getId(),
            'status' => 'Inactive',
        ]);
    }

    public function updatingWithAnInvalidCsrfTokenLeavesRowUnchanged(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'CFR BadToken Co');
        $region = $this->makeRegion($I, 'CFR BadToken Region');
        $priceList = $this->makePriceList($I, 'CFR BadToken Price List');
        $row = $this->makeRow($I, $company, $region, 'Inactive');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/company-fulfillment-region/' . $row->getId() . '/update', [
            '_token' => 'not-a-real-token',
            'status' => 'Active',
            'price_list_id' => (string) $priceList->getId(),
        ]);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(CompanyFulfillmentRegion::class, [
            'id' => $row->getId(),
            'status' => 'Inactive',
            'priceList' => null,
        ]);
    }

    /**
     * The not-found branch, actually reached (#594).
     *
     * This used to post `'_token' => 'irrelevant'` and assert 403 — a CSRF refusal, raised on
     * kernel.controller before the controller body ever ran. The row lookup, and the 404 the method
     * is named after, were never executed: the assertion held whatever the unknown-id path did,
     * including a 500 on a null dereference. A real token is what makes the controller run.
     */
    public function updatingAnUnknownRowIdReturnsNotFound(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company');
        $token = $I->csrfToken();
        $I->assertNotSame('', $token, 'without a real token this asserts the CSRF refusal, not the not-found branch');

        $I->sendAjaxPostRequest('/admin/company-fulfillment-region/999999999/update', [
            '_token' => $token,
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIs(404);
    }
}
