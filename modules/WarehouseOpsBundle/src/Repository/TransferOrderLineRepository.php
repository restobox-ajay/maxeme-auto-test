<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Repository;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Entity\TransferOrderLine;

/**
 * @extends ServiceEntityRepository<TransferOrderLine>
 */
class TransferOrderLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransferOrderLine::class);
    }

    /**
     * Everything of this product that has ever been dispatched OUT of this warehouse on a transfer.
     *
     * The source of truth behind `product_inventory.transfer_out_quantity` (#584), read from the
     * DOCUMENT rather than from the `in_transit` detail rows the document leaves behind. Those rows
     * are consumed by the receipt, so summing them gave a bucket that fell back to zero the moment
     * the goods landed — and a source warehouse that sprang back to full availability for stock now
     * standing in another building.
     *
     * Cumulative and monotonic, deliberately: the departure is permanent, because `quantity` is the
     * client's imported figure and this app does not reduce it.
     */
    public function dispatchedFromTotal(ProductCore $product, Warehouse $warehouse): int
    {
        return $this->documentTotal('l.quantityDispatched', 't.fromWarehouse', $product, $warehouse);
    }

    /**
     * Everything of this product that has ever ARRIVED at this warehouse on a transfer.
     *
     * The mirror of the above and the source of truth behind
     * `product_inventory.transfer_in_quantity`. Reads `quantity_received`, not `quantity_dispatched`
     * — dispatched 10 / received 8 credits the destination with 8, and the two that never turned up
     * stay missing until somebody writes them off at the source, where they still sit on an
     * `in_transit` row. Crediting 10 here would invent stock on the strength of a document saying
     * it was sent.
     */
    public function receivedIntoTotal(ProductCore $product, Warehouse $warehouse): int
    {
        return $this->documentTotal('l.quantityReceived', 't.toWarehouse', $product, $warehouse);
    }

    /**
     * What this warehouse's own outbound transfers say is still on a truck (#587).
     *
     *     SUM(quantity_dispatched − quantity_received) over lines whose transfer is FROM W
     *
     * The document's opinion of the `in_transit` rows, which sit at the SOURCE warehouse from
     * dispatch until receipt — so this is what the drift check holds those rows up against.
     *
     * ONE SIDE OF THE DOCUMENT, and the difference matters. `dispatchedFromTotal(W) −
     * receivedIntoTotal(W)` looks like the same expression and is not: the second term counts what
     * ARRIVED at W on other people's transfers. A warehouse that both sends and receives would have
     * its inbound arrivals subtracted from its own outbound in-flight figure, and a pair of
     * transfers in opposite directions along one route would report drift at both ends. The
     * subtraction has to happen per LINE, between the two columns of the same line.
     *
     * Never cached anywhere, for the reason #584 gives at length: a number that falls back to zero
     * when the goods land cannot define a bucket. It is only ever compared.
     */
    public function inFlightFromTotal(ProductCore $product, Warehouse $warehouse): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COALESCE(SUM(l.quantityDispatched - l.quantityReceived), 0)')
            ->innerJoin('l.transferOrder', 't')
            ->andWhere('l.product = :product')->setParameter('product', $product)
            ->andWhere('t.fromWarehouse = :warehouse')->setParameter('warehouse', $warehouse)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * What is still open and on a truck HEADED to this warehouse — the destination-side sibling of
     * {@see inFlightFromTotal()}, but NOT its mirror: this one filters to `status = DISPATCHED`, and
     * that difference is deliberate (#706).
     *
     *     SUM(quantity_dispatched − quantity_received) over DISPATCHED lines whose transfer is TO W
     *
     * `inFlightFromTotal()` is a drift check: it stays unfiltered because a shortfall's two lost
     * units sit on the source's `in_transit` row forever, unsellable, until someone writes them off
     * — so the document's own opinion should keep agreeing with that row even after the transfer is
     * marked RECEIVED. This method answers a different question — "is more still coming" — and once
     * a transfer is RECEIVED it is settled: `TransferOrderService::receive()` moves the document to
     * RECEIVED in one shot, whatever the shortfall, and there is no second receiving pass to expect.
     * Counting a RECEIVED transfer's gap here would forecast units toward this warehouse forever for
     * a loss that already happened and is not coming.
     *
     * This is the term the reorder engine was missing: a warehouse awaiting an inbound transfer
     * still read as short and got a purchase order raised on top of stock already rolling toward
     * it. See {@see \App\Contract\Inventory\InboundTransferProviderInterface}.
     */
    public function inFlightToTotal(ProductCore $product, Warehouse $warehouse): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COALESCE(SUM(l.quantityDispatched - l.quantityReceived), 0)')
            ->innerJoin('l.transferOrder', 't')
            ->andWhere('l.product = :product')->setParameter('product', $product)
            ->andWhere('t.toWarehouse = :warehouse')->setParameter('warehouse', $warehouse)
            ->andWhere('t.status = :status')->setParameter('status', TransferOrder::STATUS_DISPATCHED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Every (product, warehouse) pair any transfer has ever touched, both ends of every document
     * (#587).
     *
     * The scope of the drift check: a pair no transfer has named cannot have a transfer bucket, an
     * in-transit row or a disagreement between them, and walking every `product_inventory` row on a
     * catalogue of tens of thousands to prove that would make an hourly cron a table scan.
     *
     * BOTH ends of every line, in one query, because the check runs all three comparisons on every
     * pair. A destination that has never dispatched anything should read zero dispatched and zero
     * in transit — and an `in_transit` row sitting at a warehouse that has never sent a transfer is
     * itself a finding, which a source-side-only sweep would never look for.
     *
     * Draft and cancelled documents are in scope for the same reason documentTotal() does not
     * filter on status: their quantity columns are zero, so they contribute nothing but a pair to
     * check, and a draft whose quantities are NOT zero is exactly the hand-edit this exists to
     * catch.
     *
     * @return list<array{productId: int, warehouseId: int}>
     */
    public function pairsTouchedByTransfers(): array
    {
        /** @var list<array{productId: int|string, fromId: int|string, toId: int|string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.product) AS productId, IDENTITY(t.fromWarehouse) AS fromId, IDENTITY(t.toWarehouse) AS toId')
            ->innerJoin('l.transferOrder', 't')
            ->groupBy('l.product, t.fromWarehouse, t.toWarehouse')
            ->getQuery()
            ->getArrayResult();

        $pairs = [];
        foreach ($rows as $row) {
            foreach ([$row['fromId'], $row['toId']] as $warehouseId) {
                // Keyed so the same pair reached from two documents — or from both ends of one
                // route — is checked once and reported once rather than once per document.
                $pairs[$row['productId'] . '|' . $warehouseId] = [
                    'productId' => (int) $row['productId'],
                    'warehouseId' => (int) $warehouseId,
                ];
            }
        }

        return array_values($pairs);
    }

    /**
     * No status filter, and that is not an oversight.
     *
     * Both columns are zero until the movement that fills them actually happens, so a draft
     * contributes nothing and neither does a cancelled transfer — cancelling is only allowed from
     * `draft`, precisely because a dispatched transfer has stock on a truck and cannot be wished
     * away (see TransferOrderController::cancel()). Filtering on status as well would add a second
     * rule that has to agree with that one, and the day they disagreed the bucket would silently
     * drop a real dispatch.
     */
    private function documentTotal(string $column, string $side, ProductCore $product, Warehouse $warehouse): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select(sprintf('COALESCE(SUM(%s), 0)', $column))
            ->innerJoin('l.transferOrder', 't')
            ->andWhere('l.product = :product')->setParameter('product', $product)
            ->andWhere($side . ' = :warehouse')->setParameter('warehouse', $warehouse)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
