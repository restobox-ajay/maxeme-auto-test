<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Document\DocumentActions;
use App\Maxeme\Document\DocumentKind;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Document\DocumentMailer;
use App\Maxeme\Document\PdfRenderer;
use App\Maxeme\Document\PrintedDocument;
use App\Maxeme\Document\PrintedDocuments;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Repository\InvoiceRepository;
use App\Maxeme\Repository\RepairOrderRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Enum\QuoteApproval;
use App\Maxeme\Service\NoteBook;
use App\Maxeme\Service\RepairOrderInvoicing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * A repair order's documents: its quote and work order (live: what the repair order says now), each
 * shown, saved as a PDF and emailed; and its Invoices tab, where invoices are issued from it.
 */
#[Route('/admin/repair-orders/{id}', name: 'maxeme_repair_order_', requirements: ['id' => '\d+'])]
final class RepairOrderDocumentController extends AbstractMaxemeController
{
    /** @param array{name: string} $company */
    public function __construct(
        private readonly RepairOrderRepository $repairOrders,
        private readonly PrintedDocuments $documents,
        private readonly DocumentActions $actions,
        private readonly DocumentNumbers $numbers,
        #[Autowire(param: 'maxeme.company')]
        private readonly array $company,
    ) {
    }

    #[Route('/invoices', name: 'invoices', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function invoices(int $id, InvoiceRepository $invoices, RepairOrderInvoicing $invoicing): Response
    {
        $repairOrder = $this->repairOrder($id);
        $list = $invoices->findForRepairOrder($repairOrder);

        return $this->render('maxeme/repair_order/invoices.html.twig', [
            'repairOrder' => $repairOrder,
            'invoices' => $list,
            // Charged so far (mockup 6611dd0) and which unpaid invoice no longer matches it (mockup 0a4baef).
            'billing' => $invoicing->hasLiveInvoice($repairOrder) ? $invoicing->billing($repairOrder) : [],
            'outOfDate' => array_map(static fn ($invoice): int => (int) $invoice->getId(), array_values(array_filter($list, $invoicing->isOutOfDate(...)))),
        ]);
    }

