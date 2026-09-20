<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Money the vendor actually paid back against a debit memo (#638) — the mirror of
 * `App\Entity\CreditMemoRefund`. An admin recording a refund that has already happened, by whatever
 * means the vendor sent it — a cheque, a wire, a credit on the next order. No gateway integration
 * and none is coming: see `CreditMemoRefund`'s own docblock for why a column carried "for symmetry"
 * is exactly how `product_inventory` acquired three columns nothing ever wrote.
 *
 * Rows are created and removed only through `DebitMemo::recordRefund()`/`voidRefund()`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'debit_memo_refund')]
class DebitMemoRefund
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DebitMemo::class, inversedBy: 'refunds')]
    #[ORM\JoinColumn(name: 'debit_memo_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private DebitMemo $debitMemo;

    /** Nullable, matching CreditMemoRefund::$user: a console command or fixture writes this with nobody signed in. */
    #[ORM\ManyToOne(targetEntity: \App\Entity\AdminUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    private ?\App\Entity\AdminUser $user = null;

    #[ORM\Column(name: 'refunded_at', type: 'date_immutable')]
    private \DateTimeImmutable $refundedAt;

    /** Drawn from the same `payment_method` rows an invoice payment's and a credit memo refund's method are. */
    #[ORM\Column(length: 64)]
    private string $method = '';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->refundedAt = new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDebitMemo(): DebitMemo { return $this->debitMemo; }

    /** @internal set by DebitMemo::recordRefund() */
    public function setDebitMemo(DebitMemo $debitMemo): self { $this->debitMemo = $debitMemo; return $this; }

    public function getUser(): ?\App\Entity\AdminUser { return $this->user; }
    public function setUser(?\App\Entity\AdminUser $user): self { $this->user = $user; return $this; }
    public function getRefundedAt(): \DateTimeImmutable { return $this->refundedAt; }
    public function setRefundedAt(\DateTimeImmutable $refundedAt): self { $this->refundedAt = $refundedAt; return $this; }
    public function getMethod(): string { return $this->method; }
    public function setMethod(string $method): self { $this->method = $method; return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): self { $this->amount = $amount; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): self { $this->comment = $comment; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
