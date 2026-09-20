<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Label;

use PHPUnit\Framework\TestCase;
use WarehouseOpsBundle\Label\LabelSheetRenderer;
use WarehouseOpsBundle\Label\LabelSpec;
use WarehouseOpsBundle\Label\LabelTemplate;

/**
 * The sheet geometry, asserted on the HTML the PDF is built from.
 *
 * A label sheet fails in ways that are invisible until somebody is holding paper: a label off the
 * edge of the page, a page break in the wrong place, a barcode shrunk past the point a scanner can
 * read it. All three are arithmetic, so all three are checkable here rather than at a printer.
 */
final class LabelSheetRendererTest extends TestCase
{
    private LabelSheetRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new LabelSheetRenderer();
    }

    private function spec(string $value = 'A01-02-03'): LabelSpec
    {
        return new LabelSpec(LabelSpec::KIND_BIN, $value, 'West Warehouse', 'Pick');
    }

    public function testASheetBreaksAfterItsSlotsAreFull(): void
    {
        $template = LabelTemplate::byKey('avery-5160');
        self::assertSame(30, $template->perPage());

        $html = $this->renderer->html(array_fill(0, 31, $this->spec()), $template);

        self::assertSame(2, substr_count($html, 'class="page"'), '31 labels on a 30-up sheet is two pages');
        self::assertSame(31, substr_count($html, 'class="label"'));
    }

    public function testEveryLabelLandsInsideThePage(): void
    {
        $template = LabelTemplate::byKey('avery-5160');
        $html = $this->renderer->html(array_fill(0, 30, $this->spec()), $template);

        preg_match_all('/left:([\d.]+)mm;top:([\d.]+)mm;width:([\d.]+)mm;height:([\d.]+)mm/', $html, $matches, PREG_SET_ORDER);

        self::assertCount(30, $matches);
        foreach ($matches as [, $left, $top, $width, $height]) {
            self::assertLessThanOrEqual($template->pageWidthMm, (float) $left + (float) $width, 'a label ran off the right edge');
            self::assertLessThanOrEqual($template->pageHeightMm, (float) $top + (float) $height, 'a label ran off the bottom');
        }
    }

    public function testAContinuousRollIsOneLabelPerPage(): void
    {
        $template = LabelTemplate::byKey('roll-100x50');

        self::assertSame(1, $template->perPage());
        self::assertSame('100.00mm 50.00mm', $template->pageSizeCss(), 'the page IS the label');

        $html = $this->renderer->html(array_fill(0, 3, $this->spec()), $template);
        self::assertSame(3, substr_count($html, 'class="page"'));
    }

    public function testAValueTooLongForTheStockSaysSoInsteadOfPrintingSomethingUnscannable(): void
    {
        $tiny = LabelTemplate::custom(25, 15, 0, 0, 1, 1, 25, 15, 0, 0);
        $long = $this->spec('THIS-SERIAL-IS-FAR-TOO-LONG-FOR-A-25MM-LABEL-000001');

        self::assertNull($this->renderer->moduleMm($long, $tiny));

        $html = $this->renderer->html([$long], $tiny);
        self::assertStringContainsString('Too long for this label size', $html);
        self::assertStringNotContainsString('class="bar"', $html, 'nothing that cannot be scanned should be printed as bars');
    }

    public function testTheModuleNeverShrinksBelowWhatCheapScannersCanRead(): void
    {
        $template = LabelTemplate::byKey('roll-50x25');
        $module = $this->renderer->moduleMm($this->spec('WIDGET-100'), $template);

        self::assertNotNull($module);
        self::assertGreaterThanOrEqual(LabelTemplate::MIN_MODULE_MM, $module);
    }

    public function testAnUnencodableValueIsRefusedOnTheLabelRatherThanThrowing(): void
    {
        $spec = new LabelSpec(LabelSpec::KIND_PRODUCT, "bad\tvalue", 'Product');

        self::assertFalse($spec->isPrintable());
        self::assertStringContainsString(
            'Code 128 cannot encode',
            $this->renderer->html([$spec], LabelTemplate::byKey('roll-100x50')),
        );
    }

    public function testCustomGeometryIsClampedIntoSomethingAPrinterCanProduce(): void
    {
        // A grid of zero columns and a label wider than its page both render nothing and report
        // success, which is the worst outcome available on a screen whose job is putting ink on
        // adhesive paper.
        $template = LabelTemplate::custom(100, 50, 0, 0, 0, 0, 500, 500, 0, 0);

        self::assertSame(1, $template->columns);
        self::assertSame(1, $template->rows);
        self::assertLessThanOrEqual($template->pageWidthMm, $template->labelWidthMm);
        self::assertLessThanOrEqual($template->pageHeightMm, $template->labelHeightMm);
    }
}
