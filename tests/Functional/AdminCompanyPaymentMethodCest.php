<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyPaymentMethod;
use App\Entity\PaymentMethod;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin\CompanyPaymentMethodController — the per-company
 *  /admin/company/{id}/payment-methods page and its per-row Active/Inactive toggle. The standalone
 *  /admin/company-payment-methods grant matrix (index + bulk-save) was removed as a duplicate — see
 *  issue #123. */
final class AdminCompanyPaymentMethodCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-cpm-functional-test@example.test');
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

    private function makePaymentMethod(FunctionalTester $I, string $name): PaymentMethod
    {
        $paymentMethod = (new PaymentMethod())->setSlug(strtolower($name) . '-' . uniqid())->setName($name)->setSource('TestBundle');
        $I->haveInRepository($paymentMethod);

        return $paymentMethod;
    }

    public function theStandaloneGrantMatrixIsGone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company-payment-methods');
        $I->seeResponseCodeIs(404);
    }

    public function forCompanyShowsEveryPaymentMethodWithGrantedStatusOrDefaultActive(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'CPM ForCompany Co');
        $grantedMethod = $this->makePaymentMethod($I, 'CPM Granted Method');
        $ungrantedMethod = $this->makePaymentMethod($I, 'CPM Ungranted Method');
        $grant = (new CompanyPaymentMethod())->setCompany($company)->setPaymentMethod($grantedMethod)->setStatus(CompanyPaymentMethod::STATUS_INACTIVE);
        $I->haveInRepository($grant);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/' . $company->getId() . '/payment-methods');
        $I->seeResponseCodeIsSuccessful();

        $I->see('CPM ForCompany Co');
        $I->see('CPM Granted Method');
        $I->see('CPM Ungranted Method');
        $I->see('Inactive');
    }

    public function togglingFlipsStatusAndReturnsToThePerCompanyPage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'CPM Toggle Co');
        $paymentMethod = $this->makePaymentMethod($I, 'CPM Toggle Method');
        $grant = (new CompanyPaymentMethod())->setCompany($company)->setPaymentMethod($paymentMethod)->setStatus(CompanyPaymentMethod::STATUS_ACTIVE);
        $I->haveInRepository($grant);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/' . $company->getId() . '/payment-methods');
        $I->seeResponseCodeIsSuccessful();

        $token = $I->grabAttributeFrom(
            'form[action$="/' . $company->getId() . '/' . $paymentMethod->getId() . '/toggle"] input[name="_token"]',
            'value'
        );

        $I->sendAjaxPostRequest('/admin/company-payment-methods/' . $company->getId() . '/' . $paymentMethod->getId() . '/toggle', [
            '_token' => $token,
            'return' => 'company',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin/company/' . $company->getId() . '/payment-methods');

        $I->seeInRepository(CompanyPaymentMethod::class, [
            'company' => $company->getId(),
            'paymentMethod' => $paymentMethod->getId(),
            'status' => CompanyPaymentMethod::STATUS_INACTIVE,
        ]);
    }

    public function togglingWithAnInvalidCsrfTokenLeavesStatusUnchanged(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'CPM Toggle Bad Token Co');
        $paymentMethod = $this->makePaymentMethod($I, 'CPM Toggle Bad Token Method');
        $grant = (new CompanyPaymentMethod())->setCompany($company)->setPaymentMethod($paymentMethod)->setStatus(CompanyPaymentMethod::STATUS_ACTIVE);
        $I->haveInRepository($grant);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/' . $company->getId() . '/payment-methods');
        $I->seeResponseCodeIsSuccessful();

        $I->sendAjaxPostRequest('/admin/company-payment-methods/' . $company->getId() . '/' . $paymentMethod->getId() . '/toggle', [
            '_token' => 'not-a-real-token',
            'return' => 'company',
        ]);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(CompanyPaymentMethod::class, [
            'company' => $company->getId(),
            'paymentMethod' => $paymentMethod->getId(),
            'status' => CompanyPaymentMethod::STATUS_ACTIVE,
        ]);
    }

    public function forCompanyWithAnUnknownCompanyIdRedirectsToTheCompanyIndex(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/999999999/payment-methods');
        $I->seeCurrentUrlEquals('/admin/company');
    }
}
