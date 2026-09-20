<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Scan;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use BarcodeBundle\Barcode\ProductLookup;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;

/**
 * Turns a scanned barcode into the thing it names (#552, #607).
 *
 * ## Internal labels are namespaced, and that is not decoration
 *
 * A lot's identity is its row id — batch codes are reused across production runs with different
 * expiry dates, so the code alone does not identify a batch — and a bare integer is indistinguishable
 * from a numeric SKU. So lot labels encode `LOT-123`, and this is the only place that prefix is
 * known. Bins scan as their own code and products as their own SKU, because both of those are
 * already unique namespaces that warehouse staff read off the shelf and the box.
 *
 * ## A product is found by its SKU **or** by any barcode anybody put on it (#607)
 *
 * This is the change that makes the scan console usable at a goods-in dock. Before it, the only
 * product identifier in the database was `product_core.sku`, so scanning your own printed label
 * worked and scanning the vendor's carton — the common case, because at goods-in you are holding
 * somebody else's box — matched nothing and returned "not a bin, a SKU, a lot or a live serial".
 * Now every row in `product_barcode` is a way in: a UPC, an EAN-13, a case GTIN, or the part number
 * a particular supplier prints, all landing on the same product.
 *
 * A product with no barcode rows behaves EXACTLY as it did before — the barcode lookup returns
 * nothing and the SKU match is the whole answer.
 *
 * ## An ambiguous scan is an error, not a guess
 *
 * If one barcode matches a bin code AND a SKU, resolving it by trying things in a fixed order picks
 * one and is wrong roughly half the time — silently, and against a bin. So a value matching more than
 * one kind returns null and the caller says so; the fix is renaming one of them, which is a
 * five-second job the first time anybody hits it.
 *
 * Barcodes make that rule matter more, not less, and add a second shape of it: two different vendors
 * legitimately use one part number for two different things, so a scanned string can now name two
 * PRODUCTS rather than two kinds. That refuses too, and names both SKUs. A wrong guess at goods-in
 * books stock against the wrong product and nobody finds out until a count.
 *
 * ## Serials resolve to their live row
 *
 * `uniq_live_serial` means a serial has at most one detail row holding stock, so a serial scan
 * resolves to that row — which carries the bin, the lot and the product all at once. A serial with
 * no live row (already sold, or never received) resolves to nothing, which is the correct and useful
 * answer to "scan this to commit one specific unit".
 */
final class ScanResolver
{
    public const LOT_PREFIX = 'LOT-';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductLookup $products,
    ) {
    }

    /** The label a printer should encode for each target. Paired with resolve() on purpose. */
    public static function encodeLot(InventoryLot $lot): string
    {
        return self::LOT_PREFIX . ($lot->getId() ?? 0);
    }

    /**
     * @param Warehouse|null $warehouse limits bin lookup to one building — bin codes are unique per
     *                                  warehouse, not globally, so A01-01-01 exists in all three
     */
    public function resolve(string $raw, ?Warehouse $warehouse = null): ?ScanMatch
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        if (str_starts_with(strtoupper($value), self::LOT_PREFIX)) {
            $id = (int) substr($value, \strlen(self::LOT_PREFIX));
            $lot = $id > 0 ? $this->em->find(InventoryLot::class, $id) : null;

            // A lot prefix is unambiguous by construction, so it short-circuits rather than joining
            // the ambiguity check — nothing else can look like it.
            return $lot instanceof InventoryLot
                ? new ScanMatch(ScanMatch::KIND_LOT, $lot, $lot->getLabel())
                : null;
        }

        $matches = [];

        $bin = $this->bin($value, $warehouse);
        if ($bin instanceof WarehouseLocation) {
            $matches[] = new ScanMatch(ScanMatch::KIND_BIN, $bin, $bin->getLabel());
        }

        // One product, found by SKU or by barcode. Two products is an ambiguity, not a match, and
        // is reported by describeCollision() rather than resolved.
        $products = $this->products->products($value);
        if (\count($products) === 1) {
            $matches[] = new ScanMatch(ScanMatch::KIND_PRODUCT, $products[0], $this->products->describe($value, $products[0]));
        } elseif (\count($products) > 1) {
            return null;
        }

        $serialRow = $this->em->getRepository(InventoryDetail::class)->findOneBy([
            'serial' => $value,
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);
        if ($serialRow instanceof InventoryDetail && $serialRow->getQuantity() > 0) {
            $matches[] = new ScanMatch(ScanMatch::KIND_SERIAL, $serialRow, $serialRow->getLabel());
        }

        return \count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Why an ambiguous scan resolved to nothing, so the screen can say which two things collided
     * rather than "unknown barcode" — which would send someone looking for a label that is right
     * there in their hand.
     */
    public function describeCollision(string $raw, ?Warehouse $warehouse = null): ?string
    {
        $value = trim($raw);

        // Two products first: it is a different sentence from a cross-kind collision, and the fix
        // is a different job — one of the two products is carrying somebody else's part number.
        $ambiguity = $this->products->describeAmbiguity($value);
        if ($ambiguity !== null) {
            return $ambiguity;
        }

        $products = $this->products->products($value);
        $kinds = [];

        if ($this->bin($value, $warehouse) instanceof WarehouseLocation) {
            $kinds[] = 'a bin code';
        }
        if ($products !== []) {
            // Named for how it was found, not generically: "a product SKU" and "a product barcode"
            // are two different things to go and rename, and the person reading this is about to
            // go and rename one of them.
            $kinds[] = $this->products->isSku($value) ? 'a product SKU' : 'a product barcode';
        }
        $serialRow = $this->em->getRepository(InventoryDetail::class)->findOneBy(['serial' => $value, 'status' => InventoryDetail::STATUS_AVAILABLE]);
        if ($serialRow instanceof InventoryDetail && $serialRow->getQuantity() > 0) {
            $kinds[] = 'a serial';
        }

        return \count($kinds) > 1
            ? sprintf('%s is both %s. Rename one of them — this scan cannot mean two things.', $value, implode(' and ', $kinds))
            : null;
    }

    private function bin(string $code, ?Warehouse $warehouse): ?WarehouseLocation
    {
        $criteria = ['code' => $code, 'status' => 'Active'];
        if ($warehouse instanceof Warehouse) {
            $criteria['warehouse'] = $warehouse;
        }

        $bin = $this->em->getRepository(WarehouseLocation::class)->findOneBy($criteria);

        return $bin instanceof WarehouseLocation ? $bin : null;
    }
}
