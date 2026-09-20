<?php

declare(strict_types=1);

namespace WooCommerceBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One Bulk Resync run (#739) — the blunt, scheduled counterpart to the Push Queue's own
 * event-driven trigger. Where the Push Queue marks exactly what changed, a Bulk Resync re-queues
 * every mapped product on a connection outright, to catch anything the event-driven path ever
 * missed (a bug, a manual DB edit, a period the subscriber was mid-deploy).
 *
 * This is an audit record of what was KICKED OFF, not a live job status: a run enqueues its rows
 * into WooCommercePushQueueItem and finishes immediately (queuedCount is fixed at trigger time);
 * the actual pushing happens later, on the same cron-driven drain as every event-driven row, and
 * is not tracked back to this specific run (see WooCommerceSyncRun's admin screen for why "View
 * failures" reads the Push Queue's CURRENT Failed rows for this connection rather than this run's
 * own history — a push queue row has no stable identity across multiple runs to make the stronger
 * claim honestly).
 */
#[ORM\Entity(repositoryClass: \WooCommerceBundle\Repository\WooCommerceSyncRunRepository::class)]
#[ORM\Table(name: 'woocommerce_sync_run')]
class WooCommerceSyncRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null means every active connection was resynced in one run (the nightly scheduled shape). */
    #[ORM\ManyToOne(targetEntity: WooCommerceConnection::class)]
    #[ORM\JoinColumn(name: 'connection_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?WooCommerceConnection $connection = null;

    #[ORM\Column(name: 'queued_count')]
    private int $queuedCount = 0;

    /** Free text: 'cron' for the nightly schedule, or the admin's display name for a manual "Run now". */
    #[ORM\Column(name: 'triggered_by', length: 190)]
    private string $triggeredBy = '';

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    public function __construct()
    {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConnection(): ?WooCommerceConnection
    {
        return $this->connection;
    }

    public function setConnection(?WooCommerceConnection $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function getQueuedCount(): int
    {
        return $this->queuedCount;
    }

    public function setQueuedCount(int $queuedCount): self
    {
        $this->queuedCount = $queuedCount;

        return $this;
    }

    public function getTriggeredBy(): string
    {
        return $this->triggeredBy;
    }

    public function setTriggeredBy(string $triggeredBy): self
    {
        $this->triggeredBy = $triggeredBy;

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }
}
