<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Document\DocumentKind;
use App\Maxeme\Document\DocumentMailer;
use App\Maxeme\Document\PdfRenderer;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\InvoiceService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The repair order (legacy invoiceWorkOrderAction, blankWorkOrderAction and their PDF / email):
 * the one accounting screen every staff member can open.
 */
final class WorkOrderController extends AbstractMaxemeController
{
    #[Route('/admin/invoices/{invoiceKey}/work-order', name: 'maxeme_work_order_show', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function show(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice): Response
    {
        return $this->render('maxeme/invoice/work_order.html.twig', ['invoice' => $invoice, 'kind' => DocumentKind::WorkOrder, 'vehicles' => []]);
    }

    #[Route('/admin/invoices/{invoiceKey}/work-order.pdf', name: 'maxeme_work_order_pdf', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function pdf(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, PdfRenderer $pdf, ActivityRecorder $activity): Response
    {
        return $this->downloadDocument($invoice, DocumentKind::WorkOrder, $pdf, $activity);
    }

    #[Route('/admin/invoices/{invoiceKey}/work-order/email', name: 'maxeme_work_order_email', methods: ['POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function email(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, Request $request, DocumentMailer $mailer): RedirectResponse
    {
        return $this->emailDocument($invoice, DocumentKind::WorkOrder, $request, $mailer);
    }

    /**
     * "Empty Work Order (Print Only)": a blank repair order for the client, `?vehicle=` to switch
     * vehicle and `?address=` to pick one of the client's pickup addresses (listed in the client's
     * order, the first one by default). Nothing is saved.
     */
    #[Route('/admin/clients/{id}/work-order', name: 'maxeme_work_order_blank', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function blank(#[MapEntity] Client $client, Request $request, InvoiceService $invoices): Response
    {
        $vehicles = $client->getVehicles()->getValues();
        if ($vehicles === []) {
            $this->addFlash('error', 'Client does not have any vehicles!');

            return $this->redirectToRoute('maxeme_client_show', ['id' => $client->getId()]);
        }

        $vehicle = null;
        foreach ($vehicles as $candidate) {
            if ($candidate->getId() === $request->query->getInt('vehicle')) {
                $vehicle = $candidate;
            }
        }

        $address = null;
        foreach ($client->getAddresses() as $candidate) {
            if ($candidate->getId() === $request->query->getInt('address')) {
                $address = $candidate;
            }
        }

        $invoice = $invoices->blankWorkOrder($client, $vehicle instanceof Vehicle ? $vehicle : null, $address);

        return $this->render('maxeme/invoice/work_order.html.twig', [
            'invoice' => $invoice,
            'kind' => DocumentKind::WorkOrder,
            'vehicles' => $vehicles,
            'addresses' => $client->getAddresses()->getValues(),
            'address' => $address ?? $client->getPrimaryAddress(),
        ]);
    }
}
