<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Issue #477: product_core.sync_source records which creation path a product came from.
 *
 * Covered here are the two halves the importers cannot cover — the admin product form, which is
 * the only path that is not an import and therefore the only one whose value ("manual") means an
 * absence of automation; and the Product Detail grid's Source column, which is what makes any of
 * this visible to the person who actually needs to ask "where did this product come from?".
 */
final class AdminProductSyncSourceCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-product-sync-source-477@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    public function creatingAProductThroughTheAdminFormStampsManual(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/product/inventory/create');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/product/inventory/create', [
            '_token' => $token,
            'name' => 'Hand Made Product',
            'sku' => 'MANUAL-SRC-1',
        ]);

        $product = $I->grabEntityFromRepository(ProductCore::class, ['sku' => 'MANUAL-SRC-1']);
        $I->assertSame('manual', $product->getSyncSource());
        $I->assertSame(ProductCore::SYNC_SOURCE_MANUAL, $product->getSyncSource());
    }

    /** Editing is not creating: an update must never re-stamp an imported product as hand-made. */
    public function editingAnImportedProductLeavesItsSourceAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $imported = (new ProductCore())
            ->setSku('IMPORTED-SRC-1')
            ->setName('Imported Product')
            ->setSyncSource('number1-rim-api');
        $I->haveInRepository($imported);

        $I->amOnPage('/admin/product/inventory/update/' . $imported->getId());
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/product/inventory/update/' . $imported->getId(), [
            '_token' => $token,
            'name' => 'Imported Product Renamed',
            'sku' => 'IMPORTED-SRC-1',
        ]);

        $product = $I->grabEntityFromRepository(ProductCore::class, ['sku' => 'IMPORTED-SRC-1']);
        $I->assertSame('Imported Product Renamed', $product->getName());
        $I->assertSame('number1-rim-api', $product->getSyncSource());
    }

    public function theProductDetailGridShowsTheSourceColumn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $I->haveInRepository((new ProductCore())->setSku('GRID-SRC-RIM')->setName('Grid Rim Product')->setSyncSource('number1-rim-api'));

        $I->amOnPage('/admin/product/detail/index');
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('th[data-sort-field="sync_source"]');
        $I->seeElement('input[name="filters[sync_source]"]');
        $I->see('number1-rim-api');
    }

    public function theSourceFilterNarrowsTheGridToOneSource(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $I->haveInRepository((new ProductCore())->setSku('FILTER-SRC-RIM')->setName('Filter Rim Product')->setSyncSource('number1-rim-api'));
        $I->haveInRepository((new ProductCore())->setSku('FILTER-SRC-MANUAL')->setName('Filter Manual Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL));

        $I->amOnPage('/admin/product/detail/index?filters[sync_source]=number1-rim-api');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Filter Rim Product');
        $I->dontSee('Filter Manual Product');
    }

    /**
     * The rows the #477 backfill deliberately refused to attribute are exactly the ones an admin
     * most needs to be able to pull up, and "-" is the only handle the grid gives them.
     */
    public function theSourceFilterCanFindProductsWithNoRecordedSource(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        // Deliberately not "Unattributed"/"Attributed": Codeception's see/dontSee match
        // case-insensitively on normalized text, so one name being a substring of the other would
        // make dontSee() fail on a page that is in fact correctly filtered.
        $I->haveInRepository((new ProductCore())->setSku('FILTER-SRC-NONE')->setName('Provenance Unknown Widget'));
        $I->haveInRepository((new ProductCore())->setSku('FILTER-SRC-KNOWN')->setName('Provenance Recorded Widget')->setSyncSource('number1-rim-api'));

        $I->amOnPage('/admin/product/detail/index?filters[sync_source]=-');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Provenance Unknown Widget');
        $I->dontSee('Provenance Recorded Widget');
    }
}
