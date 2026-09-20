<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Company;
use App\Entity\CustomerUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers AuthAliasController's real HTTP path ('/login' => customer_login_alias), a
 * friendly-URL redirect in front of AuthController's real '/auth/login' (customer_login):
 * guests get bounced to the real login form, already-logged-in customers get bounced home.
 */
final class AuthAliasCest
{
    public function guestIsRedirectedToRealLoginPage(FunctionalTester $I): void
    {
        $I->amOnPage('/login');
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/auth/login');
    }

    public function loggedInCustomerIsRedirectedHome(FunctionalTester $I): void
    {
        $company = (new Company())->setName('Acme Co')->setCode('ACME-' . uniqid());
        $I->haveInRepository($company);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())->setEmail('auth-alias-functional-test@example.test')->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');

        $I->amOnPage('/login');
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/');
    }
}
