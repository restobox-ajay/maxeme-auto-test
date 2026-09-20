<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\SalesTax;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * /admin/sales-tax with a filter or a sort (#767).
 *
 * SalesTaxRepository::search() referenced self::MAX_DECIMALS_MAP, a constant that was never
 * defined anywhere in the class — every search(), filter or sort threw an undefined-constant error
 * before returning a single row. The grid's own plain GET (no filter, no sort) never hit this: it
 * still passes filters=[] and sort=null through the same method, but the loops both constants would
 * have driven never execute for an empty filter set and a null sort, so the smoke case that "the
 * page loads" was never enough to catch it.
 */
final class AdminSalesTaxFiltersCest
{
    public function filteringByProvinceNameFindsTheMatchingRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seed($I, 'Ontario', 'ON', 'HST', 13.0);
        $this->seed($I, 'Alberta', 'AB', 'GST', 5.0);

        $I->amOnPage('/admin/sales-tax?filters%5Bprovince_name%5D=Ontario');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Ontario');
        $I->dontSee('Alberta');
    }

    public function sortingByRateDoesNot500(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seed($I, 'Ontario', 'ON', 'HST', 13.0);
        $this->seed($I, 'Alberta', 'AB', 'GST', 5.0);

        $I->amOnPage('/admin/sales-tax?sort=rate&dir=asc');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Alberta');
        $I->see('Ontario');
    }

    public function searchingMatchesAcrossEveryColumnTheGridSearches(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seed($I, 'Ontario', 'ON', 'HST', 13.0);
        $this->seed($I, 'Alberta', 'AB', 'GST', 5.0);

        $I->amOnPage('/admin/sales-tax?q=HST');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Ontario');
        $I->dontSee('Alberta');
    }

    private function seed(FunctionalTester $I, string $province, string $abbreviation, string $taxType, float $rate): SalesTax
    {
        $tax = (new SalesTax())
            ->setProvinceName($province)
            ->setAbbreviation($abbreviation)
            ->setTaxType($taxType)
            ->setRate($rate)
            ->setStatus('Active')
            ->setSlug(strtolower($abbreviation) . '-' . uniqid());
        $I->haveInRepository($tax);

        return $tax;
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('sales-tax-filters-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }
}
