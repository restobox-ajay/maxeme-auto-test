<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Document\DocumentLog;
use Doctrine\ORM\Mapping as ORM;

/**
 * A quote's timeline row.
 *
 * `implements DocumentLog` adds no column, no table and no behaviour — the three methods it names
 * were already here with these exact signatures, which is what that interface's own docblock says
 * of all five log entities. Declaring it is what lets the shared `setStatus()` on
 * `AbstractSalesDocument` fill in a row it did not construct. `SalesOrderLog` declared it in stage
 * 2 for the same reason; `InvoiceLog` and `VendorBillLog` follow when their documents join.
 */
#[ORM\Entity]
#[ORM\Table(name: 'estimate_log')]
class EstimateLog implements DocumentLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Estimate::class)]
    #[ORM\JoinColumn(name: 'estimate_id', referencedColumnName: 'id', nullable: false)]
    private Estimate $estimate;

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

    public function getEstimate(): Estimate
    {
        return $this->estimate;
    }

    public function setEstimate(Estimate $estimate): self
    {
        $this->estimate = $estimate;
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
