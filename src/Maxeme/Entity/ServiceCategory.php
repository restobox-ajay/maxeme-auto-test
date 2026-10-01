<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Enum\CategoryStatus;
use App\Maxeme\Repository\ServiceCategoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A catalog of services, in a hierarchy (a category can sit under another): wholesale's
 * ProductCategory (name, parent, Visible / Hidden) plus a colour, the colour its services have
 * on the calendar. A category without a colour takes its parent's.
 *
 * Deleted for real, like wholesale's, but only while nothing uses it: no subcategory, no service.
 */
#[ORM\Entity(repositoryClass: ServiceCategoryRepository::class)]
#[ORM\Table(name: 'maxeme_service_category')]
class ServiceCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?self $parent = null;

    #[ORM\Column(length: 160)]
    private string $name = '';

    /** "#1f77b4", or null to use the parent's. */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $colour = null;

    #[ORM\Column(length: 16, enumType: CategoryStatus::class, options: ['default' => 'Visible'])]
    private CategoryStatus $status = CategoryStatus::Visible;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getParent(): ?self { return $this->parent; }

    /** For the form's Parent category select. */
    public function getParentId(): ?int { return $this->parent?->getId(); }

    /** Sets the parent, unless that would put this category under itself. */
    public function setParent(?self $parent): self
    {
        for ($ancestor = $parent; $ancestor !== null; $ancestor = $ancestor->parent) {
            if ($ancestor === $this) {
                throw new \InvalidArgumentException('A category cannot sit under itself or one of its subcategories.');
            }
        }
        $this->parent = $parent;

        return $this;
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getColour(): ?string { return $this->colour; }
    public function setColour(?string $colour): self { $this->colour = $colour !== null ? strtolower($colour) : null; return $this; }

    /** Its own colour, or the nearest ancestor's, or null when none has one. */
    public function getEffectiveColour(): ?string
    {
        for ($category = $this; $category !== null; $category = $category->parent) {
            if ($category->colour !== null) {
                return $category->colour;
            }
        }

        return null;
    }

    public function getStatus(): CategoryStatus { return $this->status; }
    public function setStatus(CategoryStatus $status): self { $this->status = $status; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** How many categories it sits under (0 at the top). */
    public function getDepth(): int
    {
        $depth = 0;
        for ($ancestor = $this->parent; $ancestor !== null; $ancestor = $ancestor->parent) {
            ++$depth;
        }

        return $depth;
    }

    /** "Maintenance > Fluids", root first. */
    public function getPath(): string
    {
        $names = [];
        for ($category = $this; $category !== null; $category = $category->parent) {
            array_unshift($names, $category->name);
        }

        return implode(' > ', $names);
    }
}
