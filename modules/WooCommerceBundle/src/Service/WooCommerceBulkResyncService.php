<?php

declare(strict_types=1);

namespace WooCommerceBundle\Service;

use App\Entity\ProductCore;
use App\Service\Pricing\ProductVisibilityGuard;
use Doctrine\ORM\EntityManagerInterface;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommerceProductMapping;
use WooCommerceBundle\Entity\WooCommerceSyncRun;
use WooCommerceBundle\Repository\WooCommerceConnectionRepository;
use WooCommerceBundle\Repository\WooCommercePushQueueItemRepository;

/**
 * Bulk Resync (#739): re-queues every mapped product on a connection (or every active connection)
 * outright, through the exact same WooCommercePushQueueItemRepository::markDirty() upsert the
 * event-driven WooCommerceInventoryChangeSubscriber uses — there is one push mechanism, and Bulk
 * Resync's only job is to decide WHAT gets marked dirty, more bluntly than a bucket change would.
 */
final class WooCommerceBulkResyncService
{
    public function __construct(
        private readonly WooCommerceConnectionRepository $connections,
        private readonly WooCommercePushQueueItemRepository $pushQueue,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductVisibilityGuard $visibilityGuard,
        private readonly WooCommerceConnectionPriceListResolver $priceLists,
    ) {
    }

    /** Null $connection resyncs every active connection in one run — the nightly scheduled shape. */
    public function run(?WooCommerceConnection $connection, string $triggeredBy): WooCommerceSyncRun
    {
        $targets = $connection instanceof WooCommerceConnection ? [$connection] : $this->connections->findAllActive();

        $queued = 0;
        foreach ($targets as $target) {
            foreach ($this->mappedProductIds($target) as $productId) {
                $this->pushQueue->markDirty($this->entityManager, (int) $target->getId(), $productId);
                $queued++;
            }
        }

        $run = (new WooCommerceSyncRun())
            ->setConnection($connection)
            ->setQueuedCount($queued)
            ->setTriggeredBy($triggeredBy);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    /** @return list<int> Every ELIGIBLE mapped product id — same ProductVisibilityGuard rule the push processor enforces, so this never queues a product that can't actually push. */
    private function mappedProductIds(WooCommerceConnection $connection): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT p.id')
            ->from(ProductCore::class, 'p')
            ->innerJoin(WooCommerceProductMapping::class, 'm', 'WITH', 'm.product = p')
            ->where('m.connection = :connection')
            ->setParameter('connection', $connection);

        $this->visibilityGuard->applyTo($qb, 'p', $this->priceLists->resolve($connection));

        return array_map('intval', array_column($qb->getQuery()->getScalarResult(), 'id'));
    }
}
