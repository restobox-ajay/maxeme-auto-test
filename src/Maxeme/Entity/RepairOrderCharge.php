<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Enum\RepairOrderChargeKind;
use Doctrine\ORM\Mapping as ORM;

/**
 * A custom line under a repair order's subtotal, above tax (as wholesale's custom fee and
 * discount lines): a label and an amount, as many as the admin adds. The amount is positive; a
 * discount takes it off.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_repair_order_charge')]
class RepairOrderCharge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RepairOrder::class, inversedBy: 'charges')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RepairOrder $repairOrder;

    #[ORM\Column(length: 10, enumType: RepairOrderChargeKind::class)]
    private RepairOrderChargeKind $kind;

    #[ORM\Column(length: 120)]
    private string $label = '';

    /** In dollars (CDN), positive. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(RepairOrder $repairOrder, RepairOrderChargeKind $kind)
    {
        $this->repairOrder = $repairOrder;
        $this->kind = $kind;
    }

    public function getId(): ?int { return $this->id; }
    public function getRepairOrder(): RepairOrder { return $this->repairOrder; }

    public function getKind(): RepairOrderChargeKind { return $this->kind; }
    public function setKind(RepairOrderChargeKind $kind): self { $this->kind = $kind; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = $label; return $this; }

    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): self { $this->amount = $amount; return $this; }

    /** What it adds to the total, in cents: negative for a discount. */
    public function getSignedCents(): int
    {
        $cents = abs((int) round((float) $this->amount * 100));

        return $this->kind === RepairOrderChargeKind::Discount ? -$cents : $cents;
    }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
}
