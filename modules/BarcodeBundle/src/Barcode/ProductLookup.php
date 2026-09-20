<?php

declare(strict_types=1);

namespace BarcodeBundle\Barcode;

use App\Entity\ProductCore;
use BarcodeBundle\Entity\ProductBarcode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "What product is this string?" — asked identically by four screens (#607, #609).
 *
 * The scan console, the barcode screen's Open box, the receiving scan and the dispatch scan all ask
 * exactly this and all have to answer it exactly the same way, including the refusals. Four copies
 * of "try the SKU, then try the barcodes, then decide what to do about two answers" would be four
 * chances for one of them to quietly start guessing.
 *
 * ## A SKU is still a product identifier, and always will be
 *
 * `product_core.sku` is matched first and is matched forever. #607 is additive: barcodes are another
 * way in, not a replacement, and a product with no barcode rows resolves by SKU exactly as it did
 * before the table existed. That is also why this returns a LIST rather than a product — the SKU and
 * a barcode can name different products, and that is a fact to report rather than a tie to break.
 *
 * ## Two answers is a refusal, not a ranking
 *
 * Ranking SKU above barcode, or barcode above SKU, would resolve every collision silently and be
 * wrong about half the time. At a goods-in dock that books stock against the wrong product and
 * nobody finds out until a count. So {@see products()} returns both and every caller refuses,
 * naming both — which is the behaviour ScanResolver has always had for a value that is both a bin
 * code and a SKU, extended to the shape barcodes add.
 */
final class ProductLookup
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BarcodeDirectory $directory,
    ) {
    }

    /**
     * Every product a scanned string names, by SKU or by any barcode attached to it.
     *
     * @return list<ProductCore>
     */
    public function products(string $raw): array
    {
        $value = trim($raw);
        if ($value === '') {
            return [];
        }

        $found = [];

        $bySku = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => $value, 'deleted' => false]);
        if ($bySku instanceof ProductCore) {
            $found[$bySku->getId() ?? 0] = $bySku;
        }

        foreach ($this->directory->productsFor($value) as $byBarcode) {
            $found[$byBarcode->getId() ?? 0] = $byBarcode;
        }

        return array_values($found);
    }

    /** True when the value is a SKU. Used to say WHICH kind of thing collided, not to rank. */
    public function isSku(string $raw): bool
    {
        return $this->em->getRepository(ProductCore::class)
            ->findOneBy(['sku' => trim($raw), 'deleted' => false]) instanceof ProductCore;
    }

    /**
     * The sentence for a code that names more than one product, or null when it does not.
     *
     * Names both, and says what to do: one of the two products is carrying somebody else's part
     * number and a person has to decide which.
     */
    public function describeAmbiguity(string $raw): ?string
    {
        $value = trim($raw);
        $products = $this->products($value);

        if (\count($products) < 2) {
            return null;
        }

        return sprintf(
            '%s is on %s. One code cannot mean two products — take it off whichever one it does not belong to. Nothing was recorded.',
            $value,
            implode(' and ', array_map(
                static fn (ProductCore $p): string => $p->getSku() ?: $p->getName(),
                $products,
            )),
        );
    }

    /**
     * What a screen says after a successful scan.
     *
     * When the value scanned was a barcode rather than the SKU, this names the barcode's kind and
     * whose it is as well as the product. On a goods-in dock that is the difference between
     * "Scanned WIDGET-1" — which is not what is printed on the box in your hands — and
     * "Scanned 0012345678905 (vendor_part, Northern Supply) → WIDGET-1 Widget", which can be checked
     * against the carton by eye.
     */
    public function describe(string $raw, ProductCore $product): string
    {
        $value = trim($raw);
        $sku = $product->getSku();

        if ($sku === $value) {
            return trim($sku . ' — ' . $product->getName());
        }

        foreach ($this->directory->rowsFor($value) as $barcode) {
            if ($barcode->getProduct() === $product) {
                return sprintf('%s → %s %s', $barcode->getLabel(), $sku, $product->getName());
            }
        }

        return trim($sku . ' — ' . $product->getName());
    }

    /** The barcode row a scanned string matched on this product, when it matched one at all. */
    public function barcodeOn(string $raw, ProductCore $product): ?ProductBarcode
    {
        foreach ($this->directory->rowsFor(trim($raw)) as $barcode) {
            if ($barcode->getProduct() === $product) {
                return $barcode;
            }
        }

        return null;
    }
}
