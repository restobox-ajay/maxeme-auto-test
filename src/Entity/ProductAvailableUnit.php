<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductAvailableUnitRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One unit this product may be expressed in (#659).
 *
 * ## What this table is for, and what it is deliberately NOT
 *
 * It holds **no ratio**. `Box-12` holds twelve because {@see UnitOfMeasure} says so, once, for the
 * whole instance — that is the NetSuite model this issue adopts, and the reason `Box-12` and
 * `Box-24` are two terms rather than one term with a per-product number beside it. A `factor` column
 * here would put the same fact in two places that can disagree, which is precisely what retiring
 * `product_packaging_unit` removes.
 *
 * What it holds is **availability**, and that is the one enhancement over stock NetSuite. A global
 * vocabulary grows without bound — every distinct pack size anybody ever ships is a term — and stock
 * NetSuite offers an item's whole units type to the user. This table is what stops the catalogue
 * reaching the person entering an order: the list is long in the catalogue and short everywhere
 * somebody works, and a tyre never sees `BAG`.
 *
 * ## Scoped twice
 *
 * To the **product**, by `product_id`; and to the product's **family**, enforced by
 * {@see \App\Service\Uom\ProductAvailableUnitService}. A product belongs to one family — count,
 * weight, volume or length — and may only use units from it, because conversion is only ever defined
 * inside a family and a product that was both "sold by weight" and "sold by count" would be asking
 * the app to convert kilograms into eaches. The family is not stored again here or on the product:
 * it IS the family of the product's base unit, which is the only row that can name it without
 * restating it.
 *
 * ## Blank is allowed
 *
 * A product may have no rows here at all. Some items do not participate — that is NetSuite's own
 * behaviour, units are opt-in per item, and it is the owner's explicit ruling. An empty list is not
 * an unconfigured product; it is a product that is ordered in its base unit and nothing else.
 *
 * ## The base unit is never a row here
 *
 * It is available by definition — stock is stored in it, so a line can always be written in it — and
 * a row saying so would be the base unit recorded twice, on `product_core.unit_id` and here, free to
 * disagree. {@see \App\Service\Uom\ProductAvailableUnitService::choicesFor()} puts it at the head of
 * every picker without it being stored.
 */
#[ORM\Entity(repositoryClass: ProductAvailableUnitRepository::class)]
#[ORM\Table(name: 'product_available_unit')]
#[ORM\UniqueConstraint(name: 'uniq_product_available_unit', columns: ['product_id', 'unit_id'])]
class ProductAvailableUnit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * `CASCADE`: the list is part of the product and has no meaning without it.
     *
     * That is the opposite of the unit side below, and the difference is which way the fact points —
     * deleting the product deletes a statement about that product, while deleting the unit would
     * delete a term other documents are written in.
     */
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    /**
     * `RESTRICT`, like `product_core.unit_id` and for the same reason.
     *
     * A unit some product is expressed in cannot be deleted out from under it. The screen refuses it
     * first, with the row counts — this is the database saying the same thing when something reaches
     * past the screen.
     */
    #[ORM\ManyToOne(targetEntity: UnitOfMeasure::class)]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private UnitOfMeasure $unit;

    public function getId(): ?int { return $this->id; }

    public function getProduct(): ProductCore { return $this->product; }

    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }

    public function getUnit(): UnitOfMeasure { return $this->unit; }

    public function setUnit(UnitOfMeasure $unit): self { $this->unit = $unit; return $this; }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return $this->unit->getLabel();
    }
}
