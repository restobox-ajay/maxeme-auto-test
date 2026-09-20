<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Label;

use BarcodeBundle\Symbology\Code128;
use BarcodeBundle\Symbology\Qr;

/**
 * Turns labels into a PDF sized for the label stock (#552).
 *
 * ## Why a PDF and not a browser print of a web page
 *
 * A browser print has no reliable relationship to millimetres — the page size, the margins and the
 * scaling are all the operator's browser dialog, and a barcode that comes out at 94% does not scan.
 * A PDF with an explicit `@page size` and every element positioned in millimetres comes out the same
 * on every machine, which is the only thing a label is for.
 *
 * ## The bars are absolutely-positioned rectangles
 *
 * Not an image, not SVG. dompdf renders a positioned block with a background colour exactly, at any
 * size, with no rasterisation step to blur an edge and no dependency on GD or an SVG library being
 * compiled in. A Code 128 symbol is at most a couple of hundred rectangles, which dompdf places
 * without complaint.
 *
 * A QR is drawn the same way, one rectangle per horizontal RUN of dark modules rather than one per
 * module (#608). A version 4 symbol is 1,089 modules and a sheet of thirty of them would be 32,000
 * elements; collapsing each row's runs turns that into a few hundred per label, which is the same
 * order as a Code 128 symbol and is why a QR sheet renders as fast as a barcode one.
 *
 * ## Reprinting is free
 *
 * Nothing here writes anything. Printing is not moving, so no movement rows, no counters, no print
 * log — a smudged label is the most common reason anyone opens this screen and a reprint that had to
 * be justified would be a reprint nobody does.
 */
final class LabelSheetRenderer
{
    /** Padding inside each label, so bars never touch the die-cut edge. */
    private const PADDING_MM = 3.0;

    /** A module wider than this wastes label on a short value without helping any scanner. */
    private const MAX_MODULE_MM = 0.8;

    /**
     * The share of the label's height a QR may take, leaving the rest for the two text lines.
     *
     * A QR is square, so unlike a bar height this cannot simply be "half": the symbol is limited by
     * whichever of the label's two dimensions runs out first, and on a 100 x 50 mm roll that is
     * always the height.
     */
    private const QR_HEIGHT_SHARE = 0.66;

    /** The four-module white margin the QR standard requires on all four sides. */
    private const QR_QUIET_MODULES = 4;

    /**
     * The sheet as HTML, in millimetres throughout.
     *
     * Split out from pdf() because this is the part worth asserting in a test: that N specs produce
     * N labels, that they land in the right slots, that the page breaks after `perPage()`, and that
     * an unscannable label says so on its face instead of printing blank.
     *
     * @param list<LabelSpec> $specs
     */
    public function html(array $specs, LabelTemplate $template): string
    {
        $perPage = $template->perPage();
        $pages = [];

        foreach (array_chunk($specs, $perPage) as $pageSpecs) {
            $labels = '';
            foreach ($pageSpecs as $index => $spec) {
                [$x, $y] = $template->slotOrigin($index);
                $labels .= $this->label($spec, $template, $x, $y);
            }
            $pages[] = sprintf('<div class="page">%s</div>', $labels);
        }

        if ($pages === []) {
            $pages[] = '<div class="page"><div class="empty">Nothing selected to print.</div></div>';
        }

        return sprintf(
            '<html><head><meta charset="utf-8"><style>%s</style></head><body>%s</body></html>',
            $this->css($template),
            implode('', $pages),
        );
    }

