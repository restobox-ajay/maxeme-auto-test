<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One service on a repair order (the mockup's "job"): usually picked from the service catalogue,
 * which fills its name, price and lines; all three can then be changed. Its price is typed or
 * loaded from the service's default price, never calculated from the lines: the lines are advice
 * only, except charge-through ones, which add to the total. There is no quantity at this level.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_repair_order_job')]
class RepairOrderJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RepairOrder::class, inversedBy: 'jobs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RepairOrder $repairOrder;

    /** The catalogue service it came from, if any. */
    #[ORM\ManyToOne(targetEntity: ServiceItem::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ServiceItem $service = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    /** In dollars (CDN). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price = '0.00';

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** @var Collection<int, RepairOrderJobLine> */
    #[ORM\OneToMany(targetEntity: RepairOrderJobLine::class, mappedBy: 'job', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    public function __construct(RepairOrder $repairOrder)
    {
        $this->repairOrder = $repairOrder;
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getRepairOrder(): RepairOrder { return $this->repairOrder; }

    public function getService(): ?ServiceItem { return $this->service; }
    public function setService(?ServiceItem $service): self { $this->service = $service; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getPrice(): string { return $this->price; }
    public function setPrice(string $price): self { $this->price = $price; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    /** @return list<RepairOrderJobLine> in order */
    public function getLines(): array { return array_values($this->lines->toArray()); }

    /** @param list<RepairOrderJobLine> $lines this service's, in order; one left out is deleted */
    public function replaceLines(array $lines): void
    {
        foreach ($this->lines as $line) {
            if (!in_array($line, $lines, true)) {
                $this->lines->removeElement($line);
            }
        }
        foreach ($lines as $position => $line) {
            if ($line->getJob() !== $this) {
                throw new \InvalidArgumentException('A line of another service cannot be added.');
            }
            $line->setPosition($position);
            if (!$this->lines->contains($line)) {
                $this->lines->add($line);
            }
        }
    }

    /** @return list<RepairOrderJobLine> the lines the customer pays for on top of the price */
    public function getChargeThroughLines(): array
    {
        return array_values(array_filter($this->getLines(), static fn (RepairOrderJobLine $line): bool => $line->isChargeThrough()));
    }
}
