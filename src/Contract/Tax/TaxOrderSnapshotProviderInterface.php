<?php

declare(strict_types=1);

namespace App\Contract\Tax;

use App\Entity\SalesOrder;

/**
 * Optional companion to TaxCalculatorInterface — lets a tax bundle snapshot per-order
 * data (e.g. a customer's registration/exemption number) onto the order itself at the
 * moment it's created or edited, so the record reflects what was true then even if the
 * source data changes later. Mirrors App\Contract\Fee\FeeOrderSnapshotProviderInterface.
 */
interface TaxOrderSnapshotProviderInterface
{
    public function applyOrderSnapshot(SalesOrder $order, int $companyId): void;
}
