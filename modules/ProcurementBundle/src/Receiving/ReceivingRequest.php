<?php

declare(strict_types=1);

namespace ProcurementBundle\Receiving;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use InventoryDepthBundle\Entity\WarehouseLocation;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;

/**
 * One delivery, ready to book in (#555): who it came from, where it is going, and what was on it.
 *
 * Modelled on `InventoryDepthBundle\Movement\MovementRequest` and for the same reason — a plain
 * value object with no entity manager and no persistence of its own, built by whoever is asking (a
 * receiving form, an import, a scanner) and handed to ReceivingService whole, so that a receipt and
 * its movements are never half-written.
 *
 * Deliberately NOT a `MovementRequest` itself. A receipt line carries things a movement has no
 * concept of — the PO line it is against, the unit cost off the packing slip, a lot *code* that may
 * not have a row yet — and it may legitimately produce no movement at all. Turning one into the
 * other is ReceivingService's job, in one place, with the rules applied.
 */
final class ReceivingRequest
{
    /**
     * @var list<array{
     *     product: ProductCore,
     *     quantity: string,
     *     purchaseOrderLine: ?PurchaseOrderLine,
     *     lotCode: ?string,
     *     expiry: ?\DateTimeImmutable,
     *     serial: ?string,
     *     location: ?WarehouseLocation,
     *     unitCost: ?string,
     *     unidentified: bool,
     *     shortDatedReason: ?string,
     *     expiryUnidentified: bool
     * }>
     */
    private array $lines = [];

    private function __construct(
        public readonly Vendor $vendor,
        public readonly Warehouse $warehouse,
        public readonly ?PurchaseOrder $purchaseOrder,
        public readonly ?string $packingSlip,
        public readonly ?string $receivedBy,
        public readonly ?string $notes,
        public readonly \DateTimeImmutable $receivedAt,
        /**
         * The idempotency key, passed straight through to MovementRequest.
         *
         * A resubmitted receiving form must book the goods in once. Empty is treated as absent
         * rather than as a key, for the reason MovementRequest::of() spells out: one stored empty
         * key would make every later empty-key delivery find that first group and record nothing.
         */
        public readonly ?string $clientOperationId,
    ) {
    }

    /**
     * A delivery against a purchase order. The vendor and the warehouse come from the order,
     * because a delivery that named different ones would not be a delivery against that order.
     */
    public static function againstPurchaseOrder(
        PurchaseOrder $purchaseOrder,
        ?string $packingSlip = null,
        ?string $receivedBy = null,
        ?string $notes = null,
        ?\DateTimeImmutable $receivedAt = null,
        ?string $clientOperationId = null,
    ): self {
        return new self(
            $purchaseOrder->getVendor(),
            $purchaseOrder->getWarehouse(),
            $purchaseOrder,
            $packingSlip,
            $receivedBy,
            $notes,
            $receivedAt ?? new \DateTimeImmutable(),
            $clientOperationId,
        );
    }

    /**
     * A delivery nobody raised a purchase order for.
     *
     * Legitimate and deliberately supported: goods arrive against a phone call, a sample turns up,
     * a warranty replacement lands. Refusing to record them means the warehouse is wrong, which is
     * worse than the missing paperwork. It is flagged on the three-way match instead.
     */
    public static function unordered(
        Vendor $vendor,
        Warehouse $warehouse,
        ?string $packingSlip = null,
        ?string $receivedBy = null,
        ?string $notes = null,
        ?\DateTimeImmutable $receivedAt = null,
        ?string $clientOperationId = null,
    ): self {
        return new self(
            $vendor,
            $warehouse,
            null,
            $packingSlip,
            $receivedBy,
            $notes,
            $receivedAt ?? new \DateTimeImmutable(),
            $clientOperationId,
        );
    }

    /**
     * One thing off the truck.
     *
     * `$lotCode` is a code and not a row: the batch may never have been seen before, and whether it
     * needs a new `inventory_lot` or reuses an existing one is decided by ReceivingService — which
     * is the only place that can get "same code, different expiry" right.
     *
     * `$quantity` is a decimal string, matching the PO line it is against.
     *
     * `$unidentified` is the receiver SAYING that these units carry no code — the warehouse
     * mid-transition case #573 names, where there is real stock and no paperwork for any of it.
     * It is what excuses a blank identity on a product whose TRACKING POLICY captures one: the row
     * still takes the policy's `sentinel_in` and still lands on the tracking worklist, exactly as
     * before, but it now takes a positive act rather than an unnoticed blank box (item 67). It
     * excuses nothing a RECEIVING RULE demands — that gate is procurement's own and is absolute.
     *
     * `$shortDatedReason` is why these goods are being accepted with less shelf life left than the
     * minimum allows (item 68). It is NOT a tick: a short date is a decision, and the only question
     * anybody asks about one afterwards is why it was made. Blank reads as absent — a line with a
     * shortfall and no reason is refused, and one with no shortfall ignores it, so a reason typed on
     * an ordinary line records nothing.
     *
     * `$expiryUnidentified` is the SAME declaration as `$unidentified`, aimed at the expiry alone
     * (#792): a lot-tracked row's batch code and its expiry are independent facts about the carton,
     * and a receiver who has the code but genuinely has no expiry printed on it must be able to say
     * so without also declaring the whole row unidentified — which would throw away a batch code
     * that is real and known. It excuses `isExpiryRequired()` alone, never the batch or the serial,
     * and only while the expiry really is blank: an expiry typed alongside it is not a declaration,
     * whatever the box says — the same rule `$unidentified` already keeps for the batch and serial.
     */
    public function add(
        ProductCore $product,
        string $quantity,
        ?PurchaseOrderLine $purchaseOrderLine = null,
        ?string $lotCode = null,
        ?\DateTimeImmutable $expiry = null,
        ?string $serial = null,
        ?WarehouseLocation $location = null,
        ?string $unitCost = null,
        bool $unidentified = false,
        ?string $shortDatedReason = null,
        bool $expiryUnidentified = false,
    ): self {
        $this->lines[] = [
            'product' => $product,
            'quantity' => $quantity,
            'purchaseOrderLine' => $purchaseOrderLine,
            'lotCode' => self::nullable($lotCode),
            'expiry' => $expiry,
            'serial' => self::nullable($serial),
            'location' => $location,
            'unitCost' => self::nullable($unitCost),
            'unidentified' => $unidentified,
            'shortDatedReason' => self::nullable($shortDatedReason),
            'expiryUnidentified' => $expiryUnidentified,
        ];

        return $this;
    }

    /**
     * @return list<array{
     *     product: ProductCore,
     *     quantity: string,
     *     purchaseOrderLine: ?PurchaseOrderLine,
     *     lotCode: ?string,
     *     expiry: ?\DateTimeImmutable,
     *     serial: ?string,
     *     location: ?WarehouseLocation,
     *     unitCost: ?string,
     *     unidentified: bool,
     *     shortDatedReason: ?string,
     *     expiryUnidentified: bool
     * }>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    private static function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
