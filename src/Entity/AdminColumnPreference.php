<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminColumnPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Which columns an admin has chosen to see in a data grid (e.g. the Product Detail table).
 *
 * One row per admin per view holds that admin's personal selection. A single row with a null admin is
 * the site-wide default a Tech Support user sets via "apply to all users": it is shown to admins who
 * have no personal row of their own, and does not overwrite anyone who has customized. See issue #120.
 */
#[ORM\Entity(repositoryClass: AdminColumnPreferenceRepository::class)]
#[ORM\Table(name: 'admin_column_preference')]
#[ORM\UniqueConstraint(name: 'uniq_admin_column_preference', columns: ['admin_id', 'view_key'])]
class AdminColumnPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The owning admin, or null for the Tech-Support-set site-wide default. */
    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'admin_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?AdminUser $admin = null;

    #[ORM\Column(length: 64)]
    private string $viewKey;

    /** @var list<string> The visible column keys, in no particular order. */
    #[ORM\Column(type: 'json')]
    private array $columns = [];

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getAdmin(): ?AdminUser { return $this->admin; }
    public function setAdmin(?AdminUser $admin): self { $this->admin = $admin; return $this; }

    public function getViewKey(): string { return $this->viewKey; }
    public function setViewKey(string $viewKey): self { $this->viewKey = $viewKey; return $this; }

    /** @return list<string> */
    public function getColumns(): array { return $this->columns; }

    /** @param list<string> $columns */
    public function setColumns(array $columns): self { $this->columns = array_values($columns); return $this; }

    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
