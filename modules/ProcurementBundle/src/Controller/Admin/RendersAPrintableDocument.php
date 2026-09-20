<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;

/**
 * The two helpers every printable buy-side document needs: render to PDF, and hand it back as a
 * download.
 *
 * A trait and not methods on `AbstractProcurementController`, on purpose. `PurchaseOrderController`
 * carries a PRIVATE copy of both today, and PHP refuses to widen a private method to protected on
 * the parent — adding them to the base class would fatal that controller until it is edited in the
 * same commit. The purchase order screens are being rebuilt on another branch right now, so this is
 * deliberately the seam-shaped version of the change: a new file nothing else has to move for.
 *
 * When the two branches meet, `PurchaseOrderController` deletes its private pair and adds
 * `use RendersAPrintableDocument;`. Nothing else has to happen, and until it does the two copies are
 * byte-identical rather than merely similar.
 */
trait RendersAPrintableDocument
{
    /** Rendered HTML as a PDF the browser offers to save. */
    protected function pdfResponse(string $pdf, string $filename): Response
    {
        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    protected function dompdf(string $html): string
    {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
