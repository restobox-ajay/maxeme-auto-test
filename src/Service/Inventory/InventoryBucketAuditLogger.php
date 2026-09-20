<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\InventoryReconciliationDiscrepancy;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\EmailNotifier;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Drift alerting for the ProductInventory buckets: when a recalc finds the cached number and the
 * recomputed one disagreeing, this is what records the discrepancy and tells somebody.
 *
 * ## It used to log changes too, and no longer does (#582)
 *
 * `logChange()` lived here, and every bucket write was supposed to call it. That is exactly the
 * arrangement #582 removed: six buckets had callers that remembered and four did not, so the
 * warehouse-side buckets had no history at all. Change logging is now
 * App\EventSubscriber\InventoryBucketChangeLogger's job, driven off the Doctrine changeset, and the
 * method is GONE rather than merely unused — leaving it would mean a well-meaning caller could
 * still call it and get a second, duplicate row for a change the listener already recorded.
 *
 * A discrepancy is a different kind of fact and stays here. It is not a change to a bucket: it is
 * an observation that the bucket and its ledger disagree, made by a recalc command that then writes
 * the correction (which the listener does log, separately, as the change it is). Nothing in a
 * changeset can produce it, because the number the recalc EXPECTED is not in the database at all.
 */
final class InventoryBucketAuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailNotifier $emailNotifier,
    ) {
    }

    /**
     * No-ops if cached and recomputed already agree. Otherwise persists a discrepancy row and
     * emails active admins — a mismatch here always means a bug in the incremental sync path,
     * since cache and source of truth should never disagree if that path is correct.
     */
    public function logDiscrepancy(
        ProductCore $product,
        Warehouse $warehouse,
        string $bucket,
        string|int|float $cachedQuantity,
        string|int|float $recomputedQuantity,
        string $source,
    ): void {
        // Decimal quantities since `QuantityType` became a decimal type, and compared in whole units
        // of the column's scale. `===` on the raw values would call `0` and `'0.0000'` a
        // discrepancy and file a row saying the ledger had drifted when nothing had moved.
        if (QuantityScale::compare($cachedQuantity, $recomputedQuantity) === 0) {
            return;
        }

        $entry = (new InventoryReconciliationDiscrepancy())
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setBucket($bucket)
            ->setCachedQuantity($cachedQuantity)
            ->setRecomputedQuantity($recomputedQuantity)
            ->setSource($source);

        $this->entityManager->persist($entry);

        $this->emailNotifier->send(
            'inventory_reconciliation_discrepancy',
            sprintf('Inventory discrepancy found: %s @ %s', $product->getSku() ?: ('#' . $product->getId()), $warehouse->getName()),
            'emails/inventory_reconciliation_discrepancy.html.twig',
            [
                'product' => $product,
                'warehouse' => $warehouse,
                'bucket' => $bucket,
                'cachedQuantity' => $cachedQuantity,
                'recomputedQuantity' => $recomputedQuantity,
                'source' => $source,
            ],
            $this->emailNotifier->activeAdminEmails(),
            fromCategory: 'support',
        );
    }
}
