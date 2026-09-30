<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Listing;

use App\Maxeme\Listing\CsvExport;
use PHPUnit\Framework\TestCase;

final class CsvExportTest extends TestCase
{
    public function testItIsAUtf8DownloadAndFormulasStayText(): void
    {
        $response = CsvExport::response('clients.csv', ['Name', 'Note'], [['陳 Chan', '=HYPERLINK("x")'], ['Ada', null], ['-5', '+1 604']]);

        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=clients.csv', (string) $response->headers->get('Content-Disposition'));
        self::assertSame("\u{FEFF}Name,Note\n\"陳 Chan\",\"'=HYPERLINK(\"\"x\"\")\"\nAda,\n'-5,\"'+1 604\"\n", $csv);
    }
}