    /** @param list<LabelSpec> $specs */
    public function pdf(array $specs, LabelTemplate $template): string
    {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($this->html($specs, $template));
        // Deliberately NOT setPaper(): the size comes from the stylesheet's `@page`, which is the
        // only place the label stock's real dimensions are known. Calling setPaper() here would
        // override it with a paper name and put a 100 × 50 mm roll label on an A4 page.
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * How wide one module can be on this template for this value, or null when the value cannot be
     * printed legibly at all.
     *
     * Shrink-to-fit rather than overflow, with a hard floor: below LabelTemplate::MIN_MODULE_MM a
     * Code 128 symbol stops reading on the cheap keyboard-wedge hardware this targets, and a label
     * that looks right and does not scan is worse than one that was never printed.
     */
    public function moduleMm(LabelSpec $spec, LabelTemplate $template): ?float
    {
        if (!$spec->isPrintable()) {
            return null;
        }

        if ($template->isQr()) {
            return $this->qrModuleMm($spec, $template);
        }

        $available = $template->labelWidthMm - 2 * self::PADDING_MM;
        $modules = Code128::widthInModules($spec->encoded);

        // Two quiet zones of ten modules each, which is what the symbology requires either side.
        $module = $available / ($modules + 20);

        if ($module < LabelTemplate::MIN_MODULE_MM) {
            return null;
        }

        return min($module, self::MAX_MODULE_MM);
    }

    /**
     * How wide one QR module can be, or null when the symbol cannot be printed legibly here.
     *
     * Constrained by BOTH dimensions, because the symbol is square: the width available after
     * padding, and the share of the height left over once the two text lines have theirs. Whichever
     * is smaller wins, and the floor is checked afterwards for the same reason it is on Code 128 —
     * a QR printed too small looks correct and reads as nothing.
     */
    private function qrModuleMm(LabelSpec $spec, LabelTemplate $template): ?float
    {
        if (!Qr::isEncodable($spec->encoded)) {
            return null;
        }

        $version = Qr::versionFor($spec->encoded);
        if ($version === null) {
            return null;
        }

        $across = Qr::sizeFor($version) + 2 * self::QR_QUIET_MODULES;

        $side = min(
            $template->labelWidthMm - 2 * self::PADDING_MM,
            ($template->labelHeightMm - 2 * self::PADDING_MM) * self::QR_HEIGHT_SHARE,
        );

        $module = $side / $across;

        return $module < LabelTemplate::MIN_QR_MODULE_MM ? null : $module;
    }

    private function label(LabelSpec $spec, LabelTemplate $template, float $x, float $y): string
    {
        $module = $this->moduleMm($spec, $template);

        $inner = $module === null
            ? sprintf(
                '<div class="unprintable">%s<br><small>%s</small></div>',
                htmlspecialchars($spec->title, \ENT_QUOTES),
                $spec->isPrintable()
                    ? 'Too long for this label size — use larger stock.'
                    : 'Contains characters Code 128 cannot encode.',
            )
            : ($template->isQr()
                ? $this->qr($spec, $template, $module)
                : $this->barcode($spec, $template, $module));

        return sprintf(
            '<div class="label" style="left:%.2fmm;top:%.2fmm;width:%.2fmm;height:%.2fmm;">%s</div>',
            $x,
            $y,
            $template->labelWidthMm,
            $template->labelHeightMm,
            $inner,
        );
    }

    private function barcode(LabelSpec $spec, LabelTemplate $template, float $module): string
    {
        // Half the label height for bars, the rest for the two text lines — the proportion every
        // die-cut label uses, and the reason it is a proportion is that the same template has to
        // work at 25 mm and at 50 mm tall.
        $barHeight = max(6.0, $template->labelHeightMm * 0.45);
        $quietZone = 10 * $module;

        $bars = '';
        foreach (Code128::bars($spec->encoded) as [$offset, $width]) {
            $bars .= sprintf(
                '<div class="bar" style="left:%.3fmm;width:%.3fmm;height:%.2fmm;"></div>',
                $quietZone + $offset * $module,
                $width * $module,
                $barHeight,
            );
        }

        return sprintf(
            '<div class="title">%s</div><div class="bars" style="height:%.2fmm;">%s</div><div class="value">%s</div>%s',
            htmlspecialchars($spec->title, \ENT_QUOTES),
            $barHeight,
            $bars,
            htmlspecialchars($spec->encoded, \ENT_QUOTES),
            $spec->subtitle === '' ? '' : sprintf('<div class="subtitle">%s</div>', htmlspecialchars($spec->subtitle, \ENT_QUOTES)),
        );
    }

    /**
     * One QR, as one absolutely-positioned rectangle per horizontal run of dark modules.
     *
     * The four-module quiet zone is drawn as padding around the grid rather than left to the label's
     * own margin: the standard requires it, and a QR butted up against the die-cut edge of the next
     * label on the sheet has no quiet zone at all on that side.
     */
    private function qr(LabelSpec $spec, LabelTemplate $template, float $module): string
    {
        $matrix = Qr::matrix($spec->encoded);
        $size = \count($matrix);
        $side = ($size + 2 * self::QR_QUIET_MODULES) * $module;
        $offset = self::QR_QUIET_MODULES * $module;

        $cells = '';
        foreach ($matrix as $row => $modules) {
            $run = 0;
            // One past the end, so a run that reaches the right edge is closed by the same branch.
            for ($column = 0; $column <= $size; $column++) {
                $dark = $column < $size && $modules[$column];

                if ($dark) {
                    $run++;

                    continue;
                }

                if ($run > 0) {
                    $cells .= sprintf(
                        '<div class="cell" style="left:%.3fmm;top:%.3fmm;width:%.3fmm;height:%.3fmm;"></div>',
                        $offset + ($column - $run) * $module,
                        $offset + $row * $module,
                        $run * $module,
                        $module,
                    );
                    $run = 0;
                }
            }
        }

        return sprintf(
            '<div class="title">%s</div><div class="qr" style="width:%.2fmm;height:%.2fmm;">%s</div><div class="value">%s</div>%s',
            htmlspecialchars($spec->title, \ENT_QUOTES),
            $side,
            $side,
            $cells,
            htmlspecialchars($spec->encoded, \ENT_QUOTES),
            $spec->subtitle === '' ? '' : sprintf('<div class="subtitle">%s</div>', htmlspecialchars($spec->subtitle, \ENT_QUOTES)),
        );
    }

    private function css(LabelTemplate $template): string
    {
        return sprintf(
            '@page { size: %s; margin: 0; }'
            . 'body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; }'
            . '.page { position: relative; width: %.2fmm; height: %.2fmm; page-break-after: always; }'
            . '.label { position: absolute; overflow: hidden; padding: %.2fmm; box-sizing: border-box; }'
            . '.bars { position: relative; }'
            . '.bar { position: absolute; top: 0; background: #000; }'
            . '.qr { position: relative; }'
            . '.cell { position: absolute; background: #000; }'
            . '.title { font-size: 7pt; font-weight: bold; margin-bottom: 1mm; }'
            . '.value { font-size: 7pt; letter-spacing: 0.4pt; margin-top: 0.8mm; }'
            . '.subtitle { font-size: 6pt; color: #333; }'
            . '.unprintable { font-size: 7pt; color: #900; }'
            . '.empty { padding: 10mm; font-size: 10pt; }',
            $template->pageSizeCss(),
            $template->pageWidthMm,
            $template->pageHeightMm,
            self::PADDING_MM,
        );
    }
}
