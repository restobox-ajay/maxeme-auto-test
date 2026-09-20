<?php

declare(strict_types=1);

namespace BarcodeBundle\Tests\Barcode;

use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use App\Entity\ProductCore;
use App\Tests\DoctrineIntegrationTestCase;
use BarcodeBundle\Barcode\BarcodeDirectory;
use BarcodeBundle\Barcode\BarcodeRegistry;
use BarcodeBundle\Barcode\ProductLookup;
use BarcodeBundle\Entity\ProductBarcode;

/**
 * "What product is this string?", which is the question every scan screen asks.
 *
 * Two properties matter more than the happy path:
 *
 *  - a product with NO barcodes resolves exactly as it did before this table existed, by SKU
 *  - a string naming TWO products resolves to nothing and names both, rather than picking one
 */
final class ProductLookupTest extends DoctrineIntegrationTestCase
{
    private ProductLookup $lookup;
    private BarcodeRegistry $registry;
    private ProductCore $widget;
    private ProductCore $sprocket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lookup = self::getContainer()->get(ProductLookup::class);
        $this->registry = self::getContainer()->get(BarcodeRegistry::class);

        $this->widget = $this->product('WIDGET-1', 'Widget');
        $this->sprocket = $this->product('SPROCKET-2', 'Sprocket');
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

    /** The behaviour before #607, unchanged: the SKU is still a product identifier. */
    public function testAProductWithNoBarcodesStillResolvesByItsSku(): void
    {
        self::assertSame([$this->widget->getId()], array_map(
            static fn (ProductCore $p): ?int => $p->getId(),
            $this->lookup->products('WIDGET-1'),
        ));
    }

    public function testAnUnknownStringResolvesToNothing(): void
    {
        self::assertSame([], $this->lookup->products('NOT-A-THING'));
        self::assertSame([], $this->lookup->products(''));
        self::assertSame([], $this->lookup->products('   '));
    }

    /** The whole point of #607: the vendor's carton scans. */
    public function testAVendorsUpcOnTheCartonResolvesToTheProduct(): void
    {
        $this->registry->attach($this->widget, '4006381333931', ProductBarcode::KIND_EAN);

        $found = $this->lookup->products('4006381333931');

        self::assertCount(1, $found);
        self::assertSame($this->widget->getId(), $found[0]->getId());
    }

    public function testASuppliersOwnPartNumberResolvesToTheSameProductAsItsUpc(): void
    {
        $this->registry->attach($this->widget, '4006381333931', ProductBarcode::KIND_EAN);
        $this->registry->attach($this->widget, 'NS-4471-B', ProductBarcode::KIND_VENDOR_PART, 'Northern Supply');
        $this->registry->attach($this->widget, 'AC/9912', ProductBarcode::KIND_VENDOR_PART, 'Atlantic Components');

        foreach (['4006381333931', 'NS-4471-B', 'AC/9912', 'WIDGET-1'] as $code) {
            $found = $this->lookup->products($code);
            self::assertCount(1, $found, sprintf('%s should name exactly one product', $code));
            self::assertSame($this->widget->getId(), $found[0]->getId(), sprintf('%s landed on the wrong product', $code));
        }
    }

    /**
     * Two vendors using one part number for two things is a real case. Ranking one above the other
     * would resolve it silently and be wrong about half the time — at goods-in that books stock
     * against the wrong product and nobody finds out until a count.
     */
    public function testOneCodeOnTwoProductsNamesBothAndResolvesToNeither(): void
    {
        $this->registry->attach($this->widget, 'NS-4471-B', ProductBarcode::KIND_VENDOR_PART, 'Northern Supply');
        $this->registry->attach($this->sprocket, 'NS-4471-B', ProductBarcode::KIND_VENDOR_PART, 'Atlantic Components');

        self::assertCount(2, $this->lookup->products('NS-4471-B'));

        $message = $this->lookup->describeAmbiguity('NS-4471-B');
        self::assertNotNull($message);
        self::assertStringContainsString('WIDGET-1', $message);
        self::assertStringContainsString('SPROCKET-2', $message);
        self::assertStringContainsString('Nothing was recorded', $message);
    }

