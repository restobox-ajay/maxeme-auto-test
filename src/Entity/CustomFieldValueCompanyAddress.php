<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'custom_field_value_company_address')]
#[ORM\UniqueConstraint(name: 'UNIQ_CUSTOM_FIELD_VALUE_COMPANY_ADDRESS', columns: ['definition_id', 'company_address_id'])]
class CustomFieldValueCompanyAddress extends AbstractCustomFieldValue
{
    #[ORM\ManyToOne(targetEntity: CompanyAddress::class)]
    #[ORM\JoinColumn(name: 'company_address_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CompanyAddress $companyAddress;

    public function getCompanyAddress(): CompanyAddress { return $this->companyAddress; }
    public function setCompanyAddress(CompanyAddress $companyAddress): static { $this->companyAddress = $companyAddress; return $this; }

    public function getObjectId(): int { return $this->companyAddress->getId(); }

    public function setObjectRef(object $reference): static
    {
        return $this->setCompanyAddress($reference);
    }
}
