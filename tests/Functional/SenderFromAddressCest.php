<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * #471 and #474: sender_from_address is the From: header, sender_replyto_address is the Reply-To,
 * support_email is the address a human is told to write to, and all three must be able to differ.
 *
 * The unit coverage in tests/Service/AppSettingsTest.php proves the resolution order. What it
 * cannot prove is that the split actually holds where it matters — end to end, through a real
 * controller, where one value ends up in a header and another ends up in prose. These do that for
 * the two places they appear at once: the contact page (which prints support_email and sends from
 * the override) and the email-changed notice (which is sent from the override and tells the
 * previous address to contact support_email).
 *
 * Every test clears AppSettings' cache on both sides of its writes. The rows themselves are rolled
 * back with the surrounding transaction, but that hour-long cache entry is real and shared, so a
 * test that only cleared beforehand would hand the next one a stale override.
 */
final class SenderFromAddressCest
{
    private const OVERRIDE = 'no-reply@mail.acme.example';
    private const SUPPORT = 'help@acme.example';
    private const REPLY_TO = 'replies@acme.example';

    /** .env.test's MAILER_FROM, which is where the chain lands with the override cleared. */
    private const MAILER_FROM = 'no-reply@wholesale.example';

    /** @param array<string, string|null> $values */
    private function setSettings(FunctionalTester $I, array $values): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        foreach ($values as $key => $value) {
            $setting = $entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key]);
            if (!$setting instanceof AppSetting) {
                $setting = (new AppSetting())->setSettingKey($key)->setName($key);
                $entityManager->persist($setting);
            }
            $setting->setSettingValue($value);
        }
        $entityManager->flush();
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function clearSettingsCache(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function submitTheContactForm(FunctionalTester $I): void
    {
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
    }

    /**
     * The contact page prints the address customers are meant to write to. Setting a sending
     * domain must not rewrite it — that would be the old coupling back again, just pointing the
     * other way.
     */
    public function theContactPagePrintsSupportEmailAndNeverTheSenderOverride(FunctionalTester $I): void
    {
        $this->setSettings($I, [
            AppSettings::SENDER_FROM_ADDRESS_KEY => self::OVERRIDE,
            'support_email' => self::SUPPORT,
        ]);

        $I->amOnPage('/contact');
        $I->seeResponseCodeIsSuccessful();
        $I->see(self::SUPPORT);
        $I->dontSee(self::OVERRIDE);

        $this->setSettings($I, [AppSettings::SENDER_FROM_ADDRESS_KEY => '', 'support_email' => null]);
    }

    /** The same page, the same request: the mail it sends goes out under the override. */
    public function theOverrideBecomesTheFromHeaderOnContactFormMail(FunctionalTester $I): void
    {
        $this->setSettings($I, [
            AppSettings::SENDER_FROM_ADDRESS_KEY => self::OVERRIDE,
            'support_email' => self::SUPPORT,
            'app_email' => 'orders@acme.example',
        ]);

        $this->submitTheContactForm($I);

        $I->seeEmailIsSent();
        $sent = $I->grabLastSentEmail();
        $I->assertSame(self::OVERRIDE, $sent->getFrom()[0]->getAddress());
        // Where the message is delivered is untouched: this overrides a sender, not a recipient.
        $I->assertSame('orders@acme.example', $sent->getTo()[0]->getAddress());

        $I->startFollowingRedirects();
        $this->setSettings($I, [
            AppSettings::SENDER_FROM_ADDRESS_KEY => '',
            'support_email' => null,
            'app_email' => null,
        ]);
    }

    /**
     * Cleared, the From: header falls to MAILER_FROM — the address beside the DSN, on the domain
     * that actually sends — and specifically not to support_email or app_email, both configured
     * here and both of which the old chain would have used before ever reaching the environment.
     * This is #474's behaviour change seen end to end.
     */
    public function clearingTheOverrideFallsToMailerFromAndNotToAContactAddress(FunctionalTester $I): void
    {
        $this->setSettings($I, [
            AppSettings::SENDER_FROM_ADDRESS_KEY => '',
            'support_email' => self::SUPPORT,
            'app_email' => 'orders@acme.example',
        ]);

        $this->submitTheContactForm($I);

        $I->seeEmailIsSent();
        $from = $I->grabLastSentEmail()->getFrom()[0]->getAddress();
        $I->assertSame(self::MAILER_FROM, $from);
        $I->assertNotSame(self::SUPPORT, $from);
        $I->assertNotSame('orders@acme.example', $from);

        $I->startFollowingRedirects();
        $this->setSettings($I, ['support_email' => null, 'app_email' => null]);
    }

    /**
     * The contact form's Reply-To is the customer who filled it in, so staff can answer them by
     * hitting reply. sender_replyto_address must not take that over — it is a store-wide default
     * for mail this system sends *out*, and this message is the one case where the right answer
     * is already known (#474).
     */
    public function theContactFormRepliesToTheSubmitterEvenWithAStoreReplyToConfigured(FunctionalTester $I): void
    {
        $this->setSettings($I, [
            AppSettings::SENDER_FROM_ADDRESS_KEY => self::OVERRIDE,
            AppSettings::SENDER_REPLYTO_ADDRESS_KEY => self::REPLY_TO,
            'app_email' => 'orders@acme.example',
        ]);

        $this->submitTheContactForm($I);

        $I->seeEmailIsSent();
        $sent = $I->grabLastSentEmail();
        $I->assertSame(['jane@example.test'], array_map(
            static fn ($address): string => $address->getAddress(),
            $sent->getReplyTo(),
        ));

        $I->startFollowingRedirects();
        $this->setSettings($I, [
            AppSettings::SENDER_FROM_ADDRESS_KEY => '',
            AppSettings::SENDER_REPLYTO_ADDRESS_KEY => '',
            'app_email' => null,
        ]);
    }

    /**
     * The email-changed notice is the sharpest case in the whole issue. It goes to the address that
     * just stopped being the account's, and says "if you did not expect this change, contact us
     * immediately at ...". That sentence has to name a mailbox somebody reads. Sent from a
     * no-reply@ on the sending domain, with support_email in the body.
     *
     * The Reply-To is asserted twice over, because this is the message the setting exists for: with
     * sender_replyto_address configured it is on the message, and with it blank there is no
     * Reply-To header at all. This site used to hardcode replyTo(support_email) (#474).
     */
    public function theEmailChangedNoticeSendsFromTheOverrideButNamesSupportEmailInItsBody(FunctionalTester $I): void
    {
        $this->setSettings($I, [
            AppSettings::SENDER_FROM_ADDRESS_KEY => self::OVERRIDE,
            AppSettings::SENDER_REPLYTO_ADDRESS_KEY => self::REPLY_TO,
            'support_email' => self::SUPPORT,
        ]);

        $customer = $this->makeCustomerAndLogInAsAdmin($I);
        $this->changeTheCustomersEmail($I, $customer);

        $I->seeEmailIsSent();
        $sent = $I->grabLastSentEmail();
        $I->assertSame(['sender-from-before@example.test'], array_map(
            static fn ($address): string => $address->getAddress(),
            $sent->getTo(),
        ));
        $I->assertSame(self::OVERRIDE, $sent->getFrom()[0]->getAddress());
        $I->assertSame([self::REPLY_TO], array_map(
            static fn ($address): string => $address->getAddress(),
            $sent->getReplyTo(),
        ));

        $body = (string) $sent->getHtmlBody();
        $I->assertStringContainsString(self::SUPPORT, $body);
        $I->assertStringNotContainsString(self::OVERRIDE, $body);

        $I->startFollowingRedirects();
        $this->setSettings($I, [
            AppSettings::SENDER_FROM_ADDRESS_KEY => '',
            AppSettings::SENDER_REPLYTO_ADDRESS_KEY => '',
            'support_email' => null,
        ]);
        $this->clearSettingsCache($I);
    }

    /**
     * Blank Reply-To means no header, asserted as absence rather than as some other value. Every
     * address a fallback might have reached for is configured, so a reintroduced "helpfully use
     * support_email" would fail here instead of quietly passing.
     */
    public function aBlankReplyToSettingSendsNoReplyToHeaderAtAll(FunctionalTester $I): void
    {
        $this->setSettings($I, [
            AppSettings::SENDER_FROM_ADDRESS_KEY => self::OVERRIDE,
            AppSettings::SENDER_REPLYTO_ADDRESS_KEY => '',
            'support_email' => self::SUPPORT,
            'app_email' => 'orders@acme.example',
        ]);

        $customer = $this->makeCustomerAndLogInAsAdmin($I);
        $this->changeTheCustomersEmail($I, $customer);

        $I->seeEmailIsSent();
        $sent = $I->grabLastSentEmail();
        $I->assertSame([], $sent->getReplyTo());
        $I->assertFalse($sent->getHeaders()->has('Reply-To'));

        $I->startFollowingRedirects();
        $this->setSettings($I, [
            AppSettings::SENDER_FROM_ADDRESS_KEY => '',
            'support_email' => null,
            'app_email' => null,
        ]);
        $this->clearSettingsCache($I);
    }

    private function makeCustomerAndLogInAsAdmin(FunctionalTester $I): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('sender-from-admin@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');

        $company = (new Company())
            ->setName('Sender From Co')
            ->setCode('SFC-' . strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 8)));
        $I->haveInRepository($company);

        $customer = (new CustomerUser())
            ->setEmail('sender-from-before@example.test')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setCompany($company)
            ->setRoles(['ROLE_COMPANY_STAFF']);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        return $customer;
    }

    private function changeTheCustomersEmail(FunctionalTester $I, CustomerUser $customer): void
    {
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/user/customer/update/' . $customer->getId());
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value');

        // The update redirects to the customer index, and the mailer collector only ever describes
        // the last request — follow the redirect and the request being inspected is the one that
        // sent nothing.
        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/admin/user/customer/update/' . $customer->getId(), [
            '_token' => $token,
            'email' => 'sender-from-after@example.test',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'status' => 'Active',
            'role' => 'Company Staff',
            'company' => (string) $customer->getCompany()?->getId(),
            'notify_email_change' => 'yes',
        ]);
    }
}
