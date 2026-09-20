<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CartRepository;
use App\Service\Pricing\CustomerPricingScope;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per session's cart — replaces the old session-array-only storage (App\Service\
 * CartService used to keep sku=>qty directly in the PHP session, nothing persisted). Keyed by
 * sessionId, the same identity model the cart already had.
 *
 * A cart is a pre-document, so it is one: extending AbstractSalesDocument is what stops a fee, tax
 * or shipping calculator being able to tell a cart from an order (issue #165). The base is a mapped
 * superclass, so this simply gives the `cart` table its own copies of the header columns rather than
 * sharing anything at runtime.
 *
 * Three fields the base carries are deliberately left empty here — source, companySnapshot and
 * poNumber. All three record how a document came to exist, and a cart has not come to exist yet;
 * they are filled at conversion, which is also when the address rows stop being bare links and the
 * buyer's identity freezes.
 *
 * `holdExpiresAt` is a core-owned cache column: CartHoldBundle's reconciliation listener writes
 * it as a side effect of syncing holds for this cart, so core can render the countdown banner
 * (`getHoldExpiresAt()`) without holding any compile-time reference to the bundle's classes. If
 * the bundle doesn't exist, this simply stays null forever and the banner never shows — cart
 * itself keeps working regardless.
 */
#[ORM\Entity(repositoryClass: CartRepository::class)]
#[ORM\Table(name: 'cart')]
#[ORM\AssociationOverrides([
    // The base leaves the foreign key with no ON DELETE rule, which is right for a document: an
    // order must not lose its buyer. A cart may — deleting a company should not fail on a stale
    // basket — so cart keeps the SET NULL it always had.
    new ORM\AssociationOverride(
        name: 'company',
        joinColumns: [new ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')],
    ),
])]
class Cart extends AbstractSalesDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128, unique: true)]
    private string $sessionId = '';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $holdExpiresAt = null;

    /** @var Collection<int, CartItem> */
    #[ORM\OneToMany(targetEntity: CartItem::class, mappedBy: 'cart', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    /** @var Collection<int, CartAddress> */
    #[ORM\OneToMany(targetEntity: CartAddress::class, mappedBy: 'cart', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cartAddresses;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        parent::__construct();
        // A cart has no origin until it becomes a document, so it says so rather than claiming an
        // Admin source. It has no purchase-order date either, but that needs nothing here: the base
        // no longer stamps one, and SalesDocumentDateStamp is scoped to orders and quotes.
        $this->source = '';
        $this->items = new ArrayCollection();
        $this->cartAddresses = new ArrayCollection();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSessionId(): string { return $this->sessionId; }
    public function setSessionId(string $sessionId): self { $this->sessionId = $sessionId; return $this; }
    public function getHoldExpiresAt(): ?\DateTimeImmutable { return $this->holdExpiresAt; }
    public function setHoldExpiresAt(?\DateTimeImmutable $holdExpiresAt): self { $this->holdExpiresAt = $holdExpiresAt; return $this; }

    /**
     * Stamping the buyer onto a cart must not freeze their identity, which is what the base does.
     * A cart is not a record of a transaction between two named parties yet; it becomes one at
     * conversion, and that is where the snapshot is taken.
     */
    public function setCompany(?Company $company): static
    {
        $this->company = $company;

        return $this;
    }

    /** @return Collection<int, CartItem> */
    public function getItems(): Collection { return $this->items; }

    /**
     * The document view of getItems(). Both names stay: `items` is what a cart has always called
     * them and what CartHoldBundle reads, `lines` is what every document is asked for.
     *
     * @return Collection<int, CartItem>
     */
    public function getLines(): Collection { return $this->items; }

    public function addItem(CartItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setCart($this);
        }

        return $this;
    }

    /** orphanRemoval on $items schedules the actual deletion once this is flushed. */
    public function removeItem(CartItem $item): self
    {
        $this->items->removeElement($item);

        return $this;
    }

    /** @return Collection<int, CartAddress> */
    public function getAddresses(): Collection
    {
        return $this->cartAddresses;
    }

    protected function newAddress(string $type): AbstractDocumentAddress
    {
        $address = (new CartAddress())->setType($type);
        $address->setCart($this);
        $this->cartAddresses->add($address);

        return $address;
    }

    /**
     * Point an address row at an address-book entry without copying it.
     *
     * The copy is what a document does; a cart only needs to know which entry it is pricing against,
     * so that the customer editing that entry moves the cart with it. getEffectiveShippingAddress()
     * resolves the link at read time.
     */
    public function linkAddress(string $type, ?CompanyAddress $address): self
    {
        if ($address === null) {
            return $this;
        }

        $this->addressForWriting($type)->setSourceAddress($address);

        return $this;
    }

    /**
     * Fill every item's live money from one pricing scope, and return the cart subtotal.
     *
     * A scope is bound to a (company, region) pair, which is precisely what a cart is priced against,
     * so one is built per cart rather than per line — see CustomerPricingScope. Items whose price
     * does not resolve are left with null money on purpose: that is what routes a checkout to an
     * estimate instead of an order, and a zero would hide it.
     */
    public function priceItems(CustomerPricingScope $scope): float
    {
        $subtotal = 0.0;
        foreach ($this->items as $item) {
            $subtotal += $item->priceAgainst($scope);
        }

        return $subtotal;
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * Note that rendering the cart page writes the header totals back (see CartController), so this
     * tracks last-viewed rather than last-modified.
     */
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf('Cart for session %s', substr($this->sessionId, 0, 12));
    }
}
