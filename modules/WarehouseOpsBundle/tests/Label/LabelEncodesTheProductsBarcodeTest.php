<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Label;

use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use BarcodeBundle\Barcode\BarcodeDirectory;
use BarcodeBundle\Barcode\BarcodeRegistry;
use BarcodeBundle\Entity\ProductBarcode;
use WarehouseOpsBundle\Label\LabelCatalog;
use WarehouseOpsBundle\Label\LabelSheetRenderer;
use WarehouseOpsBundle\Label\LabelSpec;
use WarehouseOpsBundle\Label\LabelTemplate;
use WarehouseOpsBundle\Tests\Support\WarehouseOpsTestCase;

/**
 * What a product label encodes now that a product can carry a real barcode (#607, #608).
 *
 * The fallback is the assertion that matters most. #552 printed the SKU because there was nowhere
 * else to look, and a product nobody has attached a code to must go on printing exactly that —
 * otherwise this change would silently alter every label in a catalogue of seven thousand products
 * to make one of them better.
 */
final class LabelEncodesTheProductsBarcodeTest extends WarehouseOpsTestCase
{
    private LabelCatalog $catalog;
    private BarcodeRegistry $barcodes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = self::getContainer()->get(LabelCatalog::class);
        $this->barcodes = self::getContainer()->get(BarcodeRegistry::class);
    }

    /** Byte for byte what this method produced before `product_barcode` existed. */
    public function testAProductWithNoBarcodeStillEncodesItsSku(): void
    {
        $spec = $this->catalog->forProduct($this->product);

        self::assertSame(LabelSpec::KIND_PRODUCT, $spec->kind);
        self::assertSame('WIDGET-1', $spec->encoded);
        self::assertSame('Widget', $spec->title);
    }

    /**
     * The point of #608's third gap: a product that already carries a manufacturer's GTIN should not
     * get a second, private barcode stuck on top of it.
     */
    public function testAProductWithAPrimaryBarcodeEncodesThatInsteadOfTheSku(): void
    {
        $this->barcodes->attach($this->product, '4006381333931', ProductBarcode::KIND_EAN);

        $spec = $this->catalog->forProduct($this->product);

        self::assertSame('4006381333931', $spec->encoded);
        self::assertStringContainsString('WIDGET-1', $spec->subtitle, 'the SKU stays readable on the label');
    }

    public function testChangingWhichBarcodeIsPrimaryChangesWhatTheLabelEncodes(): void
    {
        $this->barcodes->attach($this->product, '4006381333931', ProductBarcode::KIND_EAN);
        $case = $this->barcodes->attach($this->product, '10012345678902', ProductBarcode::KIND_GTIN);

        $this->barcodes->makePrimary($case);

        self::assertSame('10012345678902', $this->catalog->forProduct($this->product)->encoded);
    }

    /** Removing the last barcode puts the label back where it started. */
    public function testDeletingTheLastBarcodeReturnsTheLabelToTheSku(): void
    {
        $only = $this->barcodes->attach($this->product, '4006381333931', ProductBarcode::KIND_EAN);
        self::assertSame('4006381333931', $this->catalog->forProduct($this->product)->encoded);

        $this->barcodes->detach($only);

        self::assertSame('WIDGET-1', $this->catalog->forProduct($this->product)->encoded);
    }

    /** The bundle's kill switch reaches the printer too, without touching a single stored row. */
    public function testWithTheBarcodeBundleInactiveTheLabelEncodesTheSkuAgain(): void
    {
        $this->barcodes->attach($this->product, '4006381333931', ProductBarcode::KIND_EAN);

        // Switched off through the one activation path. A fresh BundleStatus used to be safe here
        // because nothing had created one — absence of a row was the enabled default. Every
        // installed bundle now gets an explicit Active row in setUp() (DoctrineIntegrationTestCase),
        // so a second insert for the same source trips the UNIQUE index instead of switching
        // anything off.
        self::getContainer()->get(BundleStatusRepository::class)->deactivate(BarcodeDirectory::SOURCE);
        $this->em->clear();

        self::assertSame('WIDGET-1', $this->catalog->forProduct($this->product)->encoded);
    }

    /** Both symbologies render the same value from the same spec — the template chooses, not the label. */
    public function testTheSameLabelPrintsAsCode128OrAsAQrDependingOnTheTemplate(): void
    {
        $this->barcodes->attach($this->product, '4006381333931', ProductBarcode::KIND_EAN);

        $renderer = self::getContainer()->get(LabelSheetRenderer::class);
        $spec = $this->catalog->forProduct($this->product);
        $geometry = LabelTemplate::byKey('roll-100x50');

        $bars = $renderer->html([$spec], $geometry);
        $squares = $renderer->html([$spec], $geometry->withSymbology(LabelTemplate::QR));

        self::assertStringContainsString('class="bars"', $bars);
        self::assertStringNotContainsString('class="qr"', $bars);

        self::assertStringContainsString('class="qr"', $squares);
        self::assertStringNotContainsString('class="bars"', $squares);

        // Same encoded value printed under both, so the human-readable line agrees with the symbol.
        self::assertStringContainsString('4006381333931', $bars);
        self::assertStringContainsString('4006381333931', $squares);
    }

    /** An unrecognised symbology reads as Code 128 rather than as nothing at all. */
    public function testAnUnknownSymbologyFallsBackToCode128(): void
    {
        $geometry = LabelTemplate::byKey('roll-100x50')->withSymbology('datamatrix');

        self::assertFalse($geometry->isQr());
        self::assertSame(LabelTemplate::CODE128, $geometry->symbology);
    }

    /**
     * The floor exists for the same reason Code 128's does: a symbol printed too small looks correct
     * and reads as nothing, which is worse than a label that was never printed.
     */
    public function testAQrTooSmallForTheStockIsRefusedRatherThanPrintedUnscannable(): void
    {
        $renderer = self::getContainer()->get(LabelSheetRenderer::class);
        $spec = new LabelSpec(LabelSpec::KIND_PRODUCT, str_repeat('9', 60), 'Long');
        $tiny = LabelTemplate::custom(12, 10, 0, 0, 1, 1, 12, 10, 0, 0)->withSymbology(LabelTemplate::QR);

        self::assertNull($renderer->moduleMm($spec, $tiny));
        self::assertStringContainsString('Too long for this label size', $renderer->html([$spec], $tiny));
    }
}
