<?php

declare(strict_types=1);

namespace App\Contract\Inventory;

/**
 * The one thing core needs to know about a physical inventory operation: which row in
 * `inventory_movement_group` it is.
 *
 * `inventory_bucket_change_log.group_id` points at that row (#582) — a bucket change with a group
 * behind it was caused by stock physically moving, and a bucket change without one was not. Core
 * has to be able to record the link, and core may not name InventoryDepthBundle\Entity\
 * InventoryMovementGroup to do it: modules/InventoryDepthBundle is deletable, and a core entity
 * with an ORM association to a class that may not exist is a metadata load failure, not a missing
 * feature. Same seam, same reason, as App\Contract\Inventory\DimensionalInventoryProviderInterface.
 *
 * So the column is a plain nullable integer in the mapping and a real foreign key in the database
 * (see the migration that adds it). This interface is how the id reaches the column: the bundle's
 * group implements it, App\Service\Inventory\InventoryOperationContext carries it, and
 * App\EventSubscriber\InventoryBucketChangeLogger reads getId() off it and stores the integer.
 *
 * Deliberately one method. Anything else core might want to know about an operation — its type, its
 * reason, its actor — is either already on the log row (`action`, `triggered_by`) or is one join
 * away for whoever is reading the audit trail, and adding it here would make core depend on the
 * bundle's vocabulary rather than on its identifiers.
 */
interface InventoryOperationGroupInterface
{
    /**
     * The group's database identifier, or null while it is still unflushed.
     *
     * Null is a real answer and not an error: the log row is written in the same flush as the
     * operation that caused it, and an operation may open before its group has an id. A log row
     * that lands with a null group_id says "no physical operation" — which is why every caller that
     * DOES have one is careful to attach it only once the group is on disk. See
     * InventoryDepthBundle\Movement\StockMovementService::apply(), where the group is persisted and
     * flushed with the first movement line, long before any bucket column moves.
     */
    public function getId(): ?int;
}
