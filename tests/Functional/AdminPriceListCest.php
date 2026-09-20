<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin\PriceListController — the /admin/price-list index (search, XHR/JSON mode,
 *  company-assignment roll-up), create()/update() form validation, and delete(). */
final class AdminPriceListCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-pl-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makePriceList(FunctionalTester $I, string $name, string $status = 'Active'): PriceList
    {
        $priceList = (new PriceList())->setName($name)->setCurrency('USD')->setStatus($status);
        $I->haveInRepository($priceList);

        return $priceList;
    }

    /** The AJAX delete token rides on the row button rather than a form, so it is read off the
     *  rendered index the same way the browser's JS reads it. */
    private function grabDeleteToken(FunctionalTester $I, int $id): string
    {
        return (string) $I->grabAttributeFrom('.js-delete[data-url$="/delete/' . $id . '"]', 'data-token');
    }

    private function assignCompanyToPriceList(FunctionalTester $I, PriceList $priceList, string $companyName): void
    {
        $company = (new Company())->setName($companyName)->setCode(strtoupper(substr(md5(uniqid()), 0, 8)));
        $I->haveInRepository($company);

        $region = (new FulfillmentRegion())->setName($companyName . ' Region')->setStatus('Active');
        $I->haveInRepository($region);

        $row = (new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($region)
            ->setStatus('Active')
            ->setPriceList($priceList);
        $I->haveInRepository($row);
    }

    public function indexListsPriceListsWithAssignedCompaniesAndAppliesSearch(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'PL Index Wholesale');
        $this->assignCompanyToPriceList($I, $priceList, 'PL Index Assigned Co');
        $this->makePriceList($I, 'PL Index Retail');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/index');
        $I->seeResponseCodeIsSuccessful();
        $I->see('PL Index Wholesale');
        $I->see('PL Index Retail');
        $I->see('PL Index Assigned Co');

        $I->amOnPage('/admin/price-list/index?q=Wholesale');
        $I->seeResponseCodeIsSuccessful();
        $I->see('PL Index Wholesale');
        $I->dontSee('PL Index Retail');
    }

    public function indexAsXhrReturnsJsonWithRenderedRowsAndPagination(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->makePriceList($I, 'PL Xhr Price List');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxGetRequest('/admin/price-list/index?q=PL+Xhr+Price+List');
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertStringContainsString('PL Xhr Price List', $response['html']);
        $I->assertSame(1, $response['total']);
        $I->assertSame(1, $response['page']);
        $I->assertSame(1, $response['pages']);
    }

    public function createRendersTheFormAndDefaultsCurrencyToUsd(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/create');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Create');
        $I->seeInField('currency', 'USD');
    }

    /** When the store has a configured base_currency, the create form defaults to it instead of USD. */
    public function createDefaultsCurrencyToTheConfiguredBaseCurrency(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->persist(
            (new AppSetting())->setSettingKey('base_currency')->setName('Base Currency')->setSettingValue('CAD')
        );
        $entityManager->flush();
        $I->grabService(AppSettings::class)->clearCache();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/create');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('currency', 'CAD');
    }

    /** A blank/missing currency on submit falls back to the configured base_currency, not a hardcoded USD. */
    public function creatingWithNoCurrencySubmittedUsesTheConfiguredBaseCurrency(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->persist(
            (new AppSetting())->setSettingKey('base_currency')->setName('Base Currency')->setSettingValue('EUR')
        );
        $entityManager->flush();
        $I->grabService(AppSettings::class)->clearCache();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/create');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/price-list/create', [
            '_token' => $token,
            'name' => 'No Currency Submitted',
            'status' => 'Active',
        ]);

        $priceList = $entityManager->getRepository(PriceList::class)->findOneBy(['name' => 'No Currency Submitted']);
        $I->assertNotNull($priceList);
        $I->assertSame('EUR', $priceList->getCurrency());
    }

    public function creatingWithValidDataPersistsAndRedirectsToIndex(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/create');
        $I->sendAjaxPostRequest('/admin/price-list/create', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => 'PL Created List',
            'currency' => 'cad',
            'status' => 'Inactive',
        ]);
        $I->seeCurrentUrlEquals('/admin/price-list/index');

        $I->seeInRepository(PriceList::class, [
            'name' => 'PL Created List',
            'currency' => 'CAD',
            'status' => 'Inactive',
        ]);
    }

    public function creatingWithABlankNameFailsValidationAndDoesNotPersist(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/create');
        $I->sendAjaxPostRequest('/admin/price-list/create', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => '   ',
            'currency' => 'USD',
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('Price list name is required.');

        $I->dontSeeInRepository(PriceList::class, ['currency' => 'USD', 'status' => 'Active', 'name' => '']);
    }

    public function creatingWithAnInvalidCurrencyFailsValidation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/create');
        $I->sendAjaxPostRequest('/admin/price-list/create', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => 'PL Bad Currency List',
            'currency' => 'DOLLARS',
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('Currency must be a 3-letter code');

        $I->dontSeeInRepository(PriceList::class, ['name' => 'PL Bad Currency List']);
    }

    public function updateRendersThePrefilledFormAndPersistsChanges(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'PL Update Original', 'Active');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/update/' . $priceList->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('name', 'PL Update Original');

        $I->sendAjaxPostRequest('/admin/price-list/update/' . $priceList->getId(), [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => 'PL Update Renamed',
            'currency' => 'USD',
            'status' => 'Inactive',
        ]);
        $I->seeCurrentUrlEquals('/admin/price-list/index');

        $I->seeInRepository(PriceList::class, [
            'id' => $priceList->getId(),
            'name' => 'PL Update Renamed',
            'status' => 'Inactive',
        ]);
    }

    public function updatingWithABlankNameFailsValidationAndLeavesTheOriginalNameInPlace(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'PL Update Invalid Original', 'Active');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/update/' . $priceList->getId());
        // Currency and status are deliberately DIFFERENT from the stored ones, so a controller that
        // applied the fields before validating the name is visible (#594). Posting the same values
        // back, and asserting only the 422 and the message, could not tell a refusal that wrote
        // nothing from one that wrote everything except the name.
        $I->sendAjaxPostRequest('/admin/price-list/update/' . $priceList->getId(), [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => '',
            'currency' => 'CAD',
            'status' => 'Inactive',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('Price list name is required.');

        $I->seeInRepository(PriceList::class, [
            'id' => $priceList->getId(),
            'name' => 'PL Update Invalid Original',
            'currency' => 'USD',
            'status' => 'Active',
        ]);
    }

    public function updatingAnUnknownIdRedirectsToIndexWithAnErrorFlash(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/update/999999999');
        $I->seeCurrentUrlEquals('/admin/price-list/index');
        $I->see('Price list could not be found.');
    }

    public function deletingAnExistingPriceListRemovesItAndReturnsJsonSuccess(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'PL Delete Me', 'Active');
        $id = $priceList->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/index');
        $I->sendAjaxPostRequest('/admin/price-list/delete/' . $id, [
            '_token' => $this->grabDeleteToken($I, $id),
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);
        $I->assertStringContainsString('PL Delete Me', $response['message']);

        $I->dontSeeInRepository(PriceList::class, ['id' => $id]);
    }

    public function deletingAnUnknownIdReturnsJsonNotFound(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'PL Delete Vanishing', 'Active');
        $id = $priceList->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/index');
        // Tokens are bound to the row id, so reaching the not-found branch means holding a real
        // token for an id that no longer exists.
        $token = $this->grabDeleteToken($I, $id);
        $entityManager = $I->grabService('doctrine.orm.entity_manager');
        $entityManager->remove($entityManager->find(PriceList::class, $id));
        $entityManager->flush();

        $I->sendAjaxPostRequest('/admin/price-list/delete/' . $id, ['_token' => $token]);
        $I->seeResponseCodeIs(404);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
    }

    /** Issue: a delete on a row another table still points at (here, a company's fulfillment-region
     *  assignment) must refuse with a 409 the admin can read, never a bare 500 from an uncaught
     *  ForeignKeyConstraintViolationException. */
    public function deletingAPriceListStillAssignedToACompanyReturnsAConflictAndKeepsEverything(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $referenced = $this->makePriceList($I, 'PL Referenced By Company', 'Active');
        $this->assignCompanyToPriceList($I, $referenced, 'PL Conflict Co');
        $sibling = $this->makePriceList($I, 'PL Conflict Sibling', 'Active');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/index');
        $token = $this->grabDeleteToken($I, $referenced->getId());

        $I->sendAjaxPostRequest('/admin/price-list/delete/' . $referenced->getId(), ['_token' => $token]);
        $I->seeResponseCodeIs(409);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
        $I->assertStringContainsString('PL Referenced By Company', $response['message']);

        // The row survives, and so does the assignment that blocked the delete.
        $I->seeInRepository(PriceList::class, ['id' => $referenced->getId(), 'name' => 'PL Referenced By Company']);
        $I->seeInRepository(CompanyFulfillmentRegion::class, ['priceList' => $referenced->getId()]);

        // The FK exception closes the entity manager; a fresh one is needed for the rest of this
        // request/response cycle to read the database again.
        $I->grabService('doctrine')->resetManager();

        // A sibling with no assignment is untouched by the refused delete and can still be removed.
        // The token is the app's one global CSRF token (App\Security\Csrf\Csrf), not per-row, so the
        // same value read earlier is still good here — no page reload needed to fetch another.
        $I->seeInRepository(PriceList::class, ['id' => $sibling->getId()]);
        $I->sendAjaxPostRequest('/admin/price-list/delete/' . $sibling->getId(), ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeInRepository(PriceList::class, ['id' => $sibling->getId()]);

        // Repeating the original delete is still a 409, not a second, different failure.
        $I->sendAjaxPostRequest('/admin/price-list/delete/' . $referenced->getId(), ['_token' => $token]);
        $I->seeResponseCodeIs(409);
        $I->seeInRepository(PriceList::class, ['id' => $referenced->getId()]);
    }

    public function deletingWithoutAValidTokenIsRejectedAndKeepsThePriceList(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'PL Delete Forged', 'Active');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/price-list/delete/' . $priceList->getId(), ['_token' => 'forged']);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(PriceList::class, ['id' => $priceList->getId()]);
    }

    public function creatingWithoutAValidTokenIsRejectedAndPersistsNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/create');
        $I->sendAjaxPostRequest('/admin/price-list/create', [
            '_token' => 'forged',
            'name' => 'PL Forged Create',
            'currency' => 'USD',
            'status' => 'Active',
        ]);

        $I->dontSeeInRepository(PriceList::class, ['name' => 'PL Forged Create']);
    }

    public function updatingWithoutAValidTokenIsRejectedAndLeavesTheRowAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'PL Forged Update Original', 'Active');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/price-list/update/' . $priceList->getId());
        $I->sendAjaxPostRequest('/admin/price-list/update/' . $priceList->getId(), [
            '_token' => 'forged',
            'name' => 'PL Forged Update Renamed',
            'currency' => 'USD',
            'status' => 'Inactive',
        ]);

        $I->seeInRepository(PriceList::class, [
            'id' => $priceList->getId(),
            'name' => 'PL Forged Update Original',
            'status' => 'Active',
        ]);
    }
}
