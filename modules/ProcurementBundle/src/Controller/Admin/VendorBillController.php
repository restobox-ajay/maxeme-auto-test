<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Contract\Tax\TaxContext;
use App\Contract\Tax\TaxLine;
use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Exception\DocumentLocked;
use App\Repository\BundleStatusRepository;
use App\Service\Document\DocumentLockService;
use App\Service\DocumentActorResolver;
use App\Service\RegionSeedData;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Billing\OverBillingGuard;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLine;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLineSnapshot;
use ProcurementBundle\Contract\Purchase\PurchaseFeeContext;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillAddress;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Entity\VendorBillLog;
use ProcurementBundle\Entity\VendorBillPayment;
use ProcurementBundle\Entity\VendorBillPaymentApplication;
use ProcurementBundle\Entity\VendorPrice;
use ProcurementBundle\Enum\VendorBillStatus;
use App\Http\RequestedParent;
use ProcurementBundle\Match\ThreeWayMatchService;
use ProcurementBundle\Numbering\PurchaseDocumentNumberGenerator;
use ProcurementBundle\Payment\VendorBillPaymentMover;
use ProcurementBundle\Product\ProductPicker;
use ProcurementBundle\Purchase\PurchaseDocumentChargeLines;
use ProcurementBundle\Purchase\PurchaseFeeCalculatorResolver;
use ProcurementBundle\Purchase\PurchaseTaxBreakdown;
use ProcurementBundle\Purchase\ResolvedPurchaseLine;
use ProcurementBundle\Purchase\VendorBillLineReconciler;
use ProcurementBundle\Repository\VendorBillRepository;
use ProcurementBundle\Repository\VendorPriceRepository;
use ProcurementBundle\Status\VendorBillStatusDeriver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vendor bills (#555, #658): enter one, match it, approve it, pay it, dispute it, void it.
 *
 * Every status change is a **named action** on the entity taking the actor this controller
 * resolved — there is no `setStatus()` to post to, for the reason VendorBill's docblock gives. The
 * three money-derived statuses (Open, Partially Paid, Paid) have no route at all: they are a
 * projection of what has been paid, written by VendorBillStatusDeriver.
 *
 * Approving is where the three-way match earns its place. The match summary is written onto the
 * bill's timeline **at approval time** and not recomputed later, because the question an audit asks
 * is what the match said when the money was authorised — a late delivery can make today's match
 * disagree with it, and that disagreement is information rather than an error.
 *
 * ## What #658 changed here
 *
 *  - **The save refuses over-billing.** `OverBillingGuard` runs before anything is written, so a
 *    bill charging for more than its purchase order has left is refused with nothing persisted. See
 *    that class for why the refusal is per line at save rather than document-level at approval.
 *  - **A line can name a product.** The save has always read `product_id`; no form emitted one, so a
 *    standalone bill could not name a product at all. It now renders the shared picker — the same
 *    one every other purchase screen uses — and reads it through `productIdFrom()`, including its
 *    no-JS fallback.
 *  - **Tax is calculated, not typed.** The flat `tax` field is gone. Tax comes from the SHARED
 *    calculators through `PurchaseTaxBreakdown`, per line, by tax class; what the vendor charged is
 *    entered as a manual tax row that sits beside the calculated ones.
 *  - **Charges are rows.** Freight, brokerage and duty are `PurchaseChargeLine`s on the document,
 *    with a subscription seam (`PurchaseFeeCalculatorResolver`) for bundles to add their own later.
 *  - **Payments are rows too.** `vendor_bill.amount_paid` was one cumulative figure overwritten by
 *    whoever typed last; there is now a payments screen mirroring the invoice's, one row per
 *    payment, and the paid figure is their sum.
 */
#[Route('/admin/bundles/procurement/bills')]
final class VendorBillController extends AbstractProcurementController
{
    use RendersAPrintableDocument;

    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly PurchaseDocumentNumberGenerator $numbers,
        private readonly ThreeWayMatchService $matcher,
        private readonly VendorBillStatusDeriver $statusDeriver,
        private readonly ProductPicker $picker,
        private readonly OverBillingGuard $overBilling,
        private readonly PurchaseFeeCalculatorResolver $feeCalculators,
        private readonly PurchaseTaxBreakdown $taxBreakdown,
        private readonly \ProcurementBundle\Repository\PurchaseOrderRepository $orders,
        private readonly VendorBillPaymentMover $paymentMover,
        private readonly VendorPriceRepository $vendorPrices,
        private readonly VendorBillLineReconciler $lineReconciler,
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    #[Route('', name: 'admin_bundle_procurement_bills', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['q', 'status', 'vendor', 'payable', 'dateFrom', 'dateTo']);
        // Anything that is not a calendar date reads as no filter at all, rather than reaching the
        // query as a string SQLite would happily compare against and silently return nothing for.
        $filters['dateFrom'] = (string) ($this->calendarDate($filters['dateFrom']) ?? '');
        $filters['dateTo'] = (string) ($this->calendarDate($filters['dateTo']) ?? '');
        $paging = $this->paging($request, 'date', 'desc');

        /** @var VendorBillRepository $repo */
        $repo = $this->em->getRepository(VendorBill::class);
        $result = $repo->search($filters, $paging['page'], $paging['limit'], $paging['sort'], $paging['dir']);

        return $this->render('@Procurement/bills.html.twig', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'vendors' => $this->activeVendors(),
            'statuses' => VendorBillStatus::cases(),
            'filters' => $filters,
            'page' => $paging['page'],
            'limit' => $paging['limit'],
            'pages' => max(1, (int) ceil($result['total'] / $paging['limit'])),
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    /**
     * Enter a bill — standalone, or against a purchase order named by `?po=`.
     *
     * The buy-side equivalent of the sell side's add-invoice page. Standalone is the case that was
     * missing in practice rather than in routing: the route existed, but with no product field a
     * bill raised without a PO could not name what it was for.
     */
    #[Route('/new', name: 'admin_bundle_procurement_bill_new', methods: ['GET'])]
    public function form(Request $request): Response
    {
        $this->denyIfInactive();

        // `?po=` through the three-state read (queue item 51). The `getInt()` this replaces threw a
        // raw 400 on `?po=abc` and on `?po=`, and on `?po=99999` rendered the STANDALONE bill form —
        // identical to the one `/bills/new` gives with no parameter at all, so somebody who clicked
        // "Enter a bill" from a purchase order got a form that would bill nobody, silently.
        $requestedOrder = $this->requestedParent($request, 'po', PurchaseOrder::class, 'purchase order');
        $order = $requestedOrder->entity();

        return $this->render('@Procurement/vendor_bill/edit.html.twig', $this->formContext(null, $order) + [
            'badParents' => $this->unresolvedParents($requestedOrder),
        ] + $this->editFrameContext());
    }

    /**
     * `?dispute=` pre-fills the dispute form's reason and nothing else.
     *
     * The exceptions screen links here with the match's own finding in it — "Widget: billed but not
     * received." — because `VendorBill::dispute()` refuses an empty reason and a list screen cannot
     * honestly type one on somebody's behalf. It is a suggestion in an editable box; the person
     * still submits the form. Read here rather than in the template because `InputBag::get()`
     * raises on `?dispute[]=x`, which would turn a crafted URL into a 400 from nothing.
     */
    /**
     * Live calculated-fee preview, copied from PurchaseOrderController::feeLinesAjax() and adapted
     * (#full-parity, 2026-09-14): the browser's own live charge/fee/tax editing subsystem
     * (app.js's scheduleAdminFeeRefresh('bill')) calls this with whatever the form currently shows,
     * the same way PO's own does — no preview VendorBill entity is built, since
     * PurchaseFeeContext takes plain values directly.
     */
    #[Route('/fee-lines', name: 'admin_bundle_procurement_bill_fee_lines', methods: ['GET'])]
    public function feeLinesAjax(Request $request): Response
    {
        $vendor = $this->em->find(Vendor::class, (int) $request->query->get('vendor_id', 0));
        if (!$vendor instanceof Vendor) {
            return $this->json(['lines' => [], 'total' => 0]);
        }

        $warehouse = $this->em->find(Warehouse::class, (int) $request->query->get('warehouse_id', 0));
        $province = $warehouse instanceof Warehouse ? (string) $warehouse->getProvince() : '';

        $decoded = json_decode((string) $request->query->get('lines', '[]'), true);
        $lines = [];
        foreach (is_array($decoded) ? $decoded : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $product = $this->em->find(ProductCore::class, (int) ($row['product_id'] ?? 0));
            if (!$product instanceof ProductCore) {
                continue;
            }
            $lines[] = ['product' => $product, 'quantity' => (float) ($row['qty'] ?? 0)];
        }

        $context = new PurchaseFeeContext(
            $vendor,
            $province,
            $vendor->getCurrency() ?: 'CAD',
            (float) $request->query->get('subtotal', 0),
            $lines,
        );
        $feeLines = $this->feeCalculators->calculate($context);
        $total = array_sum(array_map(static fn (PurchaseChargeLine $l): float => $l->amount, $feeLines));

        return $this->json([
            'lines' => array_map(
                static fn (PurchaseChargeLine $l): array => [
                    'slug' => $l->slug, 'label' => $l->label, 'taxClass' => $l->taxClass,
                    'amount' => $l->amount, 'type' => $l->type, 'source' => $l->source,
                ],
                $feeLines,
            ),
            'total' => round($total, 2),
        ]);
    }

    /**
     * Live calculated-tax preview, copied from PurchaseOrderController::taxBreakdownAjax() and
     * adapted (#full-parity, 2026-09-14): `PurchaseTaxBreakdown::computeRows()` is the same
     * document-agnostic province-in, rows-out rule a real save calls, reached directly with the
     * rows the browser is currently showing. The one typed freight row's amount is folded in as a
     * single preview charge line at the document's live highest tax class, mirroring PO's own.
     */
    #[Route('/tax-breakdown', name: 'admin_bundle_procurement_bill_tax_breakdown', methods: ['GET'])]
    public function taxBreakdownAjax(Request $request): Response
    {
        $warehouse = $this->em->find(Warehouse::class, (int) $request->query->get('warehouse_id', 0));
        $province = $warehouse instanceof Warehouse ? (string) $warehouse->getProvince() : '';

        $decoded = json_decode((string) $request->query->get('lines', '[]'), true);
        $rows = [];
        $codes = [];
        foreach (is_array($decoded) ? $decoded : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $taxClass = (string) ($row['tax_code'] ?? '') ?: null;
            $rows[] = ['subtotal' => (float) ($row['subtotal'] ?? 0), 'taxClass' => $taxClass];
            $codes[] = $taxClass;
        }

        $freight = max(0.0, (float) $request->query->get('freight', 0));
        $chargeLines = $freight > 0
            ? [new PurchaseChargeLine(
                'freight',
                'Freight',
                TaxContext::resolveHighestTaxClass($codes),
                $freight,
                PurchaseChargeLine::PLACEMENT_MAIN_LINE,
                PurchaseChargeLine::TYPE_FREIGHT,
                PurchaseChargeLine::SOURCE_MANUAL,
            )]
            : [];

        $breakdown = $this->taxBreakdown->computeRows($province, $rows, $chargeLines);

        return $this->json([
            'lines' => array_map(
                static fn (TaxLine $l): array => ['label' => $l->label, 'rate' => $l->rate, 'amount' => $l->amount, 'slug' => $l->slug, 'source' => $l->source],
                $breakdown['lines'],
            ),
            'total' => $breakdown['total'],
            'perLineTax' => $breakdown['perLineTax'],
            'perLineTaxLabel' => $breakdown['perLineTaxLabel'],
        ]);
    }

    #[Route('/{id}', name: 'admin_bundle_procurement_bill', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $bill = $this->billOr404($id);
        $breakdown = $this->taxBreakdown->frozenFromJson($bill->getTaxLines());
        $suggested = $request->query->all()['dispute'] ?? '';

        return $this->render('@Procurement/vendor_bill/detail.html.twig', [
            'bill' => $bill,
            'report' => $this->matcher->match($bill),
            // Do this bill's match exceptions reach the Exceptions worklist, or is this page the
            // only screen in the application that will ever mention them?
            //
            // The match itself has no status predicate and no cap — it reports on the bill you
            // opened, whatever state it is in — while `ExceptionController` covers only the bills
            // `VendorBillStatus::counts()` is true for. Both were right and neither said so, so a
            // draft bill announced "10 exception(s). Every one needs a human." beside a worklist
            // showing none, and the user believed the wrong one.
            //
            // Read from `counts()` here rather than compared against a list of statuses in the
            // template, because `ExceptionController::billsInScope()` binds the SAME predicate's
            // enumeration to its query. A second copy of `[Draft, Void]` in a template is two lists
            // that must agree with nothing making them.
            'onExceptionWorklist' => $bill->getStatusEnum()->counts(),
            'chargeRows' => $bill->getChargeLineRows(),
            'taxLines' => $breakdown['lines'] ?? [],
            'suggestedDisputeReason' => \is_scalar($suggested) ? mb_substr(trim((string) $suggested), 0, 255) : '',
            // Per-line tax, off the document's OWN frozen snapshot and keyed by position — the same
            // two arrays the invoice detail and the purchase order detail render, so a bill can
            // finally state what each of its lines came to and what tax was taken on it.
            'perLineTax' => $breakdown['perLineTax'] ?? [],
            'perLineTaxLabel' => $breakdown['perLineTaxLabel'] ?? [],
            // Adapted into the same shape `_purchase_detail_activity_log.html.twig` now reads off
            // AuditLog for PurchaseOrder (56ec9f52, #full-parity, 2026-09-14) — occurredAt/
            // actorName/summary/action rather than VendorBillLog's own createdAt/userName/comment/
            // type. VendorBillLog has not itself converged onto AuditLog (its write paths still
            // construct it directly), so the adapting happens here at the read side rather than
            // teaching the shared partial a second field-name set for one caller.
            'logs' => array_map(
                static fn (VendorBillLog $log): array => [
                    'id' => $log->getId(),
                    'occurredAt' => $log->getCreatedAt(),
                    'actorName' => $log->getUserName(),
                    'summary' => $log->getComment(),
                    'action' => $log->getType(),
                ],
                array_reverse($bill->getLogs()->toArray()),
            ),
        ]);
    }

    /**
     * The bill as a document — on screen, and as the PDF with `?pdf=1`.
     *
     * The invoice has had both since #539 and the purchase order since #555; the bill had neither,
     * which is the largest single row in the bill-versus-invoice comparison behind queue item 45 and
     * one the owner has already ruled on generally: "every doc has a pdf and print version, same
     * button layouts." One template for both, exactly as
     * `PurchaseOrderController::document()` and `InvoiceController::document()` do it, so the sheet
     * somebody signs off is byte-for-byte the one they read on screen.
     *
     * Unlike the purchase order's, this document is never emailed: a bill has no counterparty-facing
     * existence — the vendor issued the invoice it was typed from and already has their own copy.
     * So there is a print route and no send route, and that is a real asymmetry rather than a gap.
     */
    #[Route('/{id}/print', name: 'admin_bundle_procurement_bill_print', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function document(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $bill = $this->billOr404($id);
        $breakdown = $this->taxBreakdown->frozenFromJson($bill->getTaxLines());
        $isPdf = $request->query->get('pdf') === '1';

        $html = $this->renderView('@Procurement/bill_document.html.twig', [
            'bill' => $bill,
            'is_pdf' => $isPdf,
            'chargeRows' => $bill->getChargeLineRows(),
            'taxRows' => $breakdown['lines'] ?? [],
            'perLineTax' => $breakdown['perLineTax'] ?? [],
        ]);

        return $isPdf
            ? $this->pdfResponse($this->dompdf($html), sprintf('VendorBill-%s.pdf', $bill->getBillNumber()))
            : new Response($html);
    }

    #[Route('/{id}/edit', name: 'admin_bundle_procurement_bill_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id, DocumentLockService $locks): Response
    {
        $this->denyIfInactive();

        $bill = $this->billOr404($id);

        // The EXPLICIT lock, asked separately from save()'s Draft-only status rule (#759) — same
        // pair, same reasoning as OrderController::edit(). Refused on GET as well as POST, caught
        // locally so the refusal lands on the bill's own detail page, where Unlock actually is.
        try {
            $locks->assertWritable($bill, 'edited');
        } catch (DocumentLocked $locked) {
            $this->addFlash('error', $locked->getMessage());

            return $this->redirectToRoute('admin_bundle_procurement_bill', ['id' => $bill->getId()]);
        }

        return $this->render('@Procurement/vendor_bill/edit.html.twig', $this->formContext($bill, $bill->getPurchaseOrder()) + [
            // An existing bill was reached by its own id through a `\d+` route requirement, so there
            // is no parent id in the URL to have been wrong. Passed explicitly rather than left
            // undefined: `strict_variables` is on.
            'badParents' => [],
        ] + $this->editFrameContext());
    }

    /**
     * Create or update a draft bill.
     *
     * Refused once approved, for the reason an issued PO is: an approved bill is an authorisation
     * for money to leave, and editing the figures behind it afterwards would make the authorisation
     * refer to something that no longer exists. Dispute it, or void it and enter another.
     *
     * ## Everything is validated before anything is written
     *
     * The posted rows are resolved and checked — the over-billing guard, then the charges — while
     * the bill is still untouched. A refusal therefore leaves the document exactly as it was, which
     * is the difference between "your bill was not saved" and "half of your bill was saved". It is
     * also what makes the guard testable: the first bill's stored figures are provably unchanged by
     * the second bill's refusal.
     */
    #[Route('/save', name: 'admin_bundle_procurement_bill_save', methods: ['POST'])]
    public function save(Request $request, DocumentLockService $locks): Response
    {
        $this->denyIfInactive();

        $id = $request->request->getInt('id', 0);
        $bill = $id > 0 ? $this->billOr404($id) : null;

        if ($bill instanceof VendorBill) {
            try {
                $locks->assertWritable($bill, 'edited');
            } catch (DocumentLocked $locked) {
                $this->addFlash('error', $locked->getMessage());

                return $this->redirectToRoute('admin_bundle_procurement_bill', ['id' => $bill->getId()]);
            }
        }

        if ($bill instanceof VendorBill && !$bill->canEditOnStatus()) {
            // "Dispute it" is not an answer for a bill that is ALREADY disputed, and a disputed bill
            // is exactly what somebody following the approval refusal's "re-save this draft" used to
            // land here with — one impossible instruction handing over to another. Asked of the bill
            // rather than branched on the status, so this and the control on the detail page agree.
            $this->addFlash('error', sprintf(
                'Bill %s is %s and cannot be edited. %s',
                $bill->getBillNumber(),
                $bill->getStatus(),
                $bill->canReturnToDraft()
                    ? 'Return it to draft — the control is on the bill\'s own page — and then edit it.'
                    : 'Dispute it, or void it and enter another.',
            ));

            return $this->redirectToRoute('admin_bundle_procurement_bill', ['id' => $bill->getId()]);
        }

        $orderId = $this->optionalId($request, 'purchase_order_id');
        $order = $orderId > 0 ? $this->em->find(PurchaseOrder::class, $orderId) : null;
        $order = $order instanceof PurchaseOrder ? $order : null;

        // The order decides the vendor when there is one: a bill against a PO is by definition from
        // that PO's vendor, and letting the two disagree would make every match meaningless.
        //
        // Which is exactly why attaching an EXISTING bill to another vendor's order is refused here
        // rather than silently repointing the document. The picker only offers this vendor's orders,
        // so reaching this is a crafted post — but "the order wins" plus a free choice of order is a
        // way to change a saved bill's vendor without ever naming one, and the over-billing guard's
        // whole meaning rests on the bill and the order being about the same money.
        if ($bill instanceof VendorBill && $order instanceof PurchaseOrder
            && $order->getVendor()->getId() !== $bill->getVendor()->getId()) {
            $this->addFlash('error', sprintf(
                'Bill %s is from %s and %s is %s\'s order. A bill may only be attached to an order from the same vendor.',
                $bill->getBillNumber(),
                $bill->getVendorName(),
                $order->getPoNumber(),
                $order->getVendorName(),
            ));

            return $this->redirectToRoute('admin_bundle_procurement_bill_edit', ['id' => $bill->getId()]);
        }

        $vendor = $order?->getVendor() ?? $this->vendorOr404($request->request->getInt('vendor_id', 0));

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $request->request->all('lines');

        // The row a ✕ asked to drop. `-1` rather than 0 for "none": row 0 is a real row and getInt()
        // answers 0 for an absent field, so a default of 0 would delete the first line of every
        // bill saved with the button not pressed. Straight from RfqController, which learned it
        // first.
        $removedLine = $request->request->has('remove_line') ? $request->request->getInt('remove_line', -1) : -1;
        if ($removedLine >= 0) {
            unset($rows[$removedLine]);
        }

        // The charge rows, resolved at the door and BEFORE the document is touched — the buy-side's
        // ONE charge vocabulary (#635), the same PurchaseOrderController::save() reads: this form
        // now posts `charge_lines[i][label|amount|type|slug|taxClass|placement]`, not the bill's own
        // former `charges[i][label|amount|tax_class|placement|type]` shape, and a manual tax
        // adjustment is a `type: 'tax'` row in that same array rather than the separate
        // `vendor_tax_label`/`vendor_tax` fields this form used to carry.
        $chargesRaw = PurchaseDocumentChargeLines::withoutRemovedRow(
            $request->request->all('charge_lines'),
            $this->nullable((string) $request->request->get('remove_charge_line', '')),
        );

        // "No charge rows arrived" is ambiguous: it means either "the admin removed every charge
        // row" or "this post was never shown them". `charge_lines_present` is what tells the two
        // apart — the form always sends it, so its ABSENCE means the post came from somewhere that
        // has never heard of charges, and erasing a bill's freight because something re-saved it
        // would be losing data nobody asked to lose. Same flag, same reasoning, as the purchase
        // order form's own.
        if (!$request->request->has('charge_lines_present') && $chargesRaw === [] && $bill instanceof VendorBill) {
            $chargesRaw = array_merge(
                PurchaseDocumentChargeLines::fromChargeLines($bill->getChargeLineRows()),
                $this->taxBreakdown->manualTaxChargeRowsFrom($this->taxBreakdown->frozenFromJson($bill->getTaxLines())),
            );
        }
        if ($request->request->has('add_charge_line')) {
            $added = PurchaseDocumentChargeLines::rowFromAddLineChoice((string) $request->request->get('charge_line_type', ''));
            if ($added !== null) {
                $chargesRaw[] = $added;
            }
        }

        // Refused at the door rather than discovered halfway through, same as the purchase order
        // form's own save.
        $chargeError = PurchaseDocumentChargeLines::errorFor($chargesRaw);
        if ($chargeError !== null) {
            $this->addFlash('error', $chargeError);

            return $bill instanceof VendorBill
                ? $this->redirectToRoute('admin_bundle_procurement_bill_edit', ['id' => $bill->getId()])
                : $this->redirectToRoute('admin_bundle_procurement_bill_new', $order instanceof PurchaseOrder ? ['po' => $order->getId()] : []);
        }

        $charges = PurchaseDocumentChargeLines::normalize($chargesRaw);

        /** @var array<int, VendorBillLine> $existingLines */
        $existingLines = [];
        foreach ($bill?->getLines() ?? [] as $existingLine) {
            $existingLines[(int) $existingLine->getId()] = $existingLine;
        }

        $reconciled = $this->lineReconciler->reconcile($existingLines, $rows, $vendor, $order);

        try {
            // OverBillingGuard predates the reconciler and reads its own narrower shape — `line` is
            // the ATTRIBUTED purchase order line, exactly what `$resolved->attributedLine` already
            // is, so this needs no lookup of its own.
            $requestedForGuard = array_map(
                static fn (ResolvedPurchaseLine $line): array => [
                    'line' => $line->attributedLine,
                    'quantity' => (string) $line->baseQuantity,
                    'name' => $line->name,
                ],
                $reconciled['lines'],
            );
            $this->overBilling->assertWithinRemaining($order, $bill, $requestedForGuard);
        } catch (\DomainException $e) {
            // Nothing has been touched: the bill, its lines and its totals are exactly as they were,
            // and a new bill was never created. The person is sent back to the form they posted.
            $this->addFlash('error', $e->getMessage());

            return $bill instanceof VendorBill
                ? $this->redirectToRoute('admin_bundle_procurement_bill_edit', ['id' => $bill->getId()])
                : $this->redirectToRoute('admin_bundle_procurement_bill_new', $order instanceof PurchaseOrder ? ['po' => $order->getId()] : []);
        }

        if (!$bill instanceof VendorBill) {
            $bill = (new VendorBill())
                ->setBillNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_BILL));
            $this->em->persist($bill);
        }

        $bill
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->setPurchaseOrder($order)
            ->setCurrency($order?->getCurrency() ?? $vendor->getCurrency())
            ->setVendorInvoiceNo($this->nullable((string) $request->request->get('vendor_invoice_no', '')))
            ->setDocumentDate($this->calendarDate((string) $request->request->get('document_date', '')) ?? (new \DateTimeImmutable())->format('Y-m-d'))
            ->setDueDate($this->calendarDate((string) $request->request->get('due_date', '')))
            ->setNotes($this->nullable((string) $request->request->get('notes', '')));

        // The Remit To address panel — see _purchase_address_cards.html.twig and
        // PurchaseOrderController::save()'s own twin of this block. The "Load address" submit posts
        // the chosen book entry under `remit_to_address_book_id`, shifted onto `remit_to_address_id`
        // here before the shared applier reads it.
        if ($request->request->has('apply_' . VendorBillAddress::TYPE_REMIT_TO . '_address')) {
            $request->request->set(
                VendorBillAddress::TYPE_REMIT_TO . '_address_id',
                $request->request->get(VendorBillAddress::TYPE_REMIT_TO . '_address_book_id', ''),
            );
        }
        $this->applyPurchaseAddressFromRequest($bill, $vendor, $request, VendorBillAddress::TYPE_REMIT_TO);

        // The tax province, DERIVED rather than typed (queue item 32). Nothing on this form carries
        // a province any more: the receiving warehouse is named and its address answers. It happens
        // here, before the tax breakdown below reads getTaxProvince(), so a bill's frozen province
        // and its frozen tax_lines are written from one value in one breath and cannot disagree.
        //
        // The order wins when there is one, exactly as it already does for the vendor: goods on a
        // purchase order land where that order says, and letting the bill name a different building
        // would put the same fact in two places again — which is the defect this closes.
        $bill->deriveTaxProvinceFrom($this->receivingWarehouse($request, $order));

        // Where paying this bill sends the money, frozen once (#606). Only when the column is still
        // null, which is the same rule PurchaseOrder::freezeVendorSnapshot() applies: re-saving a
        // bill to fix a typo in its line quantities must not silently repoint an already-recorded
        // remit-to at whatever the vendor record says today.
        //
        // The structured VendorBillAddress row just written above (#635) is preferred over reading
        // the vendor's book directly, for the same reason PurchaseOrder::freezeVendorSnapshot()
        // prefers its own order-to snapshot: an admin may have overridden the address on this
        // document, and the printed remit-to must say what THIS bill actually recorded, not
        // whatever the vendor's address book happens to hold today. Vendor::getRemitToAddress()
        // stays the fallback for the same reason it always was — a vendor with one address gets
        // that one, and this column must still populate on the very first save.
        if ($bill->getRemitToAddress() === null) {
            $remitTo = $bill->getEffectiveRemitToAddress();
            $bill->setRemitToAddress($remitTo !== null && trim($remitTo->toSnapshot()) !== ''
                ? $remitTo->toSnapshot()
                : $vendor->getRemitToAddress()?->toSnapshot());
        }

        // Only the rows this save did not carry are removed — upserted by id through the same
        // PurchaseSideLineReconciler PurchaseOrderController already uses, rather than rebuilt from
        // scratch on every save. Unattributed first, so the purchase order line a REMOVED row
        // pointed at stops counting it in the same breath the row is dropped — anything reading a
        // billed quantity later in this request would otherwise read the in-memory graph stale.
        // orphanRemoval deletes the row at flush either way. A KEPT row's own attribution is left to
        // the upsert loop below, which re-applies whatever the post itself named.
        foreach ($existingLines as $existingId => $existingLine) {
            if (!isset($reconciled['keptIds'][$existingId])) {
                $existingLine->setPurchaseOrderLine(null);
                $bill->removeLine($existingLine);
            }
        }

        $subtotal = 0;
        foreach ($reconciled['lines'] as $resolved) {
            $line = $resolved->existingId !== null ? $existingLines[$resolved->existingId] : new VendorBillLine();

            $line
                ->setPurchaseOrderLine($resolved->attributedLine instanceof PurchaseOrderLine ? $resolved->attributedLine : null)
                ->setProduct($resolved->product)
                ->setName($resolved->name)
                ->setSku($resolved->sku)
                ->setVendorSku($resolved->vendorSku)
                ->setSubtotal($resolved->subtotal)
                ->setTaxCode($resolved->taxCode)
                ->setUnit($resolved->unit)
                ->setWeight($resolved->weight)
                ->setBatch($resolved->batch)
                ->setSortOrder($resolved->sortOrder);

            // Written only when they actually changed — see ResolvedPurchaseLine's own docblock.
            if ($resolved->writeQuantity) {
                $this->applyLineQuantity(
                    $line,
                    $resolved->enteredQuantity,
                    $resolved->baseQuantity,
                    $resolved->lineUnit,
                    $resolved->baseUnit,
                    static fn (string $base) => $line->setQuantity($base),
                );
            }
            if ($resolved->writeUnitCost) {
                $line->setUnitCost($resolved->unitCost);
            }

            $bill->addLine($line);
            $this->em->persist($line);
            $subtotal += VendorBill::cents($resolved->subtotal);
        }

        // The header figure the charge and tax layers price against, written before either is asked:
        // a document states what it is worth, and a save that has just rebuilt its lines is the only
        // thing that knows it. Same rule as FeeContext::fromDocument()'s on the sell side.
        $bill->setSubtotal(VendorBill::money($subtotal));

        // Freight rows take the document's highest goods tax class — the same rule
        // PurchaseOrderController::save() applies, resolved from the just-rebuilt lines rather than
        // frozen when the admin typed the row, so adding a taxable product afterwards still lifts
        // it.
        $chargeLines = array_merge(
            PurchaseDocumentChargeLines::toFreightLines(
                $charges,
                TaxContext::resolveHighestTaxClass(array_map(static fn (ResolvedPurchaseLine $line): ?string => $line->taxCode, $reconciled['lines'])),
            ),
            PurchaseDocumentChargeLines::toFeeLines($charges),
            // The seam: whatever bundles have registered a purchase fee calculator contribute their
            // own rows beside the typed ones. Nothing is tagged today, so this is an empty list and
            // the document's charges are exactly what an admin entered — which is the point of
            // shipping the seam before a subscriber exists rather than inventing freight rules
            // nobody stated.
            $this->feeCalculators->calculate(PurchaseFeeContext::fromVendorBill($bill)),
        );

        $bill->setChargeLines(PurchaseChargeLineSnapshot::encode($chargeLines));

        // Tax LAST, because it is taken on the goods and on the charges both — and the charges have
        // only just been settled. A `type: 'tax'` charge row is an amount read off the vendor's
        // paperwork, no rate, no class, no calculator behind it — same rule
        // PurchaseOrderController::save() applies through the same helper.
        $breakdown = $this->taxBreakdown->computeRows(
            (string) ($bill->getTaxProvince() ?? ''),
            array_map(
                static fn (ResolvedPurchaseLine $line): array => ['subtotal' => (float) $line->subtotal, 'taxClass' => $line->taxCode],
                $reconciled['lines'],
            ),
            $chargeLines,
            $this->taxBreakdown->manualTaxLinesFromCharges($charges),
        );

        $bill
            ->setTaxLines($this->taxBreakdown->toJson($breakdown))
            ->setTax(VendorBill::money(PurchaseTaxBreakdown::totalCents($breakdown)))
            ->recalculateTotals();

        $this->em->flush();

        $this->addFlash('success', sprintf('Bill %s saved as a draft.', $bill->getBillNumber()));

        // The duplicate-payment guard, as a warning and never a block. Paying the same invoice
        // twice is the most expensive clerical error in AP and it happens because the same PDF
        // arrives twice by email — but vendors do reuse their own numbers, so blocking would
        // eventually force a real bill in under a made-up number.
        if ($this->matcher->hasDuplicateVendorInvoiceNumber($bill)) {
            $this->addFlash('error', sprintf(
                'Vendor invoice %s is already on file for %s. Check it is not the same bill arriving twice before approving this one.',
                (string) $bill->getVendorInvoiceNo(),
                $bill->getVendorName(),
            ));
        }

        // "Save & add more lines" lands back on the form with a fresh set of blank rows, which is how
        // a no-JS admin enters a ten-line vendor invoice: fill the spares, save, repeat. Dropping a
        // line or a charge row goes back there too — you are still editing, and so is a "Load
        // address" submit or a charge-bar "Add Line".
        return ((string) $request->request->get('action', '') === 'save_continue'
            || $removedLine >= 0
            || $request->request->has('add_charge_line')
            || $request->request->has('remove_charge_line')
            || $request->request->has('apply_' . VendorBillAddress::TYPE_REMIT_TO . '_address')
        )
            ? $this->redirectToRoute('admin_bundle_procurement_bill_edit', ['id' => $bill->getId()])
            : $this->redirectToRoute('admin_bundle_procurement_bill', ['id' => $bill->getId()]);
    }

    #[Route('/{id}/approve', name: 'admin_bundle_procurement_bill_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(int $id): Response
    {
        return $this->act($id, function (VendorBill $bill): string {
            $report = $this->matcher->match($bill);

            $bill->approve($this->actor(), $report->summary());
            // Derived immediately, so a bill approved with money already recorded against it does
            // not sit at Open until something else happens to touch it.
            $this->statusDeriver->recalculate($bill);

            return $report->isAutoApprovable()
                ? sprintf('Bill %s approved. The three-way match was clean.', $bill->getBillNumber())
                : sprintf('Bill %s approved with %d match exception(s) recorded on its timeline.', $bill->getBillNumber(), $report->exceptionCount());
        });
    }

    #[Route('/{id}/dispute', name: 'admin_bundle_procurement_bill_dispute', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function dispute(int $id, Request $request): Response
    {
        return $this->act($id, function (VendorBill $bill) use ($request): string {
            $bill->setStatus(VendorBillStatus::Disputed->value, $this->actor(), (string) $request->request->get('reason', ''));

            return sprintf('Bill %s disputed. It stays out of the payment run until somebody resolves it.', $bill->getBillNumber());
        });
    }

    /**
     * The third answer to a dispute, and the one that had no route: send it back to be corrected.
     *
     * Approving a disputed bill ("we argued, we lost") and voiding it ("the bill was wrong") were
     * both reachable. Correcting it was not — correcting a document means editing it, editing means
     * Draft, and nothing moved a bill back there. That left one state genuinely stuck: a bill
     * disputed while still a draft, with taxable goods and no tax province, was refused approval
     * with a message telling the reader to re-save the draft, while `save()` above refuses to edit
     * anything past Draft. See `VendorBill::returnToDraft()`.
     *
     * Nothing is derived afterwards, unlike `approve()`: `applyDerivedStatus()` refuses to move a
     * Draft at all, and the action itself refuses a bill carrying a payment, so there is no money
     * for a derivation to read.
     */
    #[Route('/{id}/return-to-draft', name: 'admin_bundle_procurement_bill_return_to_draft', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function returnToDraft(int $id, Request $request): Response
    {
        return $this->act($id, function (VendorBill $bill) use ($request): string {
            $bill->returnToDraft($this->actor(), $this->nullable((string) $request->request->get('reason', '')));

            return sprintf(
                'Bill %s is a draft again. Edit it, save it — which re-derives its tax province from the receiving '
                    . 'warehouse — and approve it when it is right.',
                $bill->getBillNumber(),
            );
        });
    }

    #[Route('/{id}/void', name: 'admin_bundle_procurement_bill_void', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function void(int $id, Request $request): Response
    {
        return $this->act($id, function (VendorBill $bill) use ($request): string {
            $reason = $this->nullable((string) $request->request->get('reason', ''));

            $bill->setStatus(
                VendorBillStatus::Void->value,
                $this->actor(),
                $reason !== null ? sprintf('Bill voided: %s', $reason) : null,
            );

            return sprintf('Bill %s voided. It keeps its number.', $bill->getBillNumber());
        });
    }

    /** Freeze it (#759) — the buy-side counterpart of `InvoiceController::lockInvoice()` etc. */
    #[Route('/{id}/lock', name: 'admin_bundle_procurement_bill_lock', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function lock(int $id, Request $request, DocumentLockService $locks): Response
    {
        $this->denyIfInactive();

        $bill = $this->billOr404($id);

        $locks->lock($bill, $this->actor(), (string) $request->request->get('reason', ''), $this->em);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Bill %s is locked. It can still be printed; nothing can edit, delete or record a payment '
                . 'against it until it is unlocked.',
            $bill->getBillNumber(),
        ));

        return $this->redirectToRoute('admin_bundle_procurement_bill', ['id' => $bill->getId()]);
    }

    /** Release the freeze. ROLE_SUPER_ADMIN, which ROLE_TECH_SUPPORT inherits — see DocumentLockService. */
    #[Route('/{id}/unlock', name: 'admin_bundle_procurement_bill_unlock', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unlock(int $id, DocumentLockService $locks): Response
    {
        $this->denyIfInactive();
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $bill = $this->billOr404($id);

        $locks->unlock($bill, $this->em);
        $this->em->flush();

        $this->addFlash('success', sprintf('Bill %s is unlocked and can be edited again.', $bill->getBillNumber()));

        return $this->redirectToRoute('admin_bundle_procurement_bill', ['id' => $bill->getId()]);
    }

    /**
     * The payments screen — what has been paid against this bill, and the form that records one.
     *
     * The mirror of `admin_invoice_payments`, and it exists because the bill had no equivalent: one
     * POST route overwrote a cumulative paid-to-date figure. You could not see which payments made
     * up the balance, could not correct a mis-keyed one without computing a new total by hand, and
     * two people paying the same bill clobbered each other's figure instead of summing.
     *
     * Nothing here writes a status. Recording and amending go through VendorBill's named actions,
     * which attach the row and write the timeline entry, and the status is derived from the rows by
     * VendorBillStatusDeriver.
     */
    #[Route('/{id}/payments', name: 'admin_bundle_procurement_bill_payments', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function payments(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $bill = $this->billOr404($id);

        if ($request->isMethod('POST')) {
            return $this->savePayment($bill, $request);
        }

        // Which row the form is editing, named in the URL rather than by a script. The invoice
        // screen populates its form from `data-` attributes in JavaScript, which leaves no way to
        // correct a payment with scripting off; `?payment=12` is a link, and this application's
        // standing rule is that JS is a veneer.
        //
        // Through the three-state read since queue item 51's second pass. `getInt()` made this the
        // same pair of failures every create screen on the buy side had: `?payment=abc` was a raw
        // 400, and `?payment=99999` — or a stale link to a payment recorded against a DIFFERENT
        // bill, which is the one that actually happens — silently rendered the blank "record a
        // payment" form. Somebody who clicked Correct expecting to fix a row would have typed a
        // SECOND payment onto the bill instead, and paid the vendor twice on paper.
        $requestedPayment = $this->requestedPayment($request, $bill);
        $editing = $requestedPayment->entity();

        return $this->render('@Procurement/bill_payments.html.twig', [
            'bill' => $bill,
            'editing' => $editing,
            'badParents' => $this->unresolvedParents($requestedPayment),
            'methods' => ['Bank Transfer', 'Cheque', 'Credit Card', 'Cash', 'E-Transfer'],
            // The dropdown queue item 34 is, from the user's side. Empty is a real answer and the
            // screen says so in words rather than rendering a select with nothing in it.
            'moveTargets' => $this->paymentMover->candidatesFor($bill),
        ]);
    }

    /**
     * The payment row `?payment=` names, looked for among THIS bill's rows.
     *
     * Membership rather than existence, and {@see RequestedParent::notOneOf()} rather than
     * `unresolved()`, because the two failures are one fact from this screen's side: the id is not
     * one of these payments. "There is no such payment" would be false for the stale-link case and
     * true for the typo, and the screen cannot tell a reader which without saying something wrong
     * to the other one.
     *
     * @return RequestedParent<VendorBillPaymentApplication>
     */
    private function requestedPayment(Request $request, VendorBill $bill): RequestedParent
    {
        $requestedId = RequestedParent::requestedIdIn($request->query->all(), 'payment');

        if ($requestedId === null) {
            return RequestedParent::none('payment');
        }

        foreach ($bill->getApplications() as $application) {
            if ((string) $application->getId() === $requestedId) {
                return RequestedParent::of($application, $requestedId, 'payment');
            }
        }

        return RequestedParent::notOneOf($requestedId, 'payment', "one of this bill's payments");
    }

    /**
     * Move a payment claim onto a different bill — queue item 34.
     *
     * A claim recorded against the wrong bill could only be DELETED and re-keyed, which loses the
     * date the money left, the method, the reference and the admin who recorded it unless somebody
     * copies all four by hand first. This withdraws the claim and reapplies the same underlying
     * payment elsewhere instead: same payment id, same everything else.
     *
     * The refusal path is the loud one. Every illegal move is answered with the sentence saying
     * which rule it broke and what to do instead — see {@see \App\Payment\PaymentApplicationGuard}
     * — and nothing is written when one is refused.
     */
    #[Route('/{id}/payments/move/{paymentId}', name: 'admin_bundle_procurement_bill_payment_move', requirements: ['id' => '\d+', 'paymentId' => '\d+'], methods: ['POST'])]
    public function movePayment(int $id, int $paymentId, Request $request): Response
    {
        $this->denyIfInactive();

        $bill = $this->billOr404($id);
        $application = $this->em->find(VendorBillPaymentApplication::class, $paymentId);
        // 'target_id': the shared document_payments partial's field name (#708), not
        // 'target_bill_id' any more — the invoice screen posts the identical form to its own move
        // route, and a field name is not a place for "which side of the business" to leak into.
        $targetId = $request->request->getInt('target_id', 0);

        try {
            if (!$application instanceof VendorBillPaymentApplication) {
                throw new \DomainException('That payment could not be found.');
            }

            // Said in the user's words rather than left as a browser validation message: with
            // scripting off, and on a hand-made POST, an empty select arrives here as a 0 and the
            // person needs to be told what was missing rather than shown a silent redirect.
            if ($targetId <= 0) {
                throw new \DomainException('Choose the bill to move this payment to.');
            }

            $target = $this->em->find(VendorBill::class, $targetId);
            if (!$target instanceof VendorBill) {
                throw new \DomainException(sprintf('No bill with id %d — it may have been removed since this page loaded.', $targetId));
            }

            // moveApplication() asserts the claim is on THIS bill before reading anything else, so a
            // forged payment id cannot move somebody else's row through this route. Same protection
            // deletePayment() gets from withdrawApplication().
            $this->addFlash('success', $this->paymentMover->move(
                $this->actor(),
                $bill,
                $application,
                $target,
                $this->nullable((string) $request->request->get('reason', '')),
            ));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_bill_payments', ['id' => $id]);
    }

    #[Route('/{id}/payments/delete/{paymentId}', name: 'admin_bundle_procurement_bill_payment_delete', requirements: ['id' => '\d+', 'paymentId' => '\d+'], methods: ['POST'])]
    public function deletePayment(int $id, int $paymentId): Response
    {
        $this->denyIfInactive();

        $bill = $this->billOr404($id);
        $application = $this->em->find(VendorBillPaymentApplication::class, $paymentId);

        try {
            if (!$application instanceof VendorBillPaymentApplication) {
                throw new \DomainException('That payment could not be found.');
            }

            // withdrawApplication() refuses a claim belonging to another bill, so a forged id cannot
            // delete somebody else's row through this route. The derived status is no longer
            // recalculated here by hand — VendorBillPaymentStatusSubscriber does it for every write
            // to a bill or its payments, on flush (#708).
            $bill->withdrawApplication($this->actor(), $application);
            $this->em->flush();
            $this->addFlash('success', sprintf('Payment deleted. Bill %s now shows a balance of $%s.', $bill->getBillNumber(), $bill->getBalance()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_bill_payments', ['id' => $id]);
    }

    /**
     * Record a new payment, or correct one already recorded.
     *
     * The distinction is the posted payment_id and nothing else, which is why both live here: the
     * screen is one form that either adds a row or edits the row an admin clicked Edit on. The same
     * shape as `InvoiceController::savePayment()`, deliberately.
     *
     * Neither branch recalculates the derived status by hand any more — see
     * `VendorBillPaymentStatusSubscriber` (#708).
     */
    private function savePayment(VendorBill $bill, Request $request): Response
    {
        $method = trim((string) $request->request->get('method', ''));
        $amount = trim((string) $request->request->get('amount', ''));
        $comment = $this->nullable((string) $request->request->get('comment', ''));

        try {
            if ($method === '') {
                throw new \DomainException('A payment method is required — cheque, EFT, card, whatever it went out as.');
            }

            $paidAt = $this->paidAt($request);
            $paymentId = $request->request->getInt('payment_id', 0);

            if ($paymentId > 0) {
                $application = $this->em->find(VendorBillPaymentApplication::class, $paymentId);
                if (!$application instanceof VendorBillPaymentApplication) {
                    throw new \DomainException('That payment could not be found.');
                }

                $bill->amendApplication($this->actor(), $application, $paidAt, $method, $amount, $comment);
                $this->em->flush();
                $this->addFlash('success', sprintf('Payment updated. Bill %s now shows a balance of $%s.', $bill->getBillNumber(), $bill->getBalance()));
            } else {
                $recordedBy = $this->getUser();
                $payment = (new VendorBillPayment())
                    ->setUser($recordedBy instanceof AdminUser ? $recordedBy : null)
                    ->setPaidAt($paidAt)
                    ->setMethod($method)
                    ->setAmount($amount)
                    ->setComment($comment);

                $bill->recordPayment($this->actor(), $payment);
                $this->em->persist($payment);
                $this->em->flush();
                $this->addFlash('success', sprintf('Payment recorded. Bill %s now shows $%s paid, with a balance of $%s.', $bill->getBillNumber(), $bill->getAmountPaid(), $bill->getBalance()));
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_bill_payments', ['id' => $bill->getId()]);
    }

    /**
     * The date the money left, as typed.
     *
     * Parsed defensively, the way the invoice screen parses its own: an unreadable string reaching
     * DateTimeImmutable throws, and a 500 on a mistyped date is not an answer.
     */
    private function paidAt(Request $request): \DateTimeImmutable
    {
        $raw = trim((string) $request->request->get('paid_at', ''));

        try {
            return $raw === '' ? new \DateTimeImmutable('today') : new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new \DomainException(sprintf('%s is not a date this can read.', $raw));
        }
    }

    /**
     * The warehouses the receiving-warehouse select offers: the active ones, plus this bill's own.
     *
     * The bill's own is added because a building that has been retired since the draft was entered
     * is still where these goods went. Without it the select would have no option matching the
     * saved value, the browser would post the blank one, and re-saving a draft to fix a typo in a
     * quantity would silently drop the bill's province — a fact quietly changing because a
     * different row was deactivated is the shape this whole change exists to remove.
     *
     * @return list<Warehouse>
     */
    private function warehouseOptions(?VendorBill $bill): array
    {
        $rows = $this->activeWarehouses();

        $current = $bill?->getWarehouse();
        if ($current instanceof Warehouse && !\in_array($current, $rows, true)) {
            $rows[] = $current;
        }

        return $rows;
    }

    /**
     * Which warehouse this bill's goods landed in, and therefore where its tax province comes from.
     *
     * The purchase order decides when there is one — see the save path for why — and only a
     * standalone bill reads the posted field. An unknown or unposted id resolves to null, which is
     * a legitimate answer: a bill entered before anybody says where the goods went has no province,
     * the screens say so, and `VendorBill::approve()` refuses to authorise it if its goods are
     * taxable.
     */
    private function receivingWarehouse(Request $request, ?PurchaseOrder $order): ?Warehouse
    {
        if ($order instanceof PurchaseOrder) {
            return $order->getWarehouse();
        }

        $id = $this->optionalId($request, 'warehouse_id');
        $warehouse = $id > 0 ? $this->em->find(Warehouse::class, $id) : null;

        return $warehouse instanceof Warehouse ? $warehouse : null;
    }

    /**
     * A bill's own lines as the flat hashes _purchase_line_row.html.twig takes — see
     * PurchaseOrderController::lineRows()'s own docblock for why this is built here rather than
     * inline in the template.
     *
     * A SAVED bill's own lines when there is one; otherwise, against an order and not yet saved,
     * its lines PRE-FILLED with what is still left to bill (queue item 45, mirroring the sell
     * side's create-invoice screen offering the uninvoiced remainder) — never both, and never
     * neither when an order is named.
     *
     * @param array<int, ?float> $perLineTax keyed by position, from the frozen tax breakdown
     *
     * @return list<array<string, mixed>>
     */
    private function lineRows(?VendorBill $bill, ?PurchaseOrder $order, array $perLineTax): array
    {
        $poLineOptions = [];
        foreach ($order?->getLines() ?? [] as $poLine) {
            $poLineOptions[] = [
                'id' => $poLine->getId(),
                'label' => sprintf('%s (%s left to bill)', $poLine->getName(), $poLine->getQuantityUnbilled($bill)),
            ];
        }

        if ($bill instanceof VendorBill) {
            $productIds = [];
            foreach ($bill->getLines() as $line) {
                $productId = $line->getProduct()?->getId();
                if ($productId !== null) {
                    $productIds[] = (int) $productId;
                }
            }
            $unitsByProductId = $this->lineUnitChoicesFor($productIds, $this->em);

            $rows = [];
            foreach ($bill->getLines() as $index => $line) {
                $product = $line->getProduct();
                $rows[] = [
                    'id' => $line->getId(),
                    'productId' => $product?->getId(),
                    'name' => $line->getName(),
                    'sku' => $line->getSku(),
                    'vendorSku' => $line->getVendorSku(),
                    'qty' => $line->getQuantityEntered(),
                    'qtyRendered' => $line->getQuantityEntered(),
                    'weight' => $line->getWeight(),
                    'unitId' => (string) ($line->getUnitOfMeasure()?->getId() ?? ''),
                    'units' => $unitsByProductId[(int) ($product?->getId() ?? 0)] ?? [],
                    'unit' => $line->getUnit(),
                    'unitLabel' => $line->getDisplayUnitLabel(),
                    'baseUnitLabel' => $line->getBaseUnitLabel(),
                    'baseQuantity' => $line->getQuantity(),
                    'taxCode' => $line->getTaxCode(),
                    'cost' => $line->getDisplayUnitCost(),
                    'costRendered' => $line->getDisplayUnitCost(),
                    'baseUnitCost' => $line->getBaseUnitRate(),
                    'resolvedLineTotal' => $line->getLineTotal(),
                    'subtotal' => $line->getSubtotal(),
                    'batch' => $line->getBatch(),
                    'tracksBatch' => $product?->isTracksBatchInbound() ?? false,
                    'taxAmount' => $perLineTax[$index] ?? 0,
                    'poLineId' => $line->getPurchaseOrderLine()?->getId() ?? 0,
                    'poLineOptions' => $poLineOptions,
                ];
            }

            return $rows;
        }

        if ($order instanceof PurchaseOrder) {
            $productIds = [];
            foreach ($order->getLines() as $poLine) {
                $productId = $poLine->getProduct()?->getId();
                if ($productId !== null) {
                    $productIds[] = (int) $productId;
                }
            }
            $unitsByProductId = $this->lineUnitChoicesFor($productIds, $this->em);

            $rows = [];
            foreach ($order->getLines() as $poLine) {
                $product = $poLine->getProduct();
                $rows[] = [
                    'productId' => $product?->getId(),
                    'name' => $poLine->getName(),
                    'sku' => $poLine->getSku(),
                    'vendorSku' => $poLine->getVendorSku(),
                    // Nothing is billed yet, so there is no ENTERED figure to prefer over the base
                    // one — the remainder is stated in base units, same as before this feature.
                    'qty' => $poLine->getQuantityUnbilled(null),
                    'qtyRendered' => $poLine->getQuantityUnbilled(null),
                    'units' => $unitsByProductId[(int) ($product?->getId() ?? 0)] ?? [],
                    'baseUnitLabel' => $poLine->getBaseUnitLabel(),
                    'unitLabel' => $poLine->getBaseUnitLabel(),
                    'taxCode' => $product?->getSalesTaxCode() ?? 'E',
                    'cost' => $poLine->getUnitCost(),
                    'costRendered' => $poLine->getUnitCost(),
                    'poLineId' => $poLine->getId(),
                    'poLineOptions' => $poLineOptions,
                    'tracksBatch' => $product?->isTracksBatchInbound() ?? false,
                ];
            }

            return $rows;
        }

        return [];
    }

    /**
     * Everything the create/edit screen needs, assembled once for both routes.
     *
     * @return array<string, mixed>
     */
    private function formContext(?VendorBill $bill, ?PurchaseOrder $order): array
    {
        $selectedIds = [];
        foreach (($bill?->getLines() ?? []) as $line) {
            $product = $line->getProduct();
            if ($product instanceof ProductCore) {
                $selectedIds[] = (int) $product->getId();
            }
        }
        foreach (($order?->getLines() ?? []) as $orderLine) {
            $product = $orderLine->getProduct();
            if ($product instanceof ProductCore) {
                $selectedIds[] = (int) $product->getId();
            }
        }

        $frozen = $bill instanceof VendorBill ? $this->taxBreakdown->frozenFromJson($bill->getTaxLines()) : null;

        // The Remit To address panel — see _purchase_address_cards.html.twig. A bill raised against
        // a purchase order, or created standalone against a picked vendor, seeds from that vendor's
        // own default Remit To book entry; a saved bill reads its own frozen snapshot instead.
        $addressVendor = $bill?->getVendor() ?? $order?->getVendor();
        $productOptions = $this->picker->options($selectedIds);
        $addresses = [];
        $addressBook = [];
        if ($addressVendor instanceof Vendor) {
            // See PurchaseOrderController::formContext()'s own note: until the first save that
            // creates a structured snapshot at all, the vendor's own current book address stands
            // in, same as a brand-new bill shows.
            $addresses = [
                'Remit To' => $this->addressToRow(
                    ($bill instanceof VendorBill ? $bill->getEffectiveRemitToAddress() : null) ?? $addressVendor->getRemitToAddress(),
                    $addressVendor,
                ),
            ];
            $addressBook = $this->addressBookRows($addressVendor);
        }

        return [
            'bill' => $bill,
            'order' => $order,
            'vendors' => $this->activeVendors(),
            'nextNumber' => $bill instanceof VendorBill ? null : $this->numbers->prefixFor(PurchaseDocumentNumberGenerator::KIND_BILL) . '…',
            'products' => $productOptions,
            'remote' => $this->picker->isRemote(),
            // The vendor's own rate card per product — the same data-po-vendor-rates JSON map
            // PurchaseOrderController::formContext() builds, keyed the same way, so the product
            // picker's cost/vendor-sku autofill (_purchase_line_row.html.twig) behaves identically
            // on both documents rather than only working on one of the two forms that share it.
            'vendorRates' => $addressVendor instanceof Vendor
                ? array_map(
                    static fn (VendorPrice $rate): array => ['unitCost' => $rate->getUnitCost(), 'vendorSku' => $rate->getVendorSku()],
                    $this->vendorPrices->ratesFor($addressVendor, $productOptions),
                )
                : [],
            // Friendly province names for the tax-province note, same source PurchaseOrderController
            // reads — VendorBill's own form printed the bare province code before this, which is a
            // gap this bill_edit rebuild closes rather than a difference it preserves.
            'provinces' => RegionSeedData::PROVINCES['CA'] ?? [],
            'vendorId' => $bill?->getVendor()->getId() ?? $order?->getVendor()->getId(),
            'addressVendor' => $addressVendor,
            'addresses' => $addresses,
            'addressBook' => $addressBook,
            // The purchase orders this bill may be attached to or detached from, once its vendor is
            // known. The sell side has had `admin_invoice_link` / `admin_invoice_unlink` since #539
            // and the buy side had neither: a bill entered before anybody found the purchase order
            // number stayed standalone forever, matching against nothing. Scoped to the vendor the
            // document already names, because a bill against another vendor's order is not a link,
            // it is two documents about different money.
            'linkableOrders' => $this->orders->billableFor(
                (int) ($bill?->getVendor()->getId() ?? $order?->getVendor()->getId() ?? 0),
            ),
            // The bill's own manual charges as editable rows — freight and fee rows out of
            // charge_lines, manual tax rows out of the tax_lines snapshot — exactly the shape
            // PurchaseOrderController::formContext() builds, now that the two forms share one
            // charge vocabulary (#635). Handing them back is what stops the very next save wiping
            // them: a save rebuilds both snapshots from what the form posts.
            'chargeRows' => $bill instanceof VendorBill
                ? array_merge(
                    PurchaseDocumentChargeLines::fromChargeLines($bill->getChargeLineRows()),
                    $this->taxBreakdown->manualTaxChargeRowsFrom($frozen),
                )
                : [],
            // The frozen tax rows, so the entry screen can state what the document adds up to
            // instead of making somebody save it and open the detail page to find out (queue item
            // 45).
            'taxLines' => $frozen['lines'] ?? [],
            'lineRows' => $this->lineRows($bill, $order, $frozen['perLineTax'] ?? []),
            'hasFeeCalculators' => $this->feeCalculators->hasCalculators(),
            'taxClasses' => ['E' => 'Exempt', 'G' => 'GST only', 'S' => 'GST + PST'],
            // Where the goods landed, and therefore which province's tax rules apply. Derived, never
            // typed (queue item 32): the screen names a warehouse and the warehouse's address
            // answers. A bill against a purchase order inherits that order's warehouse and the
            // screen states it rather than offering a second choice.
            'warehouses' => $this->warehouseOptions($bill),
            'receivingWarehouse' => $order?->getWarehouse() ?? $bill?->getWarehouse(),
            'taxProvince' => $bill?->getTaxProvince(),
        ];
    }

    /**
     * The shared edit frame's own required parameters — `commercial_document_edit.html.twig`'s
     * `workspaceReady`, `formId` and `formAction` — the same helper PurchaseOrderController's
     * `editFrameContext()` is, for the same reason: VendorBill never uses the frame's
     * customer-chooser step (a vendor is chosen inline, or via `?vendor=`/`?po=`, on the same
     * page), so `workspaceReady` is unconditionally true. `formAction` differs from the page's own
     * URL because both `/new` and `/{id}/edit` post to the one shared save endpoint below.
     *
     * @return array{workspaceReady: true, formId: string, formAction: string}
     */
    private function editFrameContext(): array
    {
        return [
            'workspaceReady' => true,
            'formId' => 'bill-form',
            'formAction' => $this->generateUrl('admin_bundle_procurement_bill_save'),
        ];
    }

    /**
     * @param callable(VendorBill): string $action
     */
    private function act(int $id, callable $action): Response
    {
        $this->denyIfInactive();

        $bill = $this->billOr404($id);

        try {
            $message = $action($bill);
            $this->em->flush();
            $this->addFlash('success', $message);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_bill', ['id' => $bill->getId()]);
    }

    private function billOr404(int $id): VendorBill
    {
        $bill = $this->em->find(VendorBill::class, $id);
        if (!$bill instanceof VendorBill) {
            throw $this->createNotFoundException('No such bill.');
        }

        return $bill;
    }
}
