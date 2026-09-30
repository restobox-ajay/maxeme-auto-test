<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Accounting\InvoiceSettings;
use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Document\DocumentKind;
use App\Maxeme\Document\DocumentMailer;
use App\Maxeme\Document\PdfRenderer;
use App\Maxeme\Dto\InvoiceData;
use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Enum\InvoiceSaveIntent;
use App\Maxeme\Enum\PaymentMethod;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Listing\SearchTerm;
use App\Maxeme\Repository\InvoiceRepository;
use App\Maxeme\Repository\PartRepository;
use App\Maxeme\Repository\ServiceItemRepository;
use App\Maxeme\Schedule\CalendarView;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\InvoiceVoter;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\InvoiceService;
use App\Maxeme\Service\RecordWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The invoice builder and the printable invoice (legacy AccountingBundle InvoiceController).
 *
 * Which screen opens (legacy invoiceViewAction): a role without Accounting gets the work order;
 * someone who may edit the invoice (InvoiceVoter) gets the builder while it is unpaid, or always
 * through Edit; everyone else gets the printable invoice. The two links every role follows (the
 * calendar's $ and an invoice link) therefore only require Work Order access.
 */
final class InvoiceController extends AbstractMaxemeController
{
    /** Legacy calendar alerts, shown by Schedule › Appointments. */
    private const NO_WORK_ORDER = 'No work order has been created for this appointment yet.';
    private const NOT_EDITABLE = 'This appointment / invoice is completed. You cannot make edits to it.';

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoiceRepository $repository,
        private readonly RecordWriter $records,
        private readonly InvoiceSettings $settings,
    ) {
    }

    /** The calendar's $ icon and the profile's Invoice buttons: the appointment's invoice, created on first save. */
    #[Route('/admin/appointments/{id}/invoice', name: 'maxeme_invoice_for_appointment', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function forAppointment(#[MapEntity] Appointment $appointment, Request $request): Response
    {
        $invoice = $this->invoices->forAppointment($appointment);

        if (!$this->isGranted(Permission::ACCOUNTING_VIEW)) {
            return $invoice->isSaved()
                ? $this->redirectToRoute('maxeme_work_order_show', ['invoiceKey' => $invoice->getInvoiceKey()])
                : $this->calendarAlert(self::NO_WORK_ORDER);
        }
        // View-only Accounting: nothing to print until someone who may edit it has saved it.
        if (!$invoice->isSaved() && !$this->isGranted(InvoiceVoter::EDIT, $invoice)) {
            return $this->calendarAlert(self::NO_WORK_ORDER);
        }

        return $request->isMethod('POST') ? $this->save($invoice, $request) : $this->open($invoice, false);
    }

    #[Route('/admin/invoices/{invoiceKey}', name: 'maxeme_invoice_show', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function show(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice): Response
    {
        if (!$this->isGranted(Permission::ACCOUNTING_VIEW)) {
            return $this->redirectToRoute('maxeme_work_order_show', ['invoiceKey' => $invoice->getInvoiceKey()]);
        }

        return $this->open($invoice, false);
    }

    /** The print view's Edit (Super admin): the builder even for a paid invoice. */
    #[Route('/admin/invoices/{invoiceKey}/edit', name: 'maxeme_invoice_edit', methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function edit(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, Request $request): Response
    {
        return $request->isMethod('POST') ? $this->save($invoice, $request) : $this->open($invoice, true);
    }

    #[Route('/admin/invoices/{invoiceKey}/print', name: 'maxeme_invoice_print', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function print(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice): Response
    {
        return $this->render('maxeme/invoice/print.html.twig', ['invoice' => $invoice, 'kind' => DocumentKind::Invoice]);
    }

    #[Route('/admin/invoices/{invoiceKey}/pdf', name: 'maxeme_invoice_pdf', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function pdf(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, PdfRenderer $pdf, ActivityRecorder $activity): Response
    {
        return $this->downloadDocument($invoice, DocumentKind::Invoice, $pdf, $activity);
    }

    #[Route('/admin/invoices/{invoiceKey}/email', name: 'maxeme_invoice_email', methods: ['POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function email(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, Request $request, DocumentMailer $mailer): RedirectResponse
    {
        return $this->emailDocument($invoice, DocumentKind::Invoice, $request, $mailer);
    }

    /** The item name autocomplete: active parts and services matching `q` (legacy invoiceItemSearching). */
    #[Route('/admin/invoices/items', name: 'maxeme_invoice_items', methods: ['GET'], priority: 10)]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function items(Request $request, PartRepository $parts, ServiceItemRepository $services): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));
        $type = (string) $request->query->get('type', '');
        $items = [];

        if ($type !== 'services') {
            foreach ($parts->search($term) as $part) {
                $items[] = ['label' => $part->getName(), 'category' => 'Parts', 'value' => $part->getId(), 'price' => $part->getSalePrice()];
            }
        }
        if ($type !== 'parts') {
            foreach ($services->search($term) as $service) {
                $items[] = ['label' => $service->getFullName(), 'category' => 'Services', 'value' => $service->getId(), 'price' => $service->getPrice()];
            }
        }

        return $this->json($items);
    }

    /** Accounting › Invoices: every invoice, newest first, and the sidebar Invoice # box's results. */
    #[Route('/admin/invoices', name: 'maxeme_invoice_index', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function index(Request $request): Response
    {
        $find = SearchTerm::fromRequest($request);

        return $this->render('maxeme/invoice/index.html.twig', [
            'page' => $this->repository->findPage($find, ListQuery::fromRequest($request, array_keys(InvoiceRepository::LIST_SORTS), 'desc')),
            'find' => $find,
        ]);
    }

    /**
     * The sidebar Invoice # box: that exact number opens the invoice; otherwise the numbers starting
     * with it, and a single one of those opens too.
     */
    #[Route('/admin/invoices/find', name: 'maxeme_invoice_find', methods: ['GET'], priority: 10)]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function find(Request $request): RedirectResponse
    {
        $find = SearchTerm::fromRequest($request);
        $invoice = $this->repository->findOneByNumber($find);

        if ($invoice === null) {
            $matches = $this->repository->findPage($find, ListQuery::fromRequest($request, array_keys(InvoiceRepository::LIST_SORTS), 'desc'));
            $invoice = $matches->total === 1 ? $matches->items[0] : null;
        }

        return $invoice !== null
            ? $this->redirectToRoute('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()])
            : $this->redirectToRoute('maxeme_invoice_index', [SearchTerm::PARAM => $find->text]);
    }

    private function open(Invoice $invoice, bool $forceEdit): Response
    {
        if (!$this->isGranted(InvoiceVoter::EDIT, $invoice) || ($invoice->isPaid() && !$forceEdit)) {
            return $this->render('maxeme/invoice/print.html.twig', ['invoice' => $invoice, 'kind' => DocumentKind::Invoice]);
        }

        return $this->render('maxeme/invoice/form.html.twig', [
            'invoice' => $invoice,
            'errors' => [],
            'settings' => $this->settings,
            'paymentMethods' => PaymentMethod::cases(),
        ]);
    }

    private function save(Invoice $invoice, Request $request): Response
    {
        if (!$this->isGranted(InvoiceVoter::EDIT, $invoice)) {
            return $this->calendarAlert(self::NOT_EDITABLE);
        }

        $data = InvoiceData::fromRequest($request);
        if (($errors = $this->records->validate($data)) !== []) {
            return $this->render('maxeme/invoice/form.html.twig', [
                'invoice' => $invoice,
                'errors' => $errors,
                'settings' => $this->settings,
                'paymentMethods' => PaymentMethod::cases(),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $intent = InvoiceSaveIntent::tryFrom((string) $request->request->get('intent')) ?? InvoiceSaveIntent::Save;
        $this->invoices->save($invoice, $data, $intent);
        $this->addFlash('success', sprintf('Invoice #%s saved.', $invoice->getDisplayedId()));

        return match ($intent) {
            InvoiceSaveIntent::Save => $this->redirectToRoute('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()]),
            InvoiceSaveIntent::Complete => $this->redirectToRoute('maxeme_invoice_print', ['invoiceKey' => $invoice->getInvoiceKey()]),
            InvoiceSaveIntent::WorkOrder => $this->redirectToRoute('maxeme_work_order_show', ['invoiceKey' => $invoice->getInvoiceKey()]),
        };
    }

    private function calendarAlert(string $message): RedirectResponse
    {
        $this->addFlash(ScheduleController::ALERT, $message);

        return $this->redirectToRoute('maxeme_appointment_calendar', ['view' => CalendarView::Day->value]);
    }
}
