<?php

declare(strict_types=1);

namespace App\Maxeme\Listing;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A list's "Export CSV": a download of the given columns and rows. UTF-8 with a byte-order mark,
 * so Excel shows accented and Chinese names correctly, and a cell that starts like a formula
 * (= + - @) is prefixed with ' so a spreadsheet shows it as text rather than running it.
 */
final class CsvExport
{
    /**
     * @param list<string> $header column titles
     * @param iterable<list<string|int|float|null>> $rows
     */
    public static function response(string $filename, array $header, iterable $rows): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($header, $rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, $header, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename));

        return $response;
    }

    private static function cell(string|int|float|null $value): string
    {
        $text = (string) $value;

        // A plain number (e.g. a negative amount "-12.50") is not a formula: leave it a number.
        if (is_int($value) || is_float($value) || preg_match('/^-?\d+(\.\d+)?$/', $text) === 1) {
            return $text;
        }

        // Anything else starting like a formula is neutralised (CSV/spreadsheet formula injection).
        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'" . $text : $text;
    }
}
