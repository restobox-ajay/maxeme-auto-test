<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whether the goods an invoice bills for have actually left the building.
 *
 * The third axis on an invoice, beside {@see InvoiceStatus} and {@see InvoicePaymentStatus}, and
 * separate from both on purpose. `InvoiceStatus` answers *where is this document in its lifecycle*
 * — Draft, Pending, Processing, Completed — which is a decision an admin makes; this answers *how
 * much of it has physically shipped*, which is a fact about shipment rows. They agree most of the
 * time and are not the same question: an invoice can sit at Processing with two of its three lines
 * already on a truck, and a Completed invoice that nobody ever built a shipment for is Completed
 * because somebody said so.
 *
 * Like `InvoicePaymentStatus` and unlike `SalesOrder::$status`, this is DERIVED and enum-typed at
 * the column. {@see \App\Service\InvoiceShippingStatusDeriver} is the only writer, through
 * {@see \App\Entity\Invoice::applyDerivedShippingStatus()}, and there is no setShippingStatus() for
 * anything else to disagree with the shipment rows through.
 *
 * ## `Partially Shipped` is a bundle-only answer, and that is deliberate
 *
 * Core owns no shipment table. It asks whoever is listening on
 * {@see \App\Contract\Inventory\ShippedQuantityProviderInterface} how much of a line has gone, and
 * with no active provider the honest answer is the two ends only: a Completed invoice shipped, and
 * anything else has not. Core never invents a middle it has no information about — see the deriver's
 * "Degradation" section for why that is the correct reading rather than a limitation being
 * apologised for.
 */
enum InvoiceShippingStatus: string
{
    /** Nothing on this invoice has left. Every invoice is born here. */
    case NotShipped = 'Not Shipped';

    /**
     * Some of what was billed has gone, but not all of it.
     *
     * Only ever reachable while a shipped-quantity provider is active, because only a provider knows
     * quantities per line. Core alone cannot distinguish "half" from "none".
     */
    case PartiallyShipped = 'Partially Shipped';

    /** Every billed line is covered by a non-void shipment — or the invoice is Completed. */
    case Shipped = 'Shipped';
}
