<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\CustomFieldDefinition;
use App\Service\CustomField\CustomFieldObjectTypeCatalogue;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin\CustomFieldController — the /admin/custom-fields definition grid (one table over
 *  every object type, its object-type bar and its filters), create()/update() form validation
 *  (label/field type/object type/slug uniqueness) and persistence, and delete() including its
 *  bundle-managed-source guard, all gated by CSRF.
 *
 *  The index was five `.panel > table.data-table` blocks stacked down the page, one per object
 *  type, until `AdminListScreenConventionsCest` caught it: a page that lists rows out of the
 *  database and carries no `.table-card` is outside the grid model, and outside it no convention
 *  in that file — and no rule of `app.css` — reaches it. So the five groups became one grid with an
 *  Object Type column, and the grouping became the filter over that column it always was. The three
 *  index tests below are about what that move had to keep: every row still says which type it is
 *  for, the filter still narrows to one type, and the bar still names every type the screen offers
 *  including the ones carrying nothing. */
final class AdminCustomFieldCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-customfield-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeDefinition(FunctionalTester $I, string $slug, string $objectType = CustomFieldDefinition::OBJECT_TYPE_PRODUCT, ?string $source = null): CustomFieldDefinition
    {
        $definition = (new CustomFieldDefinition())
            ->setObjectType($objectType)
            ->setSlug($slug)
            ->setLabel('Label for ' . $slug)
            ->setFieldType(CustomFieldDefinition::FIELD_TYPE_TEXT)
            ->setSource($source);
        $I->haveInRepository($definition);

        return $definition;
    }

    /**
     * One grid over every object type, and each row says which type it is for.
     *
     * The five stacked tables stated the object type once, in the heading above each of them. One
     * table has to state it on the row, so that is what is asserted here — and asserted on the
     * cell rather than on the word anywhere on the page, because "Product" and "Customer" are in
     * the sidebar of every admin screen and a bare see() would pass with the column deleted. The
     * grid is narrowed to the one row first so the cell can be compared exactly; the two halves
     * are each other's control, since a selector that has stopped matching returns [] for both.
     */
    public function indexListsEveryObjectTypeInOneGridAndNamesTheTypeOnEachRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->makeDefinition($I, 'cf_index_product_field', CustomFieldDefinition::OBJECT_TYPE_PRODUCT);
        $this->makeDefinition($I, 'cf_index_company_field', CustomFieldDefinition::OBJECT_TYPE_COMPANY);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/custom-fields');
        $I->seeResponseCodeIsSuccessful();

        // In the grid model: the card is a DIRECT child of the content frame, which is the only
        // shape app.css:7800's full-height clamp applies to, and it opts out of the JavaScript
        // pager the way every other unpaged grid does.
        $I->seeElement('.content-frame > .table-card.no-paginate .table-scroll-region');

        // Both types' definitions on the one grid, where they used to be in two tables.
        $I->see('Label for cf_index_product_field');
        $I->see('Label for cf_index_company_field');

        $I->amOnPage('/admin/custom-fields?filters%5Bslug%5D=cf_index_product_field');
        $I->assertSame(['Product'], $this->objectTypeCells($I));

        $I->amOnPage('/admin/custom-fields?filters%5Bslug%5D=cf_index_company_field');
        $I->assertSame(['Customer'], $this->objectTypeCells($I));
    }

    /**
     * The Object Type filter does what the five headings did: shows one type's fields and no other.
     *
     * Both directions, on the same control, because "the estimate field is not here" also passes
     * on a filter that has stopped returning anything at all.
     */
    public function theObjectTypeFilterNarrowsTheGridToOneTypesDefinitions(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->makeDefinition($I, 'cf_filter_product', CustomFieldDefinition::OBJECT_TYPE_PRODUCT);
        $this->makeDefinition($I, 'cf_filter_estimate', CustomFieldDefinition::OBJECT_TYPE_ESTIMATE);

        $I->haveHttpHeader('Host', 'admin.localhost');

        $I->amOnPage('/admin/custom-fields?filters%5BobjectType%5D=estimate');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Label for cf_filter_estimate');
        $I->dontSee('Label for cf_filter_product');

        $I->amOnPage('/admin/custom-fields?filters%5BobjectType%5D=product');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Label for cf_filter_product');
        $I->dontSee('Label for cf_filter_estimate');
    }

    /**
     * The Source filter accepts the word the Source column actually prints.
     *
     * That column has no blank cell: a definition no bundle registered shows "Admin". So "admin"
     * in the box has to mean what the reader can see — `source IS NULL` — rather than a LIKE over
     * a column that holds nothing for those rows, and a bundle name still matches the stored value.
     * Both directions, each the other's control.
     */
    public function theSourceFilterAcceptsAdminAndABundleName(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->makeDefinition($I, 'cf_source_admin_owned', CustomFieldDefinition::OBJECT_TYPE_PRODUCT);
        $this->makeDefinition($I, 'cf_source_bundle_owned', CustomFieldDefinition::OBJECT_TYPE_PRODUCT, 'CustomFieldSourceTestBundle');

        $I->haveHttpHeader('Host', 'admin.localhost');

        $I->amOnPage('/admin/custom-fields?filters%5Bsource%5D=admin');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Label for cf_source_admin_owned');
        $I->dontSee('Label for cf_source_bundle_owned');

        $I->amOnPage('/admin/custom-fields?filters%5Bsource%5D=CustomFieldSourceTestBundle');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Label for cf_source_bundle_owned');
        $I->dontSee('Label for cf_source_admin_owned');
    }

    /**
     * The object-type bar names every type the screen offers, with a count apiece.
     *
     * This is the one thing the stacked tables did that a bare grid would not: a type carrying
     * nothing still had a table, and that table said "No custom fields defined for orders yet". The
     * bar says it instead, so the roll call has to be complete — every type, whether or not it has
     * a row — and the count has to move with the data rather than being a decoration.
     *
     * Counted as a difference rather than against a figure (#627). Bundles register definitions of
     * their own on some of these types, so no absolute number here would be a fact about this
     * test's own fixtures; the bar is read before and after, and the type nothing was added to is
     * the control that says the reading is not simply counting the whole table into every tab.
     */
    public function theObjectTypeBarNamesEveryTypeAndCountsWhatIsOnIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $I->haveHttpHeader('Host', 'admin.localhost');

        $I->amOnPage('/admin/custom-fields');
        $I->seeResponseCodeIsSuccessful();
        $before = $this->objectTypeBar($I);

        // Enumerated off the catalogue rather than hand-listed (docs/QUEUE.md: "enumerate, do not
        // list") — a hardcoded array here is exactly what let Vendor join Product/Customer/Order/
        // Invoice/Estimate as a real, registered type without this test noticing either way.
        $expectedLabels = ['All object types', ...array_values($I->grabService(CustomFieldObjectTypeCatalogue::class)->labels())];
        $I->assertSame(
            $expectedLabels,
            array_keys($before),
            'The object-type bar no longer names every type the screen offers, each with a count.'
            . ' A type missing from it is a type whose fields can be created and then never found.',
        );

        $this->makeDefinition($I, 'cf_bar_estimate_one', CustomFieldDefinition::OBJECT_TYPE_ESTIMATE);
        $this->makeDefinition($I, 'cf_bar_estimate_two', CustomFieldDefinition::OBJECT_TYPE_ESTIMATE);

        $I->amOnPage('/admin/custom-fields');
        $after = $this->objectTypeBar($I);

        $I->assertSame($before['Estimate'] + 2, $after['Estimate'], 'Two estimate definitions were added and the Estimate tab did not count them.');
        $I->assertSame($before['All object types'] + 2, $after['All object types'], 'The All tab is meant to be every type added up, and it did not move with them.');
        $I->assertSame($before['Invoice'], $after['Invoice'], 'Nothing was added to Invoice, so its count moving means the bar is counting the whole table into every tab.');
    }

    /** @return list<string> The Object Type cell of every row on the grid, in order. */
    private function objectTypeCells(FunctionalTester $I): array
    {
        return array_values(array_map(
            static fn (string $cell): string => trim($cell),
            $I->grabMultiple('.table-card tbody td[data-label="Object Type"]'),
        ));
    }

    /**
     * The object-type bar as `label => count`, read off the rendered tabs.
     *
     * A tab that has lost its count drops out of this array rather than being read as zero, so the
     * keys alone are an assertion that every tab still carries one.
     *
     * @return array<string, int>
     */
    private function objectTypeBar(FunctionalTester $I): array
    {
        $counts = [];

        foreach ($I->grabMultiple('.order-status-tabs a') as $tab) {
            if (preg_match('/^(.*?)\s*\((\d+)\)$/', trim($tab), $matches) === 1) {
                $counts[$matches[1]] = (int) $matches[2];
            }
        }

        return $counts;
    }

    public function createRendersTheForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/custom-fields/create');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Create Custom Field');
    }

    public function creatingWithValidDataPersistsAndRedirectsToIndex(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/custom-fields/create');
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/custom-fields/create', [
            '_token' => $token,
            'object_type' => CustomFieldDefinition::OBJECT_TYPE_PRODUCT,
            'slug' => 'cf_create_new_field',
            'label' => 'Newly Created Field',
            'field_type' => CustomFieldDefinition::FIELD_TYPE_NUMBER,
            'sort_order' => '5',
            'visible_on_listing' => '1',
        ]);
        $I->seeCurrentUrlEquals('/admin/custom-fields');

        $I->seeInRepository(CustomFieldDefinition::class, [
            'objectType' => CustomFieldDefinition::OBJECT_TYPE_PRODUCT,
            'slug' => 'cf_create_new_field',
            'label' => 'Newly Created Field',
            'fieldType' => CustomFieldDefinition::FIELD_TYPE_NUMBER,
            'sortOrder' => 5,
            'visibleOnListing' => true,
        ]);
    }

    public function creatingWithABlankLabelFailsValidationAndDoesNotPersist(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/custom-fields/create');
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/custom-fields/create', [
            '_token' => $token,
            'object_type' => CustomFieldDefinition::OBJECT_TYPE_PRODUCT,
            'slug' => 'cf_create_blank_label',
            'label' => '   ',
            'field_type' => CustomFieldDefinition::FIELD_TYPE_TEXT,
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('Label is required.');

        $I->dontSeeInRepository(CustomFieldDefinition::class, ['slug' => 'cf_create_blank_label']);
    }

    public function creatingWithADuplicateSlugForTheSameObjectTypeFailsValidation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->makeDefinition($I, 'cf_create_dup_slug', CustomFieldDefinition::OBJECT_TYPE_PRODUCT);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/custom-fields/create');
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/custom-fields/create', [
            '_token' => $token,
            'object_type' => CustomFieldDefinition::OBJECT_TYPE_PRODUCT,
            'slug' => 'cf_create_dup_slug',
            'label' => 'Duplicate Slug Attempt',
            'field_type' => CustomFieldDefinition::FIELD_TYPE_TEXT,
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('already exists for this object type');
    }

    public function creatingWithAnInvalidCsrfTokenIsRejected(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/custom-fields/create', [
            '_token' => 'not-a-real-token',
            'object_type' => CustomFieldDefinition::OBJECT_TYPE_PRODUCT,
            'slug' => 'cf_create_csrf_rejected',
            'label' => 'Csrf Rejected Field',
            'field_type' => CustomFieldDefinition::FIELD_TYPE_TEXT,
        ]);
        $I->seeResponseCodeIs(403);

        $I->dontSeeInRepository(CustomFieldDefinition::class, ['slug' => 'cf_create_csrf_rejected']);
    }

    public function updateRendersThePrefilledFormAndPersistsChangesWithoutTouchingSlugOrObjectType(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $definition = $this->makeDefinition($I, 'cf_update_original_slug', CustomFieldDefinition::OBJECT_TYPE_COMPANY);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/custom-fields/' . $definition->getId() . '/update');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('label', 'Label for cf_update_original_slug');

        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/custom-fields/' . $definition->getId() . '/update', [
            '_token' => $token,
            'label' => 'Updated Label',
            'field_type' => CustomFieldDefinition::FIELD_TYPE_SELECT,
            'options' => "One\nTwo",
            'sort_order' => '9',
            // Even if a malicious/stale form posts a different slug or object type, updateOnly must ignore them.
            'slug' => 'attempted_slug_change',
            'object_type' => CustomFieldDefinition::OBJECT_TYPE_ORDER,
        ]);
        $I->seeCurrentUrlEquals('/admin/custom-fields');

        $I->seeInRepository(CustomFieldDefinition::class, [
            'id' => $definition->getId(),
            'slug' => 'cf_update_original_slug',
            'objectType' => CustomFieldDefinition::OBJECT_TYPE_COMPANY,
            'label' => 'Updated Label',
            'fieldType' => CustomFieldDefinition::FIELD_TYPE_SELECT,
            'sortOrder' => 9,
        ]);
    }

    public function updatingWithABlankLabelFailsValidationAndLeavesTheOriginalLabelInPlace(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $definition = $this->makeDefinition($I, 'cf_update_invalid_slug', CustomFieldDefinition::OBJECT_TYPE_PRODUCT);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/custom-fields/' . $definition->getId() . '/update');
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/custom-fields/' . $definition->getId() . '/update', [
            '_token' => $token,
            'label' => '',
            'field_type' => CustomFieldDefinition::FIELD_TYPE_TEXT,
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('Label is required.');

        $I->seeInRepository(CustomFieldDefinition::class, [
            'id' => $definition->getId(),
            'label' => 'Label for cf_update_invalid_slug',
        ]);
    }

    public function updatingAnUnknownIdRedirectsToIndexWithAnErrorFlash(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/custom-fields/999999999/update');
        $I->seeCurrentUrlEquals('/admin/custom-fields');
        $I->see('Custom field could not be found.');
    }

    public function updatingWithAnInvalidCsrfTokenIsRejected(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $definition = $this->makeDefinition($I, 'cf_update_csrf_rejected', CustomFieldDefinition::OBJECT_TYPE_PRODUCT);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/custom-fields/' . $definition->getId() . '/update', [
            '_token' => 'not-a-real-token',
            'label' => 'Should Not Persist',
            'field_type' => CustomFieldDefinition::FIELD_TYPE_TEXT,
        ]);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(CustomFieldDefinition::class, [
            'id' => $definition->getId(),
            'label' => 'Label for cf_update_csrf_rejected',
        ]);
    }

    public function deletingAnExistingAdminCreatedFieldRemovesItAndRedirectsWithSuccessFlash(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $definition = $this->makeDefinition($I, 'cf_delete_me', CustomFieldDefinition::OBJECT_TYPE_PRODUCT);
        $id = $definition->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/custom-fields');
        $token = $I->grabAttributeFrom(
            'form[action$="/admin/custom-fields/' . $id . '/delete"] input[name="_token"]',
            'value'
        );

        $I->sendAjaxPostRequest('/admin/custom-fields/' . $id . '/delete', ['_token' => $token]);
        $I->seeCurrentUrlEquals('/admin/custom-fields');
        $I->see('was deleted.');

        $I->dontSeeInRepository(CustomFieldDefinition::class, ['id' => $id]);
    }

    public function deletingABundleManagedFieldIsBlockedAndLeavesItInPlace(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        // Create it admin-owned first so its delete form actually renders (bundle-managed rows
        // get a "Managed by bundle" badge instead), grab a real token off that form, then flip
        // it to bundle-managed — simulating a forged request with an otherwise-valid token.
        $definition = $this->makeDefinition($I, 'cf_delete_bundle_managed', CustomFieldDefinition::OBJECT_TYPE_PRODUCT);
        $id = $definition->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/custom-fields');
        $token = $I->grabAttributeFrom(
            'form[action$="/admin/custom-fields/' . $id . '/delete"] input[name="_token"]',
            'value'
        );

        $definition->setSource('SomeBundle');
        $I->haveInRepository($definition);

        $I->sendAjaxPostRequest('/admin/custom-fields/' . $id . '/delete', ['_token' => $token]);
        $I->seeCurrentUrlEquals('/admin/custom-fields');
        $I->see('cannot be deleted from here');

        $I->seeInRepository(CustomFieldDefinition::class, ['id' => $id]);
    }

    public function deletingWithAnInvalidCsrfTokenLeavesItInPlace(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $definition = $this->makeDefinition($I, 'cf_delete_csrf_rejected', CustomFieldDefinition::OBJECT_TYPE_PRODUCT);
        $id = $definition->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/custom-fields');
        $I->sendAjaxPostRequest('/admin/custom-fields/' . $id . '/delete', ['_token' => 'not-a-real-token']);
        $I->assertStringContainsString('Your session expired', $I->grabPageSource());

        $I->seeInRepository(CustomFieldDefinition::class, ['id' => $id]);
    }

    public function deletingAnUnknownIdRedirectsToIndexWithAnErrorFlash(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        // CSRF is checked before the existence lookup, so hitting the "not found" branch needs a
        // genuinely valid token for that id — grab one, delete the row for real to free up the id
        // for the not-found scenario, then reuse the (still session-valid, non-single-use) token
        // against the now-deleted id.
        $definition = $this->makeDefinition($I, 'cf_delete_then_reuse_token', CustomFieldDefinition::OBJECT_TYPE_PRODUCT);
        $id = $definition->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/custom-fields');
        $token = $I->grabAttributeFrom(
            'form[action$="/admin/custom-fields/' . $id . '/delete"] input[name="_token"]',
            'value'
        );

        $I->sendAjaxPostRequest('/admin/custom-fields/' . $id . '/delete', ['_token' => $token]);
        $I->dontSeeInRepository(CustomFieldDefinition::class, ['id' => $id]);

        $I->sendAjaxPostRequest('/admin/custom-fields/' . $id . '/delete', ['_token' => $token]);
        $I->seeCurrentUrlEquals('/admin/custom-fields');
        $I->see('Custom field could not be found.');
    }
}
