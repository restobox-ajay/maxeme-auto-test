<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\CustomMenuItem;
use App\Entity\FrontendMenuItemStatus;
use App\Repository\FrontendMenuItemStatusRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin/FrontendMenuManagementController — the /admin/frontend-menu-management listing
 *  of every registered FrontendMenuItemInterface plus admin-created custom links, the
 *  CSRF-protected per-item Active/Inactive toggle (for both core/bundle and custom items), custom
 *  item create/update/delete, and drag-and-drop reordering. No bundle implements
 *  FrontendMenuItemInterface yet (this is brand-new core infrastructure, see issue #4), so — like
 *  AdminBundleManagementCest uses the real CartHoldBundle descriptor rather than a fake one — this
 *  suite uses App\Tests\Support\Fixtures\TestFrontendMenuItem, tagged app.frontend_menu_item only
 *  in the test environment (see when@test in config/services.yaml), since items are wired through
 *  #[AutowireIterator('app.frontend_menu_item')] and can't be substituted per-test. The four core
 *  items (My Orders/My Quotes/FAQ/Contact) are tagged unconditionally, so they're present in every
 *  environment including test. */
final class FrontendMenuManagementCest
{
    private const TEST_KEY = 'test.frontend.menu.item';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-frontend-menu-mgmt-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    public function indexListsRegisteredMenuItemWithSourceAndStatus(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/frontend-menu-management');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Frontend Menu Management');
        $I->see('Test Frontend Menu Item');
        $I->see('FunctionalTestFixture');
        $I->seeElement('form[action$="/' . self::TEST_KEY . '/toggle"] input[name="_token"]');
    }

    public function indexListsTheFourCoreMenuItems(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/frontend-menu-management');
        $I->seeResponseCodeIsSuccessful();

        $I->see('My Orders');
        $I->see('My Quotes');
        $I->see('FAQ');
        $I->see('Contact');
        $I->seeElement('form[action$="/core.my_orders/toggle"] input[name="_token"]');
    }

    public function creatingACustomMenuItemPersistsItAndShowsItInTheList(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/frontend-menu-management/custom/create');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/frontend-menu-management/custom/create', [
            '_token' => $token,
            'label' => 'Warranty Policy',
            'url' => '/warranty',
        ]);
        $I->seeCurrentUrlEquals('/admin/frontend-menu-management');

        $I->seeInRepository(CustomMenuItem::class, ['label' => 'Warranty Policy', 'url' => '/warranty']);

        $I->amOnPage('/admin/frontend-menu-management');
        $I->see('Warranty Policy');
        $I->see('Custom');
    }

    public function creatingACustomMenuItemWithoutAUrlFailsValidation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/frontend-menu-management/custom/create');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/frontend-menu-management/custom/create', [
            '_token' => $token,
            'label' => 'Bad Item',
            'url' => 'not-a-url',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('must be a relative path');

        $I->dontSeeInRepository(CustomMenuItem::class, ['label' => 'Bad Item']);
    }

    public function updatingACustomMenuItemPersistsChanges(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $item = (new CustomMenuItem())->setLabel('Old Label')->setUrl('/old-url');
        $I->haveInRepository($item);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/frontend-menu-management/custom/' . $item->getId() . '/update');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('label', 'Old Label');

        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/frontend-menu-management/custom/' . $item->getId() . '/update', [
            '_token' => $token,
            'label' => 'New Label',
            'url' => '/new-url',
        ]);
        $I->seeCurrentUrlEquals('/admin/frontend-menu-management');

        $I->seeInRepository(CustomMenuItem::class, ['id' => $item->getId(), 'label' => 'New Label', 'url' => '/new-url']);
    }

    public function togglingACustomMenuItemFlipsItsStatus(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $item = (new CustomMenuItem())->setLabel('Togglable')->setUrl('/togglable');
        $I->haveInRepository($item);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/frontend-menu-management');
        $token = $I->grabAttributeFrom('form[action$="/custom/' . $item->getId() . '/toggle"] input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/frontend-menu-management/custom/' . $item->getId() . '/toggle', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(CustomMenuItem::class, ['id' => $item->getId(), 'status' => CustomMenuItem::STATUS_INACTIVE]);
    }

    public function deletingACustomMenuItemRemovesIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $item = (new CustomMenuItem())->setLabel('Deletable')->setUrl('/deletable');
        $I->haveInRepository($item);
        $id = $item->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/frontend-menu-management');
        $token = $I->grabAttributeFrom('form[action$="/custom/' . $id . '/delete"] input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/frontend-menu-management/custom/' . $id . '/delete', ['_token' => $token]);
        $I->seeCurrentUrlEquals('/admin/frontend-menu-management');

        $I->dontSeeInRepository(CustomMenuItem::class, ['id' => $id]);
    }

    public function reorderPersistsNewSortOrderForCoreAndCustomItems(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $item = (new CustomMenuItem())->setLabel('Reorderable')->setUrl('/reorderable');
        $I->haveInRepository($item);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/frontend-menu-management');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('#frontend-menu-reorder-token', 'value');

        $order = json_encode([
            ['type' => 'custom', 'id' => (string) $item->getId()],
            ['type' => 'core', 'id' => 'core.my_orders'],
        ]);

        $I->sendAjaxPostRequest('/admin/frontend-menu-management/reorder', [
            '_token' => $token,
            'order' => $order,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(CustomMenuItem::class, ['id' => $item->getId(), 'sortOrder' => 0]);
        $I->seeInRepository(FrontendMenuItemStatus::class, ['menuKey' => 'core.my_orders', 'sortOrder' => 1]);
    }

    public function togglingWithValidTokenFlipsStatusAndCanBeToggledBack(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/frontend-menu-management');
        $I->seeResponseCodeIsSuccessful();

        $repo = $I->grabService(FrontendMenuItemStatusRepository::class);
        $originalStatus = $repo->ensureByKey(self::TEST_KEY)->getStatus();
        $flippedStatus = $originalStatus === FrontendMenuItemStatus::STATUS_ACTIVE
            ? FrontendMenuItemStatus::STATUS_INACTIVE
            : FrontendMenuItemStatus::STATUS_ACTIVE;

        $token = $I->grabAttributeFrom('form[action$="/' . self::TEST_KEY . '/toggle"] input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/frontend-menu-management/' . self::TEST_KEY . '/toggle', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(FrontendMenuItemStatus::class, ['menuKey' => self::TEST_KEY, 'status' => $flippedStatus]);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/frontend-menu-management');
        $I->seeResponseCodeIsSuccessful();

        $restoreToken = $I->grabAttributeFrom('form[action$="/' . self::TEST_KEY . '/toggle"] input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/frontend-menu-management/' . self::TEST_KEY . '/toggle', ['_token' => $restoreToken]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(FrontendMenuItemStatus::class, ['menuKey' => self::TEST_KEY, 'status' => $originalStatus]);
    }

    public function toggleWithInvalidCsrfTokenIsRejectedAndLeavesStatusUnchanged(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $repo = $I->grabService(FrontendMenuItemStatusRepository::class);
        $originalStatus = $repo->ensureByKey(self::TEST_KEY)->getStatus();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/frontend-menu-management');
        $I->seeResponseCodeIsSuccessful();

        $I->sendAjaxPostRequest('/admin/frontend-menu-management/' . self::TEST_KEY . '/toggle', ['_token' => 'not-a-valid-token']);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(FrontendMenuItemStatus::class, ['menuKey' => self::TEST_KEY, 'status' => $originalStatus]);
    }
}
