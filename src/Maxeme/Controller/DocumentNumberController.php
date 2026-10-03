<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Document\DocumentKind;
use App\Maxeme\Document\DocumentNumberEditor;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Change Invoice # / Change Work Order # dialogs of the invoice and work order pages
 * (DocumentNumberEditor): back to the page with the new number, or with why it can't be used.
 */
final class DocumentNumberController extends AbstractMaxemeController
{
    public function __construct(
        private readonly DocumentNumberEditor $editor,
        private readonly DocumentNumbers $numbers,
    ) {
    }

    #[Route('/admin/invoices/{invoiceKey}/number', name: 'maxeme_document_number_invoice', methods: ['POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function invoice(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, Request $request): Response
    {
        $error = $this->editor->changeInvoiceNumber($invoice, (string) $request->request->get('number', ''));
        $error !== null
            ? $this->addFlash('error', $error)
            : $this->addFlash('success', sprintf('The invoice number is now %s.', $this->numbers->number($invoice)));

        return $this->redirectBack($request, 'maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()]);
    }

    #[Route('/admin/repair-orders/{id}/work-order-number', name: 'maxeme_document_number_work_order', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function workOrder(#[MapEntity] RepairOrder $repairOrder, Request $request): Response
    {
        $error = $this->editor->changeWorkOrderNumber($repairOrder, (string) $request->request->get('number', ''));
        $error !== null
            ? $this->addFlash('error', $error)
            : $this->addFlash('success', sprintf('The work order number is now %s.', $this->numbers->repairOrderNumber($repairOrder, DocumentKind::WorkOrder)));

        return $this->redirectBack($request, 'maxeme_repair_order_work_order', ['id' => $repairOrder->getId()]);
    }
}
