<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\InvoiceInventoryReservation;
use App\Entity\OrderInventoryReservation;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place that decides how much of a requested quantity ships now, how much waits for stock,
 * and how much is refused outright (#548).
 *
 * Three separate code paths used to refuse an oversell independently — the admin save
 * (AdminOrderStockValidator), the customer cart (CartService::capOrRemove()) and the pre-persist
 * re-check in CheckoutController. Relaxing three copies of one rule in three different ways is how
 * they come to disagree about the same SKU, so all three ask this instead, the same way they
 * already share OrderInventoryBucketResolver for region resolution and rounding.
 *
 * ## What it does when nothing has opted in
 *
 * `fulfilled = min(requested, available)` and everything else refused — the check those three paths
 * performed before this class existed, arithmetic included. That is the invariant the whole feature
 * rests on: `allow_backorder` is false on every row until an admin sets it, and with it false this
 * returns `backordered = 0` unconditionally, so nothing anywhere behaves differently.
 *
 * ## The cap is a cap on the bucket, not on the order
 *
 * `remainingBackorderCapacity` counts down from `max_backorder_quantity` as
 * ProductInventory::$backorderedQuantity fills up, across every order there is. Exceeding it is a
 * hard refusal for the excess, exactly as a plain oversell is — nothing waits behind the cap. The
 * order being saved has its OWN existing promise added back first, or re-saving an unchanged order
 * would refuse itself for the quantity it already holds; the same mistake on the stock bucket was
 * issue #214.
 */
final class BackorderSplitResolver
{
    public function __construct(private readonly WarehouseFulfillmentRegionService $warehouses)
    {
    }

    /**
     * Every figure here is a DECIMAL STRING — see {@see BackorderSplit}, which says why. Nothing in
     * this class rounds; a caller that hands it a rounded demand has already lost the fraction and
     * this cannot give it back.
     *
     * @param string $requested                  asked for
     * @param string $available                  ProductInventory::getAvailableQuantity() plus the asking document's own hold
     * @param bool $allowBackorder               ProductInventory::isAllowBackorder()
     * @param string $remainingBackorderCapacity ProductInventory::remainingBackorderCapacity(), the uncapped sentinel when uncapped
     */
    public function split(string $requested, string $available, bool $allowBackorder, string $remainingBackorderCapacity): BackorderSplit
    {
        $requested = self::atLeastZero($requested);
        $fulfilled = QuantityScale::compare($requested, self::atLeastZero($available)) <= 0 ? $requested : self::atLeastZero($available);
        $remainder = QuantityScale::sub($requested, $fulfilled);

        // The refusal path, unchanged from before this feature: the remainder is simply not
        // available, and the caller reports or caps exactly as it always did.
        if (!$allowBackorder) {
            return new BackorderSplit($fulfilled, QuantityScale::canonical(0), $remainder);
        }

        $capacity = self::atLeastZero($remainingBackorderCapacity);
        $backordered = QuantityScale::compare($remainder, $capacity) <= 0 ? $remainder : $capacity;

        return new BackorderSplit($fulfilled, $backordered, QuantityScale::sub($remainder, $backordered));
    }

    private static function atLeastZero(string $quantity): string
    {
        $quantity = QuantityScale::canonical($quantity);

        return QuantityScale::compare($quantity, 0) > 0 ? $quantity : QuantityScale::canonical(0);
    }

    /**
     * The same split, resolved against a live ProductInventory row.
     *
     * A missing row means nothing has ever been stocked in that warehouse, which is treated as zero
     * available AND as not opted in — the alternative would let a typo'd region bypass both the
     * stock check and the cap.
     */
    public function splitFor(
        ?ProductInventory $inventory,
        string $requested,
        string $available,
        string $ownBackorderHold = '0.0000',
    ): BackorderSplit {
        if (!$inventory instanceof ProductInventory || !$inventory->isAllowBackorder()) {
            return $this->split($requested, $available, false, QuantityScale::canonical(0));
        }

        return $this->split(
            $requested,
            $available,
            true,
            $inventory->remainingBackorderCapacity($ownBackorderHold),
        );
    }

    /**
     * Writes the split onto an order's own lines — the step that turns "this save is allowed" into
     * "this much of it is a promise".
     *
     * Run against the order's lines rather than the posted form rows, deliberately: the admin
     * create and edit forms, the customer checkout and quote conversion all build lines in their
     * own shapes and then all end up here, so the split happens once instead of four times. It also
     * means the arithmetic reads the same line quantities the reconciler is about to read, with no
     * index to keep in step between two loops over two different arrays.
     *
     * $targetStatus is the status this save will LAND the order in, not necessarily its current
     * one — the same question AdminOrderStockValidator::shortfallsFor() is asked, and for the same
     * reason. A status that holds nothing promises nothing, so every line's backordered quantity is
     * cleared: a Draft has committed to none of this yet, and voiding an order withdraws the
     * promise along with the goods.
     *
     * ## Re-splitting an existing order is deliberate
     *
     * On an edit, each line is re-split from scratch against availability with this order's own
     * holds added back, so an unchanged order resolves to the numbers it already had. Where it does
     * NOT resolve to the same numbers is when stock arrived in the meantime and nobody released it
     * yet: the re-split gives those units to the line, which is the same answer the save would give
     * a freshly typed line, and the release audit trail is not the place an edit belongs anyway.
     */
    public function applyToOrderLines(SalesOrder $order, string $targetStatus, EntityManagerInterface $entityManager): void
    {
        if (OrderInventoryBucketResolver::bucketForStatus($targetStatus) === null) {
            foreach ($order->getLines() as $line) {
                $line->setBackorderedQuantity('0.00');
            }

            return;
        }

        $warehousesByLowerRegionName = $this->warehouses->warehousesByLowerRegionName();
        $ownHold = $this->ownHoldFor($order, $entityManager);
        $ownBackorderHold = $this->ownBackorderHoldFor($order, $entityManager);

        /** @var array<string, array{allowed: bool, available: string, capacity: string}> $pools */
        $pools = [];

        foreach ($order->getLines() as $line) {
            $product = $line->getProduct();
            // An exact decimal string, not `(int) round((float) …)`. That cast promised 3 units of
            // stock for a line of 2.5 and none at all for a line of 0.4, which is a promise the
            // reconciler then could not keep either way.
            $quantity = QuantityScale::canonical($line->getQuantity());

            $warehouse = $product instanceof ProductCore && QuantityScale::compare($quantity, 0) > 0
                ? OrderInventoryBucketResolver::resolveLineWarehouse($line->getLocation(), $order->getFulfillmentRegion(), $warehousesByLowerRegionName)
                : null;

            if (!$warehouse instanceof Warehouse) {
                // Reserves nothing, so it can promise nothing either — the same skip
                // InventoryReservationReconciler makes for an unresolvable line.
                $line->setBackorderedQuantity('0.00');

                continue;
            }

            $key = $product->getId() . '|' . $warehouse->getId();

            if (!isset($pools[$key])) {
                $inventory = $entityManager->getRepository(ProductInventory::class)->findOneBy([
                    'product' => $product,
                    'warehouse' => $warehouse,
                ]);
                $allowed = $inventory instanceof ProductInventory && $inventory->isAllowBackorder();

                $pools[$key] = [
                    'allowed' => $allowed,
                    'available' => QuantityScale::add($inventory?->getAvailableQuantity() ?? 0, $ownHold[$key] ?? 0),
                    'capacity' => $allowed
                        ? $inventory->remainingBackorderCapacity($ownBackorderHold[$key] ?? 0)
                        : QuantityScale::canonical(0),
                ];
            }

            // Drawn down as the lines are walked, so two lines of the same product in one warehouse
            // compete for one pool: the first takes the stock there is and the second backorders,
            // which is the order an admin reads the form in.
            $pool = &$pools[$key];
            $split = $this->split($quantity, $pool['available'], $pool['allowed'], $pool['capacity']);
            $pool['available'] = QuantityScale::sub($pool['available'], $split->fulfilled);
            $pool['capacity'] = QuantityScale::sub($pool['capacity'], $split->backordered);
            unset($pool);

            $line->setBackorderedQuantity($split->backordered);
        }
    }

    /** The row for one product in one warehouse, or null when nothing has ever been stocked there. */
    public function inventoryFor(ProductCore $product, Warehouse $warehouse, EntityManagerInterface $entityManager): ?ProductInventory
    {
        return $entityManager->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $warehouse,
        ]);
    }

    /**
     * What this order currently contributes to every bucket, across both of its ledgers.
     *
     * Added back to availability rather than counted against the order that owns it — see
     * AdminOrderStockValidator's docblock, which is where this arithmetic and its reasoning lived
     * before the split resolver needed the identical figure. One implementation, because two
     * would eventually add back different things and only one of them would be right.
     *
     * @return array<string, string> "productId|warehouseId" => held quantity, as a decimal string
     */
    public function ownHoldFor(SalesOrder $order, EntityManagerInterface $entityManager): array
    {
        $held = [];

        $reservations = $entityManager->getRepository(OrderInventoryReservation::class)->findBy(['order' => $order]);

        $invoices = $order->getInvoices()->toArray();
        if ($invoices !== []) {
            $reservations = array_merge(
                $reservations,
                $entityManager->getRepository(InvoiceInventoryReservation::class)->findBy(['invoice' => $invoices]),
            );
        }

        foreach ($reservations as $reservation) {
            $key = $reservation->getProduct()->getId() . '|' . $reservation->getWarehouse()->getId();
            $held[$key] = QuantityScale::add($held[$key] ?? 0, $reservation->getEffectiveQuantity());
        }

        return $held;
    }

    /**
     * What this order currently holds against one specific InventoryLot, across both of its ledgers
     * (2026-09-14 lot/serial/expiry plan) — the lot-scoped twin of {@see self::ownHoldFor()}, for
     * netting an order's own existing hold on a lot out of the lot-scoped shortfall check.
     *
     * Filtered on lot id alone rather than on (product, warehouse, lot): a lot already names one
     * product, and its detail rows may in principle span more than one warehouse, so a hold against
     * it is a hold against it wherever it is standing.
     */
    /** A decimal string, like {@see ownHoldFor()}. */
    public function ownHoldForLot(SalesOrder $order, int $lotId, EntityManagerInterface $entityManager): string
    {
        $held = QuantityScale::canonical(0);

        $reservations = $entityManager->getRepository(OrderInventoryReservation::class)->findBy(['order' => $order, 'lotId' => $lotId]);

        $invoices = $order->getInvoices()->toArray();
        if ($invoices !== []) {
            $reservations = array_merge(
                $reservations,
                $entityManager->getRepository(InvoiceInventoryReservation::class)->findBy(['invoice' => $invoices, 'lotId' => $lotId]),
            );
        }

        foreach ($reservations as $reservation) {
            $held = QuantityScale::add($held, $reservation->getEffectiveQuantity());
        }

        return $held;
    }

    /**
     * What one order already promises of a product in a warehouse, read from its own ledger rows.
     *
     * Bucket-filtered on purpose. The add-back above sums an order's holds across every bucket,
     * because all of them are subtracted from availability and all of them are the order's own. The
     * cap is a different question — how full is the backorder bucket, ignoring this order's share
     * of it — and answering it with the combined figure would credit an order's stock hold against
     * its backorder ceiling.
     *
     * @return array<string, string> "productId|warehouseId" => promised quantity, as a decimal string
     */
    public function ownBackorderHoldFor(SalesOrder $order, EntityManagerInterface $entityManager): array
    {
        $held = [];

        $reservations = $entityManager->getRepository(OrderInventoryReservation::class)->findBy([
            'order' => $order,
            'bucket' => OrderInventoryReservation::BUCKET_BACKORDERED,
        ]);

        foreach ($reservations as $reservation) {
            $key = $reservation->getProduct()->getId() . '|' . $reservation->getWarehouse()->getId();
            $held[$key] = QuantityScale::add($held[$key] ?? 0, $reservation->getQuantity());
        }

        return $held;
    }
}
