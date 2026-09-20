<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use App\Entity\CustomFieldDefinition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A definition whose object type belongs to a since-deactivated bundle stays viewable and editable
 * (#745).
 *
 * `CustomFieldObjectTypeCatalogue::labels()` is Active-gated, so once a bundle is switched Inactive
 * its object type drops out of the map — deliberately, so it stops being offered for NEW fields.
 * But existing `CustomFieldDefinition` rows for that type are never deleted, and
 * `CustomFieldController::update()` finds a definition by raw id regardless of what the catalogue
 * currently offers. `admin/custom_field/form.html.twig`'s Object Type display used to look the key
 * straight up in that same Active-gated map (`objectTypes[definition.objectType]`) with no
 * fallback, which is a `Twig\Error\RuntimeError` under `strict_variables: true` for exactly the case
 * this test drives: a vendor-type field, ProcurementBundle switched off, the row's own edit screen.
 */
final class AdminCustomFieldInactiveBundleCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('cf-inactive-bundle@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    public function theUpdateScreenStillRendersForADefinitionWhoseBundleWentInactive(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $definition = (new CustomFieldDefinition())
            ->setObjectType('vendor')
            ->setSlug('inactive_bundle_check')
            ->setLabel('Inactive Bundle Check')
            ->setFieldType(CustomFieldDefinition::FIELD_TYPE_TEXT)
            ->setSource('ProcurementBundle');
        $I->haveInRepository($definition);
        $id = $definition->getId();

        $em = $I->grabService(EntityManagerInterface::class);
        $status = $em->getRepository(BundleStatus::class)->findOneBy(['source' => 'ProcurementBundle']);
        if ($status === null) {
            $status = (new BundleStatus())->setSource('ProcurementBundle');
            $em->persist($status);
        }
        $status->setStatus(BundleStatus::STATUS_INACTIVE);
        $em->flush();

        $I->amOnPage('/admin/custom-fields/' . $id . '/update');
        $I->seeResponseCodeIsSuccessful();
        // Falls back to the raw key rather than the pretty label, since the catalogue no longer
        // offers a label for it — but the row is still visibly, correctly, what it is.
        $I->see('vendor');

        // The rest of the row is still editable, and the object type is left exactly as it was:
        // update() never rewrites object_type regardless of what the catalogue currently offers.
        $I->sendFormPostRequest('/admin/custom-fields/' . $id . '/update', [
            '_token' => $I->csrfToken(),
            'label' => 'Still Editable While Inactive',
            'field_type' => CustomFieldDefinition::FIELD_TYPE_TEXT,
            'visible_on_add' => '1',
            'visible_on_edit' => '1',
        ]);
        $I->seeCurrentUrlEquals('/admin/custom-fields');

        $row = $em->getConnection()->fetchAssociative(
            'SELECT label, object_type FROM custom_field_definition WHERE id = ?',
            [$id],
        );
        $I->assertSame('Still Editable While Inactive', $row['label']);
        $I->assertSame('vendor', $row['object_type']);
    }
}
