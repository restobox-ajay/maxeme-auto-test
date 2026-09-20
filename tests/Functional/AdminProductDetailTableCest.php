<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers the server-side parts of issue #120 for the Product Details grid: the product name is no
 *  longer bold (part 1) and the new Tax Class column renders, sorts and filters (part 4). The
 *  resize/wrap/scroll behaviour (parts 2, 3, 6) is CSS/JS and not exercised here. */
final class AdminProductDetailTableCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('product-detail-table-test@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeProduct(FunctionalTester $I, string $sku, string $name, ?string $taxCode): void
    {
        $product = (new ProductCore())->setSku($sku)->setName($name)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        if ($taxCode !== null) {
            $product->setSalesTaxCode($taxCode);
        }
        $I->haveInRepository($product);
    }

    public function taxClassColumnShowsAndProductNameIsNotBold(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeProduct($I, 'PDT-TAX-1', 'Taxable Widget', 'S');

        $I->amOnPage('/admin/product/detail/index');
        $I->seeResponseCodeIsSuccessful();

        // Part 4: the Tax Class column exists (as a sortable header) and shows the code.
        $I->seeElement('th[data-sort-field="sales_tax_code"]');
        $I->see('Tax Class');
        $I->seeElement('td[data-label="Tax Class"]');
        $I->see('Taxable Widget');

        // Part 1: the product name cell is no longer wrapped in <strong>.
        $I->dontSeeElement('td.product-name-cell strong');

        // GH issue #408: the name column was also bolded purely via CSS (no <strong> involved), so
        // the check above alone doesn't catch a regression there. Assert the actual stylesheet rule
        // for this grid's name cell no longer sets a bold font-weight.
        $css = file_get_contents(\dirname(__DIR__, 2) . '/public/assets/css/app.css');
        $I->assertNotFalse($css, 'Could not read app.css.');
        $I->assertMatchesRegularExpression(
            '/\.product-admin-grid\s+\.wide-product-table\s+\.product-name-cell\s*\{[^}]*\}/s',
            $css,
            'Expected the Detail grid product-name-cell rule to still exist.'
        );
        preg_match(
            '/\.product-admin-grid\s+\.wide-product-table\s+\.product-name-cell\s*\{([^}]*)\}/s',
            $css,
            $matches
        );
        $I->assertStringNotContainsString(
            'font-weight',
            $matches[1] ?? '',
            'Product name cell should not be bold on the Detail grid.'
        );
    }

    public function taxClassColumnIsFilterable(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeProduct($I, 'PDT-TAX-S', 'Standard Taxed', 'S');
        $this->makeProduct($I, 'PDT-TAX-E', 'Exempt Item', 'E');

        $I->amOnPage('/admin/product/detail/index?filters[sales_tax_code]=E');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Exempt Item');
        $I->dontSee('Standard Taxed');
    }
}
