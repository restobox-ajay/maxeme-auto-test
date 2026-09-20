<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\CustomFieldDefinition;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Vendor as a custom-field object type (#745) — the first the storage seam does not own.
 *
 * ## Conducted (#624)
 *
 * Every definition is created through the real `/admin/custom-fields` screens, every vendor is
 * created or edited through the real `VendorController` screens, and every assertion reads
 * `custom_field_value_vendor` off a second connection rather than trusting an entity the request
 * left in memory. Nothing here calls `CustomFieldRenderer` or `CustomFieldValueRepository` — that
 * would prove the seam this Cest exists to prove is actually wired into the screens.
 *
 * ## What this proves that `EveryCustomFieldValueEntityIsRegisteredTest` cannot
 *
 * That test proves `VendorCustomFieldObjectTypeProvider` is registered — a structural fact reachable
 * without ever opening a browser. It cannot prove `VendorController::create()`/`save()` actually
 * call `CustomFieldRenderer`, because a definition can resolve through the catalogue perfectly and
 * still never appear on the form if nobody wired the render/save calls into the controller — which
 * is exactly the gap #536/#535's exploration found Vendor sitting in before #745.
 */
final class AdminVendorCustomFieldCest
{
    private int $seq = 0;

    private function loginAsAdmin(FunctionalTester $I): void
    {
        ++$this->seq;
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('vendor-cf-' . $this->seq . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function createDefinition(FunctionalTester $I, string $slug, string $label): void
    {
        $I->amOnPage('/admin/custom-fields/create');
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest('/admin/custom-fields/create', [
            '_token' => $I->csrfToken(),
            'object_type' => 'vendor',
            'slug' => $slug,
            'label' => $label,
            'field_type' => CustomFieldDefinition::FIELD_TYPE_TEXT,
            'visible_on_add' => '1',
            'visible_on_edit' => '1',
        ]);
        $I->seeCurrentUrlEquals('/admin/custom-fields');
    }

    /** Vendor is a real option on the create screen — not hidden despite being resolvable. */
    public function vendorIsOfferedOnTheDefinitionCreateForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/custom-fields/create');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name="object_type"] option[value="vendor"]');
    }

    public function theFieldRendersOnTheCreateScreenAndSavesWithTheNewVendor(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->createDefinition($I, 'account_rep_' . $this->seq, 'Account Rep');

        $I->amOnPage('/admin/bundles/procurement/vendors/new');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="custom_field[account_rep_' . $this->seq . ']"]');

        $I->sendFormPostRequest('/admin/bundles/procurement/vendors/new', [
            '_token' => $I->csrfToken(),
            'name' => 'Custom Field Vendor Co',
            'currency' => 'CAD',
            'status' => 'Active',
            'custom_field' => ['account_rep_' . $this->seq => 'Jamie Lee'],
        ]);

        $vendorId = $this->vendorIdByName($I, 'Custom Field Vendor Co');
        $I->assertNotNull($vendorId, 'The vendor create screen did not persist a row at all.');

        $I->assertSame(['account_rep_' . $this->seq => 'Jamie Lee'], $this->storedValuesBySlug($I, $vendorId));
    }

    public function theFieldRendersPrefilledOnEditAndAnUpdateReplacesIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->createDefinition($I, 'account_rep_' . $this->seq, 'Account Rep');

        $I->sendFormPostRequest('/admin/bundles/procurement/vendors/new', [
            '_token' => $I->csrfToken(),
            'name' => 'Edit Path Vendor Co',
            'currency' => 'CAD',
            'status' => 'Active',
            'custom_field' => ['account_rep_' . $this->seq => 'Original Rep'],
        ]);
        $vendorId = $this->vendorIdByName($I, 'Edit Path Vendor Co');
        $I->assertNotNull($vendorId);

        $I->amOnPage(sprintf('/admin/bundles/procurement/vendors/%d/edit', $vendorId));
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('custom_field[account_rep_' . $this->seq . ']', 'Original Rep');

        $I->sendFormPostRequest('/admin/bundles/procurement/vendors/save', [
            '_token' => $I->csrfToken(),
            'id' => (string) $vendorId,
            'name' => 'Edit Path Vendor Co',
            'currency' => 'CAD',
            'status' => 'Active',
            'custom_field' => ['account_rep_' . $this->seq => 'Replacement Rep'],
        ]);

        $I->assertSame(['account_rep_' . $this->seq => 'Replacement Rep'], $this->storedValuesBySlug($I, $vendorId));
        // ONE row, not two — the update replaced the value in place rather than appending a second.
        $I->assertCount(1, $this->valueRowsFor($I, $vendorId));
    }

    /** The row that should NOT have changed: a second vendor's value for the same field. */
    public function savingOneVendorLeavesAnotherVendorsValueAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->createDefinition($I, 'account_rep_' . $this->seq, 'Account Rep');

        $I->sendFormPostRequest('/admin/bundles/procurement/vendors/new', [
            '_token' => $I->csrfToken(),
            'name' => 'Vendor Untouched Co',
            'currency' => 'CAD',
            'status' => 'Active',
            'custom_field' => ['account_rep_' . $this->seq => 'Untouched Rep'],
        ]);
        $untouchedId = $this->vendorIdByName($I, 'Vendor Untouched Co');
        $I->assertNotNull($untouchedId);

        $I->sendFormPostRequest('/admin/bundles/procurement/vendors/new', [
            '_token' => $I->csrfToken(),
            'name' => 'Vendor Changed Co',
            'currency' => 'CAD',
            'status' => 'Active',
            'custom_field' => ['account_rep_' . $this->seq => 'Changed Rep'],
        ]);
        $changedId = $this->vendorIdByName($I, 'Vendor Changed Co');
        $I->assertNotNull($changedId);

        $I->sendFormPostRequest('/admin/bundles/procurement/vendors/save', [
            '_token' => $I->csrfToken(),
            'id' => (string) $changedId,
            'name' => 'Vendor Changed Co',
            'currency' => 'CAD',
            'status' => 'Active',
            'custom_field' => ['account_rep_' . $this->seq => 'Changed Again'],
        ]);

        $I->assertSame(['account_rep_' . $this->seq => 'Changed Again'], $this->storedValuesBySlug($I, $changedId));
        $I->assertSame(['account_rep_' . $this->seq => 'Untouched Rep'], $this->storedValuesBySlug($I, $untouchedId), 'A second vendor was resaved and it changed a row that was never touched.');
    }

    /** A value on `vendor` never leaks into another object type's table. */
    public function aVendorValueIsInvisibleToCompanyCustomFields(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->createDefinition($I, 'account_rep_' . $this->seq, 'Account Rep');

        $before = $this->rowCount($I, 'custom_field_value_company');

        $I->sendFormPostRequest('/admin/bundles/procurement/vendors/new', [
            '_token' => $I->csrfToken(),
            'name' => 'Isolation Vendor Co',
            'currency' => 'CAD',
            'status' => 'Active',
            'custom_field' => ['account_rep_' . $this->seq => 'Isolation Rep'],
        ]);

        $I->assertSame($before, $this->rowCount($I, 'custom_field_value_company'), 'Saving a vendor field wrote a row into custom_field_value_company.');
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }

    private function vendorIdByName(FunctionalTester $I, string $name): ?int
    {
        $id = $this->connection($I)->fetchOne('SELECT id FROM vendor WHERE name = ?', [$name]);

        return $id === false ? null : (int) $id;
    }

    /** @return array<string, string|null> slug => value */
    private function storedValuesBySlug(FunctionalTester $I, int $vendorId): array
    {
        $rows = $this->connection($I)->fetchAllAssociative(
            'SELECT d.slug AS slug, v.value AS value
               FROM custom_field_value_vendor v
               JOIN custom_field_definition d ON d.id = v.definition_id
              WHERE v.vendor_id = ?
              ORDER BY d.slug',
            [$vendorId],
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['slug']] = $row['value'] === null ? null : (string) $row['value'];
        }

        return $values;
    }

    /** @return list<array<string, mixed>> */
    private function valueRowsFor(FunctionalTester $I, int $vendorId): array
    {
        return $this->connection($I)->fetchAllAssociative(
            'SELECT id, definition_id, vendor_id, value FROM custom_field_value_vendor WHERE vendor_id = ? ORDER BY id',
            [$vendorId],
        );
    }

    private function rowCount(FunctionalTester $I, string $table): int
    {
        return (int) $this->connection($I)->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }
}
