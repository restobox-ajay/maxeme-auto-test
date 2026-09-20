<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'custom_field_value_order')]
#[ORM\UniqueConstraint(name: 'UNIQ_CUSTOM_FIELD_VALUE_ORDER', columns: ['definition_id', 'order_id'])]
class CustomFieldValueOrder extends AbstractCustomFieldValue
{
    #[ORM\ManyToOne(targetEntity: SalesOrder::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SalesOrder $order;

    public function getOrder(): SalesOrder { return $this->order; }
    public function setOrder(SalesOrder $order): static { $this->order = $order; return $this; }

    public function getObjectId(): int { return $this->order->getId(); }

    public function setObjectRef(object $reference): static
    {
        return $this->setOrder($reference);
    }
}
