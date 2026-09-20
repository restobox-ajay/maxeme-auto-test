<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One admin-defined field's value on one estimate (quote).
 *
 * The twin of {@see CustomFieldValueInvoice}, and of {@see CustomFieldValueOrder} before it: own
 * table, own FK with ON DELETE CASCADE, unique on (definition_id, estimate_id). See
 * CustomFieldValueInvoice's docblock for why these are separate tables rather than one shared one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'custom_field_value_estimate')]
#[ORM\UniqueConstraint(name: 'UNIQ_CUSTOM_FIELD_VALUE_ESTIMATE', columns: ['definition_id', 'estimate_id'])]
class CustomFieldValueEstimate extends AbstractCustomFieldValue
{
    #[ORM\ManyToOne(targetEntity: Estimate::class)]
    #[ORM\JoinColumn(name: 'estimate_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Estimate $estimate;

    public function getEstimate(): Estimate { return $this->estimate; }
    public function setEstimate(Estimate $estimate): static { $this->estimate = $estimate; return $this; }

    public function getObjectId(): int { return $this->estimate->getId(); }

    public function setObjectRef(object $reference): static
    {
        return $this->setEstimate($reference);
    }
}
