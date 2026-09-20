<?php

declare(strict_types=1);

namespace ProcurementBundle\Receiving;

/**
 * A block of serial numbers as a receiver actually supplies them: one per line (item 67).
 *
 * ## Why a textarea of one-per-line, and not a repeated input
 *
 * A serial is one unit, so seventy serialised units are seventy receipt lines. The receipt form now
 * grows a row on demand, which answers "ten serials means ten rows" — but pressing *Add a line*
 * seventy times is its own defect, and the walkthrough that filed this said so. The question was
 * what the bulk shape should be, and the evidence points at exactly one answer:
 *
 *  - a vendor's serial manifest arrives as a COLUMN — a spreadsheet, a CSV, a PDF table. Copying a
 *    column and pasting it gives newline-separated text, every time;
 *  - a hardware wedge scanner types what it read and then presses Enter. Pointed at a textarea,
 *    Enter inserts a NEWLINE instead of submitting the form, so a receiver can stand at the dock and
 *    scan seventy units into one box, with no JavaScript and no page round-trip per unit. Pointed at
 *    an `<input>` it would submit after the first one;
 *  - a tab separates a spreadsheet ROW the same way a newline separates a column, and neither
 *    character can occur inside a serial, so both are split on. A comma and a space are NOT: real
 *    serials contain both.
 *
 * So the same control accepts a paste and a scanning session, which no other no-JS control does.
 *
 * ## What it refuses
 *
 * Nothing here refuses. It parses, and the caller decides — duplicates are named by
 * ReceivingService, which is where the rest of the receipt's integrity rules live and where a
 * refusal can name the line it refused on.
 */
final class SerialList
{
    /**
     * The most serials one line may carry in one submission.
     *
     * Not a business rule — a ceiling on what a single form POST can turn into receipt lines and
     * movement rows, so a pasted file cannot become a hundred thousand of them. Crossing it is
     * reported by name rather than silently truncated, because a truncated serial list is a
     * delivery that is quietly short.
     */
    public const LIMIT = 500;

    /**
     * The serials in $raw, in the order they were given, blanks dropped.
     *
     * Order is kept deliberately: a receiver checking their own paste against the vendor's manifest
     * reads down the list, and re-ordering it would make that impossible.
     *
     * @return list<string>
     */
    public static function parse(?string $raw): array
    {
        $parts = preg_split('/[\r\n\t]+/', (string) $raw);

        $serials = [];
        foreach ($parts === false ? [] : $parts as $part) {
            $serial = trim($part);
            if ($serial !== '') {
                $serials[] = $serial;
            }
        }

        return $serials;
    }

    /**
     * The values that appear more than once in $serials, each named once.
     *
     * Compared case-insensitively. `abc-1` and `ABC-1` written on two cartons are one serial with a
     * typo in it far more often than they are two units, and letting both through puts two rows on
     * the shelf that a later scan cannot tell apart — whereas refusing costs a receiver one
     * correction they can make in front of the goods.
     *
     * @param list<string> $serials
     *
     * @return list<string>
     */
    public static function duplicates(array $serials): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($serials as $serial) {
            $key = mb_strtolower($serial);
            if (isset($seen[$key])) {
                $duplicates[$key] = $serial;
                continue;
            }

            $seen[$key] = true;
        }

        return array_values($duplicates);
    }
}
