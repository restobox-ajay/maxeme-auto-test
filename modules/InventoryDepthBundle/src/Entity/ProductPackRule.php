<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Entity;

use App\Entity\ProductCore;
use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Repository\ProductPackRuleRepository;

/**
 * One case SKU holds N of one unit SKU (#22).
 *
 * ## THIS IS NOT A UNIT OF MEASURE, AND THE TWO MUST NEVER MERGE
 *
 * A unit of measure converts a quantity WITHIN one SKU. `40 BOX-12` of SKU-X is `480 EA` of SKU-X:
 * one product, one `product_inventory` row, one `inventory_detail` balance, nothing moved, nothing
 * recorded, no date and no actor. {@see \App\Entity\UnitOfMeasure} and
 * {@see \App\Service\Uom\LineDenomination} own that arithmetic and it is entry-only — the line
 * stores the base figure and the inventory layer never sees the box.
 *
 * This table describes something else entirely: **two products**, two balances, two rows in
 * `product_inventory`. Moving stock from one to the other is a physical event — somebody cut a case
 * open — and it is recorded exactly like a transfer: a movement group with a reason, an actor and a
 * date, and two movements under it. NetSuite draws the same line and this follows it: same item, use
 * a unit; different items, use an assembly build/unbuild.
 *
 * The distinction is worth the whole table because of what merging them would cost. If a unit
 * conversion could move stock between products, then picking the wrong entry in a units dropdown
 * would silently relocate inventory from one SKU to another with nothing written down. So:
 *
 *  - **a rule whose two products are the SAME product is refused**, by
 *    {@see \InventoryDepthBundle\Movement\PackConversionService::declare()}. A SKU that holds N of
 *    itself is a unit of measure and belongs in `unit_of_measure`, where it costs nothing and moves
 *    nothing;
 *  - **`unitsPerCase` of 1 is refused** for the same reason — two SKUs one of which holds exactly
 *    one of the other is a relabelling, and this screen is not where relabelling is done;
 *  - **no factor is stored on a product here.** The ratio is between two SKUs and is stated once, on
 *    this row. Nothing in this bundle ever reads `unit_of_measure.factor_to_family_base` to move
 *    stock, and nothing in core ever reads this table.
 *
 * ## Directional: the CASE owns the declaration
 *
 * `case_product_id` is UNIQUE; `unit_product_id` is not. The relationship is deliberately one-way,
 * and the asymmetry is real rather than a storage convenience:
 *
 *  - **"what does this case hold" has exactly one answer.** A case of twelve holds twelve; there is
 *    no second reading of it. **"which case does this unit belong to" has no single answer** — the
 *    same bottle ships in a twelve and in a twenty-four, and both declarations are true at once.
 *    Making the unit side unique would be storing a constraint that is not a fact about the world;
 *    making both sides non-unique would leave "how many does CASE-X hold" with two possible rows and
 *    nothing to say which wins.
 *  - **the operation counts cases.** Break three cases, rebuild three cases — never "break 36
 *    units". The case is the unit of the operation, so it is the unit of the declaration.
 *  - **a symmetric table would hold the same fact twice**, free to disagree. That is precisely the
 *    shape `#659` retired when `product_packaging_unit` and `unit_of_measure` both answered "how
 *    many of the base does this hold", and the shape behind `#589`, `#590` and `#591`. One fact, one
 *    row, one direction.
 *
 * The unit SKU therefore knows nothing about the case. Anything that wants to ask it — "what can be
 * broken to make more of these?" — reads this table by `unit_product_id`, which is indexed for that
 * and may legitimately return several rows.
 *
 * ## Chains are allowed; cycles are not
 *
 * A pallet holding 40 cases and a case holding 12 eaches is two rows and a real warehouse. A cycle —
 * any path of rules leading from the unit SKU back to the case SKU — is refused when the rule is
 * declared, because breaking round a loop multiplies the quantity at every step and would
 * manufacture stock out of arithmetic.
 *
 * ## Rebuilding is opt-in, and this column is what makes it safe
 *
 * Twelve loose units are not necessarily a case that can be resealed. A shrink-wrapped pallet can be
 * rebuilt; a carton of yoghurt that was cut open cannot. Nothing in this database can tell those
 * apart, so it is declared: `rebuildAllowed` defaults to FALSE, and the unbreak screen refuses a
 * pack that has not been marked rebuildable. What makes an allowed rebuild safe is three statements
 * together — a human said this pack can be re-formed, the quantity is whole cases (partial cases
 * cannot be expressed, because the form takes a case count), and the units are really on the shelf
 * (the movement layer refuses otherwise).
 *
 * ## Cascades
 *
 * Both sides `CASCADE`. This is live configuration about two products that currently exist, not a
 * record of something that happened — the same call {@see ProductReorderRule} makes. The record of
 * what happened is {@see PackConversion} and the movement group under it, which survive because they
 * belong to the operation and not to the rule.
 */
