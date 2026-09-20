<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Label;

/**
 * The page geometry a label sheet is printed on, in millimetres (#552).
 *
 * ## Why this is a template and not a layout
 *
 * Sheet labels (Avery-style grids on A4 or Letter) and continuous label printers are different page
 * geometries, not different styling. A continuous printer's "page" IS one label; a sheet holds
 * thirty. Hardcoding either one means the other is a rewrite, so both are the same object with
 * different numbers: a continuous roll is simply a grid of one column and one row whose page is the
 * size of the label.
 *
 * That is also why the renderer never asks which kind it is holding.
 *
 * ## The symbology travels with the geometry, and that is on purpose (#608)
 *
 * Which symbology to print is not a property of the thing being labelled — a bin is a bin whether
 * its code is a line of bars or a square — it is a property of the stock and the scanner it will be
 * read by. A 50 x 25 mm bin label read by a phone camera wants a QR; a receiving line with a
 * keyboard-wedge scanner wants Code 128 and would be slower with anything else. Both are choices
 * about the label, so they live on the label's geometry.
 *
 * Code 128 remains the default for every preset. QR is chosen per print, never imposed.
 *
 * ## Module width is part of the geometry
 *
 * A Code 128 module narrower than about 0.25 mm stops reading on cheap hardware, and the symbol
 * width in modules depends on the value, so the label size and the module width have to be chosen
 * together. LabelSheetRenderer shrinks the module to fit rather than letting a long serial run off
 * the edge of the label, and refuses outright when the result would be unscannable — a label that
 * looks fine and does not scan is worse than one that was never printed.
 */
final class LabelTemplate
{
    /** Below this a Code 128 module stops reading reliably on the cheap keyboard-wedge hardware this targets. */
    public const MIN_MODULE_MM = 0.25;

    /**
     * A QR module can be smaller than a Code 128 one and still read: a camera resolves a square from
     * its neighbours in two dimensions, where a wedge scanner has to resolve a bar's width from a
     * single sweep. This is still a floor, and still refuses rather than printing something pretty
     * that will not scan.
     */
    public const MIN_QR_MODULE_MM = 0.18;

    public const CODE128 = 'code128';
    public const QR = 'qr';

    /** @return array<string, string> */
    public static function symbologies(): array
    {
        return [
            self::CODE128 => 'Code 128 — a line of bars, fastest on a wedge scanner',
            self::QR => 'QR — square, reads at any rotation, survives a crease',
        ];
    }

    private function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly float $pageWidthMm,
        public readonly float $pageHeightMm,
        public readonly float $marginTopMm,
        public readonly float $marginLeftMm,
        public readonly int $columns,
        public readonly int $rows,
        public readonly float $labelWidthMm,
        public readonly float $labelHeightMm,
        public readonly float $columnGapMm,
        public readonly float $rowGapMm,
        public readonly string $symbology = self::CODE128,
    ) {
    }

    /** The same geometry, printed in another symbology. Anything unrecognised reads as Code 128. */
    public function withSymbology(string $symbology): self
    {
        return new self(
            $this->key,
            $this->name,
            $this->pageWidthMm,
            $this->pageHeightMm,
            $this->marginTopMm,
            $this->marginLeftMm,
            $this->columns,
            $this->rows,
            $this->labelWidthMm,
            $this->labelHeightMm,
            $this->columnGapMm,
            $this->rowGapMm,
            $symbology === self::QR ? self::QR : self::CODE128,
        );
    }

    public function isQr(): bool
    {
        return $this->symbology === self::QR;
    }

    /**
     * The shipped geometries. Deliberately few: these four cover a US sheet, an A4 sheet and the two
     * die-cut roll sizes anyone buys, and a fifth nobody has asked for is a fifth to keep correct.
     *
     * @return array<string, self>
     */
    public static function presets(): array
    {
        $presets = [
            new self('avery-5160', 'Sheet — Avery 5160 (Letter, 3 × 10)', 215.9, 279.4, 12.7, 4.8, 3, 10, 66.7, 25.4, 3.0, 0.0),
            new self('a4-3x8', 'Sheet — A4 (3 × 8, 70 × 37 mm)', 210.0, 297.0, 4.5, 0.0, 3, 8, 70.0, 37.0, 0.0, 0.0),
            new self('roll-100x50', 'Roll — continuous, 100 × 50 mm', 100.0, 50.0, 0.0, 0.0, 1, 1, 100.0, 50.0, 0.0, 0.0),
            new self('roll-50x25', 'Roll — continuous, 50 × 25 mm', 50.0, 25.0, 0.0, 0.0, 1, 1, 50.0, 25.0, 0.0, 0.0),
        ];

        $byKey = [];
        foreach ($presets as $preset) {
            $byKey[$preset->key] = $preset;
        }

        return $byKey;
    }

    public static function byKey(string $key): self
    {
        return self::presets()[$key] ?? self::presets()['avery-5160'];
    }

    /**
     * A geometry typed into the form.
     *
     * Everything is clamped into a range a printer can actually produce: a label wider than its page
     * or a grid of zero columns renders nothing and reports success, which is the worst outcome
     * available on a screen whose whole job is to put ink on adhesive paper.
     */
    public static function custom(
        float $pageWidthMm,
        float $pageHeightMm,
        float $marginTopMm,
        float $marginLeftMm,
        int $columns,
        int $rows,
        float $labelWidthMm,
        float $labelHeightMm,
        float $columnGapMm,
        float $rowGapMm,
    ): self {
        $pageWidthMm = self::clampMm($pageWidthMm, 20.0, 1000.0);
        $pageHeightMm = self::clampMm($pageHeightMm, 10.0, 1000.0);

        return new self(
            'custom',
            'Custom',
            $pageWidthMm,
            $pageHeightMm,
            self::clampMm($marginTopMm, 0.0, $pageHeightMm),
            self::clampMm($marginLeftMm, 0.0, $pageWidthMm),
            max(1, min(20, $columns)),
            max(1, min(40, $rows)),
            self::clampMm($labelWidthMm, 10.0, $pageWidthMm),
            self::clampMm($labelHeightMm, 8.0, $pageHeightMm),
            self::clampMm($columnGapMm, 0.0, 50.0),
            self::clampMm($rowGapMm, 0.0, 50.0),
        );
    }

    public function perPage(): int
    {
        return $this->columns * $this->rows;
    }

    /** Top-left corner of the slot at $index (0-based within the page), in millimetres. */
    public function slotOrigin(int $index): array
    {
        $column = $index % $this->columns;
        $row = intdiv($index, $this->columns);

        return [
            $this->marginLeftMm + $column * ($this->labelWidthMm + $this->columnGapMm),
            $this->marginTopMm + $row * ($this->labelHeightMm + $this->rowGapMm),
        ];
    }

    /** The `@page size` value dompdf reads. */
    public function pageSizeCss(): string
    {
        return sprintf('%.2fmm %.2fmm', $this->pageWidthMm, $this->pageHeightMm);
    }

    private static function clampMm(float $value, float $min, float $max): float
    {
        return round(max($min, min($max, $value)), 2);
    }
}
