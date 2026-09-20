<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Shipment;

use App\Service\DocumentNumberAllocator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Same shape as `App\Service\InvoiceNumberGenerator`, scoped to the `shipment` table.
 *
 * No configurable prefix setting: unlike invoices/orders/estimates, nothing in
 * `docs/plans/2026-09-14-shipment-dispatch.md` asked for one, so `PREFIX` is a plain constant
 * rather than a Document Prefixes screen entry nobody asked for.
 */
final class ShipmentNumberGenerator
{
    public const PREFIX = 'SHP-';

    public function __construct(private readonly DocumentNumberAllocator $allocator)
    {
    }

    public function next(EntityManagerInterface $entityManager): string
    {
        $next = $this->allocator->next($entityManager, 'shipment', self::PREFIX, 'shipment', 'shipment_number');

        return sprintf('%s%d', self::PREFIX, $next);
    }
}
