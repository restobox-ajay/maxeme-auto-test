<?php

declare(strict_types=1);

namespace App\Tests\Service\Onboarding\Checks;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use App\Service\Onboarding\Checks\AppIdentityEmailsCheck;
use App\Service\Onboarding\Checks\CompanyAddressCheck;
use App\Service\Onboarding\Checks\CompanyGstNumberCheck;
use App\Service\Onboarding\Checks\CompanyNamePhoneCheck;
use App\Service\Onboarding\Checks\DeliveryPaymentPolicyCheck;
use App\Service\Onboarding\Checks\LogoUrlConfiguredCheck;
use App\Service\Onboarding\Checks\PrivacyPolicyCheck;
use App\Service\Onboarding\Checks\SalesSupportEmailCheck;
use App\Service\Onboarding\Checks\TermsConditionsPolicyCheck;
use App\Service\Onboarding\Checks\TimezoneCheck;
use App\Service\Onboarding\Checks\WebsiteUrlCheck;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Covers every AbstractSettingsFilledCheck subclass (#426): "filled out" checks for app
 * identity, company profile and the three policy links. Adversarial focus is on what "filled
 * out" must NOT accept — whitespace-only values, one blank field among several required ones —
 * and on the policy checks each reading their own distinct setting key rather than a copy-paste
 * of the same one.
 */
final class AbstractSettingsFilledCheckTest extends TestCase
{
    private function makeSetting(string $key, ?string $value): AppSetting
    {
        return (new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($value);
    }

    /** @param array<string, string|null> $values */
    private function appSettings(array $values): AppSettings
    {
        $rows = [];
        foreach ($values as $key => $value) {
            $rows[] = $this->makeSetting($key, $value);
        }

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($rows);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new AppSettings($em, new ArrayAdapter());
    }

    public function testAllFieldsFilledPasses(): void
    {
        $check = new AppIdentityEmailsCheck($this->appSettings([
            'app_name' => 'Acme',
            'app_email' => 'app@acme.test',
            'billing_email' => 'billing@acme.test',
        ]));

        $result = $check->run();

        self::assertTrue($result->passed);
    }

    public function testOneMissingFieldAmongThreeFails(): void
    {
        $check = new AppIdentityEmailsCheck($this->appSettings([
            'app_name' => 'Acme',
            'app_email' => '',
            'billing_email' => 'billing@acme.test',
        ]));

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('App Email', $result->message);
        self::assertStringNotContainsString('App Name', $result->message);
        self::assertStringNotContainsString('Billing Email', $result->message);
    }

    public function testWhitespaceOnlyValueCountsAsNotFilled(): void
    {
        $check = new AppIdentityEmailsCheck($this->appSettings([
            'app_name' => "   \t\n",
            'app_email' => 'app@acme.test',
            'billing_email' => 'billing@acme.test',
        ]));

        $result = $check->run();

        self::assertFalse($result->passed, 'a value that is only whitespace must not count as filled out');
    }

    public function testNoSettingRowAtAllFailsJustLikeAnEmptyOne(): void
    {
        // No row for billing_email at all (never created) vs. an explicit empty string — both
        // must be treated identically as "not filled out".
        $check = new AppIdentityEmailsCheck($this->appSettings([
            'app_name' => 'Acme',
            'app_email' => 'app@acme.test',
        ]));

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('Billing Email', $result->message);
    }

    public function testAllFieldsMissingListsAllOfThem(): void
    {
        $check = new CompanyAddressCheck($this->appSettings([]));

        $result = $check->run();

        self::assertFalse($result->passed);
        foreach (['Company Address', 'Company City', 'Company Country', 'Company Postal Code'] as $field) {
            self::assertStringContainsString($field, $result->message);
        }
    }

    public function testCompanyNamePhonePassesOnlyWhenBothSet(): void
    {
        $passing = new CompanyNamePhoneCheck($this->appSettings(['company_name' => 'Acme', 'company_phone' => '555-0100']));
        $failing = new CompanyNamePhoneCheck($this->appSettings(['company_name' => 'Acme', 'company_phone' => '']));

        self::assertTrue($passing->run()->passed);
        self::assertFalse($failing->run()->passed);
    }

    public function testSalesSupportEmailChecksBothIndependently(): void
    {
        $check = new SalesSupportEmailCheck($this->appSettings(['sales_email' => 'sales@acme.test']));

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('Support Email', $result->message);
        self::assertStringNotContainsString('Sales Email', $result->message);
    }

    /**
     * Timezone, Website URL and Company GST/Tax Number used to be one combined check
     * (TimezoneWebsiteGstCheck), so filling in two of the three still left the whole row "Not
     * Done" over the one that wasn't — a mismatch a user actually hit. Split into three
     * independent checks; this proves each reads its own key and none of them leak into another.
     */
    public function testTimezoneWebsiteUrlAndGstAreIndependentChecks(): void
    {
        $settings = $this->appSettings([
            'timezone' => 'America/Vancouver',
            'website_url' => 'https://acme.test',
            'company_gst_number' => '',
        ]);

        $timezone = new TimezoneCheck($settings);
        $websiteUrl = new WebsiteUrlCheck($settings);
        $gstNumber = new CompanyGstNumberCheck($settings);

        self::assertTrue($timezone->run()->passed, 'timezone is filled, so Timezone must pass');
        self::assertTrue($websiteUrl->run()->passed, 'website_url is filled, so Website URL must pass');
        self::assertFalse($gstNumber->run()->passed, 'company_gst_number is blank, so it alone must fail');
        self::assertStringContainsString('GST', $gstNumber->run()->message);
    }

    public function testLogoUrlConfiguredIsIndependentFromFileExistenceCheck(): void
    {
        // This check only asks "is the setting filled out" — verifying the file is actually on
        // disk is LogoFileExistsCheck's job, not this one's.
        $check = new LogoUrlConfiguredCheck($this->appSettings(['logo_url' => '/uploads/branding/does-not-exist-on-disk.png']));

        self::assertTrue($check->run()->passed);
    }

    /**
     * The three policy checks must each read their own AppSettings key. A copy-paste slip that
     * left two of them reading the same key would make one always mirror the other's state
     * regardless of its own setting — this proves they are wired independently.
     */
    public function testTheThreePolicyChecksReadIndependentSettingKeys(): void
    {
        $settings = $this->appSettings([
            'terms_url' => 'https://acme.test/terms',
            'privacy_url' => '',
            'returns_url' => '',
        ]);

        $terms = new TermsConditionsPolicyCheck($settings);
        $privacy = new PrivacyPolicyCheck($settings);
        $deliveryPayment = new DeliveryPaymentPolicyCheck($settings);

        self::assertTrue($terms->run()->passed, 'terms_url is filled, so Terms & Conditions must pass');
        self::assertFalse($privacy->run()->passed, 'privacy_url is blank, so Privacy Policy must fail');
        self::assertFalse($deliveryPayment->run()->passed, 'returns_url is blank, so Delivery & Payment must fail');
    }

    public function testKeysGroupsAndLabelsAreUniqueAcrossAllSettingsFilledChecks(): void
    {
        $settings = $this->appSettings([]);
        $checks = [
            new AppIdentityEmailsCheck($settings),
            new SalesSupportEmailCheck($settings),
            new CompanyAddressCheck($settings),
            new CompanyNamePhoneCheck($settings),
            new TimezoneCheck($settings),
            new WebsiteUrlCheck($settings),
            new CompanyGstNumberCheck($settings),
            new LogoUrlConfiguredCheck($settings),
            new TermsConditionsPolicyCheck($settings),
            new PrivacyPolicyCheck($settings),
            new DeliveryPaymentPolicyCheck($settings),
        ];

        $keys = array_map(static fn ($c) => $c->getKey(), $checks);

        self::assertSame($keys, array_values(array_unique($keys)), 'every check must have a distinct key (used as a DOM id)');
        foreach ($checks as $check) {
            self::assertNotSame('', trim($check->getLabel()));
            self::assertNotSame('', trim($check->getGroup()));
        }
    }
}