    /** "+ Issue Invoice": a copy of the repair order as it is now. */
    #[Route('/invoices', name: 'invoice_issue', methods: ['POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function issueInvoice(int $id, Request $request, RepairOrderInvoicing $invoicing): RedirectResponse
    {
        $repairOrder = $this->repairOrder($id);
        // A second invoice only once confirmed (mockup 34181b0).
        if ($invoicing->hasLiveInvoice($repairOrder) && $request->request->get('confirmed') !== '1') {
            $this->addFlash('error', 'This repair order already has an invoice. Confirm to issue another one.');

            return $this->redirectToRoute('maxeme_repair_order_invoices', ['id' => $id]);
        }
        // Another invoice charges only what was picked, at most what remains of each line (mockup 6611dd0).
        $pick = null;
        if ($invoicing->hasLiveInvoice($repairOrder)) {
            $remaining = array_column($invoicing->billing($repairOrder), 'remaining', 'key');
            $pick = [];
            foreach ((array) $request->request->all('pick') as $row) {
                $key = is_array($row) ? (string) ($row['key'] ?? '') : '';
                $quantity = is_array($row) && is_numeric($row['qty'] ?? null) ? (float) $row['qty'] : 0.0;
                if (isset($remaining[$key]) && $quantity > 0) {
                    $pick[$key] = min($quantity, $remaining[$key]);
                }
            }
            $pick = array_filter($pick, static fn (float $quantity): bool => $quantity > 0);
        }
        try {
            $invoice = $invoicing->issue($repairOrder, $pick);
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('maxeme_repair_order_invoices', ['id' => $id]);
        }
        $this->addFlash('success', $pick === null ? 'Invoice issued.' : 'Invoice issued for the picked quantities.');

        return $this->redirectToRoute('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()]);
    }

    /** "Complete and lock" (mockup 84d136d): a paid repair order still open becomes Completed, then Invoiced. */
    #[Route('/complete-and-lock', name: 'complete_and_lock', methods: ['POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function completeAndLock(int $id, Request $request, RepairOrderInvoicing $invoicing): RedirectResponse
    {
        $repairOrder = $this->repairOrder($id);
        try {
            $invoicing->completeAndLock($repairOrder);
            $this->addFlash('success', sprintf('%s completed and locked.', $this->numbers->repairOrderNumber($repairOrder)));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectBack($request, 'maxeme_repair_order_edit', ['id' => $id]);
    }

    #[Route('/quote', name: 'quote', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function quote(int $id): Response
    {
        return $this->page($this->repairOrder($id), DocumentKind::Quote);
    }

    #[Route('/quote.pdf', name: 'quote_pdf', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function quotePdf(int $id, PdfRenderer $pdf, ActivityRecorder $activity): Response
    {
        $repairOrder = $this->repairOrder($id);

        return $this->downloadPrinted($this->documents->quote($repairOrder), $pdf, $activity, 'RepairOrder', $repairOrder->getId());
    }

    /** Sending the quote is sending the estimate: an estimate still being built now waits on the customer. */
    #[Route('/quote/email', name: 'quote_email', methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function quoteEmail(int $id, Request $request, DocumentMailer $mailer, ValidatorInterface $validator, RepairOrderInvoicing $invoicing): Response
    {
        $repairOrder = $this->repairOrder($id);

        return $this->emailPrinted(
            $this->documents->quote($repairOrder), $request, $mailer, $validator, $this->company['name'], 'RepairOrder', $repairOrder->getId(),
            $this->generateUrl('maxeme_repair_order_quote', ['id' => $id]),
            static fn () => $invoicing->quoteSent($repairOrder),
        );
    }

    /**
     * The customer's answer to the quote (mockup aa322bb), while it is open: Approve (how: phone, email,
     * in person, signed quote; an optional note) makes the repair order Authorized; Decline (a reason)
     * makes it Cancelled, and its pending appointments with it. Either leaves a note on the repair order.
     */
    #[Route('/quote/decision', name: 'quote_decision', methods: ['POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function quoteDecision(int $id, Request $request, NoteBook $notes): RedirectResponse
    {
        $repairOrder = $this->repairOrder($id);
        $note = trim((string) $request->request->get('note', ''));
        $approve = $request->request->get('decision') === 'approve';
        $via = QuoteApproval::tryFrom((string) $request->request->get('via', ''));
        try {
            if ($approve && $via === null) {
                throw new \DomainException('Choose how the quote was approved.');
            }
            if (!$approve && $note === '') {
                throw new \DomainException('Enter the reason for declining.');
            }
            $text = $approve ? sprintf('Quote approved %s.%s', $via->phrase(), $note !== '' ? ' ' . $note : '') : 'Quote declined: ' . $note;
            if (($problem = NoteBook::problemWith($text)) !== null) {
                throw new \DomainException($problem);
            }
            $approve ? $repairOrder->approveQuote($via) : $repairOrder->declineQuote();
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('maxeme_repair_order_quote', ['id' => $id]);
        }
        $notes->add($repairOrder, $text);
        $this->addFlash('success', $approve ? 'Quote approved. The repair order is Authorized.' : 'Quote declined. The repair order is Cancelled.');

        return $this->redirectToRoute('maxeme_repair_order_quote', ['id' => $id]);
    }

    #[Route('/work-order', name: 'work_order', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function workOrder(int $id): Response
    {
        return $this->page($this->repairOrder($id), DocumentKind::WorkOrder);
    }

    #[Route('/work-order.pdf', name: 'work_order_pdf', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function workOrderPdf(int $id, PdfRenderer $pdf, ActivityRecorder $activity, EntityManagerInterface $entityManager): Response
    {
        $repairOrder = $this->repairOrder($id);
        $this->markPrinted($repairOrder, $activity, $entityManager);

        return $this->downloadPrinted($this->documents->workOrder($repairOrder), $pdf, $activity, 'RepairOrder', $repairOrder->getId());
    }

    /** The work order page is being printed (the browser's beforeprint): the first time sets "First Printed On". */
    #[Route('/work-order/printed', name: 'work_order_printed', methods: ['POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function workOrderPrinted(int $id, ActivityRecorder $activity, EntityManagerInterface $entityManager): JsonResponse
    {
        $repairOrder = $this->repairOrder($id);
        $this->markPrinted($repairOrder, $activity, $entityManager);

        return $this->json(['firstPrinted' => $repairOrder->getWorkOrderFirstPrintedAt()?->format(\DateTimeInterface::ATOM)]);
    }

    #[Route('/work-order/email', name: 'work_order_email', methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function workOrderEmail(int $id, Request $request, DocumentMailer $mailer, ValidatorInterface $validator): Response
    {
        $repairOrder = $this->repairOrder($id);

        return $this->emailPrinted(
            $this->documents->workOrder($repairOrder), $request, $mailer, $validator, $this->company['name'], 'RepairOrder', $repairOrder->getId(),
            $this->generateUrl('maxeme_repair_order_work_order', ['id' => $id]),
        );
    }

    private function page(RepairOrder $repairOrder, DocumentKind $kind): Response
    {
        $document = $kind === DocumentKind::Quote ? $this->documents->quote($repairOrder) : $this->documents->workOrder($repairOrder);

        return $this->render('maxeme/document/page.html.twig', [
            'document' => $document,
            'repairOrder' => $repairOrder,
            'invoice' => null,
            'actions' => $this->actions->for($kind, $repairOrder),
            'backUrl' => $this->generateUrl('maxeme_repair_order_edit', ['id' => $repairOrder->getId()]),
            'quoteDecision' => $kind === DocumentKind::Quote ? [
                'open' => $repairOrder->isQuoteOpen(),
                'canDecide' => $this->isGranted(Permission::WORK_ORDER_EDIT),
                'url' => $this->generateUrl('maxeme_repair_order_quote_decision', ['id' => $repairOrder->getId()]),
                'ways' => QuoteApproval::cases(),
            ] : null,
            'numberChange' => $kind === DocumentKind::WorkOrder && $this->isGranted(Permission::WORK_ORDER_EDIT) ? [
                'label' => 'Change Work Order #',
                'url' => $this->generateUrl('maxeme_document_number_work_order', ['id' => $repairOrder->getId()]),
                'value' => $repairOrder->getWorkOrderNumber(),
                'automatic' => $this->numbers->automaticRepairOrderNumber($repairOrder, DocumentKind::WorkOrder),
            ] : null,
        ]);
    }

    private function markPrinted(RepairOrder $repairOrder, ActivityRecorder $activity, EntityManagerInterface $entityManager): void
    {
        if ($repairOrder->markWorkOrderPrinted()) {
            $entityManager->flush();
            $activity->firstPrinted($this->documents->workOrder($repairOrder), 'RepairOrder', $repairOrder->getId());
        }
    }

    private function repairOrder(int $id): RepairOrder
    {
        return $this->repairOrders->findForEdit($id) ?? throw new NotFoundHttpException('No such repair order.');
    }
}
