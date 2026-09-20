<?php

declare(strict_types=1);

namespace BarcodeBundle\Barcode;

use App\Entity\ProductCore;
use BarcodeBundle\Entity\ProductBarcode;
use BarcodeBundle\Repository\ProductBarcodeRepository;
use BarcodeBundle\Symbology\Code128;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only way a barcode gets onto a product, off a product, or becomes the primary one (#607).
 *
 * ## Why this exists rather than four `$em->persist()` calls in a controller
 *
 * Two of the three writes have a side effect that has to happen with them or the data is wrong:
 *
 *  - **Making one barcode primary un-makes the others.** "At most one primary per product" is not
 *    a constraint SQLite states, so it is stated here, in the one method that can set the flag. A
 *    controller that set `is_primary = true` directly would leave two, and the label screen would
 *    then print whichever the query happened to return first — differently on different days.
 *  - **The first barcode on a product becomes its primary.** Otherwise the commonest case, one
 *    product with one UPC, would have no primary at all and the label would silently fall back to
 *    the SKU while a perfectly good GTIN sat in the table.
 *
 * ## Everything it refuses, and why each refusal is worth a retype
 *
 *  - an empty code — nothing to scan
 *  - a code Code 128 cannot encode — a barcode that cannot be printed is not a barcode, and the
 *    label renderer already refuses it at print time; refusing it at entry means finding out now
 *    rather than at a packing bench
 *  - a duplicate on the same product — the same string twice is a typo, never a fact
 *  - a GTIN kind whose check digit does not add up — see {@see Gtin}
 *
 * A code that is already on a DIFFERENT product is allowed through, deliberately. Two vendors
 * really do use one part number for two things, and refusing that would refuse a true fact. What
 * happens instead is that scanning it resolves to nothing and names both products — the same
 * treatment ScanResolver has always given a value that is both a bin code and a SKU.
 */
final class BarcodeRegistry
{
    /** A minted code that collides is re-minted this many times before the attempt is abandoned. */
    private const MINT_ATTEMPTS = 8;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductBarcodeRepository $barcodes,
    ) {
    }

    /**
     * Puts a barcode on a product.
     *
     * @throws BarcodeException when the code is one this application must not store
     */
    public function attach(
        ProductCore $product,
        string $code,
        string $kind,
        ?string $party = null,
        ?string $note = null,
        bool $primary = false,
    ): ProductBarcode {
        $code = trim($code);

        if ($code === '') {
            throw new BarcodeException('A barcode needs a code. Nothing was saved.');
        }

        if (!Code128::isEncodable($code)) {
            throw new BarcodeException(sprintf(
                '%s contains characters Code 128 cannot encode, so it could never be printed. Nothing was saved.',
                $code,
            ));
        }

        if ($this->barcodes->exists($product, $code)) {
            throw new BarcodeException(sprintf(
                '%s is already on %s. Nothing was saved.',
                $code,
                $product->getSku() ?: $product->getName(),
            ));
        }

        if (self::isChecksummed($kind) && !Gtin::isValid($code)) {
            throw new BarcodeException(sprintf(
                '%s is not a valid %s — the check digit does not add up, which almost always means a digit'
                . ' was mistyped or two were transposed. Nothing was saved.',
                $code,
                strtoupper($kind),
            ));
        }

        $barcode = (new ProductBarcode())
            ->setProduct($product)
            ->setCode($code)
            ->setKind($kind)
            ->setParty($party)
            ->setNote($note);

        $this->em->persist($barcode);

        // The first one is the primary whether or not anybody ticked the box: a product with one
        // barcode and no primary would print its SKU while carrying a real GTIN.
        if ($primary || $this->barcodes->forProduct($product) === []) {
            $this->applyPrimary($product, $barcode);
        }

        $this->em->flush();

        return $barcode;
    }

    /**
     * Mints a fresh internal EAN-13 and attaches it.
     *
     * This is the "generate a barcode" action, and it is per product and never in bulk. Minting a
     * code for every product that has none would put a number on 7,000 rows that nobody asked for
     * and no printed label matches — a decision that looks like somebody's and is nobody's.
     */
    public function mintInternal(ProductCore $product, ?string $note = null): ProductBarcode
    {
        $id = $product->getId() ?? 0;

        for ($attempt = 0; $attempt < self::MINT_ATTEMPTS; $attempt++) {
            $code = Gtin::mintInternal($id);

            // Anywhere in the table, not just on this product: a minted number that already names
            // something else would resolve to two products and scan as nothing.
            if ($this->barcodes->forCode($code) === []) {
                return $this->attach($product, $code, ProductBarcode::KIND_INTERNAL, null, $note);
            }
        }

        throw new BarcodeException(
            'Could not mint an unused internal barcode after several attempts. Nothing was saved — try again.',
        );
    }

    /** Makes this the barcode a label encodes, and un-makes whichever one was. */
    public function makePrimary(ProductBarcode $barcode): void
    {
        $product = $barcode->getProduct();

        if (!$product instanceof ProductCore) {
            throw new BarcodeException('That barcode is not attached to a product.');
        }

        $this->applyPrimary($product, $barcode);
        $this->em->flush();
    }

    /**
     * Takes a barcode off a product.
     *
     * Removing the primary promotes the oldest survivor rather than leaving the product with
     * barcodes and no primary — a state in which the label screen would fall back to the SKU while
     * a real GTIN sat one row away.
     */
    public function detach(ProductBarcode $barcode): void
    {
        $product = $barcode->getProduct();
        $wasPrimary = $barcode->isPrimary();

        $this->em->remove($barcode);
        $this->em->flush();

        if (!$wasPrimary || !$product instanceof ProductCore) {
            return;
        }

        $survivor = $this->barcodes->forProduct($product)[0] ?? null;
        if ($survivor instanceof ProductBarcode) {
            $survivor->setPrimary(true);
            $this->em->flush();
        }
    }

    /** GTIN kinds carry a check digit; part numbers are whatever the other party says they are. */
    public static function isChecksummed(string $kind): bool
    {
        return \in_array($kind, [
            ProductBarcode::KIND_UPC,
            ProductBarcode::KIND_EAN,
            ProductBarcode::KIND_GTIN,
        ], true);
    }

    private function applyPrimary(ProductCore $product, ProductBarcode $winner): void
    {
        foreach ($this->barcodes->forProduct($product) as $existing) {
            if ($existing !== $winner && $existing->isPrimary()) {
                $existing->setPrimary(false);
            }
        }

        $winner->setPrimary(true);
    }
}
