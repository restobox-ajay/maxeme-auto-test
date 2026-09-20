<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers the BC tax bundle's config screen, in particular the rules summary rendered under the
 * form. The rates in that summary are read from the same rows the form edits rather than written
 * out as prose, so the page cannot drift from the configured values — these tests are what pin
 * that, since a hardcoded "5%" would look identical until someone changed the rate.
 */
final class AdminBcTaxConfigCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-bctax-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    public function configPageExplainsHowTheRulesApply(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/tax/bc');
        $I->seeResponseCodeIsSuccessful();

        $I->see('How these rules apply');
        // The three tax classes and the PST-number exemption are the parts operators get wrong.
        $I->see('Exempt');
        $I->see('GST only');
        $I->see('Standard');
        $I->see('A PST number exempts the customer from PST');
        $I->see('Tax is worked out per line, not on the order total');
    }

    public function rulesSummaryShowsTheConfiguredRatesNotHardcodedOnes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        // Read the rate out of the column heading that carries it, not off the page (#627):
        // see('5.000%') is a substring match, so a heading reading '15.000%' satisfies it, and a
        // GST rate rendered in the PST heading satisfies both calls between them.
        $gstHeading = 'table.data-table thead th:nth-child(2)';
        $pstHeading = 'table.data-table thead th:nth-child(3)';

        // Defaults: GST 5%, PST 7%.
        $I->amOnPage('/admin/bundles/tax/bc');
        $I->assertSame('GST (5.000%)', trim($I->grabTextFrom($gstHeading)), 'tax_rate.rate for GST');
        $I->assertSame('PST (7.000%)', trim($I->grabTextFrom($pstHeading)), 'tax_rate.rate for PST');

        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/bundles/tax/bc', [
            '_token' => $token,
            'rate_gst' => '6',
            'status_gst' => 'Active',
            'rate_bc-pst' => '8',
            'status_bc-pst' => 'Active',
        ]);

        $I->amOnPage('/admin/bundles/tax/bc');
        $I->assertSame('GST (6.000%)', trim($I->grabTextFrom($gstHeading)), 'tax_rate.rate for GST 0.05 -> 0.06');
        $I->assertSame('PST (8.000%)', trim($I->grabTextFrom($pstHeading)), 'tax_rate.rate for PST 0.07 -> 0.08');
        $I->dontSee('5.000%');
    }
}
