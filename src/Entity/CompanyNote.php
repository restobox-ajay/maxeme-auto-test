<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One internal note against a company — admin-only, never shown to the customer (#358).
 *
 * The note itself — author, text, timestamp, and the argument for why a note is a row rather than a
 * line in a packed string — moved up to `AbstractPartyNote` in #635, where the vendor side's notes
 * meet it. Both tables already spelled those three columns the same way, so the extraction cost
 * neither of them a migration.
 *
 * All that is left here is the FK: this note is about a company.
 *
 * Shaped after SalesOrderLog and EstimateLog deliberately: the admin UI already presents company
 * notes and document logs as the same thing — timestamped entries with add, edit and delete — and
 * they should not be two different mechanisms underneath.
 */
#[ORM\Entity]
#[ORM\Table(name: 'company_note')]
#[ORM\Index(name: 'idx_company_note_company', fields: ['company'])]
class CompanyNote extends AbstractPartyNote
{
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    public function getCompany(): Company { return $this->company; }

    public function setCompany(Company $company): self { $this->company = $company; return $this; }
}
