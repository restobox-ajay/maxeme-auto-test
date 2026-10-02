<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Enum\ServiceLineType;
use App\Maxeme\Repository\ServiceLineRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A catalogue service's line (see AbstractServiceLine). Its item is a plain foreign key, so a
 * labour rate, product or fee a service uses cannot be deleted from under it.
 */
#[ORM\Entity(repositoryClass: ServiceLineRepository::class)]
#[ORM\Table(name: 'maxeme_service_line')]
class ServiceLine extends AbstractServiceLine
{
    #[ORM\ManyToOne(targetEntity: ServiceItem::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ServiceItem $service;

    public function __construct(ServiceItem $service, ServiceLineType $type)
    {
        parent::__construct($type);
        $this->service = $service;
    }

    public function getService(): ServiceItem { return $this->service; }
}
