<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Enum\TaxClass;
use Doctrine\ORM\Mapping as ORM;

/**
 * Something the shop charges for, picked by its code: a labour rate or a government fee. Each has
 * a name, a price, a tax class and an Active switch (an inactive one is kept but not offered).
 */
#[ORM\MappedSuperclass]
abstract class AbstractCharge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    /** Short and unique, e.g. "LAB-STD" or "TIRE-ENV". */
    #[ORM\Column(length: 40)]
    protected string $code = '';

    #[ORM\Column(length: 255)]
    protected string $name = '';

    /** In dollars (CDN). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    protected string $price = '0.00';

    #[ORM\Column(length: 16, enumType: TaxClass::class, options: ['default' => 'gst_pst'])]
    protected TaxClass $taxClass = TaxClass::GstPst;

    #[ORM\Column(options: ['default' => true])]
    protected bool $active = true;

    #[ORM\Column]
    protected \DateTimeImmutable $lastUpdated;

    public function __construct()
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): static { $this->code = strtoupper($code); return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getPrice(): string { return $this->price; }
    public function setPrice(string $price): static { $this->price = $price; return $this; }

    public function getTaxClass(): TaxClass { return $this->taxClass; }
    public function setTaxClass(TaxClass $taxClass): static { $this->taxClass = $taxClass; return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }

    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }

    public function touch(): void
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }
}
