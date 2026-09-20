<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Contract\Document\DocumentLog;
use Doctrine\ORM\Mapping as ORM;

/**
 * A purchase order's timeline row — copied from App\Entity\SalesOrderLog and adapted
 * (#full-parity, 2026-09-13): `purchaseOrder`/`purchase_order_id` in place of `order`/`order_id`,
 * and `vendorNotified` in place of `customerNotified` — the buyer on this side is us, so a flag
 * asking whether the CUSTOMER was told would have nothing to say; `vendorNotified` asks the same
 * question about the other party this document actually has one of.
 *
 * Implements {@see DocumentLog}, same as SalesOrderLog: the three methods the interface names were
 * already here with these signatures, so a shared `setStatus()` can fill a row it did not
 * construct.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_order_log')]
#[ORM\Index(name: 'idx_po_log_order', fields: ['purchaseOrder'])]
class PurchaseOrderLog implements DocumentLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class)]
    #[ORM\JoinColumn(name: 'purchase_order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PurchaseOrder $purchaseOrder;

    #[ORM\Column(name: 'user_name', length: 255, nullable: true)]
    private ?string $userName = null;

    #[ORM\Column(type: 'text')]
    private string $comment = '';

    #[ORM\Column(length: 64)]
    private string $type = 'System';

    #[ORM\Column(name: 'vendor_notified', options: ['default' => false])]
    private bool $vendorNotified = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPurchaseOrder(): PurchaseOrder
    {
        return $this->purchaseOrder;
    }

    public function setPurchaseOrder(PurchaseOrder $purchaseOrder): self
    {
        $this->purchaseOrder = $purchaseOrder;
        return $this;
    }

    public function getUserName(): ?string
    {
        return $this->userName;
    }

    public function setUserName(?string $userName): self
    {
        $this->userName = $userName;
        return $this;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function setComment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function isVendorNotified(): bool
    {
        return $this->vendorNotified;
    }

    public function setVendorNotified(bool $vendorNotified): self
    {
        $this->vendorNotified = $vendorNotified;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
