<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Entity\ProductCore;
use App\Maxeme\Enum\ServiceLineType;
use Doctrine\ORM\Mapping as ORM;

/**
 * A line of one repair order service (see AbstractServiceLine). It also points at the repair order
 * itself, so its changes show in the repair order's one history.
 *
 * A repair order records work done, so deleting a labour rate, product or fee one used is allowed:
 * the line keeps the item's name (itemName) and loses only the link.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_repair_order_job_line')]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(name: 'labour', joinColumns: [new ORM\JoinColumn(name: 'labour_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]),
    new ORM\AssociationOverride(name: 'product', joinColumns: [new ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]),
    new ORM\AssociationOverride(name: 'govtFee', joinColumns: [new ORM\JoinColumn(name: 'govt_fee_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]),
])]
class RepairOrderJobLine extends AbstractServiceLine
{
    #[ORM\ManyToOne(targetEntity: RepairOrderJob::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RepairOrderJob $job;

    #[ORM\ManyToOne(targetEntity: RepairOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RepairOrder $repairOrder;

    /** The item's name when it was chosen, kept if the item is deleted later. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $itemName = null;

    public function __construct(RepairOrderJob $job, ServiceLineType $type)
    {
        parent::__construct($type);
        $this->job = $job;
        $this->repairOrder = $job->getRepairOrder();
    }

    public function getJob(): RepairOrderJob { return $this->job; }
    public function getRepairOrder(): RepairOrder { return $this->repairOrder; }

    public function setItem(Labour|ProductCore|GovtFee|null $item): static
    {
        parent::setItem($item);
        $this->itemName = $item !== null ? parent::getItemLabel() : null;

        return $this;
    }

    public function getItemLabel(): string
    {
        return $this->getItem() === null && $this->itemName !== null ? $this->itemName : parent::getItemLabel();
    }
}
