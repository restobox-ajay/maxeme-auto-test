<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Inventory;

use App\Contract\Inventory\ShippedQuantityProviderInterface;
use App\Entity\InvoiceLine;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\ShipmentLine;

/**
 * This bundle's answer to core's "how much of this invoice line has actually left the building".
 *
 * Core owns the invoice, the grid and the derived column; `shipment_line` is ours, so core asks
 * through `App\Contract\Inventory\ShippedQuantityProviderInterface` and never names this class.
 * Discovered purely by the `app.shipped_quantity` tag and filtered by
 * `BundleStatusRepository::isActiveForInstance()` before it is ever called, the same way
 * `InvoiceShipmentsPanelProvider` is discovered by `app.injection_point` — switch this bundle
 * Inactive on App Management, or delete it, and core's deriver simply finds nothing listening and
 * falls back to the invoice's own status.
 *
 * Read-only. It never writes the invoice's column and never writes anything else: the deriver in
 * core is the single writer, and this only supplies the number it derives from.
 *
 * ## The same sum as the oversell guard, and why it is written out again
 *
 * `ShipmentService::shippedUnitsSoFar()` asks the identical question — non-void
 * `ShipmentLine.quantity` summed for one invoice line — and cannot be called here: it is private,
 * and it answers in the whole ten-thousandths its own guard compares in, while core's contract here
 * is the decimal string the column holds. Two methods, one question, and they can no longer give
 * different answers about a fraction.
 *
 * They used to. That guard rounded — `(int) round((float) $quantity)` — so 0.4 of a case read as
 * nothing shipped and 1.6 as two, while this class kept the fraction; an invoice could read
 * Partially Shipped off these rows while the guard, looking at the same rows, believed the line was
 * fully covered. The 2026-09-17 sweep moved `ShipmentService` onto `App\Service\QuantityScale`, which is the
 * same arithmetic core's deriver does, so the disagreement is gone rather than merely documented.
 *
 * The aggregate is returned as the decimal string the column holds, and the comparison against what
 * was billed is core's to make. The WHERE clause is deliberately identical to the guard's:
 * **`s.voidedAt IS NULL`**. A voided shipment has, as far as the goods are concerned, not happened
 * — `ShipmentVoidService` leaves its lines untouched on purpose and returns the stock through a new
 * movement — so counting one would leave an invoice reading Shipped with the units back on the
 * shelf.
 */
final class ShippedQuantityProvider implements ShippedQuantityProviderInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function shippedQuantityForInvoiceLine(InvoiceLine $invoiceLine): string
    {
        if ($invoiceLine->getId() === null) {
            // Nothing can have shipped against a line that was never persisted, and a DQL parameter
            // bound to an unmanaged entity has no id to compare on. Same first line as
            // ShipmentService::shippedUnitsSoFar(), for the same reason.
            return '0';
        }

        $sum = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(sl.quantity), 0)')
            ->from(ShipmentLine::class, 'sl')
            ->innerJoin('sl.shipment', 's')
            ->andWhere('sl.invoiceLine = :invoiceLine')->setParameter('invoiceLine', $invoiceLine)
            ->andWhere('s.voidedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $sum;
    }
}
