<?php

namespace App\Entity;

use App\Enum\ProductStatus;
use App\Repository\ProductCoreRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductCoreRepository::class)]
#[ORM\Table(name: 'product_core')]
#[ORM\UniqueConstraint(name: 'uniq_product_core_sku', fields: ['sku'])]
class ProductCore
{
    /**
     * Provenance for a product an admin typed into the admin product form by hand
     * (Admin\ProductController::create()).
     *
     * Every other creation path names its own value on the service that owns it —
     * Number1RimImportBundle\Service\RimApiImportService::SYNC_SOURCE,
     * Number1ProductImportBundle\Service\ProductCsvTransformer::SYNC_SOURCE,
     * App\Command\ProductImportCommand::SYNC_SOURCE. "manual" has no owning service to
     * live on: it is the absence of an importer, so it belongs to the entity itself.
     */
    public const SYNC_SOURCE_MANUAL = 'manual';

    /**
     * Quantity is a number a human types: the admin grid, the product form and the CSV import all
     * set `product_inventory.quantity` directly. Every product until someone opts one in.
     */
    public const INVENTORY_MODE_SIMPLE = 'simple';

    /**
     * Quantity is maintained by the depth layer beneath it (#550) — bins, lots, serials, statuses —
     * and nobody types it. Only selectable while a
     * App\Contract\Inventory\DimensionalInventoryProviderInterface is registered and Active; see
     * App\Service\Inventory\InventoryModeResolver, which reads a stored `dimensional` as `simple`
     * when there is no provider to honour it.
     */
    public const INVENTORY_MODE_DIMENSIONAL = 'dimensional';

