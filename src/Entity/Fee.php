<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FeeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FeeRepository::class)]
#[ORM\Table(name: 'fee')]
class Fee
{
    public const PLACEMENT_MAIN_LINE = 'main_line';
    public const PLACEMENT_AFTER_TAX = 'after_tax_line';

    /**
     * Renders in the totals box above the tax row. Presentational only — unlike PLACEMENT_AFTER_TAX
     * it carries no tax rule, so a before-tax-line fee is taxed or not purely by its tax class.
     */
    public const PLACEMENT_BEFORE_TAX_LINE = 'before_tax_line';

    public const TAX_CLASSES = ['E', 'G', 'S'];
    public const PLACEMENTS = [self::PLACEMENT_MAIN_LINE, self::PLACEMENT_BEFORE_TAX_LINE, self::PLACEMENT_AFTER_TAX];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $slug = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, options: ['default' => 0])]
    private string $defaultValue = '0.0000';

    #[ORM\Column(length: 1)]
    private string $taxClass = 'E';

    #[ORM\Column(length: 100)]
    private string $source = '';

    #[ORM\Column(length: 20, options: ['default' => self::PLACEMENT_MAIN_LINE])]
    private string $placement = self::PLACEMENT_MAIN_LINE;

    public function getId(): ?int { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDefaultValue(): float { return (float) $this->defaultValue; }
    public function setDefaultValue(float $value): static { $this->defaultValue = (string) $value; return $this; }

    public function getTaxClass(): string { return $this->taxClass; }
    public function setTaxClass(string $taxClass): static { $this->taxClass = $taxClass; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $source): static { $this->source = $source; return $this; }

    public function getPlacement(): string { return $this->placement; }
    public function setPlacement(string $placement): static { $this->placement = $placement; return $this; }

    /**
     * The single source of truth for what makes a fee valid — every fee-bundle config controller
     * used to also whitelist tax class/placement inline with in_array() before this ran, one copy
     * of the same two arrays per controller. Folded in here so App\Validation\Constraint\ValidFee
     * (and anywhere else a Fee gets validated) enforces all four rules the same way.
     *
     * After Tax fees are added to the grand total without ever being taxed themselves — enforce
     * that here.
     */
    public function assertValid(): void
    {
        $label = $this->name !== '' ? $this->name : $this->slug;

        if (!in_array($this->taxClass, self::TAX_CLASSES, true)) {
            throw new \InvalidArgumentException(sprintf('Fee "%s" has an invalid tax class.', $label));
        }

        if (!in_array($this->placement, self::PLACEMENTS, true)) {
            throw new \InvalidArgumentException(sprintf('Fee "%s" has an invalid placement.', $label));
        }

        if ($this->placement === self::PLACEMENT_AFTER_TAX && $this->taxClass !== 'E') {
            throw new \InvalidArgumentException('After Tax fees cannot be taxable — set the tax class to Exempt first.');
        }

        if ((float) $this->defaultValue < 0) {
            throw new \InvalidArgumentException(sprintf('Fee "%s" cannot have a negative value.', $label));
        }
    }
}
