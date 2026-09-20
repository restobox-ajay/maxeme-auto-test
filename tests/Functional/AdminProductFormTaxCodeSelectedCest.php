<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The product edit form pre-selects the stored sales_tax_code.
 *
 * Reported as a display-only bug: the value persists to product_core.sales_tax_code but the form's
 * tax-code control was said to render nothing selected, so a saved code looked blank to an admin —
 * with the follow-on risk that re-saving the form blanks a real value.
 *
 * Pinned here because the failure mode is invisible to the persistence layer: the column can be
 * perfectly correct while the form lies about it, and nothing else in the suite renders this
 * control. There are two controls to check, not one — a JS searchable-select whose chosen item
 * carries .is-selected, and the hidden native <select> that is what actually submits — and a
 * regression in either alone reproduces the report.
 *
 * Legacy codes are covered too because productToFormRow() pipes the stored value through
 * normalizeSalesTaxCode(), which folds older data (0/EXEMPT, 5/GST, L/PST) onto E/G/S. That mapping
 * sits between the database and the markup, so it is exactly where a stored-but-not-selected bug
 * would live.
 */
final class AdminProductFormTaxCodeSelectedCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('product-form-taxcode@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function product(FunctionalTester $I, string $sku, ?string $taxCode): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Tax Code Product ' . $sku)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        if ($taxCode !== null) {
            $product->setSalesTaxCode($taxCode);
        }
        $I->haveInRepository($product);

        return $product;
    }

    private function assertSelected(FunctionalTester $I, ProductCore $product, string $expected): void
    {
        $I->amOnPage('/admin/product/inventory/update/' . $product->getId());
        $I->seeResponseCodeIsSuccessful();

        // The control that actually submits.
        $I->seeElement(sprintf('select[name="sales_tax_code"] option[value="%s"][selected]', $expected));
        // The visible JS widget, which is what an admin actually looks at.
        $I->seeElement(sprintf('li.ss-item.is-selected[data-value="%s"]', $expected));
    }

    public function storedCodeIsPreselectedOnTheEditForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        foreach (['E', 'G', 'S'] as $code) {
            $this->assertSelected($I, $this->product($I, 'TAXSEL-' . $code, $code), $code);
        }
    }

    /** Legacy values must resolve to their canonical short code and select THAT option. */
    public function legacyCodesArePreselectedAsTheirCanonicalShortCode(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        foreach (['EXEMPT' => 'E', 'GST' => 'G', 'PST' => 'S', '0' => 'E', '5' => 'G', 'L' => 'S'] as $stored => $expected) {
            $this->assertSelected($I, $this->product($I, 'TAXLEGACY-' . $stored, (string) $stored), $expected);
        }
    }

    /** A product with no code selects the blank option, not one of the real ones. */
    public function productWithoutACodeSelectsTheBlankOption(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->product($I, 'TAXSEL-NONE', null);

        $I->amOnPage('/admin/product/inventory/update/' . $product->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('select[name="sales_tax_code"] option[value=""][selected]');
        foreach (['E', 'G', 'S'] as $code) {
            $I->dontSeeElement(sprintf('select[name="sales_tax_code"] option[value="%s"][selected]', $code));
        }
    }
}
