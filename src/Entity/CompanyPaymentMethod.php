<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CompanyPaymentMethodRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompanyPaymentMethodRepository::class)]
#[ORM\Table(name: 'company_payment_method')]
#[ORM\UniqueConstraint(name: 'UNIQ_COMPANY_PAYMENT_METHOD', columns: ['company_id', 'payment_method_id'])]
class CompanyPaymentMethod
{
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_INACTIVE = 'Inactive';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: PaymentMethod::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PaymentMethod $paymentMethod;

    #[ORM\Column(length: 32, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    public function getId(): ?int { return $this->id; }

    public function getCompany(): Company { return $this->company; }
    public function setCompany(Company $company): static { $this->company = $company; return $this; }

    public function getPaymentMethod(): PaymentMethod { return $this->paymentMethod; }
    public function setPaymentMethod(PaymentMethod $paymentMethod): static { $this->paymentMethod = $paymentMethod; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
}