    /** @return list<string> */
    public static function inventoryModes(): array
    {
        return [self::INVENTORY_MODE_SIMPLE, self::INVENTORY_MODE_DIMENSIONAL];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true)]
    private ?ProductCategory $category = null;

    #[ORM\Column(length: 80)]
    private string $sku = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'is_private')]
    private bool $private = false;

    /** @var Collection<int, Company> */
    #[ORM\ManyToMany(targetEntity: Company::class)]
    #[ORM\JoinTable(name: 'company_product_access')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'company_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $privateCompanies;

    // Free-text on purpose — see the ProductStatus docblock for why this isn't enum-typed.
    #[ORM\Column(length: 32)]
    private string $status = 'Active';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $plant = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $weight = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $costPrice = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $originalPrice = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $defaultPrice = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $deposit = null;

    // Suggested Price (phase 2 of SUGGESTED_PRICE_CORE_ADAPTATION_PLAN.md) — Number/Markup$/Markup% override
    // for the storefront "Price Suggested" display, resolved against defaultPrice/originalPrice. Native
    // columns, promoted from Number1SuggestedPriceBundle's Custom Fields (see the migration that added these).
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $suggestedPriceType = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $suggestedPriceValue = null;

    #[ORM\Column]
    private bool $visible = true;

    #[ORM\Column]
    private bool $featured = false;

    #[ORM\Column]
    private bool $deleted = false;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $salesTaxCode = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $shippingClass = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $syncSource = null;

    /**
     * `simple` | `dimensional` (#550). Lives in core, not in the bundle, because core has to decide
     * whether to render an editable quantity field and cannot ask the bundle that without depending
     * on it. NOT NULL with a default, so the column is inert for every product that never opts in.
     */
    #[ORM\Column(length: 16, options: ['default' => self::INVENTORY_MODE_SIMPLE])]
    private string $inventoryMode = self::INVENTORY_MODE_SIMPLE;

    /**
     * What identity a unit of this product must carry — lot, serial, expiry — declared rather than
     * inferred from whatever rows happen to exist (#573).
     *
     * Nullable, and null means the same as the seeded `None` policy: nothing is tracked. That is
     * deliberate rather than lazy — `TrackingPolicyRepository::policyFor()` answers with an inert
     * policy for a null, so no caller has to null-check and no code path depends on the seed row
     * having been written. A product that points at nothing behaves exactly as every product did
     * before this column existed.
     *
     * `SET NULL` on delete for the same reason: deleting a policy must degrade its products to
     * "untracked", never delete them.
     */
    #[ORM\ManyToOne(targetEntity: TrackingPolicy::class)]
    #[ORM\JoinColumn(name: 'tracking_policy_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TrackingPolicy $trackingPolicy = null;

    /**
     * What every quantity of this product is counted in (#601, phase 1 #643).
     *
     * Per product, not per family and not per instance: product A is stored in `kg`, product B in
     * `g`, product C in `each`. Safe because quantities are never summed across products — every
     * inventory row is already keyed `(product, warehouse)` — and it is what settles the price grid,
     * since "price per unit" means per THIS product's base and the grid therefore needs no unit
     * column of its own.
     *
     * **Nullable, and left NULL on every existing product.** Filling it in on rows that already
     * exist would be deciding what their historical numbers meant, which is a person's call and not
     * a migration's. Nothing reads it in phase 1.
     *
     * **Never assigned directly once set.** App\Service\Uom\ProductBaseUnitService::assign() is the
     * way in: changing this while stock or documents exist restates every one of those figures at
     * once, and it refuses to. `RESTRICT` on delete for the matching reason — a unit some product is
     * denominated in cannot be deleted out from under it.
     */
    #[ORM\ManyToOne(targetEntity: UnitOfMeasure::class)]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?UnitOfMeasure $baseUnit = null;

    /**
     * Which of this product's available units a line entry STARTS on (#659). Optional.
     *
     * NetSuite's per-item sale/purchase/stock units, collapsed to one: a default is a convenience
     * about where the picker opens, and splitting it three ways would be three fields to keep in
     * step for a choice the person can change on the line anyway.
     *
     * NULL means the picker opens on the base unit, which is what every line did before this issue.
     * The value is constrained to the base unit or a row of `product_available_unit`
     * (App\Service\Uom\ProductAvailableUnitService::apply()), so a default can never be a unit the
     * line entry is not allowed to offer — a dropdown whose pre-selected option is not in it would
     * post a value the server then refuses.
     *
     * `RESTRICT` on delete, like the base unit above: a unit some product opens on is in use.
     */
    #[ORM\ManyToOne(targetEntity: UnitOfMeasure::class)]
    #[ORM\JoinColumn(name: 'default_unit_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?UnitOfMeasure $defaultUnit = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $remarks = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $shortDescription = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $longDescription = null;

    /** @var Collection<int, ProductImage> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductImage::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['primaryImage' => 'DESC', 'sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $images;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->privateCompanies = new ArrayCollection();
        $this->images = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getCategory(): ?ProductCategory { return $this->category; }
    public function setCategory(?ProductCategory $category): self { $this->category = $category; return $this; }
    public function getSku(): string { return $this->sku; }
    public function setSku(string $sku): self { $this->sku = $sku; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function isPrivate(): bool { return !$this->privateCompanies->isEmpty(); }
    public function setPrivate(bool $private): self { $this->private = $private; return $this; }
    /** @return Collection<int, Company> */
    public function getPrivateCompanies(): Collection { return $this->privateCompanies; }
    public function clearPrivateCompanies(): self { $this->privateCompanies->clear(); return $this; }
    public function addPrivateCompany(Company $company): self
    {
        if (!$this->privateCompanies->contains($company)) {
            $this->privateCompanies->add($company);
        }

        return $this;
    }
    public function getStatus(): string { return $this->status; }

    /**
     * Typed accessor for new code. Returns null for any stored string this enum does not know —
     * the column is deliberately not enum-typed, so such a row hydrates instead of blowing up.
     */
    public function getStatusEnum(): ?ProductStatus { return ProductStatus::tryFrom($this->status); }

    /**
     * May this product be sold and counted?
     *
     * Null-safe on purpose, and closed rather than open: an unrecognised status is NOT sellable.
     * A product whose status nothing understands is exactly the product that must not quietly
     * behave like an Active one.
     */
    public function isSellable(): bool { return $this->getStatusEnum()?->isSellable() === true; }

    /*
     * ------------------------------------------------------------------------------------------
     * Actions (queue item 9). There is no setStatus().
     * ------------------------------------------------------------------------------------------
     *
     * A bare string setter is what let the set of legal statuses live in whatever code happened to
     * compare against it, and it is what would let an importer or a replayed form post write
     * 'active', 'ACTIVE' or 'Draf' into a column three layers of the app then read as "not Active,
     * therefore Inactive". These three verbs are the whole set of destinations, so adding a fourth
     * status is a decision somebody has to make here rather than a string that appears.
     *
     * Unlike SalesOrder's approve()/void(), none of these guards its from-state, and that is
     * deliberate rather than an omission. An order's status is a lifecycle — Closed must not go
     * back to Draft. A product's status is a classification, and every move between the three is
     * legitimate and reversible: a withdrawn line comes back, a product an import could not make
     * sense of goes to Draft whatever it was before, and a resolved Draft is activated. The one
     * transition that DOES need a guard — "may this Draft be activated yet?" — depends on whether
     * the thing that drafted it has been settled, which is knowledge the escalation queue owns and
     * this entity does not.
     *
     * No verb writes a log entry: unlike SalesOrder there is no ProductLog to write one to, and
     * AuditLogSubscriber already records every status change path-independently.
     */

    /** Any status -> Active. The product is sellable and counts as stock we have. */
    public function activate(): self { $this->status = ProductStatus::Active->value; return $this; }

    /** Any status -> Inactive. Withdrawn from the catalogue by a decision somebody made. */
    public function deactivate(): self { $this->status = ProductStatus::Inactive->value; return $this; }

    /**
     * Any status -> Draft. Something the app needs in order to know what a quantity of this
     * product means is unresolved, so it must not transact until a person settles it.
     */
    public function holdAsDraft(): self { $this->status = ProductStatus::Draft->value; return $this; }

    /**
     * Dispatches one of the verbs above from a status a person picked.
     *
     * The product form is a select with three options, so the controller has a ProductStatus and
     * needs the verb that writes it. The match is exhaustive by type rather than by default arm:
     * a fourth case added to the enum fails here at once instead of silently doing nothing.
     */
    public function applyStatusChoice(ProductStatus $status): self
    {
        return match ($status) {
            ProductStatus::Active => $this->activate(),
            ProductStatus::Inactive => $this->deactivate(),
            ProductStatus::Draft => $this->holdAsDraft(),
        };
    }

    public function getPlant(): ?string { return $this->plant; }
    public function setPlant(?string $plant): self { $this->plant = $plant; return $this; }
    public function getType(): ?string { return $this->type; }
    public function setType(?string $type): self { $this->type = $type; return $this; }
    public function getUnit(): ?string { return $this->unit; }
    public function setUnit(?string $unit): self { $this->unit = $unit; return $this; }
    public function getWeight(): ?string { return $this->weight; }
    public function setWeight(?string $weight): self { $this->weight = $weight; return $this; }
    public function getCostPrice(): ?string { return $this->costPrice; }
    public function setCostPrice(?string $costPrice): self { $this->costPrice = $costPrice; return $this; }
    public function getOriginalPrice(): ?string { return $this->originalPrice; }
    public function setOriginalPrice(?string $originalPrice): self { $this->originalPrice = $originalPrice; return $this; }
    public function getDefaultPrice(): ?string { return $this->defaultPrice; }
    public function setDefaultPrice(?string $defaultPrice): self { $this->defaultPrice = $defaultPrice; return $this; }
    public function getDeposit(): ?string { return $this->deposit; }
    public function setDeposit(?string $deposit): self { $this->deposit = $deposit; return $this; }
    public function getSuggestedPriceType(): ?string { return $this->suggestedPriceType; }
    public function setSuggestedPriceType(?string $suggestedPriceType): self { $this->suggestedPriceType = $suggestedPriceType; return $this; }
    public function getSuggestedPriceValue(): ?string { return $this->suggestedPriceValue; }
    public function setSuggestedPriceValue(?string $suggestedPriceValue): self { $this->suggestedPriceValue = $suggestedPriceValue; return $this; }
    public function isVisible(): bool { return $this->visible; }
    public function setVisible(bool $visible): self { $this->visible = $visible; return $this; }
    public function isFeatured(): bool { return $this->featured; }
    public function setFeatured(bool $featured): self { $this->featured = $featured; return $this; }
    public function isDeleted(): bool { return $this->deleted; }
    public function setDeleted(bool $deleted): self { $this->deleted = $deleted; return $this; }
    public function getSalesTaxCode(): ?string { return $this->salesTaxCode; }
    public function setSalesTaxCode(?string $salesTaxCode): self { $this->salesTaxCode = $salesTaxCode; return $this; }
    public function getShippingClass(): ?string { return $this->shippingClass; }
    public function setShippingClass(?string $shippingClass): self { $this->shippingClass = $shippingClass; return $this; }
    public function getSyncSource(): ?string { return $this->syncSource; }
    public function setSyncSource(?string $syncSource): self { $this->syncSource = $syncSource; return $this; }
    public function getInventoryMode(): string { return $this->inventoryMode; }

    /** Anything unrecognised falls back to `simple` — the mode that needs nothing installed to mean something. */
    public function setInventoryMode(string $inventoryMode): self
    {
        $this->inventoryMode = \in_array($inventoryMode, self::inventoryModes(), true)
            ? $inventoryMode
            : self::INVENTORY_MODE_SIMPLE;

        return $this;
    }

    public function getTrackingPolicy(): ?TrackingPolicy { return $this->trackingPolicy; }
    public function setTrackingPolicy(?TrackingPolicy $trackingPolicy): self { $this->trackingPolicy = $trackingPolicy; return $this; }

    /**
     * Whether a batch/lot or serial capture UI should offer itself for this product on the way IN
     * — the buy-side direction of the same test `OrderController::productTracksBatch()` makes for
     * the way OUT, kept here rather than duplicated per controller since it reads nothing but this
     * product's own policy.
     */
    public function isTracksBatchInbound(): bool
    {
        return $this->trackingPolicy instanceof TrackingPolicy
            && ($this->trackingPolicy->tracksLotsInbound() || $this->trackingPolicy->tracksSerialsInbound());
    }

    public function getBaseUnit(): ?UnitOfMeasure { return $this->baseUnit; }

    /**
     * Go through App\Service\Uom\ProductBaseUnitService::assign() instead of calling this.
     *
     * It is public because Doctrine hydration and the fixture builders need it, not because a
     * caller should reach for it: the refusal that protects every figure already counted in the old
     * unit lives in that service, and this setter has no way to run it.
     *
     * @internal
     */
    public function setBaseUnit(?UnitOfMeasure $baseUnit): self { $this->baseUnit = $baseUnit; return $this; }

    public function getDefaultUnit(): ?UnitOfMeasure { return $this->defaultUnit; }

    /**
     * Go through App\Service\Uom\ProductAvailableUnitService::apply() instead of calling this.
     *
     * Public for the same reason {@see setBaseUnit()} is — hydration and fixtures — and with the
     * same warning: the rule that a default must be one of the units the product actually offers
     * lives in that service, and this setter has no way to run it.
     *
     * @internal
     */
    public function setDefaultUnit(?UnitOfMeasure $defaultUnit): self { $this->defaultUnit = $defaultUnit; return $this; }

    public function getRemarks(): ?string { return $this->remarks; }
    public function setRemarks(?string $remarks): self { $this->remarks = $remarks; return $this; }
    public function getShortDescription(): ?string { return $this->shortDescription; }
    public function setShortDescription(?string $shortDescription): self { $this->shortDescription = $shortDescription; return $this; }
    public function getLongDescription(): ?string { return $this->longDescription; }
    public function setLongDescription(?string $longDescription): self { $this->longDescription = $longDescription; return $this; }
    /** @return Collection<int, ProductImage> */
    public function getImages(): Collection { return $this->images; }

    public function addImage(ProductImage $image): self
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->setProduct($this);
        }

        return $this;
    }

    public function removeImage(ProductImage $image): self
    {
        if ($this->images->removeElement($image)) {
            if ($image->getProduct() === $this) {
                $image->setProduct(null);
            }
        }

        return $this;
    }

    public function clearImages(): self
    {
        foreach ($this->images as $img) {
            if ($img instanceof ProductImage) {
                $this->removeImage($img);
            }
        }

        return $this;
    }

    public function getPrimaryImageFilename(): ?string
    {
        foreach ($this->images as $img) {
            if ($img instanceof ProductImage && $img->isPrimaryImage()) {
                return $img->getFilename();
            }
        }

        return null;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
