<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers Number1CategoryProductPageBundle's per-page grid/list view config (#26): admin can
 * restrict a role's (Tire/Wheel/Accessories) catalog page to only Grid or only List, and choose
 * which one is the default when a customer's request has no explicit `ProductSearch[view]`.
 * Exercised end-to-end (admin save -> App\Service\CatalogViewConfigResolver ->
 * Customer\CatalogController::index()) since the behavior only exists as the wiring between
 * those three pieces, not in any one of them alone.
 */
final class CategoryProductPageViewModeCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('category-page-view-mode-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function loadConfigScreenAndGrabToken(FunctionalTester $I): string
    {
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/bundles/number1-category-product-page');
        $I->seeResponseCodeIsSuccessful();

        return $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    private function saveTireRoleViewConfig(FunctionalTester $I, int $categoryId, bool $gridAvailable, bool $listAvailable, string $defaultView): void
    {
        $token = $this->loadConfigScreenAndGrabToken($I);

        $payload = [
            '_token' => $token,
            'role_category_tire' => (string) $categoryId,
            'role_category_wheel' => '0',
            'role_category_accessories' => '0',
            'view_default_tire' => $defaultView,
        ];
        if ($gridAvailable) {
            $payload['view_grid_available_tire'] = '1';
        }
        if ($listAvailable) {
            $payload['view_list_available_tire'] = '1';
        }

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/bundles/number1-category-product-page', $payload + ['_token' => $I->csrfToken()]);
        $I->seeCurrentUrlEquals('/admin/bundles/number1-category-product-page');
    }

    public function disablingListViewHidesTheToggleAndForcesGridEvenIfRequested(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('View Mode Test Warehouse 1')->setStatus('Active')->setGuestVisible(true);
        $I->haveInRepository($region);

        $category = (new ProductCategory())->setName('View Mode Grid Only Category');
        $I->haveInRepository($category);

        $product = (new ProductCore())->setSku('VIEWMODE-GRID-ONLY-SKU')->setName('Grid Only Test Product')->setCategory($category)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $this->loginAsAdmin($I);
        $this->saveTireRoleViewConfig($I, (int) $category->getId(), gridAvailable: true, listAvailable: false, defaultView: 'GRID_VIEW');

        $I->seeInRepository(AppSetting::class, ['settingKey' => 'number1_category_page_view_list_tire', 'settingValue' => '0']);

        $I->haveHttpHeader('Host', 'localhost');
        $I->amOnPage('/product/index?ProductSearch[category_id]=' . $category->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Grid Only Test Product');
        $I->dontSeeElement('.catalog-view-group');
        $I->dontSeeElement('.catalog-shell.is-list-view');

        // Even an explicit request for the disabled view can't turn it on.
        $I->amOnPage('/product/index?ProductSearch[category_id]=' . $category->getId() . '&ProductSearch[view]=LIST_VIEW');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('.catalog-shell.is-list-view');
    }

    public function settingListAsDefaultRendersListViewWithoutAnExplicitQueryParam(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('View Mode Test Warehouse 2')->setStatus('Active')->setGuestVisible(true);
        $I->haveInRepository($region);

        $category = (new ProductCategory())->setName('View Mode List Default Category');
        $I->haveInRepository($category);

        $product = (new ProductCore())->setSku('VIEWMODE-LIST-DEFAULT-SKU')->setName('List Default Test Product')->setCategory($category)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $this->loginAsAdmin($I);
        $this->saveTireRoleViewConfig($I, (int) $category->getId(), gridAvailable: true, listAvailable: true, defaultView: 'LIST_VIEW');

        $I->seeInRepository(AppSetting::class, ['settingKey' => 'number1_category_page_view_default_tire', 'settingValue' => 'LIST_VIEW']);

        $I->haveHttpHeader('Host', 'localhost');
        $I->amOnPage('/product/index?ProductSearch[category_id]=' . $category->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('List Default Test Product');
        $I->seeElement('.catalog-view-group');
        $I->seeElement('.catalog-shell.is-list-view');
    }
}
