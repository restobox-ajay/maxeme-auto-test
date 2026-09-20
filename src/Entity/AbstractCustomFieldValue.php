<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class AbstractCustomFieldValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomFieldDefinition::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CustomFieldDefinition $definition;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $value = null;

    public function getId(): ?int { return $this->id; }

    public function getDefinition(): CustomFieldDefinition { return $this->definition; }
    public function setDefinition(CustomFieldDefinition $definition): static { $this->definition = $definition; return $this; }

    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $value): static { $this->value = $value; return $this; }

    /** The id of the entity (order/product/company/...) this value belongs to. */
    abstract public function getObjectId(): int;

    /** Points this value at an entity by id only, without loading it (see CustomFieldValueRepository). */
    abstract public function setObjectRef(object $reference): static;
}
