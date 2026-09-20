<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductFeeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductFeeRepository::class)]
#[ORM\Table(name: 'product_fee')]
#[ORM\UniqueConstraint(name: 'UNIQ_PRODUCT_FEE', columns: ['product_id', 'fee_id'])]
class ProductFee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\ManyToOne(targetEntity: Fee::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Fee $fee;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, options: ['default' => 0])]
    private string $value = '0.0000';

    public function getId(): ?int { return $this->id; }

    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): static { $this->product = $product; return $this; }

    public function getFee(): Fee { return $this->fee; }
    public function setFee(Fee $fee): static { $this->fee = $fee; return $this; }

    public function getValue(): float { return (float) $this->value; }
    public function setValue(float $value): static { $this->value = (string) $value; return $this; }
}
