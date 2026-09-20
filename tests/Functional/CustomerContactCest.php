<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Customer/ContactController — the /faq page and the /contact form's guest/logged-in
 *  prefill, validation, CSRF, and success/failure submission paths. */
final class CustomerContactCest
{
    public function faqPageRendersForAGuest(FunctionalTester $I): void
    {
        $I->amOnPage('/faq');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Frequently Asked Questions');
        $I->see('Contact Support');
    }

    public function contactFormRendersBlankForAGuest(FunctionalTester $I): void
    {
        $I->amOnPage('/contact');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Get in Touch');
        $I->seeInField('name', '');
        $I->seeInField('email', '');
    }

    public function contactFormPrefillsFromLoggedInCustomer(FunctionalTester $I): void
    {
        $company = (new Company())
            ->setName('Acme Co')
            ->setCode('ACME-' . uniqid());
        $I->haveInRepository($company);

        $address = (new CompanyAddress())->setCompany($company)->setProvince('BC')->setCity('Vancouver');
        $I->haveInRepository($address);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('contact-prefill-test@example.test')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');
        $I->amOnPage('/contact');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('name', 'Jane Doe');
        $I->seeInField('email', 'contact-prefill-test@example.test');
        $I->seeInField('location', 'BC, Vancouver');
    }