#[ORM\Entity(repositoryClass: ProductPackRuleRepository::class)]
#[ORM\Table(name: 'inventory_pack_rule')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_pack_rule_case', fields: ['caseProduct'])]
#[ORM\Index(name: 'idx_inventory_pack_rule_unit', fields: ['unitProduct'])]
class ProductPackRule
{
    /** Below this a rule is a relabelling, not a pack. See the class docblock. */
    public const MINIMUM_UNITS_PER_CASE = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The SKU that is the container. One declaration per case SKU — see the class docblock. */
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'case_product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $caseProduct;

    /** The SKU that comes out of it. NOT unique: one unit SKU may be held by several pack sizes. */
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'unit_product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $unitProduct;

    /**
     * How many of the unit SKU one case holds. NOT NULL, no default, at least 2.
     *
     * A plain `integer` rather than the `quantity` type the movement columns use: this is a pack
     * SIZE, a property of the packaging, and it is whole by definition. Half a case of twelve is six
     * units of the unit SKU, which is a quantity of a different product and is expressible without
     * fractions here.
     */
    #[ORM\Column(name: 'units_per_case', type: 'integer')]
    private int $unitsPerCase = self::MINIMUM_UNITS_PER_CASE;

    /** Whether a case of this pack may be re-formed from loose units. FALSE by default — see above. */
    #[ORM\Column(name: 'rebuild_allowed', type: 'boolean', options: ['default' => false])]
    private bool $rebuildAllowed = false;

    public function getId(): ?int { return $this->id; }
    public function getCaseProduct(): ProductCore { return $this->caseProduct; }
    public function setCaseProduct(ProductCore $caseProduct): self { $this->caseProduct = $caseProduct; return $this; }
    public function getUnitProduct(): ProductCore { return $this->unitProduct; }
    public function setUnitProduct(ProductCore $unitProduct): self { $this->unitProduct = $unitProduct; return $this; }
    public function getUnitsPerCase(): int { return $this->unitsPerCase; }
    public function setUnitsPerCase(int $unitsPerCase): self { $this->unitsPerCase = $unitsPerCase; return $this; }
    public function isRebuildAllowed(): bool { return $this->rebuildAllowed; }
    public function setRebuildAllowed(bool $rebuildAllowed): self { $this->rebuildAllowed = $rebuildAllowed; return $this; }

    /** How many units $cases cases of this pack contain. The only place the multiplication happens. */
    public function unitsFor(int $cases): int
    {
        return $cases * $this->unitsPerCase;
    }

    public function describe(): string
    {
        return sprintf(
            '1 %s = %d × %s',
            $this->caseProduct->getSku() ?: $this->caseProduct->getName(),
            $this->unitsPerCase,
            $this->unitProduct->getSku() ?: $this->unitProduct->getName(),
        );
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return $this->describe();
    }
}
