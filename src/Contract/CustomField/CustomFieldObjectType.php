<?php

declare(strict_types=1);

namespace App\Contract\CustomField;

/**
 * One object type a custom field can be hung off, as declared by whoever owns the target entity
 * (#745).
 *
 * This is a description of a mapping, not the values themselves. It carries everything
 * `CustomFieldValueRepository` needs to find and write the right row — the value-entity class, the
 * target-entity class it points at, and the association property that joins them — plus the label
 * the admin screens show. The four together are exactly the tuple `CustomFieldValueRepository::MAP`
 * used to hardcode per object type.
 */
final class CustomFieldObjectType
{
    /**
     * @param string $key                 the `custom_field_definition.object_type` value, e.g. 'vendor'
     * @param string $label               the admin-facing label, e.g. 'Vendor'
     * @param class-string $valueEntityClass  the `AbstractCustomFieldValue` subclass storing this type's values
     * @param class-string $targetEntityClass the entity a value row points at, e.g. Vendor::class
     * @param string $associationProperty the property on $valueEntityClass holding that reference
     * @param bool $selectable            whether `/admin/custom-fields/create` offers this type at
     *                                    all. True for everything an admin should be able to hang
     *                                    an ad-hoc field off. False for a type that is registered
     *                                    and resolvable (`CustomFieldValueRepository` still needs
     *                                    the mapping) but only ever populated by the bundle that
     *                                    owns it via `ensureBySlug()` — `company_address` and
     *                                    `product_category` predate this interface and were already
     *                                    excluded from the create screen for exactly that reason;
     *                                    see `CoreCustomFieldObjectTypeProvider`.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $valueEntityClass,
        public readonly string $targetEntityClass,
        public readonly string $associationProperty,
        public readonly bool $selectable = true,
    ) {
    }
}
