<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Accounting\InvoiceSettings;
use App\Maxeme\Accounting\LineTax;
use App\Maxeme\Accounting\Money;
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
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Listing\PartSearch;
use App\Maxeme\Listing\SearchTerm;
use App\Maxeme\Repository\InvoiceRepository;
use App\Maxeme\Repository\PaymentTypeRepository;
use App\Maxeme\Repository\ServiceItemRepository;
use App\Maxeme\Schedule\CalendarView;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\InvoiceVoter;
use App\Maxeme\Security\Permission;
use App\Maxeme\Accounting\PartTaxes;
use App\Maxeme\Accounting\PaymentReport;
use App\Maxeme\Document\DocumentActions;
use App\Maxeme\Service\RepairOrderInvoicing;
use App\Maxeme\Service\InvoicePayments;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Document\PrintedDocuments;
use App\Maxeme\Service\InvoiceService;
use App\Maxeme\Service\RecordWriter;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly RepairOrderInvoicing $repairOrderInvoicing,
        private readonly InvoicePayments $invoicePayments,
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
    public function items(Request $request, PartSearch $parts, ServiceItemRepository $services, PartTaxes $partTaxes): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));
        $type = (string) $request->query->get('type', '');
        $items = [];

        if ($type !== 'services') {
            foreach ($parts->search($term) as $part) {
                $items[] = ['category' => 'Parts'] + $part;
            }
        }
        if ($type !== 'parts') {
            foreach ($services->search($term) as $service) {
                // Its catalogue charge-through lines, per one service, and what of them carries each tax.
                $serviceTax = LineTax::of($service->getTaxClass());
                $chargeThrough = ['all' => 0, 'gst' => 0, 'pst' => 0];
                foreach ($service->getLines() as $line) {
                    if ($line->isChargeThrough()) {
                        $tax = LineTax::ofLine($line, $serviceTax, $partTaxes);
                        $chargeThrough['all'] += $line->getExtendedCents();
                        $chargeThrough['gst'] += $tax->gst ? $line->getExtendedCents() : 0;
                        $chargeThrough['pst'] += $tax->pst ? $line->getExtendedCents() : 0;
                    }
                }
                $items[] = [
                    'label' => $service->getFullName(), 'category' => 'Services', 'value' => $service->getId(), 'price' => $service->getPrice(),
                    'chargeThroughCents' => $chargeThrough['all'], 'chargeThroughGstCents' => $chargeThrough['gst'], 'chargeThroughPstCents' => $chargeThrough['pst'],
                ];
            }
        }

        return $this->json($items);
    }

    /** Invoices › Export CSV: every invoice of the current view (search, sort), not just the page. */
    #[Route('/admin/invoices/export.csv', name: 'maxeme_invoice_export', methods: ['GET'], priority: 10)]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function export(Request $request, ActivityRecorder $activity, \App\Service\AppSettings $appSettings): Response
    {
        // The shop's timezone setting (Settings), the zone every screen shows; not the fixed maxeme.timezone parameter.
        $timezone = $appSettings->timezone()->getName();
        $list = ListQuery::fromRequest($request, array_keys(InvoiceRepository::LIST_SORTS), 'desc');
        $find = SearchTerm::fromRequest($request);
        // Streamed one invoice at a time (the whole table at once ran out of memory).
        $invoices = $this->repository->iterateAllInView($find, $list);
        $filename = CsvExport::datedFilename('invoices', $timezone);
        $activity->exported('accounting', 'Invoice', $filename, $this->repository->countAllInView($find, $list), $list->describe());
        $number = $this->numbers->numberer(DocumentKind::Invoice);
        $zone = new \DateTimeZone($timezone);
        $payments = $this->invoicePayments;

        return CsvExport::response($filename, ['Invoice #', 'Date', 'Client', 'Vehicle', 'Status', 'Payment method', 'Subtotal', 'Sales Tax', 'Total'], (static function () use ($invoices, $number, $zone, $payments): \Generator {
            // Paid with more than one method: each with its amount ("Debit 100.00 + Credit Card 218.60", as the
            // Payment Report). The payments are looked up 200 invoices at a time, before the iterator clears them.
            $rows = static function (array $batch) use ($number, $zone, $payments): \Generator {
                $methodsOf = $payments->methodsByInvoice($batch);
                foreach ($batch as $invoice) {
                    $methods = $methodsOf[$invoice->getId()] ?? [];
                    yield [
                        $number($invoice),
                        $invoice->getDocumentDate()->setTimezone($zone)->format('m/d/Y'),
                        $invoice->getClientFullName(),
                        $invoice->getVehicleFullName(),
                        $invoice->getStatus()->label(),
                        count($methods) > 1 ? PaymentReport::methodLabel($methods) : $invoice->getPaymentType()?->getName(),
                        $invoice->getSubtotal(),
                        $invoice->getSalesTax(),
                        $invoice->getTotalPrice(),
                    ];
                }
            };
            $batch = [];
            foreach ($invoices as $invoice) {
                $batch[] = $invoice;
                if (count($batch) === 200) {
                    yield from $rows($batch);
                    $batch = [];
                }
            }
            yield from $rows($batch);
        })());
    }

    /** Accounting › Invoices: every invoice, newest first, and the sidebar Invoice # box's results. */
    #[Route('/admin/invoices', name: 'maxeme_invoice_index', methods: ['GET'])]
    #[RequiresPermission(Permission::ACCOUNTING_VIEW)]
    public function index(Request $request): Response
    {
        $find = SearchTerm::fromRequest($request);

        $page = $this->repository->findPage($find, ListQuery::fromRequest($request, array_keys(InvoiceRepository::LIST_SORTS), 'desc'));

        return $this->render('maxeme/invoice/index.html.twig', [
            'page' => $page,
            'find' => $find,
            // Mockup 0a4baef: unpaid invoices whose repair order changed since.
            'outOfDate' => array_map(static fn (Invoice $invoice): int => (int) $invoice->getId(), array_values(array_filter($page->items, $this->repairOrderInvoicing->isOutOfDate(...)))),
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

    /** The Remark (mockups 649ae8e, d2fbfc8, df38f34): saved on its own, on a paid invoice too; JSON for the Payment report's pencil. */
    #[Route('/admin/invoices/{invoiceKey}/remark', name: 'maxeme_invoice_remark', methods: ['POST'])]
    #[RequiresPermission(Permission::ACCOUNTING_EDIT)]
    public function remark(#[MapEntity(mapping: ['invoiceKey' => 'invoiceKey'])] Invoice $invoice, Request $request, EntityManagerInterface $entityManager): Response
    {
        $note = mb_substr(trim((string) $request->request->get('admin_note', '')), 0, 1000);
        $invoice->setAdminNote($note);
        $entityManager->flush();
        $message = sprintf('Remark %s for %s.', $note !== '' ? 'saved' : 'removed', $this->numbers->number($invoice));
        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return $this->json(['message' => $message, 'remark' => $invoice->getAdminNote()]);
        }
        $this->addFlash('success', $message);

        return $this->redirectToRoute('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()]);
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
            // Payment ▾ (mockup 345c037): Mark Paid (the whole balance), Add Payment, View Payments.
            'paymentMenu' => $this->isGranted(Permission::ACCOUNTING_VIEW) ? [
                'count' => count($this->invoicePayments->of($invoice)),
                'balance' => Money::fromCents($this->invoicePayments->balanceCents($invoice)),
                'canEdit' => $this->isGranted(Permission::ACCOUNTING_EDIT) && $invoice->getStatus() !== InvoiceStatus::Cancelled,
                'types' => $this->paymentTypes->findActive(),
            ] : null,
            // Mockup 84d136d: asked after a payment made the invoice Paid while the repair order is open.
            // Mockup 0a4baef: the repair order changed since this unpaid invoice was issued.
            'outOfDate' => $this->repairOrderInvoicing->isOutOfDate($invoice),
            'offerClose' => $repairOrder !== null && $this->isGranted(Permission::WORK_ORDER_EDIT) && $this->repairOrderInvoicing->paidWhileOpen($repairOrder),
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
        try {
            $this->invoices->save($invoice, $data, $intent);
        } catch (\DomainException $exception) {
            return $this->renderForm($invoice, ['discount' => $exception->getMessage()], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        // The builder's own payment (an invoice with no repair order) becomes a payment row (mockup 345c037).
        $this->invoicePayments->adoptBuilderPayment($invoice);
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
