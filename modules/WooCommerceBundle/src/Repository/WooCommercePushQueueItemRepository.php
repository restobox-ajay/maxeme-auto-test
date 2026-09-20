<?php

declare(strict_types=1);

namespace WooCommerceBundle\Repository;

use App\Entity\ProductCore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommercePushQueueItem;

/** @extends ServiceEntityRepository<WooCommercePushQueueItem> */
class WooCommercePushQueueItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WooCommercePushQueueItem::class);
    }

    /**
     * Marks (connection, product) dirty via a raw upsert — called from
     * WooCommerceInventoryChangeSubscriber's onFlush listener, where going through the ORM's
     * identity map/persist() would mean an extra SELECT to know whether the row already exists and
     * risks the same kind of mid-flush complication ProductPrivacyLinker's docblock describes for a
     * different association. SQLite's UPSERT (3.24+) does the find-or-create and the status reset
     * to Pending in one statement.
     *
     * Refreshes any already-managed entity for this pair afterwards, for the same reason
     * ProductPrivacyLinker::sync() does: a raw write is invisible to an object the identity map is
     * already holding, so a caller that dirtied and then re-read this row in the same request
     * (unlikely today — the drain command always runs as its own fresh process — but not
     * impossible for a future caller) would otherwise see stale, pre-write state.
     */
    public function markDirty(EntityManagerInterface $entityManager, int $connectionId, int $productId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $connection = $entityManager->getConnection();

        $connection->executeStatement(
            'INSERT INTO woocommerce_push_queue_item (connection_id, product_id, status, attempts, error_message, requested_at, pushed_at)
             VALUES (:connectionId, :productId, :status, 0, NULL, :requestedAt, NULL)
             ON CONFLICT(connection_id, product_id) DO UPDATE SET status = :status, error_message = NULL, requested_at = :requestedAt',
            [
                'connectionId' => $connectionId,
                'productId' => $productId,
                'status' => WooCommercePushQueueItem::STATUS_PENDING,
                'requestedAt' => $now,
            ],
        );

        $existing = $this->findOneBy(['connection' => $connectionId, 'product' => $productId]);
        if ($existing !== null) {
            $entityManager->refresh($existing);
        }
    }

    /** @return list<WooCommercePushQueueItem> Every row still waiting to be pushed, oldest request first. */
    public function findAllPending(): array
    {
        return $this->findBy(['status' => WooCommercePushQueueItem::STATUS_PENDING], ['requestedAt' => 'ASC']);
    }

    /**
     * @param array{connection?: ?WooCommerceConnection, status?: ?string} $filters
     *
     * @return list<WooCommercePushQueueItem>
     */
    public function search(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('q');

        if (($filters['connection'] ?? null) instanceof WooCommerceConnection) {
            $qb->andWhere('q.connection = :connection')->setParameter('connection', $filters['connection']);
        }
        if (($filters['status'] ?? '') !== '') {
            $qb->andWhere('q.status = :status')->setParameter('status', $filters['status']);
        }

        return $qb
            ->addOrderBy("CASE WHEN q.status = 'Failed' THEN 0 WHEN q.status = 'Pending' THEN 1 ELSE 2 END", 'ASC')
            ->addOrderBy('q.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForConnectionAndProduct(WooCommerceConnection $connection, ProductCore $product): ?WooCommercePushQueueItem
    {
        return $this->findOneBy(['connection' => $connection, 'product' => $product]);
    }
}
