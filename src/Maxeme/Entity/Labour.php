<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Enum\LabourUnit;
use App\Maxeme\Repository\LabourRepository;
use Doctrine\ORM\Mapping as ORM;

/** A labour rate (Parts & Services › Labour): price per hour or per item; sublet when an outside shop does the work. */
#[ORM\Entity(repositoryClass: LabourRepository::class)]
#[ORM\Table(name: 'maxeme_labour')]
#[ORM\UniqueConstraint(name: 'uniq_maxeme_labour_code', columns: ['code'])]
class Labour extends AbstractCharge
{
    #[ORM\Column(length: 8, enumType: LabourUnit::class, options: ['default' => 'hour'])]
    private LabourUnit $unit = LabourUnit::Hour;

    /** Done by an outside shop and charged on. */
    #[ORM\Column(options: ['default' => false])]
    private bool $sublet = false;

    public function getUnit(): LabourUnit { return $this->unit; }
    public function setUnit(LabourUnit $unit): self { $this->unit = $unit; return $this; }

    public function isSublet(): bool { return $this->sublet; }
    public function setSublet(bool $sublet): self { $this->sublet = $sublet; return $this; }
}
