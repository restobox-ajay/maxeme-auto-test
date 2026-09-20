<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Label;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use BarcodeBundle\Barcode\BarcodeDirectory;
use BarcodeBundle\Entity\ProductBarcode;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;
use WarehouseOpsBundle\Scan\ScanResolver;

/**
 * What each label target encodes, and what a person reads on it (#552).
 *
 * Kept apart from the controller because "what goes on a lot label" is a decision with consequences
 * — the wrong choice makes a label that scans as something else — and it should be assertable
 * without an HTTP request.
 *
 * | Target  | Encodes                    | Why |
 * |---------|----------------------------|-----|
 * | Product | its primary barcode, else the SKU | scan to identify what you are holding |
 * | Bin     | `warehouse_location.code`  | scan to say where you are |
 * | Lot     | `LOT-{id}`, code and expiry printed | scan to capture the batch at receipt or pick |
 * | Serial  | the serial itself          | scan to commit one specific unit |
 *
 * ## The product label encodes the product's primary barcode, and the SKU only when it has none
 *
 * #552 printed the SKU because there was nowhere else to look: `product_core` had no UPC, EAN,
 * GTIN or barcode column anywhere in the codebase. #607 added `product_barcode`, so this method —
 * the one the old docblock said would have to change the day a vendor identifier existed — now
 * asks for the primary barcode first.
 *
 * The fallback is not a detail. A product with no barcode rows prints EXACTLY the label it printed
 * before, encoding EXACTLY the SKU, because that is still the honest answer for stock that carries
 * no manufacturer's code. What changes is that a product which DOES carry a real GTIN stops getting
 * a second, private barcode stuck on top of it.
 *
 * ## Why the lot label is namespaced and the others are not
 *
 * A lot's identity is its row id, because batch codes are reused across production runs with
 * different expiry dates. A bare id is indistinguishable from a numeric SKU, so it is prefixed;
 * ScanResolver::LOT_PREFIX is the only other place that prefix is known, and the two are paired
 * deliberately. Bins and SKUs are already unique namespaces that staff read off the shelf and the
 * box, so wrapping them would mean a label that does not match what the vendor printed.
 */
