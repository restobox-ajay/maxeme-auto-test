<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Company;
use App\Entity\CustomerUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A rate-limited sign-in must say so, not report a generic failure.
 *
 * login_throttling allows 5 attempts per 15 minutes and then throws
 * TooManyLoginAttemptsAuthenticationException. Its message mentions neither credentials nor CSRF, so
 * buildLoginErrorMessage() used to fall through to "We could not sign you in. Please try again." —
 * meaning a *correct* password entered after a few typos was reported exactly like a wrong one.
 *
 * That is how a working authenticator comes to look broken: an admin resets someone's password,
 * watches the correct password get rejected, and has nothing on screen to distinguish a lockout that
 * clears by itself from a genuine fault. The admin login already shows the real message; only the
 * customer side masked it.
 */
final class CustomerLoginThrottleMessageCest
{
    private const LOGIN = '/auth/login';

    private function customer(FunctionalTester $I, string $email, string $password): CustomerUser
    {
        $company = (new Company())->setName('Throttle Co ' . uniqid());
        $I->haveInRepository($company);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new CustomerUser())->setEmail($email);
        $user->setCompany($company);
        $user->setPassword($hasher->hashPassword($user, $password));
        $I->haveInRepository($user);

        return $user;
    }

    private function attempt(FunctionalTester $I, string $email, string $password): void
    {
        $I->amOnPage(self::LOGIN);
        $token = $I->grabAttributeFrom('input[name="_csrf_token"]', 'value');

        // The customer firewall uses _username/_password, not the admin firewall's email/password.
        $I->sendAjaxPostRequest(self::LOGIN, [
            '_token' => $I->csrfToken(),
            '_csrf_token' => $token,
            '_username' => $email,
            '_password' => $password,
        ]);
    }

    public function aWrongPasswordSaysTheCredentialsAreWrong(FunctionalTester $I): void
    {
        $email = 'throttle-one-' . uniqid() . '@example.test';
        $this->customer($I, $email, 'correct-password-123');

        // The failed POST redirects back to the login page and that response carries the error, which
        // is consumed as it renders — so assert here rather than after another visit.
        $this->attempt($I, $email, 'definitely-wrong');
        $I->see('Email or password is incorrect.');
    }

    public function beingRateLimitedSaysSoRatherThanFailingGenerically(FunctionalTester $I): void
    {
        $email = 'throttle-many-' . uniqid() . '@example.test';
        $this->customer($I, $email, 'correct-password-123');

        // Trip the limiter: 5 allowed, so the sixth is refused before the password is even checked.
        for ($i = 0; $i < 6; ++$i) {
            $this->attempt($I, $email, 'wrong-' . $i);
        }

        $this->attempt($I, $email, 'correct-password-123');

        // The point: the correct password now fails, and the page must explain why.
        $I->see('Too many failed sign-in attempts');
        $I->dontSee('We could not sign you in. Please try again.');
    }
}
