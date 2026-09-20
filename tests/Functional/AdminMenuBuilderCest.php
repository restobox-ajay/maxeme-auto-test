<?php

declare(strict_types=1);

namespace Tests\Functional;

use AdminMenuBundle\Entity\AdminCustomMenuItem;
use AdminMenuBundle\Entity\AdminMenuItemStatus;
use App\Entity\AdminUser;
use App\Service\AppSettings;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers AdminMenuBundle\Controller\Admin\AdminMenuConfigController — the /admin/bundles/admin-menu
 * drag-and-drop builder: hide/show a core entry, reparent a core or custom entry, full custom-item
 * CRUD, and whole-list reordering. The Active/Inactive kill-switch and the "default sidebar with no
 * overrides stored" guarantee are covered by AdminMenuDefaultSidebarCest instead, since both are
 * about the rendered sidebar rather than this builder screen.
 */
final class AdminMenuBuilderCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsTechSupport(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-menu-builder-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function actAsPlainAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-menu-builder-plain-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    public function indexListsCoreAndCustomEntriesWithParentAndStatus(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);
        $I->haveInRepository((new AdminCustomMenuItem())->setItemKey('custom.builder_index_test')->setLabel('Builder Index Item')->setUrl('/builder-index'));

        $I->amOnPage('/admin/bundles/admin-menu');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Admin Menu');
        $I->see('Dashboard');
        $I->see('Builder Index Item');
        $I->see('Custom');
        $I->seeElement('form[action$="/help/toggle"] input[name="_token"]');
    }

    /**
     * The actual fix: a bundle-contributed top-level group (Warehouse, from WarehouseOpsBundle's
     * own AdminMenuOverrideProviderInterface) used to have no row on this screen at all — only
     * App\Menu\Admin\AdminMenuCatalog's own keys were ever listed, so its position could never be
     * overridden no matter what an admin dragged. It is listed now, and hiding it works exactly
     * the way hiding a core entry already did, through the same AdminMenuItemStatusRepository.
     */
    public function aBundleContributedGroupIsListedAndCanBeHiddenFromTheSidebar(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);

        $I->amOnPage('/admin/bundles/admin-menu');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Warehouse');
        $I->see('Bundle (group)');

        $token = $I->grabAttributeFrom('form[action$="/warehouse/toggle"] input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/bundles/admin-menu/warehouse/toggle', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(AdminMenuItemStatus::class, ['itemKey' => 'warehouse', 'hidden' => true]);

        // dontSee('Warehouse') would also match the unrelated core "Warehouses" settings entry
        // (Settings > Warehouses), so this checks the exact top-level group label instead.
        $I->amOnPage('/admin');
        $I->dontSeeElement('.nav-group-title .nav-label', ['text' => 'Warehouse']);
    }

    /** The same fix for reparenting: a bundle's own child screen can be moved to another group too, not just a core one. */
    public function aBundleContributedScreenCanBeReparentedToAnotherGroup(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);

        $I->amOnPage('/admin/bundles/admin-menu');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('form[action$="/warehouse.scan/reparent"] input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/admin-menu/warehouse.scan/reparent', [
            '_token' => $token,
            'parent' => 'apps',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(AdminMenuItemStatus::class, ['itemKey' => 'warehouse.scan', 'parentKey' => 'apps']);
    }

    public function anAdminWhoIsNotTechSupportCannotReachTheBuilder(FunctionalTester $I): void
    {
        $this->actAsPlainAdmin($I);

        $I->amOnPage('/admin/bundles/admin-menu');
        $I->seeResponseCodeIs(403);

        $I->sendAjaxPostRequest('/admin/bundles/admin-menu/help/toggle', []);
        $I->seeResponseCodeIs(403);
    }

    public function hidingACoreEntryStoresItAndHidesItFromTheSidebar(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);

        $I->amOnPage('/admin/bundles/admin-menu');
        $token = $I->grabAttributeFrom('form[action$="/sales.quotes/toggle"] input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/admin-menu/sales.quotes/toggle', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(AdminMenuItemStatus::class, ['itemKey' => 'sales.quotes', 'hidden' => true]);

        $I->amOnPage('/admin');
        $I->dontSeeElement('.nav-sub', ['text' => 'Quotes']);

        // Toggling back shows it again.
        $I->amOnPage('/admin/bundles/admin-menu');
        $restoreToken = $I->grabAttributeFrom('form[action$="/sales.quotes/toggle"] input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/bundles/admin-menu/sales.quotes/toggle', ['_token' => $restoreToken]);
        $I->seeInRepository(AdminMenuItemStatus::class, ['itemKey' => 'sales.quotes', 'hidden' => false]);
    }

    public function reparentingACoreEntryMovesItToTheChosenGroupInTheSidebar(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);

        $I->amOnPage('/admin/bundles/admin-menu');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('form[action$="/settings.sales_tax/reparent"] input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/admin-menu/settings.sales_tax/reparent', [
            '_token' => $token,
            'parent' => 'apps',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(AdminMenuItemStatus::class, ['itemKey' => 'settings.sales_tax', 'parentKey' => 'apps']);
    }

    public function creatingACustomMenuItemPersistsItAndShowsItInTheBuilderAndSidebar(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);

        $I->amOnPage('/admin/bundles/admin-menu/custom/create');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/admin-menu/custom/create', [
            '_token' => $token,
            'label' => 'Warehouse Map',
            'url' => '/admin/warehouse-map',
            'parent' => '',
        ]);
        $I->seeCurrentUrlEquals('/admin/bundles/admin-menu');

        $I->seeInRepository(AdminCustomMenuItem::class, ['label' => 'Warehouse Map', 'url' => '/admin/warehouse-map']);

        $I->amOnPage('/admin/bundles/admin-menu');
        $I->see('Warehouse Map');

        $I->amOnPage('/admin');
        $I->see('Warehouse Map');
        $I->seeElement('a[href="/admin/warehouse-map"]');
    }

    public function creatingACustomMenuItemWithoutAUrlFailsValidation(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);

        $I->amOnPage('/admin/bundles/admin-menu/custom/create');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/admin-menu/custom/create', [
            '_token' => $token,
            'label' => 'Bad Item',
            'url' => 'not-a-url',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('must be a relative path');

        $I->dontSeeInRepository(AdminCustomMenuItem::class, ['label' => 'Bad Item']);
    }

    public function updatingACustomMenuItemPersistsChanges(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);
        $item = (new AdminCustomMenuItem())->setLabel('Old Label')->setUrl('/old-url');
        $I->haveInRepository($item);

        $I->amOnPage('/admin/bundles/admin-menu/custom/' . $item->getId() . '/update');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('label', 'Old Label');

        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/bundles/admin-menu/custom/' . $item->getId() . '/update', [
            '_token' => $token,
            'label' => 'New Label',
            'url' => '/new-url',
        ]);
        $I->seeCurrentUrlEquals('/admin/bundles/admin-menu');

        $I->seeInRepository(AdminCustomMenuItem::class, ['id' => $item->getId(), 'label' => 'New Label', 'url' => '/new-url']);
    }

    public function deletingACustomMenuItemRemovesItFromTheBuilderAndSidebar(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);
        $item = (new AdminCustomMenuItem())->setLabel('Deletable Item')->setUrl('/deletable');
        $I->haveInRepository($item);
        $id = $item->getId();

        $I->amOnPage('/admin/bundles/admin-menu');
        $I->see('Deletable Item');
        $token = $I->grabAttributeFrom('form[action$="/custom/' . $id . '/delete"] input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/admin-menu/custom/' . $id . '/delete', ['_token' => $token]);
        $I->seeCurrentUrlEquals('/admin/bundles/admin-menu');

        $I->dontSeeInRepository(AdminCustomMenuItem::class, ['id' => $id]);

        $I->amOnPage('/admin');
        $I->dontSee('Deletable Item');
    }

    public function reorderPersistsNewSortOrderForCoreAndCustomItems(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);
        $item = (new AdminCustomMenuItem())->setLabel('Reorderable Item')->setUrl('/reorderable');
        $I->haveInRepository($item);

        $I->amOnPage('/admin/bundles/admin-menu');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('#admin-menu-reorder-token', 'value');

        $order = json_encode([
            ['type' => 'custom', 'id' => $item->getItemKey()],
            ['type' => 'core', 'id' => 'dashboard'],
        ]);

        $I->sendAjaxPostRequest('/admin/bundles/admin-menu/reorder', [
            '_token' => $token,
            'order' => $order,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(AdminCustomMenuItem::class, ['id' => $item->getId(), 'sortOrder' => 0]);
        $I->seeInRepository(AdminMenuItemStatus::class, ['itemKey' => 'dashboard', 'sortOrder' => 1]);
    }

    public function toggleWithInvalidCsrfTokenIsRejectedAndLeavesStatusUnchanged(FunctionalTester $I): void
    {
        $this->actAsTechSupport($I);

        $I->amOnPage('/admin/bundles/admin-menu');
        $I->seeResponseCodeIsSuccessful();

        $I->sendAjaxPostRequest('/admin/bundles/admin-menu/help/toggle', ['_token' => 'not-a-valid-token']);
        $I->seeResponseCodeIs(403);

        $I->dontSeeInRepository(AdminMenuItemStatus::class, ['itemKey' => 'help', 'hidden' => true]);
    }
}
