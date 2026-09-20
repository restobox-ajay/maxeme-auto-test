<?php

declare(strict_types=1);

namespace BarcodeBundle\Tests\Barcode;

use App\Entity\ProductCore;
use App\Tests\DoctrineIntegrationTestCase;
use BarcodeBundle\Barcode\BarcodeException;
use BarcodeBundle\Barcode\BarcodeRegistry;
use BarcodeBundle\Entity\ProductBarcode;
use BarcodeBundle\Repository\ProductBarcodeRepository;

/**
 * What gets onto a product, what does not, and which one a label encodes.
 *
 * The refusals are the interesting half. A barcode screen that accepts a mistyped UPC produces a
 * product that scans as somebody else's, months later, with nothing to point at.
 */
final class BarcodeRegistryTest extends DoctrineIntegrationTestCase
{
    private BarcodeRegistry $registry;
    private ProductBarcodeRepository $barcodes;
    private ProductCore $product;
    private ProductCore $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = self::getContainer()->get(BarcodeRegistry::class);
        $this->barcodes = self::getContainer()->get(ProductBarcodeRepository::class);

        $this->product = $this->product('WIDGET-1', 'Widget');
        $this->other = $this->product('SPROCKET-2', 'Sprocket');
        $this->em->flush();
    }

    private function product(string $sku, string $name): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku)
            ->setName($name)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);

        return $product;
    }

    public function testAProductStartsWithNoBarcodesAndNoPrimary(): void
    {
        self::assertSame([], $this->barcodes->forProduct($this->product));
        self::assertNull($this->barcodes->primaryFor($this->product));
    }

    /**
     * Otherwise the commonest case in the world — one product, one UPC — would have no primary, and
     * the label would fall back to the SKU while a perfectly good GTIN sat one row away.
     */
    public function testTheFirstBarcodeOnAProductBecomesItsPrimaryWithoutBeingAsked(): void
    {
        $barcode = $this->registry->attach($this->product, '4006381333931', ProductBarcode::KIND_EAN);

        self::assertTrue($barcode->isPrimary());
        self::assertSame($barcode, $this->barcodes->primaryFor($this->product));
    }

    public function testAProductCanCarryManyBarcodesOfDifferentKinds(): void
    {
        $this->registry->attach($this->product, '4006381333931', ProductBarcode::KIND_EAN);
        $this->registry->attach($this->product, '10012345678902', ProductBarcode::KIND_GTIN);
        $this->registry->attach($this->product, 'NS-4471-B', ProductBarcode::KIND_VENDOR_PART, 'Northern Supply');
        $this->registry->attach($this->product, 'AC/9912', ProductBarcode::KIND_VENDOR_PART, 'Atlantic Components');

        $codes = array_map(static fn (ProductBarcode $b): string => $b->getCode(), $this->barcodes->forProduct($this->product));

        self::assertCount(4, $codes);
        self::assertContains('NS-4471-B', $codes);
        self::assertContains('AC/9912', $codes);
    }

    /** "At most one primary" is not a constraint SQLite states, so it is stated in one method. */
    public function testMakingOneBarcodePrimaryUnmakesTheOther(): void
    {
        $first = $this->registry->attach($this->product, '4006381333931', ProductBarcode::KIND_EAN);
        $second = $this->registry->attach($this->product, '10012345678902', ProductBarcode::KIND_GTIN);

        $this->registry->makePrimary($second);

        $this->em->refresh($first);
        self::assertFalse($first->isPrimary());
        self::assertTrue($second->isPrimary());
        self::assertSame($second->getId(), $this->barcodes->primaryFor($this->product)?->getId());

        $primaries = array_filter($this->barcodes->forProduct($this->product), static fn (ProductBarcode $b): bool => $b->isPrimary());
        self::assertCount(1, $primaries, 'a product must never have two primary barcodes');
    }

    public function testAttachingWithThePrimaryFlagTakesTheFlagOffWhicheverHadIt(): void
    {
        $first = $this->registry->attach($this->product, '4006381333931', ProductBarcode::KIND_EAN);
        $second = $this->registry->attach($this->product, '036000291452', ProductBarcode::KIND_UPC, null, null, true);

        $this->em->refresh($first);
        self::assertFalse($first->isPrimary());
        self::assertTrue($second->isPrimary());
    }

    /**
     * The check digit is the one piece of self-proving data on a barcode screen, and a transposed
     * pair is the commonest typing mistake there is. Refusing it costs a retype; accepting it costs
     * a receiving line booked against the wrong product.
     */
    public function testAGtinWithAWrongCheckDigitIsRefusedAndNothingIsSaved(): void
    {
        $this->expectException(BarcodeException::class);
        $this->expectExceptionMessageMatches('/check digit/');

        try {
            $this->registry->attach($this->product, '4006381333913', ProductBarcode::KIND_EAN);
        } finally {
            self::assertSame([], $this->barcodes->forProduct($this->product));
        }
    }

    /** A vendor's part number is whatever the vendor says it is; there is no checksum to fail. */
    public function testAVendorPartNumberIsNotCheckDigitVerified(): void
    {
        $barcode = $this->registry->attach($this->product, '4006381333913', ProductBarcode::KIND_VENDOR_PART, 'Northern Supply');

        self::assertSame('4006381333913', $barcode->getCode());
    }

    public function testTheSameCodeTwiceOnOneProductIsRefused(): void
    {
        $this->registry->attach($this->product, 'NS-4471-B', ProductBarcode::KIND_VENDOR_PART);

        $this->expectException(BarcodeException::class);
        $this->expectExceptionMessageMatches('/already on/');

        $this->registry->attach($this->product, 'NS-4471-B', ProductBarcode::KIND_VENDOR_PART);
    }

    /**
     * Two vendors legitimately use one part number for two different things, so this is a true fact
     * and refusing it would refuse the truth. What happens instead is that scanning it resolves to
     * nothing and names both — see ProductLookupTest.
     */
    public function testTheSameCodeOnTwoDifferentProductsIsAllowed(): void
    {
        $this->registry->attach($this->product, 'NS-4471-B', ProductBarcode::KIND_VENDOR_PART, 'Northern Supply');
        $this->registry->attach($this->other, 'NS-4471-B', ProductBarcode::KIND_VENDOR_PART, 'Atlantic Components');

        self::assertCount(2, $this->barcodes->forCode('NS-4471-B'));
        self::assertCount(2, $this->barcodes->productsForCode('NS-4471-B'));
    }

    public function testACodeCode128CannotEncodeIsRefusedRatherThanStoredUnprintable(): void
    {
        $this->expectException(BarcodeException::class);
        $this->expectExceptionMessageMatches('/could never be printed/');

        $this->registry->attach($this->product, "WIDGET\u{00A0}1", ProductBarcode::KIND_INTERNAL);
    }

    public function testAnEmptyCodeIsRefused(): void
    {
        $this->expectException(BarcodeException::class);

        $this->registry->attach($this->product, '   ', ProductBarcode::KIND_INTERNAL);
    }

    public function testGeneratingMintsAValidInternalEan13AndAttachesIt(): void
    {
        $barcode = $this->registry->mintInternal($this->product);

        self::assertSame(ProductBarcode::KIND_INTERNAL, $barcode->getKind());
        self::assertSame('2', $barcode->getCode()[0]);
        self::assertTrue($barcode->isPrimary(), 'the first barcode on a product is its primary');
        self::assertSame([$barcode->getId()], array_map(
            static fn (ProductBarcode $b): ?int => $b->getId(),
            $this->barcodes->forProduct($this->product),
        ));
    }

    public function testTwoGeneratedCodesForOneProductDoNotCollide(): void
    {
        $first = $this->registry->mintInternal($this->product);
        $second = $this->registry->mintInternal($this->product);

        self::assertNotSame($first->getCode(), $second->getCode());
        self::assertCount(2, $this->barcodes->forProduct($this->product));
    }

    /**
     * Otherwise the product would have barcodes and no primary, and the label screen would fall back
     * to the SKU while a real GTIN sat one row away.
     */
    public function testDeletingThePrimaryPromotesTheOldestSurvivor(): void
    {
        $first = $this->registry->attach($this->product, '4006381333931', ProductBarcode::KIND_EAN);
        $second = $this->registry->attach($this->product, '036000291452', ProductBarcode::KIND_UPC);

        $this->registry->detach($first);

        $this->em->refresh($second);
        self::assertTrue($second->isPrimary());
        self::assertSame($second->getId(), $this->barcodes->primaryFor($this->product)?->getId());
    }

    /** Deleting the last one puts the product back exactly where it started. */
    public function testDeletingTheLastBarcodeLeavesTheProductWithNoneAndNoPrimary(): void
    {
        $only = $this->registry->attach($this->product, '4006381333931', ProductBarcode::KIND_EAN);

        $this->registry->detach($only);

        self::assertSame([], $this->barcodes->forProduct($this->product));
        self::assertNull($this->barcodes->primaryFor($this->product));
    }

    /** Nothing this bundle does may reach back into `product_core`. */
    public function testDeletingABarcodeLeavesTheProductRowUntouched(): void
    {
        $sku = $this->product->getSku();
        $name = $this->product->getName();

        $barcode = $this->registry->attach($this->product, '4006381333931', ProductBarcode::KIND_EAN);
        $this->registry->detach($barcode);

        $this->em->refresh($this->product);
        self::assertSame($sku, $this->product->getSku());
        self::assertSame($name, $this->product->getName());
        self::assertFalse($this->product->isDeleted());
    }
}
