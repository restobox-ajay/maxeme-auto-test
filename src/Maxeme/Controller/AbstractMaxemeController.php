<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Document\DocumentKind;
use App\Maxeme\Document\DocumentMailer;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Document\PdfRenderer;
use App\Maxeme\Entity\Invoice;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** What the Maxeme admin screens share. */
abstract class AbstractMaxemeController extends AbstractController
{
    /** The hidden field a modal form carries so it returns to the page (and filters) it was opened from. */
    public const RETURN_FIELD = '_return';

    /** Back to the form's `_return` page when it is an admin path; otherwise to the fallback route. */
    protected function redirectBack(Request $request, string $fallbackRoute, array $fallbackParams = []): RedirectResponse
    {
        $return = (string) $request->request->get(self::RETURN_FIELD, '');

        if (preg_match('#^/admin(/|\?|$)#', $return) === 1 && !str_starts_with($return, '//')) {
            return $this->redirect($return);
        }

        return $this->redirectToRoute($fallbackRoute, $fallbackParams);
    }

    /** @param array<string, string> $errors */
    protected function flashErrors(array $errors): void
    {
        foreach ($errors as $message) {
            $this->addFlash('error', $message);
        }
    }

    /** A document's PDF, saved by the browser under its filename, and recorded in the Activity Log. */
    protected function downloadDocument(Invoice $invoice, DocumentKind $kind, PdfRenderer $pdf, ActivityRecorder $activity, DocumentNumbers $numbers): Response
    {
        $response = new Response($pdf->render($invoice, $kind), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $numbers->filename($invoice, $kind)),
        ]);
        $activity->downloaded($invoice, $kind);

        return $response;
    }

    /** The Email PDF modals: `emails` (comma-separated), then back to the page with the legacy messages. */
    protected function emailDocument(Invoice $invoice, DocumentKind $kind, Request $request, DocumentMailer $mailer): RedirectResponse
    {
        try {
            $sent = $mailer->send($invoice, $kind, (string) $request->request->get('emails', ''));
            $sent
                ? $this->addFlash('success', 'Email sent successfully!')
                : $this->addFlash('error', 'Something went wrong. Please try again later!');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectBack($request, $kind === DocumentKind::Invoice ? 'maxeme_invoice_show' : 'maxeme_work_order_show', ['invoiceKey' => $invoice->getInvoiceKey()]);
    }
}
