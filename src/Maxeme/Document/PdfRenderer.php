<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

use App\Maxeme\Entity\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * An invoice's documents as PDF files (dompdf, as core renders its documents). The page and the
 * PDF render the same document template (maxeme/invoice/pdf.html.twig wraps it).
 *
 * dompdf's bundled fonts have no Chinese glyphs; when `maxeme.pdf_font` points at an existing
 * TrueType font it is registered and used for the whole document.
 */
final class PdfRenderer
{
    public const FONT_FAMILY = 'maxeme-cjk';

    public function __construct(
        private readonly Environment $twig,
        #[Autowire(param: 'maxeme.pdf_font')]
        private readonly string $fontPath,
        #[Autowire(param: 'kernel.cache_dir')]
        private readonly string $cacheDir,
    ) {
    }

    public function render(Invoice $invoice, DocumentKind $kind): string
    {
        $hasFont = is_file($this->fontPath);
        $html = $this->twig->render('maxeme/invoice/pdf.html.twig', [
            'invoice' => $invoice,
            'kind' => $kind,
            'fontFamily' => $hasFont ? self::FONT_FAMILY : null,
        ]);

        $fontDir = $this->cacheDir . '/maxeme-dompdf-fonts';
        if (!is_dir($fontDir)) {
            mkdir($fontDir, 0775, true);
        }

        $options = new Options();
        $options->setFontDir($fontDir);
        $options->setFontCache($fontDir);
        $options->setChroot([dirname($this->fontPath)]);

        $dompdf = new Dompdf($options);
        if ($hasFont) {
            $dompdf->getFontMetrics()->registerFont(['family' => self::FONT_FAMILY, 'style' => 'normal', 'weight' => 'normal'], $this->fontPath);
            $dompdf->getFontMetrics()->registerFont(['family' => self::FONT_FAMILY, 'style' => 'normal', 'weight' => 'bold'], $this->fontPath);
        }
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