final class LabelCatalog
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BarcodeDirectory $barcodes,
    ) {
    }

    /**
     * The product label.
     *
     * Encodes the primary barcode where the product has one — the real GTIN, so the label you stick
     * on agrees with the one the manufacturer already printed — and the SKU where it does not, which
     * is byte-for-byte the label this method produced before `product_barcode` existed.
     *
     * The human-readable subtitle names which of the two it is, because an operator holding a carton
     * has to be able to tell "this is our own code" from "this is the vendor's" without scanning it.
     */
    public function forProduct(ProductCore $product): LabelSpec
    {
        $primary = $this->barcodes->primaryFor($product);

        return new LabelSpec(
            LabelSpec::KIND_PRODUCT,
            $primary?->getCode() ?: $product->getSku(),
            $product->getName(),
            $primary === null
                ? $product->getSku()
                : sprintf('%s · %s', $product->getSku(), ProductBarcode::describeKind($primary->getKind())),
        );
    }

    public function forBin(WarehouseLocation $bin): LabelSpec
    {
        return new LabelSpec(
            LabelSpec::KIND_BIN,
            $bin->getCode(),
            $bin->getWarehouse()->getName(),
            $bin->getZone() === null && $bin->getAisle() === null
                ? ucfirst($bin->getType())
                : trim(sprintf('%s %s', (string) $bin->getZone(), (string) $bin->getAisle())),
        );
    }

    public function forLot(InventoryLot $lot): LabelSpec
    {
        return new LabelSpec(
            LabelSpec::KIND_LOT,
            ScanResolver::encodeLot($lot),
            $lot->getProduct()->getSku() ?: $lot->getProduct()->getName(),
            // Code AND expiry, always together — the code alone does not identify a batch, and this
            // line is the only part of the label a person can check against the box by eye.
            $lot->getLabel(),
        );
    }

    public function forSerial(InventoryDetail $row): LabelSpec
    {
        return new LabelSpec(
            LabelSpec::KIND_SERIAL,
            (string) $row->getSerial(),
            $row->getProduct()->getSku() ?: $row->getProduct()->getName(),
            $row->getLot()?->getLabel() ?? '',
        );
    }

    /**
     * Every bin in a warehouse, in pick-path order.
     *
     * Batch printing a whole warehouse is the normal case, not an edge one: bins are labelled once,
     * when the racking goes up, and then reprinted a shelf at a time forever after.
     *
     * @return list<LabelSpec>
     */
    public function binsFor(Warehouse $warehouse): array
    {
        $specs = [];

        foreach ($this->em->getRepository(WarehouseLocation::class)->findBy(
            ['warehouse' => $warehouse, 'status' => 'Active'],
            ['sortKey' => 'ASC', 'code' => 'ASC'],
        ) as $bin) {
            $specs[] = $this->forBin($bin);
        }

        return $specs;
    }

    /**
     * Every lot of one product that still has stock somewhere, earliest expiry first.
     *
     * Filtered to lots with stock because a lot that has been fully sold has no boxes left to stick
     * a label on, and a sheet of thirty labels for batches that no longer exist is the fastest way
     * to make people stop using the screen.
     *
     * @return list<LabelSpec>
     */
    public function lotsFor(ProductCore $product): array
    {
        // Walked from the DETAIL rows through their own `lot` association rather than joining
        // `inventory_lot` to `inventory_detail` as two unrelated roots. An arbitrary join with a
        // WITH clause is deprecated in Doctrine ORM (issue #12192) and the suite already reports one;
        // adding a second would be adding to a debt this had no reason to touch.
        /** @var list<InventoryDetail> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('d', 'l')
            ->from(InventoryDetail::class, 'd')
            ->innerJoin('d.lot', 'l')
            ->andWhere('d.product = :product')->setParameter('product', $product)
            ->andWhere('d.quantity > 0')
            ->andWhere('d.status = :available')->setParameter('available', InventoryDetail::STATUS_AVAILABLE)
            ->orderBy('l.expiry', 'ASC')
            ->getQuery()
            ->getResult();

        // One label per lot, not one per bin the lot is split across.
        $specs = [];
        foreach ($rows as $row) {
            $lot = $row->getLot();
            if ($lot instanceof InventoryLot && !isset($specs[$lot->getId() ?? 0])) {
                $specs[$lot->getId() ?? 0] = $this->forLot($lot);
            }
        }

        return array_values($specs);
    }

    /**
     * Every live serial of one product — one label per physical unit.
     *
     * @return list<LabelSpec>
     */
    public function serialsFor(ProductCore $product): array
    {
        /** @var list<InventoryDetail> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('d')
            ->from(InventoryDetail::class, 'd')
            ->andWhere('d.product = :product')->setParameter('product', $product)
            ->andWhere('d.serial IS NOT NULL')
            ->andWhere('d.quantity > 0')
            ->andWhere('d.status = :available')->setParameter('available', InventoryDetail::STATUS_AVAILABLE)
            ->orderBy('d.serial', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(fn (InventoryDetail $row): LabelSpec => $this->forSerial($row), $rows);
    }

    /**
     * The same spec repeated, which is what "print 12 of these" means.
     *
     * Reprinting is free and unlimited on purpose: a smudged label is the most common reason anyone
     * opens the label screen, and a reprint that had to be justified is a reprint nobody does.
     *
     * @param list<LabelSpec> $specs
     *
     * @return list<LabelSpec>
     */
    public function repeat(array $specs, int $copies): array
    {
        $copies = max(1, min(200, $copies));
        $out = [];

        foreach ($specs as $spec) {
            for ($i = 0; $i < $copies; $i++) {
                $out[] = $spec;
            }
        }

        return $out;
    }
}
