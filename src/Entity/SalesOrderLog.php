<?php

namespace App\Entity;

use App\Contract\Document\DocumentLog;
use Doctrine\ORM\Mapping as ORM;

/**
 * A sales order's timeline row.
 *
 * Implements {@see DocumentLog} as of queue item 64, which adds no column and no behaviour: the
 * three methods that interface names were already here with these signatures. It exists so that a
 * shared `setStatus()` can fill a row it did not construct — see that interface for why the log is
 * written by the setter rather than beside it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order_log')]
class SalesOrderLog implements DocumentLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SalesOrder::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false)]
    private SalesOrder $order;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userName = null;

    #[ORM\Column(type: 'text')]
    private string $comment;

    #[ORM\Column(length: 64)]
    private string $type = 'System';

    #[ORM\Column]
    private bool $customerNotified = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): SalesOrder
    {
        return $this->order;
    }

    public function setOrder(SalesOrder $order): self
    {
        $this->order = $order;
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

    public function isCustomerNotified(): bool
    {
        return $this->customerNotified;
    }

    public function setCustomerNotified(bool $customerNotified): self
    {
        $this->customerNotified = $customerNotified;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
