<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An estimate's own frozen billing or shipping address.
 *
 * Estimates snapshot for the same reason orders do, and arguably a stronger one: the team prices a
 * quote against a specific delivery address, so if it changes before the quote is accepted the
 * quoted shipping and tax no longer describe the job. On conversion the resulting order copies this
 * snapshot rather than re-reading the address book.
 */
#[ORM\Entity]
#[ORM\Table(name: 'estimate_address')]
#[ORM\UniqueConstraint(name: 'uniq_estimate_address_type', fields: ['estimate', 'type'])]
class EstimateAddress extends AbstractDocumentAddress
{
    #[ORM\ManyToOne(targetEntity: Estimate::class, inversedBy: 'estimateAddresses')]
    #[ORM\JoinColumn(name: 'estimate_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Estimate $estimate;

    public function getEstimate(): Estimate { return $this->estimate; }
    public function setEstimate(Estimate $estimate): self { $this->estimate = $estimate; return $this; }
}
