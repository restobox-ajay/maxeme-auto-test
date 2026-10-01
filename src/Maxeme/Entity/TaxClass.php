<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Repository\TaxClassRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Which sales taxes a charge carries (Config › Settings › Tax Classes), e.g. S = GST + PST,
 * G = GST only, E = Exempt. Labour and government fees pick one; invoices still tax every line at
 * GST + PST until per-line tax classes are specified (see the project notes).
 */
#[ORM\Entity(repositoryClass: TaxClassRepository::class)]
#[ORM\Table(name: 'maxeme_tax_class')]
#[ORM\UniqueConstraint(name: 'uniq_maxeme_tax_class_code', columns: ['code'])]
class TaxClass
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Short and unique, e.g. "S". */
    #[ORM\Column(length: 8)]
    private string $code = '';

    #[ORM\Column(length: 120)]
    private string $name = '';

    /** @var Collection<int, TaxRate> the taxes charged; none = exempt */
    #[ORM\ManyToMany(targetEntity: TaxRate::class)]
    #[ORM\JoinTable(name: 'maxeme_tax_class_rate')]
    #[ORM\OrderBy(['code' => 'ASC'])]
    private Collection $rates;

    #[ORM\Column]
    private \DateTimeImmutable $lastUpdated;

    public function __construct()
    {
        $this->rates = new ArrayCollection();
        $this->lastUpdated = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = strtoupper($code); return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    /** @return Collection<int, TaxRate> */
    public function getRates(): Collection { return $this->rates; }

    /** @param iterable<TaxRate> $rates */
    public function setRates(iterable $rates): self
    {
        $this->rates->clear();
        foreach ($rates as $rate) {
            $this->rates->add($rate);
        }

        return $this;
    }

    /** "GST + PST", or "None" when exempt. */
    public function getRatesLabel(): string
    {
        $codes = $this->rates->map(static fn (TaxRate $rate): string => $rate->getCode())->toArray();

        return $codes !== [] ? implode(' + ', $codes) : 'None';
    }

    /** "S - GST+PST" */
    public function getLabel(): string
    {
        return sprintf('%s - %s', $this->code, $this->name);
    }

    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }

    public function touch(): void
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }
}
