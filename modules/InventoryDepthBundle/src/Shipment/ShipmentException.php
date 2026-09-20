<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Shipment;

/**
 * Refuses to record or void a shipment, for a reason a user can act on.
 *
 * Same shape as `ProcurementBundle\Receiving\ReceivingException` — the sell side's equivalent.
 */
final class ShipmentException extends \DomainException
{
}
