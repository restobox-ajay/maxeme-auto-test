<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Repository\TechnicianRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A shop technician (Config › Settings › Technicians): a name to pick in the master technician
 * field. Not a login account; an inactive one is kept but no longer offered.
 */
#[ORM\Entity(repositoryClass: TechnicianRepository::class)]
#[ORM\Table(name: 'maxeme_technician')]
#[ORM\UniqueConstraint(name: 'uniq_maxeme_technician_name', columns: ['name'])]
class Technician
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $lastUpdated;

    public function __construct()
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }

    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }

    public function touch(): void
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }
}
