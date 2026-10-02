<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Enum\DocumentChargeKind;
use Doctrine\ORM\Mapping as ORM;

/** A repair order's custom fee or discount (see AbstractDocumentCharge). */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_repair_order_charge')]
class RepairOrderCharge extends AbstractDocumentCharge
{
    #[ORM\ManyToOne(targetEntity: RepairOrder::class, inversedBy: 'charges')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RepairOrder $repairOrder;

    public function __construct(RepairOrder $repairOrder, DocumentChargeKind $kind)
    {
        parent::__construct($kind);
        $this->repairOrder = $repairOrder;
    }

    public function getRepairOrder(): RepairOrder { return $this->repairOrder; }
}
