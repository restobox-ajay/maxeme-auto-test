<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminColumnPreference;
use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Entity\ProductImage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers issue #523: the Main Image column on the Product Detail grid — where it sits, what it
 *  renders for a product with and without an image, and that it behaves as an ordinary toggleable
 *  column rather than a hardcoded always-on one. */
final class AdminProductDetailImageColumnCest
{
    private function loginAsAdmin(FunctionalTester $I, string $email = 'product-image-column@example.test'): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail($email);
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        return $admin;
    }

    private function makeProduct(FunctionalTester $I, string $sku, string $name, ?string $imageFilename = null): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName($name)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        if ($imageFilename !== null) {
            // Only the primary image feeds the column — getPrimaryImageFilename() ignores the rest.
            $image = (new ProductImage())->setProduct($product)->setFilename($imageFilename)->setPrimaryImage(true);
            $I->haveInRepository($image);
        }

        return $product;
    }

    public function theColumnRendersTheProductsPrimaryImageRightAfterId(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeProduct($I, 'PDT-IMG-1', 'Pictured Widget', 'widget-front.jpg');

        $I->amOnPage('/admin/product/detail/index');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Image');
        $I->seeElement('td[data-label="Image"] img.col-image-thumb');
        $I->seeElement('img.col-image-thumb[src="/uploads/products/widget-front.jpg"]');

        // Position: the Image header sits between ID and Product name, and the body cells follow
        // the same order. Asserting on source order is the only way to pin "right after ID" here.
        $html = $I->grabPageSource();
        $idHeader = strpos($html, 'col-id"  data-sort-field="id"');
        $imageHeader = strpos($html, '<th class="col-image">Image</th>');
        $nameHeader = strpos($html, 'col-name"  data-sort-field="name"');
        $I->assertNotFalse($idHeader);
        $I->assertNotFalse($imageHeader);
        $I->assertNotFalse($nameHeader);
        $I->assertGreaterThan($idHeader, $imageHeader);
        $I->assertGreaterThan($imageHeader, $nameHeader);
    }

    /** A product with no image gets an empty cell, not a broken <img> and not a missing column. */
    public function aProductWithoutAnImageRendersAnEmptyCell(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeProduct($I, 'PDT-IMG-NONE', 'Imageless Widget');

        $I->amOnPage('/admin/product/detail/index');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Imageless Widget');
        // The cell is still there (or the row would shift out of alignment with the headers)...
        $I->seeElement('td[data-label="Image"]');
        // ...but carries no image element at all.
        $I->dontSeeElement('td[data-label="Image"] img');
    }

    /** #523's "must be part of the existing choose-columns system", not a hardcoded column. */
    public function theColumnIsOfferedInTheColumnChooser(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/product/detail/index');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#column-chooser input[name="columns[]"][value="image"]');
    }

    public function deselectingTheColumnHidesIt(FunctionalTester $I): void
    {
        $admin = $this->loginAsAdmin($I, 'product-image-column-hide@example.test');
        $this->makeProduct($I, 'PDT-IMG-HIDE', 'Hidden Image Widget', 'hidden.jpg');

        $I->amOnPage('/admin/product/detail/index');
        $token = $I->grabAttributeFrom('#column-chooser input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/product/detail/columns', [
            '_token' => $token,
            'columns' => ['id', 'name'],
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeInRepository(AdminColumnPreference::class, ['admin' => $admin->getId(), 'viewKey' => 'product_detail']);

        $I->amOnPage('/admin/product/detail/index');
        $I->seeInSource('.col-image { display: none !important;');

        // And selecting it back un-hides it — the same round trip every other column makes.
        $I->sendAjaxPostRequest('/admin/product/detail/columns', [
            '_token' => $token,
            'columns' => ['id', 'image', 'name'],
        ]);
        $I->amOnPage('/admin/product/detail/index');
        $I->dontSeeInSource('.col-image { display: none !important;');
    }

    /**
     * The 35px height is what keeps a tall product shot from stretching the whole row, and the 50px
     * cap with object-fit is what clips a wide one instead of squeezing it. Both live only in CSS,
     * so assert the rule itself rather than a rendered pixel size.
     */
    public function theThumbnailIsHeightCappedAndClippedRatherThanSqueezed(FunctionalTester $I): void
    {
        $css = file_get_contents(\dirname(__DIR__, 2) . '/public/assets/css/app.css');
        $I->assertNotFalse($css, 'Could not read app.css.');

        preg_match('/\.wide-product-table\s+\.col-image-thumb\s*\{([^}]*)\}/s', $css, $m);
        $rule = $m[1] ?? '';

        $I->assertStringContainsString('height: 35px', $rule);
        $I->assertStringContainsString('max-width: 50px', $rule);
        $I->assertStringContainsString('object-fit: cover', $rule);
    }
}
