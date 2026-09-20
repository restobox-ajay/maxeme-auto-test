<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\AbstractCustomFieldValue;
use Doctrine\ORM\Mapping as ORM;

/**
 * One custom field's value against one vendor (#745) — the buy-side counterpart of
 * `App\Entity\CustomFieldValueCompany`, same shape in every respect: `AbstractCustomFieldValue`
 * supplies the id, the definition FK and the value column, this supplies the FK to the entity the
 * value is about.
 *
 * Registered as a custom-field target through `VendorCustomFieldObjectTypeProvider`, which is the
 * whole of what this bundle does beyond defining the table — `App\Service\CustomFieldRenderer`
 * renders it, saves it and lists it the same way it does for every core object type, and this
 * bundle writes no rendering code of its own. See
 * `App\Contract\CustomField\CustomFieldObjectTypeProviderInterface` for the four-step recipe this
 * follows.
 */
#[ORM\Entity]
#[ORM\Table(name: 'custom_field_value_vendor')]
#[ORM\UniqueConstraint(name: 'UNIQ_CUSTOM_FIELD_VALUE_VENDOR', columns: ['definition_id', 'vendor_id'])]
class VendorCustomFieldValue extends AbstractCustomFieldValue
{
    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Vendor $vendor;

    public function getVendor(): Vendor { return $this->vendor; }
    public function setVendor(Vendor $vendor): static { $this->vendor = $vendor; return $this; }

    public function getObjectId(): int { return $this->vendor->getId(); }

    public function setObjectRef(object $reference): static
    {
        return $this->setVendor($reference);
    }
}
