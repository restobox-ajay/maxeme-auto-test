<?php

declare(strict_types=1);

namespace WooCommerceBundle\Service;

use App\Entity\ProductInventory;
use App\Service\Pricing\ProductVisibilityGuard;
use Doctrine\ORM\EntityManagerInterface;
use WooCommerceBundle\Entity\WooCommercePushQueueItem;
use WooCommerceBundle\Repository\WooCommerceProductMappingRepository;

/**
 * Pushes one Push Queue item (#739) — the one real "drain a row" implementation, shared by
 * `app:woocommerce:push-queue:drain` (the cron-driven worker) and the admin Push Queue screen's
 * "Push now"/"Push queue now" actions, so there is exactly one place that computes what quantity to
 * send and calls the REST client, not a copy in the command and a second one in the controller.
 */
final class WooCommercePushQueueProcessor
{
    public function __construct(
        private readonly WooCommerceProductMappingRepository $mappings,
        private readonly WooCommerceRestClient $restClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductVisibilityGuard $visibilityGuard,
        private readonly WooCommerceConnectionPriceListResolver $priceLists,
    ) {
    }

    /** Pushes this item and updates its own status in place. Does not flush — caller owns the transaction. */
    public function push(WooCommercePushQueueItem $item): void
    {
        $connection = $item->getConnection();
        $product = $item->getProduct();
        if ($connection === null || $product === null) {
            $item->markFailed('Queue item has no connection or product.');

            return;
        }

        $mapping = $this->mappings->findOneForConnectionAndProduct($connection, $product);
        if ($mapping === null) {
            $item->markFailed('No WooCommerce mapping exists for this product on this connection any more.');

            return;
        }

        $warehouse = $connection->getDefaultWarehouse();
        if ($warehouse === null) {
            $item->markFailed('Connection has no default warehouse — nothing to read a quantity from.');

            return;
        }

        if (!$this->visibilityGuard->isEligible($product, $this->priceLists->resolve($connection))) {
            $item->markFailed('Product is private, inactive, or hidden on this connection\'s price list — not eligible for sync.');

            return;
        }

        $inventory = $this->entityManager->getRepository(ProductInventory::class)
            ->findOneBy(['product' => $product, 'warehouse' => $warehouse]);

        $available = $inventory instanceof ProductInventory ? (float) $inventory->getAvailableQuantity() : 0.0;
        $quantity = (int) round($available) + $connection->getAvailabilityBuffer();
        // Woo's stock_quantity is never sent negative — a buffer more negative than what's on hand
        // is a safety margin, not a request to advertise a negative number to shoppers.
        $quantity = max(0, $quantity);

        try {
            $this->restClient->updateStock($connection, $mapping, $quantity);
            $item->markPushed();
        } catch (\RuntimeException $e) {
            $item->markFailed($e->getMessage());
        }
    }
}
