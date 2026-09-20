<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CustomFieldDefinitionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CustomFieldDefinitionRepository::class)]
#[ORM\Table(name: 'custom_field_definition')]
#[ORM\UniqueConstraint(name: 'UNIQ_CUSTOM_FIELD_DEFINITION', columns: ['object_type', 'slug'])]
class CustomFieldDefinition
{
    public const OBJECT_TYPE_PRODUCT = 'product';
    public const OBJECT_TYPE_COMPANY = 'company';
    public const OBJECT_TYPE_ORDER = 'order';
    public const OBJECT_TYPE_COMPANY_ADDRESS = 'company_address';
    public const OBJECT_TYPE_PRODUCT_CATEGORY = 'product_category';
    /**
     * The two sell-side documents beside the order. Same shape as OBJECT_TYPE_ORDER in every
     * respect — own value table, own FK, its own row in CustomFieldValueRepository::MAP — because
     * an admin who can hang a field off an order had no way to hang one off the quote it came from
     * or the invoice it became.
     *
     * The strings are 'invoice' and 'estimate', matching the entity and table names the rest of the
     * application already uses for these two documents; `object_type` is VARCHAR(20), so both fit.
     */
    public const OBJECT_TYPE_INVOICE = 'invoice';
    public const OBJECT_TYPE_ESTIMATE = 'estimate';

    public const FIELD_TYPE_TEXT = 'text';
    public const FIELD_TYPE_TEXTAREA = 'textarea';
    public const FIELD_TYPE_NUMBER = 'number';
    public const FIELD_TYPE_CHECKBOX = 'checkbox';
    public const FIELD_TYPE_SELECT = 'select';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $objectType = self::OBJECT_TYPE_PRODUCT;

    #[ORM\Column(length: 100)]
    private string $slug = '';

    #[ORM\Column(length: 255)]
    private string $label = '';

    #[ORM\Column(length: 20)]
    private string $fieldType = self::FIELD_TYPE_TEXT;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $options = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $visibleOnAdd = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $visibleOnEdit = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $visibleOnListing = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $searchable = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\ManyToOne(targetEntity: ProductCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true)]
    private ?ProductCategory $category = null;

    public function getId(): ?int { return $this->id; }

    public function getObjectType(): string { return $this->objectType; }
    public function setObjectType(string $objectType): static { $this->objectType = $objectType; return $this; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }

    public function getFieldType(): string { return $this->fieldType; }
    public function setFieldType(string $fieldType): static { $this->fieldType = $fieldType; return $this; }

    /** @return string[]|null */
    public function getOptions(): ?array
    {
        if ($this->options === null) {
            return null;
        }

        $decoded = json_decode($this->options, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param string[]|null $options */
    public function setOptions(?array $options): static
    {
        $this->options = $options === null ? null : json_encode($options, JSON_UNESCAPED_UNICODE);

        return $this;
    }

    public function isVisibleOnAdd(): bool { return $this->visibleOnAdd; }
    public function setVisibleOnAdd(bool $visibleOnAdd): static { $this->visibleOnAdd = $visibleOnAdd; return $this; }

    public function isVisibleOnEdit(): bool { return $this->visibleOnEdit; }
    public function setVisibleOnEdit(bool $visibleOnEdit): static { $this->visibleOnEdit = $visibleOnEdit; return $this; }

    public function isVisibleOnListing(): bool { return $this->visibleOnListing; }
    public function setVisibleOnListing(bool $visibleOnListing): static { $this->visibleOnListing = $visibleOnListing; return $this; }

    public function isSearchable(): bool { return $this->searchable; }
    public function setSearchable(bool $searchable): static { $this->searchable = $searchable; return $this; }

    public function getSource(): ?string { return $this->source; }
    public function setSource(?string $source): static { $this->source = $source; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): static { $this->sortOrder = $sortOrder; return $this; }

    /** Null means "applies to every category" — the default, and the only value every pre-existing definition has. */
    public function getCategory(): ?ProductCategory { return $this->category; }
    public function setCategory(?ProductCategory $category): static { $this->category = $category; return $this; }
}
