<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Repository\TaxRateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A sales tax and its rate (Config › Settings › Tax Rates): GST and PST. The migration adds both
 * rows and only their name and rate are edited; invoices charge them (InvoiceSettings) and tax
 * classes say which apply.
 */
#[ORM\Entity(repositoryClass: TaxRateRepository::class)]
#[ORM\Table(name: 'maxeme_tax_rate')]
#[ORM\UniqueConstraint(name: 'uniq_maxeme_tax_rate_code', columns: ['code'])]
class TaxRate
{
    public const GST = 'GST';
    public const PST = 'PST';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 8)]
    private string $code;

    #[ORM\Column(length: 60)]
    private string $name;

    /** In percent. */
    #[ORM\Column(options: ['default' => 0])]
    private int $rate;

    #[ORM\Column]
    private \DateTimeImmutable $lastUpdated;

    public function __construct(string $code, string $name, int $rate)
    {
        $this->code = $code;
        $this->name = $name;
        $this->rate = $rate;
        $this->lastUpdated = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getRate(): int { return $this->rate; }
    public function setRate(int $rate): self { $this->rate = $rate; return $this; }

    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }

    public function touch(): void
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }
}
