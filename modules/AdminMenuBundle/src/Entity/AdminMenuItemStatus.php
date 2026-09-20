<?php

declare(strict_types=1);

namespace AdminMenuBundle\Entity;

use AdminMenuBundle\Repository\AdminMenuItemStatusRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A hide/reorder/reparent override for one core sidebar entry, keyed by its stable
 * App\Menu\Admin\AdminMenuCatalog key — never by label or position, so renaming a catalog label
 * or moving the hardcoded default around never orphans a saved override.
 *
 * Only created lazily, on first touch (see AdminMenuItemStatusRepository::ensureByKey()): a core
 * key with no row here has made no request to be hidden, reordered or reparented, and stays
 * exactly where App\Menu\Admin\AdminMenuCatalog::defaultTree() puts it.
 */
#[ORM\Entity(repositoryClass: AdminMenuItemStatusRepository::class)]
#[ORM\Table(name: 'admin_menu_item_status')]
class AdminMenuItemStatus
{
    /**
     * Sentinel stored in parentKey to mean "explicitly moved to the top level" — distinct from
     * null, which means "no parent override at all, keep the catalog default parent". Without
     * this, an override that moves a normally-nested entry (e.g. Settings > Sales Tax) to the
     * root could never be told apart from one that was simply never touched.
     */
    public const ROOT_PARENT = '__root__';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150, unique: true)]
    private string $itemKey = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $hidden = false;

    #[ORM\Column(nullable: true)]
    private ?int $sortOrder = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $parentKey = null;

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

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function setHidden(bool $hidden): static
    {
        $this->hidden = $hidden;

        return $this;
    }

    public function getSortOrder(): ?int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(?int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /** Raw stored value: null (no override), self::ROOT_PARENT (explicit top-level), or a parent key. */
    public function getParentKey(): ?string
    {
        return $this->parentKey;
    }

    public function setParentKey(?string $parentKey): static
    {
        $this->parentKey = $parentKey;

        return $this;
    }

    /** The parent override as App\Contract\Menu\AdminMenuOverrideProviderInterface expects it: null both for "no override" and "explicit root" is wrong here — callers filter "no override" out before calling this. */
    public function getEffectiveParent(): ?string
    {
        return $this->parentKey === self::ROOT_PARENT ? null : $this->parentKey;
    }

    public function hasParentOverride(): bool
    {
        return $this->parentKey !== null;
    }
}