    /** A SKU that is also somebody else's barcode is the same refusal, wearing a different hat. */
    public function testASkuThatIsAlsoAnotherProductsBarcodeIsAmbiguous(): void
    {
        $this->registry->attach($this->sprocket, 'WIDGET-1', ProductBarcode::KIND_VENDOR_PART, 'Northern Supply');

        self::assertCount(2, $this->lookup->products('WIDGET-1'));
        self::assertNotNull($this->lookup->describeAmbiguity('WIDGET-1'));
        self::assertTrue($this->lookup->isSku('WIDGET-1'));
    }

    public function testNoAmbiguityIsReportedWhenThereIsOnlyOneAnswer(): void
    {
        $this->registry->attach($this->widget, '4006381333931', ProductBarcode::KIND_EAN);

        self::assertNull($this->lookup->describeAmbiguity('4006381333931'));
        self::assertNull($this->lookup->describeAmbiguity('WIDGET-1'));
        self::assertNull($this->lookup->describeAmbiguity('NOT-A-THING'));
    }

    /**
     * The sentence a receiving desk reads back. Scanning a vendor carton and being told "WIDGET-1"
     * gives an operator nothing to check against the box in their hands; naming the code, its kind
     * and whose it is gives them everything.
     */
    public function testAScannedBarcodeIsDescribedByWhatIsPrintedOnTheBoxAndWhoseItIs(): void
    {
        $this->registry->attach($this->widget, 'NS-4471-B', ProductBarcode::KIND_VENDOR_PART, 'Northern Supply');

        $described = $this->lookup->describe('NS-4471-B', $this->widget);

        self::assertStringContainsString('NS-4471-B', $described);
        self::assertStringContainsString('Northern Supply', $described);
        self::assertStringContainsString('WIDGET-1', $described);

        self::assertSame('WIDGET-1 — Widget', $this->lookup->describe('WIDGET-1', $this->widget));
    }

    public function testADeletedProductsBarcodeStopsResolving(): void
    {
        $this->registry->attach($this->widget, '4006381333931', ProductBarcode::KIND_EAN);
        $this->widget->setDeleted(true);
        $this->em->flush();

        self::assertSame([], $this->lookup->products('4006381333931'));
        self::assertSame([], $this->lookup->products('WIDGET-1'));
    }

    /**
     * The bundle's own kill switch. With it Inactive, every barcode answer is empty and the SKU
     * match is the whole answer — which is precisely the application as it was before #607, with
     * every row still sitting in the table waiting to be switched back on.
     */
    public function testWithTheBundleInactiveOnlyTheSkuResolvesAndNoRowIsLost(): void
    {
        $this->registry->attach($this->widget, '4006381333931', ProductBarcode::KIND_EAN);

        // Switched off through the one activation path. A fresh BundleStatus used to be safe here
        // because nothing had created one — absence of a row was the enabled default. Every
        // installed bundle now gets an explicit Active row in setUp() (DoctrineIntegrationTestCase),
        // so a second insert for the same source trips the UNIQUE index instead of switching
        // anything off.
        self::getContainer()->get(BundleStatusRepository::class)->deactivate(BarcodeDirectory::SOURCE);
        $this->em->clear();

        self::assertSame([], $this->lookup->products('4006381333931'), 'a barcode must not resolve with the bundle off');
        self::assertCount(1, $this->lookup->products('WIDGET-1'), 'the SKU must still resolve, exactly as before #607');

        $directory = self::getContainer()->get(BarcodeDirectory::class);
        self::assertFalse($directory->isActive());
        self::assertNull($directory->primaryFor($this->widget), 'a label must fall back to the SKU with the bundle off');
        self::assertSame([], $directory->forProduct($this->widget));

        // The row is still there. Switching the bundle back on restores every barcode.
        self::assertCount(1, self::getContainer()->get(\BarcodeBundle\Repository\ProductBarcodeRepository::class)->forProduct($this->widget));
    }
}
