<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'custom_field_value_product')]
#[ORM\UniqueConstraint(name: 'UNIQ_CUSTOM_FIELD_VALUE_PRODUCT', columns: ['definition_id', 'product_id'])]
class CustomFieldValueProduct extends AbstractCustomFieldValue
{
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): static { $this->product = $product; return $this; }

    public function getObjectId(): int { return $this->product->getId(); }

    public function setObjectRef(object $reference): static
    {
        return $this->setProduct($reference);
    }
}
