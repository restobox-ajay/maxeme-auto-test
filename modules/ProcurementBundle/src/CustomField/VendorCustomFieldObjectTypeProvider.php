<?php

declare(strict_types=1);

namespace ProcurementBundle\CustomField;

use App\Contract\CustomField\CustomFieldObjectType;
use App\Contract\CustomField\CustomFieldObjectTypeProviderInterface;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorCustomFieldValue;

/**
 * Makes `vendor` a custom-field object type (#745), following
 * `App\Contract\CustomField\CustomFieldObjectTypeProviderInterface`'s four-step recipe: the value
 * entity is `VendorCustomFieldValue`, its migration adds `custom_field_value_vendor`, this is the
 * provider, and `VendorController`'s create/edit/save actions call `CustomFieldRenderer`.
 *
 * Gated on this bundle being Active through `CustomFieldObjectTypeCatalogue`, same as every other
 * provider — belt-and-braces here rather than load-bearing, since every `VendorController` action
 * already refuses with `denyIfInactive()` before it could reach a custom field either way.
 */
final class VendorCustomFieldObjectTypeProvider implements CustomFieldObjectTypeProviderInterface
{
    /** @return list<CustomFieldObjectType> */
    public function customFieldObjectTypes(): array
    {
        return [
            new CustomFieldObjectType('vendor', 'Vendor', VendorCustomFieldValue::class, Vendor::class, 'vendor'),
        ];
    }
}
