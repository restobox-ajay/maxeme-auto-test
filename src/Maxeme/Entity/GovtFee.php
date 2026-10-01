<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Repository\GovtFeeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A government fee (Parts & Services › Government Fees), e.g. a tire or battery levy: added to an
 * invoice by hand, or by a GovtFeeRule (code that decides an invoice needs it, by its code).
 */
#[ORM\Entity(repositoryClass: GovtFeeRepository::class)]
#[ORM\Table(name: 'maxeme_govt_fee')]
#[ORM\UniqueConstraint(name: 'uniq_maxeme_govt_fee_code', columns: ['code'])]
class GovtFee extends AbstractCharge
{
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
}
