<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One internal note against a product — admin-only, shaped after {@see CompanyNote} and
 * {@see VendorNote}: a row per note (author, text, real timestamp) rather than the single packed
 * `product_core.remarks` CLOB, for exactly the reasons {@see AbstractPartyNote} lists.
 *
 * `remarks` is not read, split, or dropped by this — it stays the Product Details grid's own
 * sortable/filterable column and the product form's own field, untouched. This is a second,
 * independent place to record something about a product: the Product Inventory Hub's own notes
 * log, the way a company or vendor has one on their own detail page.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_note')]
#[ORM\Index(name: 'idx_product_note_product', fields: ['product'])]
class ProductNote extends AbstractPartyNote
{
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    public function getProduct(): ProductCore { return $this->product; }

    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
}
