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
    public function edit(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, Request $request, PaymentTypeRepository $paymentTypes, ScheduleSettings $schedule): Response
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
                $this->addFlash('success', sprintf('Invoice %s saved.', $this->numbers->number($invoice)));

                return $this->redirectToRoute('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()]);
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
