<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Entity\AbstractPartyNote;
use Doctrine\ORM\Mapping as ORM;

/** One note on a repair order: author, message, timestamp (the same note as a client's). */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_repair_order_note')]
#[ORM\Index(name: 'idx_maxeme_repair_order_note_ro', fields: ['repairOrder'])]
class RepairOrderNote extends AbstractPartyNote
{
    #[ORM\ManyToOne(targetEntity: RepairOrder::class, inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RepairOrder $repairOrder;

    public function __construct(RepairOrder $repairOrder)
    {
        parent::__construct();
        $this->repairOrder = $repairOrder;
    }

    public function getRepairOrder(): RepairOrder { return $this->repairOrder; }
}
