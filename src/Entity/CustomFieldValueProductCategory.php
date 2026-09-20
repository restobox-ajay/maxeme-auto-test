<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'custom_field_value_product_category')]
#[ORM\UniqueConstraint(name: 'UNIQ_CUSTOM_FIELD_VALUE_PRODUCT_CATEGORY', columns: ['definition_id', 'product_category_id'])]
class CustomFieldValueProductCategory extends AbstractCustomFieldValue
{
    #[ORM\ManyToOne(targetEntity: ProductCategory::class)]
    #[ORM\JoinColumn(name: 'product_category_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCategory $productCategory;

    public function getProductCategory(): ProductCategory { return $this->productCategory; }
    public function setProductCategory(ProductCategory $productCategory): static { $this->productCategory = $productCategory; return $this; }

    public function getObjectId(): int { return $this->productCategory->getId(); }

    public function setObjectRef(object $reference): static
    {
        return $this->setProductCategory($reference);
    }
}
