<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin\CategoryController — the /admin/category index (search, XHR/JSON mode),
 *  create()/update() form validation and parent assignment, toggle(), and delete() including
 *  its product-assigned and has-subcategories guards. */
final class AdminCategoryCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-cat-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeCategory(FunctionalTester $I, string $name, string $status = 'Visible', ?ProductCategory $parent = null): ProductCategory
    {
        $category = (new ProductCategory())->setName($name)->setStatus($status)->setParent($parent);
        $I->haveInRepository($category);

        return $category;
    }

    /** The AJAX toggle/delete tokens ride on the row buttons rather than a form, so they are read
     *  off the rendered index the same way the browser's JS reads them. */
    private function grabToggleToken(FunctionalTester $I, int $id): string
    {
        return (string) $I->grabAttributeFrom('.js-category-status[data-url$="/toggle/' . $id . '"]', 'data-token');
    }

    private function grabDeleteToken(FunctionalTester $I, int $id): string
    {
        return (string) $I->grabAttributeFrom('.js-delete[data-url$="/delete/' . $id . '"]', 'data-token');
    }

    /** Tokens are bound to the row id, so reaching the not-found branch means holding a real token
     *  for an id that no longer exists: render the row, take its token, then drop the row. */
    private function dropCategory(FunctionalTester $I, ProductCategory $category): void
    {
        $entityManager = $I->grabService('doctrine.orm.entity_manager');
        $entityManager->remove($entityManager->find(ProductCategory::class, $category->getId()));
        $entityManager->flush();
    }

    public function indexListsCategoriesWithHierarchyAndAppliesSearch(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $parent = $this->makeCategory($I, 'Cat Index Parent');
        $this->makeCategory($I, 'Cat Index Child', 'Visible', $parent);
        $this->makeCategory($I, 'Cat Index Unrelated');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/index');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Cat Index Parent');
        $I->see('Cat Index Child');
        $I->see('Cat Index Unrelated');

        $I->amOnPage('/admin/category/index?q=Child');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Cat Index Child');
        $I->dontSee('Cat Index Unrelated');
    }

    public function indexAsXhrReturnsJsonWithRenderedRowsAndPagination(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->makeCategory($I, 'Cat Xhr Category');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxGetRequest('/admin/category/index?q=Cat+Xhr+Category');
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertStringContainsString('Cat Xhr Category', $response['html']);
        $I->assertSame(1, $response['total']);
        $I->assertSame(1, $response['page']);
        $I->assertSame(1, $response['pages']);
    }

    /**
     * #370: the row list is depth-first ordered so a parent and its children are normally
     * contiguous, but a plain array_slice pagination has no awareness of that — when the split
     * lands exactly between a parent and its child, the child's page used to show only the
     * "Parent category" column value, not a standalone row for the parent the way the rest of the
     * list does. With limit=1 the parent lands alone on page 1 and the child alone on page 2;
     * page 2 should still repeat the parent's own row (identified by its toggle button's id) atop
     * the page, not merely name it in the child's "Parent category" cell.
     */
    public function indexRepeatsTheParentRowWhenItSplitsAcrossPagination(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $parent = $this->makeCategory($I, 'Cat Page Split Parent');
        $this->makeCategory($I, 'Cat Page Split Child', 'Visible', $parent);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxGetRequest('/admin/category/index?limit=1&page=2');
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertStringContainsString('Cat Page Split Child', $response['html']);
        $I->assertStringContainsString(
            'toggle/' . $parent->getId(),
            $response['html'],
        );
    }

    public function createRendersTheForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/create');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Create');
        $I->seeInField('status', 'Visible');
    }

    public function creatingWithValidDataPersistsWithParentAndRedirectsToIndex(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $parent = $this->makeCategory($I, 'Cat Create Parent');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/create');
        $I->sendAjaxPostRequest('/admin/category/create', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => 'Cat Created Child',
            'status' => 'Hidden',
            'parent_id' => (string) $parent->getId(),
        ]);
        $I->seeCurrentUrlEquals('/admin/category/index');

        $I->seeInRepository(ProductCategory::class, [
            'name' => 'Cat Created Child',
            'status' => 'Hidden',
            'parent' => $parent->getId(),
        ]);
    }

    public function creatingWithABlankNameFailsValidationAndDoesNotPersist(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/create');

        $before = $I->grabNumRecords(ProductCategory::class);

        $I->sendAjaxPostRequest('/admin/category/create', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => '   ',
            'status' => 'Visible',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('Category name is required.');

        // Counted, not matched on `name = ''` (#594). The posted value is three spaces, so a
        // controller that persisted-then-validated would leave a row whose name is '   ' — which
        // the old `dontSeeInRepository(['name' => ''])` query could never find, whatever it stored.
        $I->assertSame(
            $before,
            $I->grabNumRecords(ProductCategory::class),
            'a refused create must leave no product_category row behind, whatever the blank name was stored as',
        );
    }

    public function updateRendersThePrefilledFormAndPersistsChanges(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $category = $this->makeCategory($I, 'Cat Update Original', 'Visible');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/update/' . $category->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('name', 'Cat Update Original');

        $I->sendAjaxPostRequest('/admin/category/update/' . $category->getId(), [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => 'Cat Update Renamed',
            'status' => 'Hidden',
        ]);
        $I->seeCurrentUrlEquals('/admin/category/index');

        $I->seeInRepository(ProductCategory::class, [
            'id' => $category->getId(),
            'name' => 'Cat Update Renamed',
            'status' => 'Hidden',
        ]);
    }

    public function updatingWithASelfParentIgnoresItInsteadOfPersistingASelfReference(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $category = $this->makeCategory($I, 'Cat Self Parent Original', 'Visible');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/update/' . $category->getId());
        $I->sendAjaxPostRequest('/admin/category/update/' . $category->getId(), [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => 'Cat Self Parent Original',
            'status' => 'Visible',
            'parent_id' => (string) $category->getId(),
        ]);
        $I->seeCurrentUrlEquals('/admin/category/index');

        $I->seeInRepository(ProductCategory::class, [
            'id' => $category->getId(),
            'parent' => null,
        ]);
    }

    public function updatingWithABlankNameFailsValidationAndLeavesTheOriginalNameInPlace(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $category = $this->makeCategory($I, 'Cat Update Invalid Original', 'Visible');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/update/' . $category->getId());
        $I->sendAjaxPostRequest('/admin/category/update/' . $category->getId(), [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => '',
            'status' => 'Visible',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('Category name is required.');

        $I->seeInRepository(ProductCategory::class, [
            'id' => $category->getId(),
            'name' => 'Cat Update Invalid Original',
        ]);
    }

    public function updatingAnUnknownIdRedirectsToIndexWithAnErrorFlash(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/update/999999999');
        $I->seeCurrentUrlEquals('/admin/category/index');
        $I->see('Category could not be found.');
    }

    public function togglingFlipsStatusAndReturnsJsonSuccess(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $category = $this->makeCategory($I, 'Cat Toggle Me', 'Visible');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/index');
        $I->sendAjaxPostRequest('/admin/category/toggle/' . $category->getId(), [
            '_token' => $this->grabToggleToken($I, $category->getId()),
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);
        $I->assertSame('Hidden', $response['status']);

        $I->seeInRepository(ProductCategory::class, [
            'id' => $category->getId(),
            'status' => 'Hidden',
        ]);
    }

    public function togglingAnUnknownIdReturnsJsonNotFound(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $category = $this->makeCategory($I, 'Cat Toggle Vanishing', 'Visible');
        $id = $category->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/index');
        $token = $this->grabToggleToken($I, $id);
        $this->dropCategory($I, $category);

        $I->sendAjaxPostRequest('/admin/category/toggle/' . $id, ['_token' => $token]);
        $I->seeResponseCodeIs(404);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
    }

    public function deletingAnExistingCategoryWithNoProductsOrChildrenRemovesItAndReturnsJsonSuccess(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $category = $this->makeCategory($I, 'Cat Delete Me', 'Visible');
        $id = $category->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/index');
        $I->sendAjaxPostRequest('/admin/category/delete/' . $id, [
            '_token' => $this->grabDeleteToken($I, $id),
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);
        $I->assertStringContainsString('Cat Delete Me', $response['message']);

        $I->dontSeeInRepository(ProductCategory::class, ['id' => $id]);
    }

    public function deletingACategoryAssignedToProductsIsBlockedWithConflict(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $category = $this->makeCategory($I, 'Cat Delete Blocked By Product', 'Visible');
        $product = (new ProductCore())->setSku('CAT-DELETE-BLOCK-SKU')->setName('Cat Delete Block Product')->setCategory($category)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/index');
        $I->sendAjaxPostRequest('/admin/category/delete/' . $category->getId(), [
            '_token' => $this->grabDeleteToken($I, $category->getId()),
        ]);
        $I->seeResponseCodeIs(409);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
        $I->assertStringContainsString('1 product', $response['message']);

        $I->seeInRepository(ProductCategory::class, ['id' => $category->getId()]);
    }

    public function deletingACategoryWithSubcategoriesIsBlockedWithConflict(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $parent = $this->makeCategory($I, 'Cat Delete Blocked Parent', 'Visible');
        $this->makeCategory($I, 'Cat Delete Blocked Child', 'Visible', $parent);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/index');
        $I->sendAjaxPostRequest('/admin/category/delete/' . $parent->getId(), [
            '_token' => $this->grabDeleteToken($I, $parent->getId()),
        ]);
        $I->seeResponseCodeIs(409);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
        $I->assertStringContainsString('1 subcategory', $response['message']);

        $I->seeInRepository(ProductCategory::class, ['id' => $parent->getId()]);
    }

    public function togglingWithoutAValidTokenIsRejectedAndLeavesTheStatusAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $category = $this->makeCategory($I, 'Cat Toggle Forged', 'Visible');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/category/toggle/' . $category->getId(), ['_token' => 'forged']);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(ProductCategory::class, [
            'id' => $category->getId(),
            'status' => 'Visible',
        ]);
    }

    public function deletingWithoutAValidTokenIsRejectedAndKeepsTheCategory(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $category = $this->makeCategory($I, 'Cat Delete Forged', 'Visible');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/category/delete/' . $category->getId(), ['_token' => 'forged']);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(ProductCategory::class, ['id' => $category->getId()]);
    }

    public function creatingWithoutAValidTokenIsRejectedAndPersistsNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/create');
        $I->sendAjaxPostRequest('/admin/category/create', [
            '_token' => 'forged',
            'name' => 'Cat Forged Create',
            'status' => 'Visible',
        ]);

        $I->dontSeeInRepository(ProductCategory::class, ['name' => 'Cat Forged Create']);
    }

    public function updatingWithoutAValidTokenIsRejectedAndLeavesTheRowAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $category = $this->makeCategory($I, 'Cat Forged Update Original', 'Visible');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/update/' . $category->getId());
        $I->sendAjaxPostRequest('/admin/category/update/' . $category->getId(), [
            '_token' => 'forged',
            'name' => 'Cat Forged Update Renamed',
            'status' => 'Hidden',
        ]);

        $I->seeInRepository(ProductCategory::class, [
            'id' => $category->getId(),
            'name' => 'Cat Forged Update Original',
            'status' => 'Visible',
        ]);
    }

    public function deletingAnUnknownIdReturnsJsonNotFound(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $category = $this->makeCategory($I, 'Cat Delete Vanishing', 'Visible');
        $id = $category->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/index');
        $token = $this->grabDeleteToken($I, $id);
        $this->dropCategory($I, $category);

        $I->sendAjaxPostRequest('/admin/category/delete/' . $id, ['_token' => $token]);
        $I->seeResponseCodeIs(404);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
    }
}
