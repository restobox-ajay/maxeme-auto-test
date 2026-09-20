<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One vendor's price against one requirement line (#637). The mechanism that makes N competing
 * quotes against one `Rfq` possible: the requirement (`RfqLine`) is asked once, and this is asked
 * once per invited vendor per requirement line.
 *
 * `$unitCost` is nullable, mirroring `EstimateLine::$price`: null means "not quoted yet", distinct
 * from a real $0.00. `RfqVendorReply::isFullyPriced()` reads exactly that distinction.
 */
#[ORM\Entity]
#[ORM\Table(name: 'rfq_vendor_reply_line')]
#[ORM\Index(name: 'idx_rfq_reply_line_reply', fields: ['reply'])]
class RfqVendorReplyLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RfqVendorReply::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'reply_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private RfqVendorReply $reply;

    /** The requirement this prices. SET NULL rather than CASCADE: losing the link must never delete a quote actually given. */
    #[ORM\ManyToOne(targetEntity: RfqLine::class)]
    #[ORM\JoinColumn(name: 'rfq_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?RfqLine $rfqLine = null;

    /** Nullable — null means "not quoted yet". See the class docblock. */
    #[ORM\Column(name: 'unit_cost', type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $unitCost = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $subtotal = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function getId(): ?int { return $this->id; }
    public function getReply(): RfqVendorReply { return $this->reply; }
    public function setReply(RfqVendorReply $reply): self { $this->reply = $reply; return $this; }
    public function getRfqLine(): ?RfqLine { return $this->rfqLine; }
    public function setRfqLine(?RfqLine $rfqLine): self { $this->rfqLine = $rfqLine; return $this; }
    public function getUnitCost(): ?string { return $this->unitCost; }
    public function setUnitCost(?string $unitCost): self { $this->unitCost = $unitCost; return $this; }
    public function getSubtotal(): ?string { return $this->subtotal; }
    public function setSubtotal(?string $subtotal): self { $this->subtotal = $subtotal; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
}
