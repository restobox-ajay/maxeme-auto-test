<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FrontendMenuItemStatusRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FrontendMenuItemStatusRepository::class)]
#[ORM\Table(name: 'frontend_menu_item_status')]
class FrontendMenuItemStatus
{
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_INACTIVE = 'Inactive';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $menuKey = '';

    #[ORM\Column(length: 32, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }

    public function getMenuKey(): string { return $this->menuKey; }
    public function setMenuKey(string $menuKey): static { $this->menuKey = $menuKey; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): static { $this->sortOrder = $sortOrder; return $this; }
}
