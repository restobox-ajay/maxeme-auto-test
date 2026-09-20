<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Scan;

use App\Entity\ProductCore;
use BarcodeBundle\Barcode\BarcodeRegistry;
use BarcodeBundle\Entity\ProductBarcode;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;
use WarehouseOpsBundle\Label\LabelCatalog;
use WarehouseOpsBundle\Scan\ScanMatch;
use WarehouseOpsBundle\Scan\ScanResolver;
use WarehouseOpsBundle\Tests\Support\WarehouseOpsTestCase;

/**
 * What a scanned barcode is allowed to mean.
 *
 * The interesting case is the one that means two things: resolving by trying kinds in a fixed order
 * would pick one and be wrong about half the time, silently, and against a bin.
 */
final class ScanResolverTest extends WarehouseOpsTestCase
{
    private ScanResolver $resolver;
    private BarcodeRegistry $barcodes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = self::getContainer()->get(ScanResolver::class);
        $this->barcodes = self::getContainer()->get(BarcodeRegistry::class);
    }

    public function testABinCodeResolvesToItsBin(): void
    {
        $match = $this->resolver->resolve('A-01', $this->warehouse);

        self::assertInstanceOf(ScanMatch::class, $match);
        self::assertSame(ScanMatch::KIND_BIN, $match->kind);
        self::assertInstanceOf(WarehouseLocation::class, $match->entity);
    }

    public function testASkuResolvesToItsProduct(): void
    {
        $match = $this->resolver->resolve('WIDGET-1', $this->warehouse);

        self::assertInstanceOf(ScanMatch::class, $match);
        self::assertSame(ScanMatch::KIND_PRODUCT, $match->kind);
        self::assertInstanceOf(ProductCore::class, $match->entity);
    }

    public function testALotLabelRoundTripsThroughTheEncoderItWasPrintedWith(): void
    {
        $lot = $this->lot('BATCH-9', '2027-01-31');
        $catalog = self::getContainer()->get(LabelCatalog::class);

        $encoded = $catalog->forLot($lot)->encoded;
        self::assertSame(ScanResolver::LOT_PREFIX . $lot->getId(), $encoded);

        $match = $this->resolver->resolve($encoded, $this->warehouse);
        self::assertInstanceOf(ScanMatch::class, $match);
        self::assertSame(ScanMatch::KIND_LOT, $match->kind);
        self::assertInstanceOf(InventoryLot::class, $match->entity);
        self::assertSame($lot->getId(), $match->entity->getId());
    }

    public function testALotIsNamespacedSoANumericSkuCannotBeMistakenForIt(): void
    {
        $numeric = (new ProductCore())
            ->setSku('1')
            ->setName('A product whose SKU is a bare number')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($numeric);
        $this->em->flush();

        // Lot id 1 exists too, and scanning "1" still means the product — because a lot is never
        // printed as a bare id.
        $this->lot('L1');

        $match = $this->resolver->resolve('1', $this->warehouse);
        self::assertSame(ScanMatch::KIND_PRODUCT, $match?->kind);
    }

    public function testASerialResolvesToTheOneRowHoldingIt(): void
    {
        $this->receive(1, $this->binA, null, 'SN-0001');

        $match = $this->resolver->resolve('SN-0001', $this->warehouse);
        self::assertSame(ScanMatch::KIND_SERIAL, $match?->kind);
    }

    public function testAnUnknownBarcodeResolvesToNothing(): void
    {
        self::assertNull($this->resolver->resolve('NOT-A-THING', $this->warehouse));
        self::assertNull($this->resolver->resolve('', $this->warehouse));
        self::assertNull($this->resolver->resolve(ScanResolver::LOT_PREFIX . '9999', $this->warehouse));
    }

    public function testACodeThatMeansTwoThingsIsRefusedAndSaysWhichTwo(): void
    {
        // A product whose SKU is the same string as an existing bin code.
        $clash = (new ProductCore())
            ->setSku('A-01')
            ->setName('Unfortunately named product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($clash);
        $this->em->flush();

        self::assertNull($this->resolver->resolve('A-01', $this->warehouse), 'a guess here is wrong half the time');

        $collision = $this->resolver->describeCollision('A-01', $this->warehouse);
        self::assertNotNull($collision);
        self::assertStringContainsString('a bin code', $collision);
        self::assertStringContainsString('a product SKU', $collision);
    }

    public function testBinCodesAreScopedToTheWarehouseTheScannerIsStandingIn(): void
    {
        // Bin codes are unique per warehouse, not globally: A-01 exists in every building.
        $other = (new \App\Entity\Warehouse())->setName('East')->setStatus('Active');
        $this->em->persist($other);
        $twin = (new WarehouseLocation())->setWarehouse($other)->setCode('A-01')->setSortKey(10);
        $this->em->persist($twin);
        $this->em->flush();

        $match = $this->resolver->resolve('A-01', $this->warehouse);
        self::assertInstanceOf(WarehouseLocation::class, $match?->entity);
        self::assertSame($this->warehouse->getId(), $match->entity->getWarehouse()->getId());
    }

    /**
     * The reason #607 exists. On a goods-in dock you are holding somebody ELSE'S box, and before
     * this the only product identifier in the database was `product_core.sku` — so scanning a label
     * this business printed worked and scanning the one actually on the carton matched nothing.
     */
    public function testAVendorsBarcodeOnTheCartonResolvesToTheProduct(): void
    {
        $this->barcodes->attach($this->product, '4006381333931', ProductBarcode::KIND_EAN);

        $match = $this->resolver->resolve('4006381333931', $this->warehouse);

        self::assertInstanceOf(ScanMatch::class, $match);
        self::assertSame(ScanMatch::KIND_PRODUCT, $match->kind);
        self::assertSame($this->product->getId(), $match->entity->getId());
    }

    /** A supplier's own part number is a way in too, and three suppliers can each have their own. */
    public function testEachSuppliersPartNumberLandsOnTheSameProduct(): void
    {
        $this->barcodes->attach($this->product, 'NS-4471-B', ProductBarcode::KIND_VENDOR_PART, 'Northern Supply');
        $this->barcodes->attach($this->product, 'AC/9912', ProductBarcode::KIND_VENDOR_PART, 'Atlantic Components');

        foreach (['NS-4471-B', 'AC/9912', 'WIDGET-1'] as $code) {
            $match = $this->resolver->resolve($code, $this->warehouse);

            self::assertInstanceOf(ScanMatch::class, $match, sprintf('%s resolved to nothing', $code));
            self::assertSame($this->product->getId(), $match->entity->getId(), sprintf('%s landed on the wrong product', $code));
        }
    }

    /** The flash names the code, its kind and whose it is, so it can be checked against the carton. */
    public function testASuccessfulBarcodeScanIsDescribedByWhatIsPrintedOnTheBox(): void
    {
        $this->barcodes->attach($this->product, 'NS-4471-B', ProductBarcode::KIND_VENDOR_PART, 'Northern Supply');

        $match = $this->resolver->resolve('NS-4471-B', $this->warehouse);

        self::assertInstanceOf(ScanMatch::class, $match);
        self::assertStringContainsString('NS-4471-B', $match->label);
        self::assertStringContainsString('Northern Supply', $match->label);
        self::assertStringContainsString('WIDGET-1', $match->label);
    }

    /**
     * A part number two vendors both use is a true fact about the world, so it is storable — and it
     * must never be guessed between. At goods-in a wrong guess books stock against the wrong product
     * and nobody finds out until a count.
     */
    public function testACodeOnTwoProductsIsRefusedAndNamesBoth(): void
    {
        $second = (new ProductCore())
            ->setSku('SPROCKET-2')
            ->setName('Sprocket')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($second);
        $this->em->flush();

        $this->barcodes->attach($this->product, 'NS-4471-B', ProductBarcode::KIND_VENDOR_PART, 'Northern Supply');
        $this->barcodes->attach($second, 'NS-4471-B', ProductBarcode::KIND_VENDOR_PART, 'Atlantic Components');

        self::assertNull($this->resolver->resolve('NS-4471-B', $this->warehouse), 'a guess here is wrong half the time');

        $collision = $this->resolver->describeCollision('NS-4471-B', $this->warehouse);
        self::assertNotNull($collision);
        self::assertStringContainsString('WIDGET-1', $collision);
        self::assertStringContainsString('SPROCKET-2', $collision);
    }

    /** A barcode that is also a bin code collides across kinds, and says which two kinds. */
    public function testABarcodeThatIsAlsoABinCodeIsRefusedAndSaysWhichTwoKinds(): void
    {
        $this->barcodes->attach($this->product, 'A-01', ProductBarcode::KIND_VENDOR_PART, 'Northern Supply');

        self::assertNull($this->resolver->resolve('A-01', $this->warehouse));

        $collision = $this->resolver->describeCollision('A-01', $this->warehouse);
        self::assertNotNull($collision);
        self::assertStringContainsString('a bin code', $collision);
        self::assertStringContainsString('a product barcode', $collision);
    }

    /** A product nobody has given a barcode to behaves exactly as it did before #607. */
    public function testAProductWithNoBarcodeStillResolvesOnlyByItsSku(): void
    {
        self::assertInstanceOf(ScanMatch::class, $this->resolver->resolve('WIDGET-1', $this->warehouse));
        self::assertNull($this->resolver->resolve('4006381333931', $this->warehouse));
        self::assertNull($this->resolver->describeCollision('4006381333931', $this->warehouse));
    }
}
