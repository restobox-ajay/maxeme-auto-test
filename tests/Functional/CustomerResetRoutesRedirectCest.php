<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Company;
use App\Entity\CustomerUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A signed-in customer is sent home from the reset routes, as they already are from login and
 * register. Before this the reset form rendered for them — a dead end implying they were logged out.
 * See issue #91.
 */
final class CustomerResetRoutesRedirectCest
{
    private function loginAsCustomer(FunctionalTester $I): void
    {
        $company = (new Company())->setName('Reset Redirect Co ' . uniqid());
        $I->haveInRepository($company);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new CustomerUser())->setEmail('reset-redirect-' . uniqid() . '@example.test');
        $user->setCompany($company);
        $user->setPassword($hasher->hashPassword($user, 'test-password-123'));
        $I->haveInRepository($user);

        $I->amLoggedInAs($user, 'main');
    }

    public function theResetRoutesRedirectASignedInCustomer(FunctionalTester $I): void
    {
        $this->loginAsCustomer($I);

        foreach (['/auth/password-reset', '/auth/forgot-password'] as $url) {
            $I->stopFollowingRedirects();
            $I->amOnPage($url);
            $I->seeResponseCodeIs(302);
            $I->startFollowingRedirects();
        }
    }

    public function aSignedOutVisitorStillGetsTheForm(FunctionalTester $I): void
    {
        // The whole point of the route: it must still work for the people it is for.
        $I->amOnPage('/auth/password-reset');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('form');
    }
}
