<?php

declare(strict_types=1);

namespace BarcodeBundle\Barcode;

use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use BarcodeBundle\Entity\ProductBarcode;
use BarcodeBundle\Repository\ProductBarcodeRepository;

/**
 * The one door every other bundle uses to ask a barcode question (#607).
 *
 * ## Why this exists rather than the repository being injected everywhere
 *
 * Because this bundle has an Active/Inactive switch on App Management like every other, and the
 * whole point of that switch is that turning it off returns the application to exactly what it did
 * before the bundle existed. A repository injected straight into WarehouseOpsBundle's scan resolver
 * and ProcurementBundle's receiving scan would keep answering after the switch was thrown, because
 * a repository does not know what a bundle is.
 *
 * So every read goes through here, and with the bundle Inactive every read returns nothing:
 *
 *  - `ScanResolver` finds no barcodes and matches on `product_core.sku` alone, which is exactly what
 *    it did before #607.
 *  - `LabelCatalog` finds no primary barcode and encodes the SKU, which is exactly what it printed
 *    before #607.
 *  - the receiving and dispatch scan screens find no barcodes and still resolve a SKU.
 *
 * The rows stay in the table untouched, so turning it back on restores every barcode. That is the
 * same shape `InventoryModeResolver` gives InventoryDepthBundle's switch, and it is the reason
 * `bin/ci-bundles-off` can prove the pre-bundle behaviour is unchanged rather than asserting it.
 *
 * ## Two bundles depend on this one, and neither depends on the other
 *
 * WarehouseOpsBundle (scan console, labels, dispatch scan) and ProcurementBundle (receiving scan)
 * are peers: neither imports the other, and `InventoryDepthBundle\Delete\ReferenceCounter` says so
 * out loud. A barcode is needed by both, so it cannot live in either without making one depend on
 * the other sideways. It lives here, and both depend on this the way both already depend on
 * InventoryDepthBundle.
 */
final class BarcodeDirectory
{
    public const SOURCE = 'BarcodeBundle';

    public function __construct(
        private readonly ProductBarcodeRepository $barcodes,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    public function isActive(): bool
    {
        return $this->bundleStatusRepo->isActive(self::SOURCE);
    }

    /**
     * Every DISTINCT product a scanned string names.
     *
     * Empty is the ordinary answer and the caller must handle it as "this code is not a barcode",
     * not as an error: the table starts empty and most products never get a row in it.
     *
     * More than one is a collision, and the caller's job is to name both and refuse. It is a real
     * case — two vendors legitimately use one part number for two different things — and guessing
     * between them books stock against the wrong product about half the time.
     *
     * @return list<ProductCore>
     */
    public function productsFor(string $code): array
    {
        return $this->isActive() ? $this->barcodes->productsForCode($code) : [];
    }

    /**
     * Every barcode row carrying a scanned string, so a caller can say which kind and whose it is.
     *
     * @return list<ProductBarcode>
     */
    public function rowsFor(string $code): array
    {
        return $this->isActive() ? $this->barcodes->forCode($code) : [];
    }

    /**
     * The barcode a label should encode for this product, or null when it has none.
     *
     * Null is not a failure. It is the answer for every product nobody has attached a code to, and
     * the caller's fallback for it — the SKU — is what was printed before this table existed.
     */
    public function primaryFor(ProductCore $product): ?ProductBarcode
    {
        return $this->isActive() ? $this->barcodes->primaryFor($product) : null;
    }

    /**
     * Every barcode on one product, the primary first.
     *
     * @return list<ProductBarcode>
     */
    public function forProduct(ProductCore $product): array
    {
        return $this->isActive() ? $this->barcodes->forProduct($product) : [];
    }
}
