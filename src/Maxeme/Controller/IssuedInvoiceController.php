<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Dto\IssuedInvoiceForm;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Enum\DocumentChargeKind;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Repository\PaymentTypeRepository;
use App\Maxeme\Schedule\ScheduleSettings;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RepairOrderInvoicing;
use App\Maxeme\Service\InvoicePayments;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An invoice issued from a repair order: its edit page (status, date, payment, recommendations, its
 * lines and its fees and discounts) and Delete (the repair order's Invoices tab). The legacy
 * invoices keep the invoice builder.
 */
#[Route('/admin/invoices/{invoiceKey}', name: 'maxeme_invoice_issued_')]
final class IssuedInvoiceController extends AbstractMaxemeController
{
    public function __construct(
        private readonly RepairOrderInvoicing $invoicing,
        private readonly DocumentNumbers $numbers,
    ) {
    }

    #[Route('/edit-issued', name: 'edit', methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function edit(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, Request $request, PaymentTypeRepository $paymentTypes, ScheduleSettings $schedule, InvoicePayments $payments): Response
    {
        if (!$invoice->isIssuedFromRepairOrder()) {
            throw new NotFoundHttpException('Only an invoice issued from a repair order is edited here.');
        }

        $form = IssuedInvoiceForm::fromEntity($invoice, $schedule->timezone());
        $errors = [];
        if ($request->isMethod('POST')) {
            $form = IssuedInvoiceForm::fromRequest($request);
            $errors = $this->invoicing->update($invoice, $form);
            if ($errors === []) {
                $payments->sync($invoice, true);
                $this->addFlash('success', sprintf('Invoice %s saved.', $this->numbers->number($invoice)));
                // Paid while its repair order is still open (paid ahead of time): the invoice page asks whether to complete and lock it.
                $repairOrder = $invoice->getRepairOrder();

                return $this->redirectToRoute('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()] + ($repairOrder !== null && $this->invoicing->paidWhileOpen($repairOrder) ? ['offerClose' => 1] : []));
            }
        }

        $lines = [];
        foreach ($invoice->getServiceLines() as $line) {
            $lines[(string) $line->getId()] = $line;
        }

        return $this->render('maxeme/invoice/issued_edit.html.twig', [
            'invoice' => $invoice,
            'form' => $form,
            'lineEntities' => $lines,
            'errors' => $errors,
            'statuses' => InvoiceStatus::cases(),
            'chargeKinds' => DocumentChargeKind::cases(),
            'paymentTypes' => $paymentTypes->findActiveOrOwn($invoice->getPaymentType()),
        ], new Response('', $errors !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Sync Repair Order (mockup 0a4baef): an unpaid invoice that no longer matches its repair order
     * takes its current lines, fees and totals; the number, date and payments stay.
     */
    #[Route('/sync', name: 'sync', methods: ['POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function sync(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, Request $request, InvoicePayments $payments): Response
    {
        try {
            $this->invoicing->syncFromRepairOrder($invoice);
            // A payment already made may now cover the new total.
            $payments->sync($invoice);
            $this->addFlash('success', sprintf('Invoice %s synced with its repair order.', $this->numbers->number($invoice)));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        $back = (string) $request->request->get('back', '');

        return str_starts_with($back, '/admin/') ? $this->redirect($back) : $this->redirectToRoute('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()]);
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function delete(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice): JsonResponse
    {
        $number = $this->numbers->number($invoice);
        try {
            $this->invoicing->delete($invoice);
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['message' => sprintf('Invoice %s deleted.', $number)]);
    }
}
