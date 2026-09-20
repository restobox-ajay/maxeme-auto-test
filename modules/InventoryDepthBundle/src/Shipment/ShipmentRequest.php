<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Shipment;

use App\Entity\Company;
use App\Entity\InvoiceLine;

/**
 * One shipment, ready to record: the customer it's going to, and how much of which invoice lines
 * left the building (`docs/plans/2026-09-14-shipment-dispatch.md`).
 *
 * Modelled on `ProcurementBundle\Receiving\ReceivingRequest` and for the same reason — a plain
 * value object with no entity manager and no persistence of its own, built by whoever is asking (an
 * admin form, the Completed-transition listener) and handed to `ShipmentService::ship()` whole, so
 * a shipment and its movements are never half-written.
 *
 * `$company` is fixed at construction rather than inferred from the first line, which is what makes
 * the same-customer guard in `ShipmentService::ship()` a plain per-line comparison instead of a
 * first-line special case — see "Combined shipments" in the plan.
 *
 * ## `$allocations` — the step-2 lot/serial breakdown (2026-09-20)
 *
 * Optional, and empty is the common case: it means "use the invoice line's own captured lot/serial
 * whole", which is what a `simple` product needs (nothing to allocate) and what
 * `InvoiceShippingRemainderSubscriber` always sends (no human is present to pick anything).
 *
 * Given, it is authoritative — see `ShipmentService`'s own docblock for why a real pick at ship time
 * may legitimately name a different lot/serial than the one on the invoice, and why one invoice line
 * can need more than one: a serial identifies one unit, so a qty-5 line can never have "the" serial,
 * and a lot can run dry mid-pick and need a second batch to finish the line. Each allocation becomes
 * its own `ShipmentLine` row.
 *
 * ## Every quantity on it is a decimal string, and always was
 *
 * Checked in the 2026-09-17 sweep that moved `ShipmentService` off `(int) round((float) $quantity)`,
 * and nothing here needed changing: `add()` has always taken and stored `string`, matching
 * `InvoiceLine::getQuantity()` and the `NUMERIC(14, 4)` columns underneath. That is exactly why the
 * rounding was a bug in the service rather than in the shape of this request — a caller's `'0.40'`
 * arrived here intact and was destroyed one layer later. `ship()` still writes an untracked line's
 * quantity through to `ShipmentLine` byte for byte, so it is stored as typed and never reformatted.
 */
final class ShipmentRequest
{
    /** @var list<array{invoiceLine: InvoiceLine, quantity: string, allocations: list<array{lotId: ?int, serial: ?string, quantity: string}>}> */
    private array $lines = [];

    private function __construct(
        public readonly Company $company,
        public readonly ?string $shippedBy,
        public readonly ?string $notes,
        public readonly \DateTimeImmutable $shippedAt,
        /**
         * The idempotency key, passed straight through to the movement layer for a question-2 line
         * and used, separately, to recognise a resubmitted shipment outright.
         *
         * Empty is treated as absent rather than as a key — see `MovementRequest::of()`'s identical
         * reasoning, which applies unchanged: one stored empty key would make every later
         * empty-key shipment find that first one and record nothing.
         */
        public readonly ?string $clientOperationId,
    ) {
    }

    public static function for(
        Company $company,
        ?string $shippedBy = null,
        ?string $notes = null,
        ?\DateTimeImmutable $shippedAt = null,
        ?string $clientOperationId = null,
    ): self {
        return new self($company, $shippedBy, $notes, $shippedAt ?? new \DateTimeImmutable(), $clientOperationId);
    }

    /**
     * One invoice line's worth on this shipment. `$quantity` is a decimal string, matching
     * `InvoiceLine::$quantity`.
     *
     * `$allocations` is the optional step-2 breakdown — see the class docblock. When given, each
     * entry's `quantity` (also a decimal string) must sum to exactly `$quantity`;
     * `ShipmentService::assertRequestIsShippable()` is what checks that, not this method, so a
     * caller building the request incrementally is never refused mid-build.
     *
     * @param list<array{lotId: ?int, serial: ?string, quantity: string}> $allocations
     */
    public function add(InvoiceLine $invoiceLine, string $quantity, array $allocations = []): self
    {
        $this->lines[] = ['invoiceLine' => $invoiceLine, 'quantity' => $quantity, 'allocations' => $allocations];

        return $this;
    }

    /** @return list<array{invoiceLine: InvoiceLine, quantity: string, allocations: list<array{lotId: ?int, serial: ?string, quantity: string}>}> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
