<?php

declare(strict_types=1);

namespace App\Contract\CustomField;

/**
 * Lets whoever owns an entity make it a target for admin-defined custom fields (#745).
 *
 * Before this, `CustomFieldController::OBJECT_TYPES` and `CustomFieldValueRepository::MAP` each
 * hardcoded the same seven types in core. Vendor could not join them without core naming the
 * procurement bundle's own `Entity\Vendor` — the layering violation "core reads nothing a bundle
 * writes" exists to prevent. Modelled on `DocumentPrefixProviderInterface` (#615), which solves the same
 * problem for document number prefixes: a provider declares what it owns, a catalogue collects
 * every Active one, and core goes through the same seam instead of staying the hardcoded case.
 *
 * ## What a bundle must do to make one of its entities custom-field-compatible
 *
 * 1. An entity extending `App\Entity\AbstractCustomFieldValue`, mapped to its own table, with a
 *    `ManyToOne` to the target entity and `getObjectId()`/`setObjectRef()` implemented — see
 *    `App\Entity\CustomFieldValueCompany` for the shape every core value entity already follows.
 * 2. A migration for that table. Purely additive; nothing else changes.
 * 3. A provider implementing this interface, tagged `app.custom_field_object_type_provider`,
 *    declaring the four things `CustomFieldValueRepository` needs — see `CustomFieldObjectType`.
 * 4. Calls to `App\Service\CustomFieldRenderer::renderFields()` and `::saveFromRequest()` in
 *    whichever controller owns the entity's add/edit screens — see `CompanyController` or
 *    `ProductController` for the existing shape; nothing else in this seam renders or persists a
 *    field's HTML, so a bundle never writes markup of its own for this.
 *
 * A provider MUST NOT contribute a key another provider already owns. Core wins on collision and
 * the duplicate is dropped, exactly as `DocumentPrefixCatalogue` does — a bundle quietly taking
 * over `product` would point every product's custom field values at the wrong table.
 */
interface CustomFieldObjectTypeProviderInterface
{
    /**
     * The object types this provider owns.
     *
     * @return list<CustomFieldObjectType>
     */
    public function customFieldObjectTypes(): array;
}
