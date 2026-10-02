<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Document\DocumentActions;
use App\Maxeme\Document\DocumentKind;
use App\Maxeme\Document\DocumentMailer;
use App\Maxeme\Document\PdfRenderer;
use App\Maxeme\Document\PrintedDocument;
use App\Maxeme\Document\PrintedDocuments;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Repository\InvoiceRepository;
use App\Maxeme\Repository\RepairOrderRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RepairOrderInvoicing;
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
        #[Autowire(param: 'maxeme.company')]
        private readonly array $company,
    ) {
    }

    #[Route('/invoices', name: 'invoices', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function invoices(int $id, InvoiceRepository $invoices): Response
    {
        $repairOrder = $this->repairOrder($id);

        return $this->render('maxeme/repair_order/invoices.html.twig', [
            'repairOrder' => $repairOrder,
            'invoices' => $invoices->findForRepairOrder($repairOrder),
        ]);
    }

    /** "+ Issue Invoice": a copy of the repair order as it is now. */
    #[Route('/invoices', name: 'invoice_issue', methods: ['POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function issueInvoice(int $id, RepairOrderInvoicing $invoicing): RedirectResponse
    {
        $repairOrder = $this->repairOrder($id);
        try {
            $invoice = $invoicing->issue($repairOrder);
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('maxeme_repair_order_invoices', ['id' => $id]);
        }
        $this->addFlash('success', 'Invoice issued.');

        return $this->redirectToRoute('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()]);
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

    #[Route('/work-order', name: 'work_order', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function workOrder(int $id): Response
    {
        return $this->page($this->repairOrder($id), DocumentKind::WorkOrder);
    }

    #[Route('/work-order.pdf', name: 'work_order_pdf', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function workOrderPdf(int $id, PdfRenderer $pdf, ActivityRecorder $activity): Response
    {
        $repairOrder = $this->repairOrder($id);

        return $this->downloadPrinted($this->documents->workOrder($repairOrder), $pdf, $activity, 'RepairOrder', $repairOrder->getId());
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
        ]);
    }

    private function repairOrder(int $id): RepairOrder
    {
        return $this->repairOrders->findForEdit($id) ?? throw new NotFoundHttpException('No such repair order.');
    }
}
