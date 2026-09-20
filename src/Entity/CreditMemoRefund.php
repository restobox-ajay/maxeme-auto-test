<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Money paid back to the customer against a credit note (#586).
 *
 * The mirror image of InvoicePayment, column for column, because it is the mirror image of the act:
 * an admin who logs a cheque arriving against an invoice logs a cheque going out against a credit
 * note, on the same screen shape, choosing from the same `payment_method` rows. Refunding and
 * applying are the two ways a credit note's balance leaves, and both are ordinary rows this
 * document sums.
 *
 * ## THERE IS NO stripe_payment_intent_id, AND THERE IS NOT GOING TO BE
 *
 * InvoicePayment carries one because a customer-initiated card payment genuinely arrives from
 * Stripe and the intent id is how the webhook finds the row it already wrote. Nothing about this
 * table has anything to do with a gateway: the feature is an admin recording a refund they have
 * ALREADY made, by whatever means they made it. A column carried "for symmetry" is exactly how
 * `product_inventory` acquired `incoming_quantity`, `reserved_quantity` and `manual_adjustment` —
 * three columns nothing in this application has ever written, each of which now has to be explained
 * to everyone who reads the table. Gateway-driven refunds, if they are ever built, will need a
 * gateway reference whose shape nobody can guess today, and adding a column then is cheaper than
 * having carried a wrong one for a year.
 *
 * Rows are created and removed only through CreditMemo::recordRefund() and CreditMemo::voidRefund(),
 * so the note's balance and its Open/Closed status cannot be left disagreeing with these rows.
 */
#[ORM\Entity]
#[ORM\Table(name: 'credit_memo_refund')]
class CreditMemoRefund
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CreditMemo::class, inversedBy: 'refunds')]
    #[ORM\JoinColumn(name: 'credit_memo_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CreditMemo $creditMemo;

    /**
     * Nullable, matching InvoicePayment::$user — but for a different reason worth writing down. On a
     * payment it is null because the customer paid without an admin present. Here every refund is
     * logged by a person, so null means the row was written by a console command or a fixture rather
     * than by anyone; the column stays nullable so such a path does not have to invent an admin.
     */
    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    private ?AdminUser $user = null;

    #[ORM\Column(name: 'refunded_at', type: 'date_immutable')]
    private \DateTimeImmutable $refundedAt;

    /** Drawn from the same `payment_method` rows an invoice payment's method is. */
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

    public function getCreditMemo(): CreditMemo { return $this->creditMemo; }

    /**
     * Set by CreditMemo::recordRefund(), which is the only thing that should call it.
     *
     * @internal
     */
    public function setCreditMemo(CreditMemo $creditMemo): self { $this->creditMemo = $creditMemo; return $this; }

    public function getUser(): ?AdminUser { return $this->user; }
    public function setUser(?AdminUser $user): self { $this->user = $user; return $this; }

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
