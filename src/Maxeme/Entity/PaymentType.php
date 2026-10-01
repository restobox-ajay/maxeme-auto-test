<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Repository\PaymentTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * How an invoice is paid (Config › Settings › Payment Types), e.g. Cash 1 (NT) or Debit. The invoice
 * builder offers the active ones in list order; the Summary Report has a tab for each.
 */
#[ORM\Entity(repositoryClass: PaymentTypeRepository::class)]
#[ORM\Table(name: 'maxeme_payment_type')]
#[ORM\UniqueConstraint(name: 'uniq_maxeme_payment_type_name', columns: ['name'])]
class PaymentType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private string $name = '';

    /** The list order (lowest first). */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

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

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }

    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }

    public function touch(): void
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }
}
