<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\DisplayNumber;
use App\Service\Pricing\CustomerPricingScope;
use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per (cart, product) line. `fulfillmentRegion` always reflects the session's CURRENT
 * region — not fixed at the moment the item was added. Every add/update touch converts it to
 * whatever region is active then, since availability is only ever meaningful against a specific
 * region's stock and this app has one active region per session, applied to the whole cart
 * uniformly (see App\Service\CartService). CartHoldBundle\Entity\CartHold reads the region
 * straight through this association rather than duplicating it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cart_item')]
#[ORM\Index(name: 'idx_cart_item_unit', fields: ['unitOfMeasure'])]
#[ORM\UniqueConstraint(name: 'uniq_cart_item_cart_product', fields: ['cart', 'product'])]
class CartItem implements DocumentLine, DenominatedLine
{
    use DenominatedQuantity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Cart::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'cart_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Cart $cart;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\ManyToOne(targetEntity: FulfillmentRegion::class)]
    #[ORM\JoinColumn(name: 'fulfillment_region_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private FulfillmentRegion $fulfillmentRegion;

    #[ORM\Column(type: 'quantity')]
    private string $quantity = '0.0000';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /*
     * Money, deliberately unmapped — no #[ORM\Column], nothing written to cart_item.
     *
     * What a customer pays moves with their company, their region and the price list resolved from
     * the two, so a stored figure is stale the moment any of them changes; the cart page has always
     * re-resolved it from the catalog at render time for exactly that reason. What was missing was
     * anywhere to put the answer, so resolveCartRows() computed it and threw it into a plain array.
     * These properties are that somewhere: same values, on the line, where a document consumer
     * expects to find them. priceAgainst() fills them.
     */
    private ?string $price = null;
    private ?string $subtotal = null;
    private ?string $taxCode = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCart(): Cart { return $this->cart; }
    public function setCart(Cart $cart): self { $this->cart = $cart; return $this; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function getFulfillmentRegion(): FulfillmentRegion { return $this->fulfillmentRegion; }
    public function setFulfillmentRegion(FulfillmentRegion $fulfillmentRegion): self { $this->fulfillmentRegion = $fulfillmentRegion; return $this; }
    public function getQuantity(): string { return $this->quantity; }
    /**
     * Restating the base figure forgets how it was entered (#601, #646).
     *
     * Writing this column directly says "the row is this many BASE units", and the only
     * truthful entered figure for that is the same number in base units — which is what
     * `quantity_entered` NULL and `unit_id` NULL mean. See DenominatedQuantity.
     */
    public function setQuantity(string|int|float $quantity): self
    {
        $this->quantity = QuantityScale::canonical($quantity);
        $this->forgetEnteredExpression();

        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    /** Null until priced, and null after pricing when no price resolves — see CustomerPrice. */
    public function getPrice(): ?string { return $this->price; }

    public function getSubtotal(): ?string { return $this->subtotal; }

    /**
     * Falls through to the product when nothing has priced this line yet. The code is the product's
     * either way; carrying it on the line is what lets a caller read one shape across the three line
     * types. The fallback matters because an unpriced cart reporting every line exempt is a silent
     * $0-tax answer rather than an obvious failure.
     */
    public function getTaxCode(): ?string { return $this->taxCode ?? $this->product->getSalesTaxCode(); }

    /**
     * A cart has no row order to record: its items are a set, one per product, and the unique
     * constraint above is what says so. Answering 0 for every item leaves a stable sort holding
     * them exactly as the collection did — see DocumentLine::getSortOrder().
     */
    public function getSortOrder(): int { return 0; }

    /**
     * Resolve this line's money against a (company, region) pricing scope, and return what it adds
     * to the cart subtotal — zero when no price resolves, since an unpriced line contributes nothing
     * to a total it cannot be part of.
     *
     * Price and subtotal are left null in that case rather than zeroed: "no price" is what sends a
     * checkout to an estimate instead of an order, and a zero would erase the distinction.
     */
    public function priceAgainst(CustomerPricingScope $scope): float
    {
        $customerPrice = $scope->priceFor($this->product);
        $this->taxCode = $this->product->getSalesTaxCode();
        $this->price = $customerPrice->amount;

        if (!$customerPrice->isPriced()) {
            $this->subtotal = null;

            return 0.0;
        }

        $subtotal = (float) $customerPrice->amount * (float) $this->quantity;
        $this->subtotal = number_format($subtotal, 2, '.', '');

        return $subtotal;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf(
            '%s x%s @ %s (cart #%s)',
            $this->product->getSku() ?: ('#' . ($this->product->getId() ?? '?')),
            (new DisplayNumber())->qty($this->quantity),
            $this->fulfillmentRegion->getName(),
            $this->cart->getId() ?? '?',
        );
    }

    /** The base figure #601 denominates everything in: `quantity`, unchanged by phase 3. */
    public function getQuantityBase(): string { return $this->quantity; }

    /**
     * No rounding here any more. It used to be `(int) round((float) $base)`, which turned half a
     * kilogram into one and 0.4 of a drum into nothing at all — a base figure resolved through
     * `LineDenomination::factorToBase()` is fractional whenever the line's unit is smaller than the
     * product's, and this was where that fraction died. The column is `NUMERIC(14, 4)` and now so is
     * the property; the scale a store actually keeps is applied by `QuantityScale` where the figure
     * enters, not by a cast here.
     */
    protected function storeQuantityBase(string $base): void { $this->setQuantity($base); }
}
