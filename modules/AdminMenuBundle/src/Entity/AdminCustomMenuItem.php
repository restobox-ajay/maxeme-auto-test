<?php

declare(strict_types=1);

namespace AdminMenuBundle\Entity;

use AdminMenuBundle\Repository\AdminCustomMenuItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An admin-created sidebar link with no existence in core — unlike a core entry, which can only
 * ever be hidden (see AdminMenuItemStatus), a custom item is fully deletable, since deleting it
 * loses nothing core needs to fall back to.
 */
#[ORM\Entity(repositoryClass: AdminCustomMenuItemRepository::class)]
#[ORM\Table(name: 'admin_custom_menu_item')]
class AdminCustomMenuItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Stable key handed to App\Menu\Admin\AdminMenuTreeBuilder; generated once at creation, never edited. */
    #[ORM\Column(length: 150, unique: true)]
    private string $itemKey = '';

    #[ORM\Column(length: 150)]
    private string $label = '';

    #[ORM\Column(length: 2048)]
    private string $url = '';

    /** A core top-level key to nest under, or null for the top level. */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $parentKey = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        // Generated once, up front, rather than derived from the id after a flush: the tree
        // builder needs a stable key to detect a duplicate-of-a-core-key collision and to resolve
        // parent overrides, and nothing about a custom item's own identity should depend on
        // insertion order or timing.
        $this->itemKey = 'custom.' . bin2hex(random_bytes(8));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItemKey(): string
    {
        return $this->itemKey;
    }

    public function setItemKey(string $itemKey): static
    {
        $this->itemKey = $itemKey;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getParentKey(): ?string
    {
        return $this->parentKey;
    }

    public function setParentKey(?string $parentKey): static
    {
        $this->parentKey = $parentKey;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
