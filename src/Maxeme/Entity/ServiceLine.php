<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Entity\ProductCore;
use App\Maxeme\Enum\ServiceLineType;
use App\Maxeme\Listing\ItemLabel;
use App\Maxeme\Repository\ServiceLineRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of what a service is made of: labour hours, parts, sublet work, a government fee or a
 * discount, each with a quantity and a price per unit (negative for a discount).
 *
 * Charge through: when on, the invoice shows the line and adds quantity × price to the total on top
 * of the service's own price (e.g. a government levy); when off, the line is internal only and its
 * price is ignored. The item is a typed reference (labour, product or govt fee), so a record a
 * service uses cannot be deleted from under it.
 */
#[ORM\Entity(repositoryClass: ServiceLineRepository::class)]
#[ORM\Table(name: 'maxeme_service_line')]
class ServiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceItem::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ServiceItem $service;

    #[ORM\Column(length: 16, enumType: ServiceLineType::class)]
    private ServiceLineType $type;

    /** For Labour and Sublet lines. */
    #[ORM\ManyToOne(targetEntity: Labour::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Labour $labour = null;

    /** For Parts lines: a core product. */
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?ProductCore $product = null;

    /** For Gvt Fees lines. */
    #[ORM\ManyToOne(targetEntity: GovtFee::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?GovtFee $govtFee = null;

    /** Hours for labour, a count otherwise; always 1 for a discount. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $quantity = '1.00';

    /** In dollars (CDN); negative for a discount. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $unitPrice = '0.00';

    #[ORM\Column(options: ['default' => false])]
    private bool $chargeThrough = false;

    /** Its order on the service, from 0. */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(ServiceItem $service, ServiceLineType $type)
    {
        $this->service = $service;
        $this->type = $type;
    }

    public function getId(): ?int { return $this->id; }
    public function getService(): ServiceItem { return $this->service; }
    public function getType(): ServiceLineType { return $this->type; }

    public function getLabour(): ?Labour { return $this->labour; }
    public function getProduct(): ?ProductCore { return $this->product; }
    public function getGovtFee(): ?GovtFee { return $this->govtFee; }

    /** Points the line at its item, which must suit its type (none for a discount). */
    public function setItem(Labour|ProductCore|GovtFee|null $item): self
    {
        $expected = match ($this->type) {
            ServiceLineType::Labour, ServiceLineType::Sublet => Labour::class,
            ServiceLineType::Part => ProductCore::class,
            ServiceLineType::GovtFee => GovtFee::class,
            ServiceLineType::Discount => null,
        };
        if ($expected === null ? $item !== null : !$item instanceof $expected) {
            throw new \InvalidArgumentException(sprintf('A %s line cannot point at %s.', $this->type->label(), $item === null ? 'nothing' : $item::class));
        }

        $this->labour = $item instanceof Labour ? $item : null;
        $this->product = $item instanceof ProductCore ? $item : null;
        $this->govtFee = $item instanceof GovtFee ? $item : null;

        return $this;
    }

    public function getItem(): Labour|ProductCore|GovtFee|null
    {
        return $this->labour ?? $this->product ?? $this->govtFee;
    }

    /** The item's id, for the edit form. */
    public function getItemId(): ?int { return $this->getItem()?->getId(); }

    /** What the line is, as the edit form and logs name it. */
    public function getItemLabel(): string
    {
        $item = $this->getItem();

        return match (true) {
            $item instanceof ProductCore => ItemLabel::product($item),
            $item !== null => ItemLabel::charge($item),
            default => $this->type->label(),
        };
    }

    /** "Labour · Test labour (LAB) · 1.5 × 100.00", plus " (charged)" when it is charged through: the CSV export's text. */
    public function describe(): string
    {
        return sprintf(
            '%s%s · %s × %s%s',
            $this->type->label(),
            $this->type->hasItem() ? ' · ' . $this->getItemLabel() : '',
            (string) (float) $this->quantity,
            number_format((float) $this->unitPrice, 2, '.', ''),
            $this->chargeThrough ? ' (charged)' : '',
        );
    }

    public function getQuantity(): string { return $this->quantity; }
    public function setQuantity(string $quantity): self { $this->quantity = $quantity; return $this; }

    public function getUnitPrice(): string { return $this->unitPrice; }
    public function setUnitPrice(string $unitPrice): self { $this->unitPrice = $unitPrice; return $this; }

    public function isChargeThrough(): bool { return $this->chargeThrough; }
    public function setChargeThrough(bool $chargeThrough): self { $this->chargeThrough = $chargeThrough; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
}
