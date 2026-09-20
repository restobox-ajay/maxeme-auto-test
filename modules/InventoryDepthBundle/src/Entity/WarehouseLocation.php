<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Entity;

use App\Entity\Warehouse;
use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Repository\WarehouseLocationRepository;

/**
 * A bin: a named place inside a warehouse (#550).
 *
 * `sort_key` is pick-path traversal order, and it is the tiebreak the automatic source picker uses
 * after expiry — so it is a real operational number, not decoration. #552 (warehouse operations)
 * walks bins in exactly this order.
 */
#[ORM\Entity(repositoryClass: WarehouseLocationRepository::class)]
#[ORM\Table(name: 'warehouse_location')]
#[ORM\UniqueConstraint(name: 'uniq_wh_location', fields: ['warehouse', 'code'])]
#[ORM\Index(name: 'idx_wh_location_pick_path', fields: ['warehouse', 'sortKey'])]
class WarehouseLocation
{
    public const TYPE_PICK = 'pick';
    public const TYPE_RECEIVING = 'receiving';
    public const TYPE_STAGING = 'staging';
    public const TYPE_TRANSIT = 'transit';
    public const TYPE_HOLD = 'hold';

    /** @return list<string> */
    public static function types(): array
    {
        return [self::TYPE_PICK, self::TYPE_RECEIVING, self::TYPE_STAGING, self::TYPE_TRANSIT, self::TYPE_HOLD];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Warehouse $warehouse;

    #[ORM\Column(length: 64)]
    private string $code = '';

    #[ORM\Column(length: 16)]
    private string $type = self::TYPE_PICK;

    /** Pick-path traversal order. Lower is visited first; ties break on code. */
    #[ORM\Column(name: 'sort_key', options: ['default' => 0])]
    private int $sortKey = 0;

    #[ORM\Column(length: 16, options: ['default' => 'Active'])]
    private string $status = 'Active';

    /**
     * Where the bin is, for the map (#552). All four are nullable and nothing reads them unless the
     * bin map is opened: a warehouse that never opens it never fills them in, and `sort_key`
     * continues to drive picking on its own.
     *
     * Zone and aisle are deliberately flat labels for grouping and filtering rather than a hierarchy
     * with tables of its own — three warehouses of a few hundred bins do not need a tree, and the
     * tree would then need maintaining to stay true.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $zone = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $aisle = null;

    #[ORM\Column(name: 'map_x', nullable: true)]
    private ?int $mapX = null;

    #[ORM\Column(name: 'map_y', nullable: true)]
    private ?int $mapY = null;

    public function getId(): ?int { return $this->id; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function setWarehouse(Warehouse $warehouse): self { $this->warehouse = $warehouse; return $this; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }
    public function getType(): string { return $this->type; }

    public function setType(string $type): self
    {
        $this->type = \in_array($type, self::types(), true) ? $type : self::TYPE_PICK;

        return $this;
    }

    public function getSortKey(): int { return $this->sortKey; }
    public function setSortKey(int $sortKey): self { $this->sortKey = $sortKey; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function isActive(): bool { return $this->status === 'Active'; }

    public function getZone(): ?string { return $this->zone; }
    public function setZone(?string $zone): self { $this->zone = self::blankToNull($zone); return $this; }
    public function getAisle(): ?string { return $this->aisle; }
    public function setAisle(?string $aisle): self { $this->aisle = self::blankToNull($aisle); return $this; }
    public function getMapX(): ?int { return $this->mapX; }
    public function setMapX(?int $mapX): self { $this->mapX = $mapX; return $this; }
    public function getMapY(): ?int { return $this->mapY; }
    public function setMapY(?int $mapY): self { $this->mapY = $mapY; return $this; }

    /** A bin only appears on the map once it has both coordinates; one of the two places it nowhere. */
    public function isMapped(): bool { return $this->mapX !== null && $this->mapY !== null; }

    private static function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf('%s @ %s', $this->code, $this->warehouse->getName());
    }
}
