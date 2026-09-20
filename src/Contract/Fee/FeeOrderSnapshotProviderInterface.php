<?php

declare(strict_types=1);

namespace App\Contract\Fee;

use App\Entity\SalesOrder;

/**
 * Optional companion to FeeFieldProviderInterface — lets a fee bundle snapshot
 * per-order data (e.g. a customer's registration/exemption number) onto the order
 * itself at the moment it's created or edited, so the record reflects what was
 * true then even if the source data (e.g. a company's own registration number,
 * bundle-owned custom field or otherwise) changes later. Mirrors how Order::$feeLines/$taxLines are already snapshots,
 * never recomputed on view.
 */
interface FeeOrderSnapshotProviderInterface
{
    public function applyOrderSnapshot(SalesOrder $order, int $companyId): void;
}
