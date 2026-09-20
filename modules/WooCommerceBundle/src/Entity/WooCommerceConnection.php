<?php

declare(strict_types=1);

namespace WooCommerceBundle\Entity;

use App\Entity\PriceList;
use App\Entity\Warehouse;
use Doctrine\ORM\Mapping as ORM;
use WooCommerceBundle\Repository\WooCommerceConnectionRepository;

/**
 * One WooCommerce store this app talks to (#739/#741). Never assume there is only one — a company
 * can run several storefronts (a main store, a trade portal, a clearance outlet), each with its own
 * credentials, tier pricing, fulfilling warehouse and available-to-sell buffer.
 *
 * Credentials stored plain, matching this app's existing precedent for a payment gateway's secret
 * (StripeConfigProvider) — no new encryption-at-rest mechanism invented here.
 */
#[ORM\Entity(repositoryClass: WooCommerceConnectionRepository::class)]
#[ORM\Table(name: 'woocommerce_connection')]
#[ORM\UniqueConstraint(name: 'uniq_woocommerce_connection_slug', columns: ['slug'])]
class WooCommerceConnection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    /**
     * Short, url-safe, permanent once orders start flowing: it feeds every SKU this connection
     * auto-creates for a Woo item with no SKU of its own (#739) — {slug}-{woo-order-id}-{line}.
     */
    #[ORM\Column(length: 60)]
    private string $slug = '';

    #[ORM\Column(name: 'store_url', length: 255)]
    private string $storeUrl = '';

    #[ORM\Column(name: 'consumer_key', length: 255)]
    private string $consumerKey = '';

    #[ORM\Column(name: 'consumer_secret', length: 255)]
    private string $consumerSecret = '';

    /** Verifies X-WC-Webhook-Signature on every inbound order — see WebhookController. */
    #[ORM\Column(name: 'webhook_secret', length: 255)]
    private string $webhookSecret = '';

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $active = true;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'default_warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Warehouse $defaultWarehouse = null;

    /**
     * Added to Available before it is pushed to Woo (#739). Negative holds back a safety margin;
     * positive lets Woo oversell slightly. Zero by default: no buffer until someone asks for one.
     */
    #[ORM\Column(name: 'availability_buffer', options: ['default' => 0])]
    private int $availabilityBuffer = 0;

    /**
     * Woo customer tier/role => internal PriceList id, e.g. {"wholesale_tier_1": 4}. A tier with
     * no mapping here falls back to $defaultPriceList.
     *
     * @var array<string, int>
     */
    #[ORM\Column(name: 'tier_price_list_map', type: 'json')]
    private array $tierPriceListMap = [];

    #[ORM\ManyToOne(targetEntity: PriceList::class)]
    #[ORM\JoinColumn(name: 'default_price_list_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PriceList $defaultPriceList = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** Set by WebhookController on every accepted order, shown on the connections list. */
    #[ORM\Column(name: 'last_order_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastOrderAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = trim($slug);

        return $this;
    }

    public function getStoreUrl(): string
    {
        return $this->storeUrl;
    }

    public function setStoreUrl(string $storeUrl): self
    {
        $this->storeUrl = trim($storeUrl);

        return $this;
    }

    public function getConsumerKey(): string
    {
        return $this->consumerKey;
    }

    public function setConsumerKey(string $consumerKey): self
    {
        $this->consumerKey = trim($consumerKey);

        return $this;
    }

    public function getConsumerSecret(): string
    {
        return $this->consumerSecret;
    }

    public function setConsumerSecret(string $consumerSecret): self
    {
        $this->consumerSecret = trim($consumerSecret);

        return $this;
    }

    public function getWebhookSecret(): string
    {
        return $this->webhookSecret;
    }

    public function setWebhookSecret(string $webhookSecret): self
    {
        $this->webhookSecret = trim($webhookSecret);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getDefaultWarehouse(): ?Warehouse
    {
        return $this->defaultWarehouse;
    }

    public function setDefaultWarehouse(?Warehouse $warehouse): self
    {
        $this->defaultWarehouse = $warehouse;

        return $this;
    }

    public function getAvailabilityBuffer(): int
    {
        return $this->availabilityBuffer;
    }

    public function setAvailabilityBuffer(int $availabilityBuffer): self
    {
        $this->availabilityBuffer = $availabilityBuffer;

        return $this;
    }

    /** @return array<string, int> */
    public function getTierPriceListMap(): array
    {
        return $this->tierPriceListMap;
    }

    /** @param array<string, int> $map */
    public function setTierPriceListMap(array $map): self
    {
        $this->tierPriceListMap = $map;

        return $this;
    }

    public function getDefaultPriceList(): ?PriceList
    {
        return $this->defaultPriceList;
    }

    public function setDefaultPriceList(?PriceList $priceList): self
    {
        $this->defaultPriceList = $priceList;

        return $this;
    }

    /** The price list a Woo customer tier resolves to — the tier map, falling back to the default. */
    public function priceListIdForTier(?string $tier): ?int
    {
        if ($tier !== null && isset($this->tierPriceListMap[$tier])) {
            return $this->tierPriceListMap[$tier];
        }

        return $this->defaultPriceList?->getId();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastOrderAt(): ?\DateTimeImmutable
    {
        return $this->lastOrderAt;
    }

    public function touchLastOrderAt(): self
    {
        $this->lastOrderAt = new \DateTimeImmutable();

        return $this;
    }
}
