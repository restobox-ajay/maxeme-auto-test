<?php

declare(strict_types=1);

namespace WooCommerceBundle\Entity;

use App\Entity\ProductCore;
use Doctrine\ORM\Mapping as ORM;

/**
 * One (connection, product) pair waiting to have its Available-to-sell quantity pushed to
 * WooCommerce (#739) — the event-driven half of outbound stock sync, distinct from Bulk Resync's
 * scheduled full-catalogue sweep. A row is upserted here the moment a bucket change makes this
 * pair's availability stale (WooCommerceInventoryChangeSubscriber), and drained by
 * `app:woocommerce:push-queue:drain`, run on a short cron interval — not inline with the request
 * that dirtied it, so a checkout or a receiving scan never waits on a call to Woo's API.
 *
 * Unique on (connection_id, product_id): a product that changes stock five times before the drain
 * command next runs still pushes once, at whatever quantity is current when it runs — re-dirtying
 * an already-Pending row bumps requestedAt rather than creating a second row.
 */
#[ORM\Entity(repositoryClass: \WooCommerceBundle\Repository\WooCommercePushQueueItemRepository::class)]
#[ORM\Table(name: 'woocommerce_push_queue_item')]
#[ORM\UniqueConstraint(name: 'uniq_woo_push_queue_connection_product', columns: ['connection_id', 'product_id'])]
class WooCommercePushQueueItem
{
    public const STATUS_PENDING = 'Pending';
    public const STATUS_PUSHED = 'Pushed';
    public const STATUS_FAILED = 'Failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WooCommerceConnection::class)]
    #[ORM\JoinColumn(name: 'connection_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?WooCommerceConnection $connection = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ProductCore $product = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'requested_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'pushed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $pushedAt = null;

    public function __construct()
    {
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConnection(): ?WooCommerceConnection
    {
        return $this->connection;
    }

    public function setConnection(WooCommerceConnection $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function getProduct(): ?ProductCore
    {
        return $this->product;
    }

    public function setProduct(ProductCore $product): self
    {
        $this->product = $product;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /** Marks this row ready to be picked up again — a fresh bucket change, or an admin's "Push now". */
    public function markPending(): self
    {
        $this->status = self::STATUS_PENDING;
        $this->requestedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markPushed(): self
    {
        $this->status = self::STATUS_PUSHED;
        $this->pushedAt = new \DateTimeImmutable();
        $this->errorMessage = null;

        return $this;
    }

    public function markFailed(string $message): self
    {
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $message;
        $this->attempts++;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getPushedAt(): ?\DateTimeImmutable
    {
        return $this->pushedAt;
    }
}
