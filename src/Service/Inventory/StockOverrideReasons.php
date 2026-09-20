<?php

declare(strict_types=1);

namespace App\Service\Inventory;

/**
 * The reasons posted with a save, one per document row, with blank meaning ABSENT (#326).
 *
 * The sell-side counterpart of the `short_dated_reason` box on the receiving form, and it exists as
 * its own object for one reason: **a blank reason has to read as no reason in every layer that
 * touches it, and the only way to be sure of that is to have one layer.** A reason trimmed to
 * nothing in the controller but passed through as `''` by the thing that decides — or the reverse —
 * is a save that goes through with an empty string in the record, which is precisely the hole this
 * whole mechanism exists to close: an override nobody can explain is the same as no override
 * record at all.
 *
 * So blanking happens HERE, once, in {@see self::nullable()}, and the controllers hand this object
 * the raw post. The controllers ALSO refuse to write a row without one — see
 * {@see StockOverrideRecorder::record()} — which is a second gate on the same fact rather than a
 * second implementation of it: the recorder asks this object, it does not re-parse the post.
 *
 * Whitespace counts as blank. `"   "` is a person tabbing past the box, not a reason, and
 * `trim()` is what the receiving side's `ReceivingRequest::nullable()` has always done.
 */
final class StockOverrideReasons
{
    /**
     * @param array<int, string> $byLineIndex posted row index => a reason with something in it
     */
    private function __construct(private readonly array $byLineIndex)
    {
    }

    /**
     * Read off the POSTED ROWS, whatever shape the screen posts them in.
     *
     * The order form posts `lines[7][stock_override_reason]` and hands those rows straight over.
     * The standalone invoice form posts parallel `line_*[]` arrays and folds the reason into its own
     * row objects first, under the key it names here — which is what keeps the reason's index in
     * step with the quantity's after that screen drops a removed row and re-indexes.
     *
     * Keyed by the row's key in that array rather than by any line's database id: a create has no
     * ids yet, and an edit matches rows to lines only much further down the save. The same key is
     * what {@see StockShortfall::$lineIndexes} carries, so the two agree by construction.
     *
     * @param array<int|string, mixed> $postedLines rows exactly as the screen assembled them
     */
    public static function fromPostedLines(array $postedLines, string $field = 'stock_override_reason'): self
    {
        $reasons = [];

        foreach ($postedLines as $index => $line) {
            if (!is_array($line)) {
                continue;
            }

            $reason = self::nullable($line[$field] ?? null);
            if ($reason !== null) {
                $reasons[(int) $index] = $reason;
            }
        }

        return new self($reasons);
    }

    /**
     * The reason covering one shortfall, or null when nobody gave one.
     *
     * A shortfall is a (product, region) GROUP and may span several rows — see
     * {@see StockShortfall} — so a reason on any one of its rows answers for it. The FIRST is
     * taken, in posted order, which is the row an operator reading the form top to bottom would
     * have typed into. One decision about one product in one region gets one answer, the same way
     * one pallet at the dock gets one reason box whichever of two findings raised it.
     */
    public function forShortfall(StockShortfall $shortfall): ?string
    {
        foreach ($shortfall->lineIndexes as $index) {
            if (isset($this->byLineIndex[$index])) {
                return $this->byLineIndex[$index];
            }
        }

        return null;
    }

    /**
     * Blank is absent, and whitespace is blank.
     *
     * The one place that decides it, for the reason the class docblock gives. Length is capped at
     * the column's 255 rather than refused: a reason too long to store is still a reason, and
     * turning a save away over the 256th character would be the mechanism obstructing exactly the
     * person it is asking to explain themselves.
     */
    private static function nullable(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
