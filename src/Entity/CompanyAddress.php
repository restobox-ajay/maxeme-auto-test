<?php

namespace App\Entity;

use App\Repository\CompanyAddressRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entry in a customer's address book.
 *
 * Everything shared with a supplier's address book moved up to `AbstractPartyAddress` in #635 —
 * label, the contact block, the street lines, the locality columns and the delivery instructions.
 * The superclass adopted THIS class's column names verbatim (`address_line1`, `phone`, …), so this
 * table's DDL is unchanged to the byte and `company_address` needed no migration; `vendor_address`
 * absorbed all the renaming instead.
 *
 * What stays here is only what the sell side alone asks of an address: which company owns it, which
 * one is the default to bill and which to ship, and when it was created.
 */
#[ORM\Entity(repositoryClass: CompanyAddressRepository::class)]
#[ORM\Table(name: 'company_address')]
class CompanyAddress extends AbstractPartyAddress
{
    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'addresses')]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(options: ['default' => false])]
    private bool $isDefaultShipping = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isDefaultBilling = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getCompany(): Company { return $this->company; }
    public function setCompany(Company $company): self { $this->company = $company; return $this; }

    public function isDefaultShipping(): bool { return $this->isDefaultShipping; }
    public function setIsDefaultShipping(bool $isDefaultShipping): self { $this->isDefaultShipping = $isDefaultShipping; return $this; }

    public function isDefaultBilling(): bool { return $this->isDefaultBilling; }
    public function setIsDefaultBilling(bool $isDefaultBilling): self { $this->isDefaultBilling = $isDefaultBilling; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
