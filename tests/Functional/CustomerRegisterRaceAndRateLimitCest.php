<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Company;
use App\Entity\CustomerUser;
use Doctrine\ORM\EntityManagerInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers the two guest-registration defects on POST /auth/register:
 *
 *  - #332, the duplicate-email pre-check being a non-atomic read-then-write, so the loser of a
 *    race is answered by the unique index rather than by the pre-check, and used to be shown a
 *    generic "Registration failed" plus the raw SQLSTATE text instead of the graceful field error.
 *  - #336, the endpoint having no rate limit of any kind, so an unbounded number of POSTs each
 *    bought a password hash, four INSERTs, a mail to an attacker-chosen address and a mail to
 *    every admin.
 *
 * Every test here clears cache.rate_limiter first, for the same reason CustomerAuthCest and
 * AdminAuthCest already do: limiter state is a filesystem cache pool that outlives both the test's
 * database transaction and the suite run itself, so without it these tests inherit whatever quota
 * an earlier test (or an earlier run) already spent from the same per-IP bucket.
 */
final class CustomerRegisterRaceAndRateLimitCest
{
    /**
     * #332, driven for real rather than by fabricating the exception.
     *
     * A true concurrent collision is not reachable from a functional test: SQLite locks the whole
     * database for writing and the suite runs one process inside one transaction, so there is no
     * second writer to race against. What IS reachable is the exact interleaving that matters — a
     * conflicting row appearing between register()'s SELECT pre-check and its flush. An onFlush
     * listener occupies precisely that gap: Doctrine dispatches onFlush after computing the change
     * set and *before* UnitOfWork::commit() opens its transaction, so an INSERT issued from there
     * lands on the connection ahead of the ORM's own and is committed independently of it.
     *
     * So the violation this asserts on is genuine: a real second row, a real INSERT executed by
     * the ORM, a real uniq_customer_user_email failure raised by the real SQLite driver, arriving
     * at the controller as a real UniqueConstraintViolationException. Only the *scheduling* of the
     * competing write is simulated.
     */
    public function aRegistrationLosingTheDuplicateEmailRaceGetsTheGracefulFieldError(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
        // Its own IP, like every test here: the #336 limiter is consumed before the block that
        // flushes, so even a registration that goes on to lose the race spends quota. Sharing the
        // default REMOTE_ADDR would silently hand part of that bill to whichever other test in the
        // suite registers next.
        $I->haveServerParameter('REMOTE_ADDR', '203.0.113.1');

        $contestedEmail = 'auth-register-race-test@example.test';
        $this->raceInAConflictingRowAtFlushTime($I, $contestedEmail);

        $I->amOnPage('/auth/register');
        $I->sendFormPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
            '_token' => $I->csrfToken(),
            'user_email' => $contestedEmail,
        ]));

        $I->seeResponseCodeIsSuccessful();
        // Guards the test against passing vacuously: if the trigger did not fire, the flush would
        // have succeeded and this would be the post-registration redirect to the login page.
        $I->dontSeeCurrentUrlEquals('/auth/login');
        // The same answer the sequential path produces — see CustomerAuthCest's
        // registerWithADuplicateEmailShowsAFieldError, which exercises the pre-check winning.
        $I->see('An account with this email already exists.');
        $I->dontSee('Registration failed. Please verify your details and try again.');
    }

    /**
     * #332's secondary complaint: in any non-prod environment the generic catch appended
     * $e->getMessage() to a guest-visible error list, so the loser of the race read
     * "SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed:
     * customer_user.email" in their own error box. Separate from the test above because it fails
     * for a different reason: that one is about the message being unhelpful, this one is about the
     * driver internals being shown to an anonymous visitor at all.
     */
    public function aRegistrationLosingTheRaceDoesNotLeakTheDriverMessageToTheGuest(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
        $I->haveServerParameter('REMOTE_ADDR', '203.0.113.2');

        $contestedEmail = 'auth-register-race-leak-test@example.test';
        $this->raceInAConflictingRowAtFlushTime($I, $contestedEmail);

        $I->amOnPage('/auth/register');
        $I->sendFormPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
            '_token' => $I->csrfToken(),
            'user_email' => $contestedEmail,
        ]));

        $I->seeResponseCodeIsSuccessful();
        // Same vacuous-pass guard as above: every remaining assertion is a dontSee, and a
        // registration that quietly succeeded would satisfy all of them.
        $I->dontSeeCurrentUrlEquals('/auth/login');
        $I->see('An account with this email already exists.');
        $I->dontSee('SQLSTATE');
        $I->dontSee('UNIQUE constraint failed');
        $I->dontSee('Debug:');
    }

    /**
     * #336, the headline tier: 2 accepted registrations per 5 minutes per IP, and the third is
     * turned away.
     *
     * The assertion that matters is not just the message but the absence of the third company —
     * the issue is explicit that the consume has to happen BEFORE the entity-creation block, so
     * that a refused POST costs no password hash, no INSERT and no mail. A limiter consulted after
     * the block would still print this message while having already paid for everything.
     */
    public function theThirdRegistrationInFiveMinutesFromOneIpIsRefusedBeforeAnythingIsCreated(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
        // #356: every limiter in the app is off by default in dev/test so ordinary Cests never
        // throttle each other — this header opts THIS test back into the real limiter, since it
        // exists specifically to prove that limiter works.
        $I->haveHttpHeader('X-Test-Rate-Limiter-On', 'true');
        $I->haveServerParameter('REMOTE_ADDR', '203.0.113.10');

        $this->register($I, 'burst-1@example.test', 'Burst One Co');
        $I->seeCurrentUrlEquals('/auth/login');

        $this->register($I, 'burst-2@example.test', 'Burst Two Co');
        $I->seeCurrentUrlEquals('/auth/login');

        $I->grabService(EntityManagerInterface::class)->clear();
        $this->register($I, 'burst-3@example.test', 'Burst Three Co');

        $I->seeResponseCodeIsSuccessful();
        $I->see('Too many registration attempts. Please wait a while before trying again.');
        $I->dontSeeCurrentUrlEquals('/auth/login');

        // Nothing was paid for: no user, no company, and no mail — neither the one addressed to
        // the submitted company_email nor the alert to every Active AdminUser.
        $I->dontSeeInRepository(CustomerUser::class, ['email' => 'burst-3@example.test']);
        $I->dontSeeInRepository(Company::class, ['name' => 'Burst Three Co']);
        $I->dontSeeEmailIsSent();
    }

    /**
     * The counterpart, and the reason the consume sits after validation rather than at the top of
     * the POST branch: this form has twenty-odd required fields and the tightest tier is 2 per 5
     * minutes, so charging quota for a mistyped postal code would lock a real customer out over a
     * typo. A rejected submission reaches no hash, no INSERT and no mail, so there is nothing to
     * meter. Three failed attempts followed by a good one must still register.
     */
    public function submissionsThatFailValidationDoNotSpendRateLimitQuota(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
        $I->haveHttpHeader('X-Test-Rate-Limiter-On', 'true');
        $I->haveServerParameter('REMOTE_ADDR', '203.0.113.20');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $I->amOnPage('/auth/register');
            $I->sendFormPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
                '_token' => $I->csrfToken(),
                'user_email' => 'typo-fixer@example.test',
                'ship_postal' => '',
            ]));
            $I->see('Shipping postal code is required.');
            $I->dontSee('Too many registration attempts.');
        }

        $this->register($I, 'typo-fixer@example.test', 'Typo Fixer Co');
        $I->seeCurrentUrlEquals('/auth/login');
        $I->seeInRepository(CustomerUser::class, ['email' => 'typo-fixer@example.test']);
    }

    /**
     * The second, email-keyed limiter #336 asks to consider. The per-IP tiers cap what one source
     * can send; they say nothing about many sources aimed at one inbox, which is exactly the shape
     * of the mail-amplification abuse — company_email is used verbatim as the `to:` of the
     * registration email. Every request below comes from a different IP, so every per-IP tier is
     * untouched and only the recipient limiter can refuse anything: six registrations naming the
     * same company_email are accepted, the seventh is not.
     */
    public function theRecipientLimiterCapsRegistrationMailToOneAddressAcrossDifferentIps(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
        $I->haveHttpHeader('X-Test-Rate-Limiter-On', 'true');

        $victimInbox = 'victim@amplification.test';

        for ($n = 1; $n <= 6; $n++) {
            $I->haveServerParameter('REMOTE_ADDR', '198.51.100.' . $n);
            $this->register($I, "botnet-{$n}@example.test", "Botnet {$n} Co", $victimInbox);
            $I->seeCurrentUrlEquals('/auth/login');
        }

        $I->haveServerParameter('REMOTE_ADDR', '198.51.100.7');
        $I->grabService(EntityManagerInterface::class)->clear();
        $this->register($I, 'botnet-7@example.test', 'Botnet 7 Co', $victimInbox);

        $I->seeResponseCodeIsSuccessful();
        $I->see('Too many registration attempts. Please wait a while before trying again.');
        $I->dontSeeInRepository(CustomerUser::class, ['email' => 'botnet-7@example.test']);
        $I->dontSeeEmailIsSent();
    }

    /**
     * Arms the competing writer: a BEFORE INSERT trigger that, the instant register()'s own INSERT
     * for $email reaches the database, first inserts a row carrying that same address. The ORM's
     * INSERT then proceeds into a table that now holds it and is refused by uniq_customer_user_email.
     *
     * A trigger rather than a Doctrine onFlush listener because the Symfony module reboots the
     * kernel for every request and only persists doctrine.dbal.default_connection across the
     * reboot — an EntityManager (and therefore any EventManager listener attached to it) from
     * before the request is thrown away and never sees the flush. The connection survives, so
     * anything installed *in the database* survives with it. Deliberately not a TEMPORARY trigger:
     * a temp object lives for the connection, which this suite reuses for the whole run, whereas a
     * plain one is DDL inside the test's transaction and disappears with the rollback.
     *
     * Nothing about the failure is faked: a real second row, a real INSERT issued by the ORM, a
     * real unique-index violation raised by the real SQLite driver, converted by DBAL into a real
     * UniqueConstraintViolationException. Only the scheduling of the competing write is arranged,
     * because genuine concurrency is not available here — SQLite locks the whole database for
     * writing and the suite is one process inside one transaction, so there is no second writer to
     * race against. SQLite's recursive_triggers default (off) is what stops the inserted row from
     * re-entering the trigger.
     */
    private function raceInAConflictingRowAtFlushTime(FunctionalTester $I, string $email): void
    {
        $I->grabService(EntityManagerInterface::class)->getConnection()->executeStatement(sprintf(
            <<<'SQL'
            CREATE TRIGGER register_race_injector
            BEFORE INSERT ON customer_user
            WHEN NEW.email = '%s'
            BEGIN
                INSERT INTO customer_user (email, roles, password, status, created_at)
                VALUES (NEW.email, '["ROLE_COMPANY_OWNER"]', 'not-a-real-hash', 'Inactive', '2026-01-01 00:00:00');
            END
            SQL,
            $email,
        ));
    }

    private function register(FunctionalTester $I, string $userEmail, string $companyName, ?string $companyEmail = null): void
    {
        $I->amOnPage('/auth/register');
        $I->sendFormPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
            '_token' => $I->csrfToken(),
            'user_email' => $userEmail,
            'company_name' => $companyName,
            'company_email' => $companyEmail ?? 'orders@pinnaclesupply.test',
        ]));
    }

    /** @return array<string, string> */
    private function validRegistrationPayload(): array
    {
        return [
            'company_name' => 'Pinnacle Supply Co',
            'company_email' => 'orders@pinnaclesupply.test',
            'first_name' => 'Pat',
            'last_name' => 'Owner',
            'user_email' => 'auth-register-new-test@example.test',
            'user_phone' => '555-0150',
            'password' => 'a-strong-password-1',
            'confirm_password' => 'a-strong-password-1',
            'agree_terms' => '1',
            'ship_address1' => '100 Main St',
            'ship_city' => 'Calgary',
            'ship_province' => 'Alberta',
            'ship_country' => 'Canada',
            'ship_postal' => 'T2P 1J9',
            'bill_same' => '1',
        ];
    }
}
