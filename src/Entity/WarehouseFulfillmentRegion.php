<?php

namespace App\Entity;

use App\Repository\WarehouseFulfillmentRegionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Which warehouse's stock answers for which fulfillment region (#546).
 *
 * A join table rather than a foreign key column on either side, deliberately. The shape is
 * many-to-many; the LOGIC is one-to-one, and `uniq_wfr_region` is the only thing holding it
 * there. Dropping that index is what enables a region served by more than one warehouse — an
 * index drop plus an allocation policy, not a data migration, which is the whole reason the
 * mapping does not live as `warehouse.fulfillment_region_id`.
 *
 * `priority` exists for that day and nothing reads it yet: it is which warehouse serves a region
 * first once a region can have more than one. Every row created today gets 0.
 *
 * Every resolver in the app takes the single result (see WarehouseFulfillmentRegionService).
 */
#[ORM\Entity(repositoryClass: WarehouseFulfillmentRegionRepository::class)]
#[ORM\Table(name: 'warehouse_fulfillment_region')]
#[ORM\UniqueConstraint(name: 'uniq_wfr_region', columns: ['fulfillment_region_id'])]
#[ORM\UniqueConstraint(name: 'uniq_wfr_pair', columns: ['warehouse_id', 'fulfillment_region_id'])]
class WarehouseFulfillmentRegion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Warehouse $warehouse;

    #[ORM\ManyToOne(targetEntity: FulfillmentRegion::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FulfillmentRegion $fulfillmentRegion;

    /**
     * Which warehouse serves this region first, once a region can have more than one. Nothing
     * reads it yet and every row gets 0 — it is here so that enabling many-to-many needs no
     * schema change.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $priority = 0;

    public function getId(): ?int { return $this->id; }

    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function setWarehouse(Warehouse $warehouse): self { $this->warehouse = $warehouse; return $this; }

    public function getFulfillmentRegion(): FulfillmentRegion { return $this->fulfillmentRegion; }
    public function setFulfillmentRegion(FulfillmentRegion $fulfillmentRegion): self { $this->fulfillmentRegion = $fulfillmentRegion; return $this; }

    public function getPriority(): int { return $this->priority; }
    public function setPriority(int $priority): self { $this->priority = $priority; return $this; }
}
