<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Accounting\InvoiceSettings;
use App\Maxeme\Accounting\Money;
use App\Service\Product\ProductPicker;
use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Document\DocumentKind;
use App\Maxeme\Document\DocumentMailer;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Document\PdfRenderer;
use App\Maxeme\Dto\InvoiceData;
use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Enum\InvoiceSaveIntent;
use App\Maxeme\Listing\CsvExport;
use App\Maxeme\Listing\ItemLabel;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Listing\SearchTerm;
use App\Maxeme\Repository\InvoiceRepository;
use App\Maxeme\Repository\PaymentTypeRepository;
use App\Maxeme\Repository\ServiceItemRepository;
use App\Maxeme\Schedule\CalendarView;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\InvoiceVoter;
use App\Maxeme\Security\Permission;
use App\Maxeme\Document\DocumentActions;
use App\Maxeme\Document\PrintedDocuments;
use App\Maxeme\Service\InvoiceService;
use App\Maxeme\Service\RecordWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        private readonly PaymentTypeRepository $paymentTypes,
        private readonly DocumentNumbers $numbers,
        private readonly PrintedDocuments $documents,
        private readonly DocumentActions $actions,
        #[Autowire(param: 'maxeme.company')]
        private readonly array $company,
    ) {
    }

    /** The calendar's $ icon and the profile's Invoice buttons: the appointment's invoice, created on first save. */
    #[Route('/admin/appointments/{id}/invoice', name: 'maxeme_invoice_for_appointment', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function forAppointment(#[MapEntity] Appointment $appointment, Request $request): Response
    {
        // An appointment of a repair order: its invoices are on the repair order (its work order for roles without Accounting).
        $repairOrder = $appointment->getRepairOrder();
        if ($repairOrder !== null) {
            return $this->isGranted(Permission::ACCOUNTING_VIEW)
                ? $this->redirectToRoute('maxeme_repair_order_invoices', ['id' => $repairOrder->getId()])
                : $this->redirectToRoute('maxeme_repair_order_work_order', ['id' => $repairOrder->getId()]);
        }

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
            return $invoice->getRepairOrder() !== null
                ? $this->redirectToRoute('maxeme_repair_order_work_order', ['id' => $invoice->getRepairOrder()->getId()])
                : $this->redirectToRoute('maxeme_work_order_show', ['invoiceKey' => $invoice->getInvoiceKey()]);
        }

        return $invoice->getRepairOrder() !== null ? $this->detail($invoice) : $this->open($invoice, false);
    }

    /** The print view's Edit (Super admin): the builder even for a paid invoice. */
    #[Route('/admin/invoices/{invoiceKey}/edit', name: 'maxeme_invoice_edit', methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function edit(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, Request $request): Response
    {
        if ($invoice->isIssuedFromRepairOrder()) {
            return $this->redirectToRoute('maxeme_invoice_issued_edit', ['invoiceKey' => $invoice->getInvoiceKey()]);
        }

        return $request->isMethod('POST') ? $this->save($invoice, $request) : $this->open($invoice, true);
    }

    #[Route('/admin/invoices/{invoiceKey}/print', name: 'maxeme_invoice_print', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function print(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice): Response
    {
        return $invoice->getRepairOrder() !== null
            ? $this->detail($invoice)
            : $this->render('maxeme/invoice/print.html.twig', ['invoice' => $invoice, 'kind' => DocumentKind::Invoice]);
    }

    #[Route('/admin/invoices/{invoiceKey}/pdf', name: 'maxeme_invoice_pdf', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function pdf(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, PdfRenderer $pdf, ActivityRecorder $activity, DocumentNumbers $numbers): Response
    {
        return $invoice->getRepairOrder() !== null
            ? $this->downloadPrinted($this->documents->invoice($invoice), $pdf, $activity, 'Invoice', $invoice->getId())
            : $this->downloadDocument($invoice, DocumentKind::Invoice, $pdf, $activity, $numbers);
    }

    /**
     * Save as Quote: the invoice's items as a quote PDF, QUOTE on top with its QO- number and no
     * payment lines; an invoice issued from a repair order gives that repair order's quote.
     */
    #[Route('/admin/invoices/{invoiceKey}/quote.pdf', name: 'maxeme_invoice_quote_pdf', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function quotePdf(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, PdfRenderer $pdf, ActivityRecorder $activity, DocumentNumbers $numbers): Response
    {
        $repairOrder = $invoice->getRepairOrder();

        return $repairOrder !== null
            ? $this->downloadPrinted($this->documents->quote($repairOrder), $pdf, $activity, 'RepairOrder', $repairOrder->getId())
            : $this->downloadDocument($invoice, DocumentKind::Quote, $pdf, $activity, $numbers);
    }

    /** An invoice's "Save and Email" page (the invoice detail page's Invoice PDF menu). */
    #[Route('/admin/invoices/{invoiceKey}/send', name: 'maxeme_invoice_send', methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function send(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, Request $request, DocumentMailer $mailer, ValidatorInterface $validator): Response
    {
        return $this->emailPrinted(
            $this->documents->invoice($invoice), $request, $mailer, $validator, $this->company['name'], 'Invoice', $invoice->getId(),
            $this->generateUrl('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()]),
        );
    }

    #[Route('/admin/invoices/{invoiceKey}/email', name: 'maxeme_invoice_email', methods: ['POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function email(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, Request $request, DocumentMailer $mailer): RedirectResponse
    {
        return $this->emailDocument($invoice, DocumentKind::Invoice, $request, $mailer);
    }

    /** The item name autocomplete: sellable products (parts) and services matching `q` (legacy invoiceItemSearching). */
    #[Route('/admin/invoices/items', name: 'maxeme_invoice_items', methods: ['GET'], priority: 10)]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function items(Request $request, ProductPicker $products, ServiceItemRepository $services): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));
        $type = (string) $request->query->get('type', '');
        $items = [];

        if ($type !== 'services') {
            // Parts are core's products: its own search (name, SKU, barcode), sellable ones only.
            foreach ($products->searchPage($term)['products'] as $product) {
                if ($product->isSellable()) {
                    $items[] = ['label' => ItemLabel::product($product), 'category' => 'Parts', 'value' => $product->getId(), 'price' => Money::rounded($product->getDefaultPrice())];
                }
            }
        }
        if ($type !== 'parts') {
            foreach ($services->search($term) as $service) {
                $items[] = ['label' => $service->getFullName(), 'category' => 'Services', 'value' => $service->getId(), 'price' => $service->getPrice()];
            }
        }

        return $this->json($items);
    }

    /** Invoices › Export CSV: every invoice of the current view (search, sort), not just the page. */
    #[Route('/admin/invoices/export.csv', name: 'maxeme_invoice_export', methods: ['GET'], priority: 10)]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function export(Request $request, ActivityRecorder $activity, #[Autowire(param: 'maxeme.timezone')] string $timezone): Response
    {
        $list = ListQuery::fromRequest($request, array_keys(InvoiceRepository::LIST_SORTS), 'desc');
        $invoices = $this->repository->findAllInView(SearchTerm::fromRequest($request), $list);
        $filename = sprintf('invoices-%s.csv', (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d'));
        $activity->exported('accounting', 'Invoice', $filename, count($invoices), $list->describe());
        $numbers = $this->numbers;

        return CsvExport::response($filename, ['Invoice #', 'Date', 'Client', 'Vehicle', 'Status', 'Payment method', 'Subtotal', 'Sales Tax', 'Total'], (static function () use ($invoices, $numbers, $timezone): \Generator {
            foreach ($invoices as $invoice) {
                yield [
                    $numbers->number($invoice),
                    $invoice->getDocumentDate()->setTimezone(new \DateTimeZone($timezone))->format('m/d/Y'),
                    $invoice->getClientFullName(),
                    $invoice->getVehicleFullName(),
                    $invoice->getStatus()->label(),
                    $invoice->getPaymentType()?->getName(),
                    $invoice->getSubtotal(),
                    $invoice->getSalesTax(),
                    $invoice->getTotalPrice(),
                ];
            }
        })());
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

    /** The invoice detail page of an invoice billing a repair order: the invoice and the repair order's document bar. */
    private function detail(Invoice $invoice): Response
    {
        $repairOrder = $invoice->getRepairOrder();
        $actions = $this->actions->for(DocumentKind::Invoice, $repairOrder, $invoice);
        if ($this->isGranted(InvoiceVoter::EDIT, $invoice) || $this->isGranted('ROLE_SUPER_ADMIN')) {
            $actions[] = ['label' => 'Edit', 'url' => $this->generateUrl('maxeme_invoice_edit', ['invoiceKey' => $invoice->getInvoiceKey()]), 'class' => 'mx-warning'];
        }

        return $this->render('maxeme/document/page.html.twig', [
            'document' => $this->documents->invoice($invoice),
            'repairOrder' => $repairOrder,
            'invoice' => $invoice,
            'actions' => $actions,
            'backUrl' => $repairOrder !== null ? $this->generateUrl('maxeme_repair_order_invoices', ['id' => $repairOrder->getId()]) : $this->generateUrl('maxeme_invoice_index'),
            'numberChange' => $this->isGranted(Permission::ACCOUNTING_EDIT) ? [
                'label' => 'Change Invoice #',
                'url' => $this->generateUrl('maxeme_document_number_invoice', ['invoiceKey' => $invoice->getInvoiceKey()]),
                'value' => $invoice->getCustomNumber(),
                'automatic' => $this->numbers->automaticNumber($invoice),
            ] : null,
        ]);
    }

    private function open(Invoice $invoice, bool $forceEdit): Response
    {
        if (!$this->isGranted(InvoiceVoter::EDIT, $invoice) || ($invoice->isPaid() && !$forceEdit)) {
            return $this->render('maxeme/invoice/print.html.twig', ['invoice' => $invoice, 'kind' => DocumentKind::Invoice]);
        }

        return $this->renderForm($invoice, []);
    }

    private function save(Invoice $invoice, Request $request): Response
    {
        if (!$this->isGranted(InvoiceVoter::EDIT, $invoice)) {
            return $this->calendarAlert(self::NOT_EDITABLE);
        }

        $data = InvoiceData::fromRequest($request);
        if (($errors = $this->records->validate($data)) !== []) {
            return $this->renderForm($invoice, $errors, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $intent = InvoiceSaveIntent::tryFrom((string) $request->request->get('intent')) ?? InvoiceSaveIntent::Save;
        $this->invoices->save($invoice, $data, $intent);
        $this->addFlash('success', sprintf('Invoice %s saved.', $this->numbers->number($invoice)));

        return match ($intent) {
            InvoiceSaveIntent::Save => $this->redirectToRoute('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()]),
            InvoiceSaveIntent::Complete => $this->redirectToRoute('maxeme_invoice_print', ['invoiceKey' => $invoice->getInvoiceKey()]),
            InvoiceSaveIntent::WorkOrder => $this->redirectToRoute('maxeme_work_order_show', ['invoiceKey' => $invoice->getInvoiceKey()]),
            InvoiceSaveIntent::Quote => $this->redirectToRoute('maxeme_invoice_quote_pdf', ['invoiceKey' => $invoice->getInvoiceKey()]),
        };
    }

    /** "Oil filter (OF-123)" */
    /** @param array<string, string> $errors */
    private function renderForm(Invoice $invoice, array $errors, ?Response $response = null): Response
    {
        $paymentTypes = $this->paymentTypes->findActiveOrOwn($invoice->getPaymentType());

        return $this->render('maxeme/invoice/form.html.twig', [
            'invoice' => $invoice,
            'errors' => $errors,
            'settings' => $this->settings,
            'paymentTypes' => $paymentTypes,
        ], $response);
    }

    private function calendarAlert(string $message): RedirectResponse
    {
        $this->addFlash(ScheduleController::ALERT, $message);

        return $this->redirectToRoute('maxeme_appointment_calendar', ['view' => CalendarView::Day->value]);
    }
}
