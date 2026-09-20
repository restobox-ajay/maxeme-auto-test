<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\AbstractSalesDocument;
use App\Entity\InventoryReservation;
use App\Entity\Invoice;
use App\Entity\InvoiceInventoryReservation;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrderLine;
use App\Entity\Warehouse;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An invoice, as the reconciler sees it (#539 stage 3): it holds `pending` or `approved` on exactly
 * what it bills.
 *
 * No remainder arithmetic on this side, unlike the order's. An invoice line IS the billed quantity —
 * there is nothing left over on an invoice — so the quantity is the line's own, and which bucket it
 * lands in is the invoice's status and nothing else (see InvoiceInventoryBucketResolver).
 *
 * Two things narrow that: a line billing goods that do not exist yet (#548), and a line whose goods
 * have been credited back (#586). Both are handled inside stockedQuantityFor(), as subtractions from
 * this invoice's own target rather than as a second document holding a negative or a second ledger
 * of its own.
 *
 * A line billing something the order never carried — a fee, an extra added at ship time — reserves
 * stock here like any other line if it names a product. That is correct: the goods are going out
 * whether or not an order row anticipated them.
 */
final class InvoiceReservationSubject implements InventoryReservationSubject
{
    public function __construct(private readonly Invoice $invoice)
    {
    }

    public function targetBucket(): ?string
    {
        return InvoiceInventoryBucketResolver::bucketForStatus($this->invoice->getStatus());
    }

    public function heldLines(): iterable
    {
        foreach ($this->invoice->getLines() as $line) {
            $product = $line->getProduct();
            if (!$product instanceof ProductCore) {
                continue;
            }

            yield [
                'product' => $product,
                'location' => $line->getLocation(),
                'quantity' => self::stockedQuantityFor($line),
                'lot' => $line->getLotId(),
                'serial' => $line->getSerial(),
            ];
        }
    }

    /**
     * What this line holds: everything it bills, less anything credited back, unless some of what
     * it bills does not exist yet.
     *
     * ## Credits net out HERE, and that is the whole of #586's inventory design
     *
     * A credit note releases a hold. The obvious-looking alternatives were both rejected:
     *
     *  - a NEGATIVE invoice would push negatives into `pending_quantity`/`approved_quantity`, which
     *    InvoiceInventoryBucketResolver maps a status straight onto and which
     *    ProductInventory::adjustPending() clamps at zero, so the release would be swallowed on some
     *    rows and not others;
     *  - a THIRD reservation entity would mean a second copy of InventoryReservationReconciler's
     *    diff. InventoryReservationSubject's docblock is unambiguous about where that leads, and
     *    #548 already answered the same question for backorder without one — the order's sales-hold
     *    target is computed net of what the line backordered, and this is the same move on the
     *    invoice side.
     *
     * So crediting 4 units of an invoice line simply makes this method answer four smaller.
     * `invoice_inventory_reservation.quantity` drops by 4 at the next reconcile, and
     * `pending_quantity`/`approved_quantity` follow through the reconciler that already exists.
     * Nothing new is stored and nothing new is queried.
     *
     * Netted FIRST, before the backorder arithmetic below, because it is a reduction of what this
     * line bills at all: a line billing 10 of which 4 came back bills 6 for every purpose here,
     * including the comparison against the order line's stocked portion.
     *
     * A checkout invoices its order in full the instant the order is placed, backordered units
     * included — the customer has paid for them. Held whole, those units would be counted twice:
     * once here and once in the order's `backordered` bucket, leaving a SKU reading as twice as
     * oversold as it is. So the promise is held once, by the order, and the invoice holds only the
     * part with stock behind it.
     *
     * The billed quantity is matched against the line's stocked portion in invoice-id order, so an
     * order line split across several invoices attributes its stock to the invoices that came
     * first and its shortfall to the last. That is also the order goods actually leave in.
     *
     * ## Nothing backordered, nothing to do
     *
     * The early return is the guarantee, not an optimisation: with no backordered units behind a
     * line — the state of every line in the app until a SKU is opted in — this returns the billed
     * quantity and no other code below runs, so an invoice reserves exactly what it always did,
     * including the over-billed and order-less rows #539 deliberately allows.
     *
     * Static and public because InventoryRecalcCommand has to recompute the Pending bucket by
     * exactly this rule. Two implementations of it would mean the hourly recalc "correcting" a
     * correct cache into a wrong one, which is the failure mode the whole recalc exists to catch.
     */
    public static function stockedQuantityFor(InvoiceLine $line): string
    {
        // Exact decimal strings throughout, and no rounding anywhere.
        //
        // This method used to open `(int) round((float) $line->getQuantity())`, which ROUNDED UP:
        // an invoice line for 1.5 held 2 units of real stock, so every such line over-reserved by
        // half a unit and starved the next order of it — a live defect and not a limitation, since
        // the bucket columns have always been able to hold the half. A line for 0.4 held nothing at
        // all, which is the same bug in the other direction.
        $billed = QuantityScale::sub($line->getQuantity(), self::creditedUnitsAffectingStock($line));
        $billed = QuantityScale::compare($billed, 0) > 0 ? $billed : QuantityScale::canonical(0);

        $orderLine = $line->getSalesOrderLine();
        if (!$orderLine instanceof SalesOrderLine || QuantityScale::compare($orderLine->getBackorderedUnits(), 0) <= 0) {
            return $billed;
        }

        $stocked = QuantityScale::sub($orderLine->getQuantity(), $orderLine->getBackorderedUnits());
        $stocked = QuantityScale::compare($stocked, 0) > 0 ? $stocked : QuantityScale::canonical(0);

        $available = QuantityScale::sub($stocked, self::earlierBilledFor($line->getInvoice(), $orderLine));
        $capped = QuantityScale::compare($billed, $available) <= 0 ? $billed : $available;

        return QuantityScale::compare($capped, 0) > 0 ? $capped : QuantityScale::canonical(0);
    }

    /** Credited units that release this line's hold: a shipped line only releases via a restocked note. */
    private static function creditedUnitsAffectingStock(InvoiceLine $line): string
    {
        $shipped = $line->getInvoice()->getStatus() === 'Completed';
        $units = QuantityScale::canonical(0);
        foreach ($line->getCreditLines() as $creditLine) {
            $memo = $creditLine->getCreditMemo();
            if (!$memo->countsTowardCreditedQuantity() || ($shipped && !$memo->isRestock())) {
                continue;
            }
            $units = QuantityScale::add($units, $creditLine->getQuantity());
        }

        return QuantityScale::compare($units, 0) > 0 ? $units : QuantityScale::canonical(0);
    }

    /**
     * How much of one order line the invoices BEFORE this one already billed.
     *
     * Ordered by id, the only total order these documents have. An invoice not yet written has no
     * id and cannot have billed anything before this one, so it contributes nothing.
     */
    private static function earlierBilledFor(Invoice $current, SalesOrderLine $orderLine): string
    {
        $thisId = $current->getId();
        if ($thisId === null) {
            return QuantityScale::canonical(0);
        }

        $total = QuantityScale::canonical(0);

        foreach ($orderLine->getOrder()->getCountingInvoices() as $invoice) {
            $otherId = $invoice->getId();
            if ($otherId === null || $otherId >= $thisId) {
                continue;
            }

            foreach ($invoice->getLines() as $otherLine) {
                if ($otherLine->getSalesOrderLine() === $orderLine) {
                    $total = QuantityScale::add($total, $otherLine->getQuantity());
                }
            }
        }

        return $total;
    }

    public function fallbackRegionName(): ?string
    {
        return $this->invoice->getFulfillmentRegion();
    }

    public function existingReservations(EntityManagerInterface $entityManager): array
    {
        return array_values($entityManager->getRepository(InvoiceInventoryReservation::class)->findBy(['invoice' => $this->invoice]));
    }

    public function newReservation(ProductCore $product, Warehouse $warehouse): InventoryReservation
    {
        return (new InvoiceInventoryReservation())
            ->setInvoice($this->invoice)
            ->setProduct($product)
            ->setWarehouse($warehouse);
    }

    public function changeAction(): string
    {
        return 'invoice_reconciled';
    }

    public function document(): AbstractSalesDocument
    {
        return $this->invoice;
    }

    public function reference(): string
    {
        return $this->invoice->getDocumentNumber();
    }
}
