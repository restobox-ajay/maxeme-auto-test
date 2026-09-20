<?php

namespace App\Entity;

use App\Repository\ProductPricingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductPricingRepository::class)]
#[ORM\Table(name: 'product_pricing')]
#[ORM\UniqueConstraint(name: 'uniq_product_pricing_product_list', fields: ['product', 'priceList'])]
class ProductPricing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\ManyToOne(targetEntity: PriceList::class)]
    #[ORM\JoinColumn(name: 'price_list_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PriceList $priceList;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6)]
    private string $price = '0.00';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $ruleType = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $ruleValue = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'USD';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function getPriceList(): PriceList { return $this->priceList; }
    public function setPriceList(PriceList $priceList): self { $this->priceList = $priceList; return $this; }
    public function getPrice(): string { return $this->price; }
    public function setPrice(string $price): self { $this->price = $price; return $this; }
    public function getRuleType(): ?string { return $this->ruleType; }
    public function setRuleType(?string $ruleType): self { $this->ruleType = $ruleType !== null ? trim($ruleType) : null; return $this; }
    public function getRuleValue(): ?string { return $this->ruleValue; }
    public function setRuleValue(?string $ruleValue): self { $this->ruleValue = $ruleValue !== null && trim($ruleValue) !== '' ? $ruleValue : null; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): self { $this->currency = strtoupper(substr($currency, 0, 3)); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
