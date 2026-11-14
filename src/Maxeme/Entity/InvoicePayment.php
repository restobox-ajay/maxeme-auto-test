<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One payment towards an invoice (mockup 345c037): an invoice takes one or more (a deposit, then the
 * rest); it is Paid once they cover its total (App\Maxeme\Service\InvoicePayments).
 */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_invoice_payment')]
class InvoicePayment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\ManyToOne(targetEntity: PaymentType::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PaymentType $paymentType;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column]
    private \DateTimeImmutable $paidAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Invoice $invoice, ?PaymentType $paymentType, string $amount, \DateTimeImmutable $paidAt, ?string $note = null)
    {
        $this->invoice = $invoice;
        $this->createdAt = new \DateTimeImmutable();
        $this->change($paymentType, $amount, $paidAt, $note);
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getPaymentType(): ?PaymentType { return $this->paymentType; }
    public function getAmount(): string { return $this->amount; }
    public function getPaidAt(): \DateTimeImmutable { return $this->paidAt; }
    public function getNote(): ?string { return $this->note; }

    public function change(?PaymentType $paymentType, string $amount, \DateTimeImmutable $paidAt, ?string $note): void
    {
        $this->paymentType = $paymentType;
        $this->amount = $amount;
        $this->paidAt = $paidAt;
        $note = $note !== null ? trim($note) : null;
        $this->note = $note !== '' ? $note : null;
    }
}
