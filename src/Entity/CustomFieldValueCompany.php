<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'custom_field_value_company')]
#[ORM\UniqueConstraint(name: 'UNIQ_CUSTOM_FIELD_VALUE_COMPANY', columns: ['definition_id', 'company_id'])]
class CustomFieldValueCompany extends AbstractCustomFieldValue
{
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    public function getCompany(): Company { return $this->company; }
    public function setCompany(Company $company): static { $this->company = $company; return $this; }

    public function getObjectId(): int { return $this->company->getId(); }

    public function setObjectRef(object $reference): static
    {
        return $this->setCompany($reference);
    }
}