    /**
     * The customer header and the contact page both defaulted company_phone to a hardcoded number
     * ('+1 604-270-8687' and '123-456-7900'). Clearing the setting in admin therefore did not
     * remove the phone number — it printed another company's number instead. Blank must now mean
     * no phone number at all.
     */
    public function clearingTheCompanyPhoneSettingLeavesNoFallbackNumberOnTheContactPage(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $setting = $entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => 'company_phone']);
        if ($setting instanceof AppSetting) {
            $setting->setSettingValue(null);
            $entityManager->flush();
        }
        $I->grabService(AppSettings::class)->clearCache();

        $I->amOnPage('/contact');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('604-270-8687');
        $I->dontSee('123-456-7900');
        // The header's tel: link and the contact cards' ☎ rows are dropped rather than left blank.
        $I->dontSeeElement('.customer-topbar-meta a[href^="tel:"]');
    }

    /** With the setting populated, the header still links it. */
    public function aConfiguredCompanyPhoneStillShowsInTheCustomerHeader(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $setting = $entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => 'company_phone']);
        if (!$setting instanceof AppSetting) {
            $setting = (new AppSetting())->setSettingKey('company_phone')->setName('Company Phone');
            $entityManager->persist($setting);
        }
        $setting->setSettingValue('+1 250 555 0142');
        $entityManager->flush();
        $I->grabService(AppSettings::class)->clearCache();

        $I->amOnPage('/contact');
        $I->seeResponseCodeIsSuccessful();
        $I->see('+1 250 555 0142', '.customer-topbar-meta');

        // Leave the setting cleared: the suite shares one database across the run.
        //
        // Re-fetched through a FRESHLY grabbed EntityManager, and verified (#594). Codeception
        // reboots the kernel between requests and the reboot resets Doctrine, so `$setting` — read
        // before the request above — is DETACHED by now and `flush()` on the manager that loaded it
        // writes nothing and raises nothing. The cleanup this comment promises silently did not
        // happen, and company_phone stayed set for every test that ran afterwards.
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $setting = $entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => 'company_phone']);
        $setting?->setSettingValue(null);
        $entityManager->flush();
        $I->grabService(AppSettings::class)->clearCache();

        $I->assertNull(
            $entityManager->getConnection()->fetchOne(
                'SELECT setting_value FROM app_setting WHERE setting_key = ?',
                ['company_phone'],
            ) ?: null,
            'the cleanup has to actually land, or the next test in the run is reading a fixture nobody set',
        );
    }

    public function submittingBlankFormShowsValidationErrors(FunctionalTester $I): void
    {
        $I->amOnPage('/contact');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/contact', [
            '_token' => $token,
            'name' => '',
            'email' => '',
            'location' => '',
            'subject' => '',
            'comments' => '',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Please fix the highlighted fields below.');
        $I->see('Name is required.');
        $I->see('Email is required.');
        $I->see('Location is required.');
        $I->see('Subject is required.');
        $I->see('Comments are required.');
    }

    public function submittingAnInvalidEmailShowsAFieldSpecificError(FunctionalTester $I): void
    {
        $I->amOnPage('/contact');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/contact', [
            '_token' => $token,
            'name' => 'Jane Doe',
            'email' => 'not-an-email',
            'location' => 'Vancouver, BC',
            'subject' => 'Question',
            'comments' => 'Hi there',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Email must be a valid email address.');
    }

    public function submittingAShortCommentShowsAFieldSpecificError(FunctionalTester $I): void
    {
        $I->amOnPage('/contact');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/contact', [
            '_token' => $token,
            'name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'location' => 'Vancouver, BC',
            'subject' => 'Question',
            'comments' => 'Hi',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Comments must be at least 5 characters.');
    }

    public function submittingWithAnInvalidCsrfTokenRedirectsWithAnErrorFlash(FunctionalTester $I): void
    {
        $I->sendAjaxPostRequest('/contact', [
            '_token' => 'not-a-real-token',
            'name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'location' => 'Vancouver, BC',
            'subject' => 'Question',
            'comments' => 'Hello there',
        ]);
        $I->seeResponseCodeIs(403);
        $I->assertStringContainsString('Your session expired', $I->grabPageSource());
    }

    /**
     * With nothing configured the recipient falls back to the store's own active admins. It used to
     * fall back to the literal 'admin@gmail.com' — a stranger's mailbox that every unconfigured
     * installation quietly forwarded its customers' messages to (#351).
     */
    public function submittingAValidFormSucceedsAndRedirects(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('contact-fallback-admin@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amOnPage('/contact');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/contact', [
            '_token' => $token,
            'name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'location' => 'Vancouver, BC',
            'subject' => 'Question about pricing',
            'comments' => 'What is the wholesale price for widgets?',
        ]);

        $I->seeEmailIsSent();
        $sent = $I->grabLastSentEmail();
        $I->assertSame(['contact-fallback-admin@example.test'], array_map(
            static fn ($address): string => $address->getAddress(),
            $sent->getTo(),
        ));
        $I->assertStringNotContainsString('gmail.com', implode(',', array_map(
            static fn ($address): string => $address->getAddress(),
            $sent->getTo(),
        )));
        // From: resolves through AppSettings::supportFromAddress(), not the old
        // no-reply@wholesalecatalog.test literal. Nothing is configured here on purpose - this
        // test is about the *recipient* falling back to active admins - so it lands on the
        // MAILER_FROM tier (#366).
        $I->assertSame('no-reply@wholesale.example', $sent->getFrom()[0]->getAddress());

        $I->startFollowingRedirects();
        $I->amOnPage('/contact');
        $I->see('Thanks! Your message has been sent.');
    }

    /** A configured contact address wins over the admin fallback. */
    public function aConfiguredContactAddressReceivesTheMessage(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->persist(
            (new AppSetting())->setSettingKey('app_email')->setName('App Email')->setSettingValue('orders@thestore.example')
        );
        $entityManager->flush();
        $I->grabService(AppSettings::class)->clearCache();

        $I->amOnPage('/contact');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/contact', [
            '_token' => $token,
            'name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'location' => 'Vancouver, BC',
            'subject' => 'Question about pricing',
            'comments' => 'What is the wholesale price for widgets?',
        ]);

        $I->seeEmailIsSent();
        $I->assertSame('orders@thestore.example', $I->grabLastSentEmail()->getTo()[0]->getAddress());

        $I->grabService(AppSettings::class)->clearCache();
    }

    /**
     * No configured address and no admin to fall back on: the message must not be sent anywhere, and
     * the visitor must not be told it was. Silence beats delivering it to someone else's inbox.
     */
    public function withNoRecipientAtAllTheMessageIsNotSentAnywhere(FunctionalTester $I): void
    {
        $I->amOnPage('/contact');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/contact', [
            '_token' => $token,
            'name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'location' => 'Vancouver, BC',
            'subject' => 'Question about pricing',
            'comments' => 'What is the wholesale price for widgets?',
        ]);

        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('Thanks! Your message has been sent.');
        $I->see('Message could not be sent right now.');
    }

    /** The contact cards printed invented offices' details when the settings were blank (#351). */
    public function theContactCardsPrintNoInventedAddressOrEmailWhenNothingIsConfigured(FunctionalTester $I): void
    {
        $I->amOnPage('/contact');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('123 Placeholder Street');
        $I->dontSee('hello@example.ca');
        $I->dontSee('support@example.ca');
        $I->dontSee('V5K 0A1');
    }
}
