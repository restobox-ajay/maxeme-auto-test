<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Contract\Tax\TaxContext;
use App\Contract\Tax\TaxLine;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Exception\DocumentLocked;
use App\Repository\AuditLogRepository;
use App\Repository\BundleStatusRepository;
use App\Service\AppSettings;
use App\Service\Document\DocumentLockService;
use App\Service\DocumentActorResolver;
use App\Service\Email\EmailTemplateRenderer;
use App\Service\QuantityScale;
use App\Service\RegionSeedData;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLine;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLineSnapshot;
use ProcurementBundle\Contract\Purchase\PurchaseFeeContext;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderAddress;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorPrice;
use ProcurementBundle\Enum\PurchaseOrderStatus;
use ProcurementBundle\Inventory\IncomingStockReconciler;
use ProcurementBundle\Numbering\PurchaseDocumentNumberGenerator;
use ProcurementBundle\Product\ProductPicker;
use ProcurementBundle\Purchase\PurchaseDocumentChargeLines;
use ProcurementBundle\Purchase\PurchaseFeeCalculatorResolver;
use ProcurementBundle\Purchase\PurchaseOrderLineEditGuard;
use ProcurementBundle\Purchase\PurchaseSideLineReconciler;
use ProcurementBundle\Purchase\ResolvedPurchaseLine;
use ProcurementBundle\Purchase\PurchaseTaxBreakdown;
use ProcurementBundle\Repository\PurchaseOrderRepository;
use ProcurementBundle\Repository\VendorPriceRepository;
use ProcurementBundle\Status\PurchaseOrderStatusDeriver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Purchase orders (#555): raise one, issue it, print it, send it, close it, cancel it.
 *
 * Every status change here goes through a **named action** on the entity, with the actor this
 * controller resolved passed in explicitly. There is no `setStatus()` to post to, deliberately —
 * see PurchaseOrder's class docblock. What that buys is that the same `issue()` an admin's button
 * calls is callable by an import or a console command, attributed to whoever actually did it.
 *
 * The two derived statuses — Partially Received and Received — have no route at all. They are a
 * projection of what arrived, written by PurchaseOrderStatusDeriver when a receipt is booked in,
 * and a button that set them would be a button for lying about the warehouse.
 */
#[Route('/admin/bundles/procurement/purchase-orders')]
final class PurchaseOrderController extends AbstractProcurementController
{
    /**
     * The email that carries a PO to its vendor, as a template CODE plus the fallback pair
     * EmailNotifier::send() takes — a subject and a view to render when nothing resolves.
     *
     * That fallback pair is the mechanism, not a workaround: `EmailTemplateRenderer::render()`
     * returns null for a code that neither ships nor has a row, and "resolved template, else
     * fallback Twig view" is what every transactional email in this app already does.
     *
     * The code is deliberately NOT registered in `ShippedEmailTemplates`. That class is byte-pinned
     * to the migration chain by ShippedEmailTemplatesMatchTheChainTest — it is the record of the 22
     * templates #507 lifted out of the chain, and its symmetric-difference assertion means a 23rd
     * entry would have to be seeded back INTO the chain, which is the exact thing #507 removed. So
     * the body ships as a view here, and an admin who wants to reword it creates a
     * `purchase_order_vendor` row in Settings → Email Templates, which then wins outright the same
     * way an override of a shipped code does.
     */
    private const EMAIL_TEMPLATE_CODE = 'purchase_order_vendor';
    private const EMAIL_FALLBACK_VIEW = '@Procurement/emails/purchase_order_vendor.html.twig';

    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly PurchaseDocumentNumberGenerator $numbers,
        // Issuing, closing and cancelling all change what is expected on the dock, which is exactly
        // what `product_inventory.incoming_quantity` caches (#583).
        private readonly IncomingStockReconciler $incoming,
        // #637: a line left with no typed price resolves one from the vendor's own rate card
        // instead of silently costing nothing.
        private readonly VendorPriceRepository $vendorPrices,
        // The product field itself: this screen used to ask for a database id.
        private readonly ProductPicker $picker,
        // #655: tax is SHARED with the sell side — this calls the same calculators through the same
        // contract. See PurchaseTaxBreakdown for the ruling and what it does and does not reuse.
        private readonly PurchaseTaxBreakdown $purchaseTax,
        // #655: fees are NOT shared. This is the buy side's own seam; nothing subscribes to it yet.
        private readonly PurchaseFeeCalculatorResolver $purchaseFees,
        // #47: an edit to an issued order moves what was ORDERED, which is one of the two terms the
        // derived status is computed from. ReceivingService has always called this on the other
        // term; the save has to call it now that the first one can move too.
        private readonly PurchaseOrderStatusDeriver $poStatus,
        private readonly PurchaseSideLineReconciler $lineReconciler,
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    /**
     * Everything the edit form needs to render a document's charges, tax and totals.
     *
     * Assembled here rather than at each of the two call sites (`/new` and `/{id}/edit`) because
     * that is exactly how those two drifted before: `/new` has no saved lines and no saved charges,
     * so a regression on the saved half stays invisible until somebody reopens a document. One
     * builder cannot disagree with itself.
     *
     * @return array<string, mixed>
     */
    private function formContext(?PurchaseOrder $order, ?Vendor $requestedVendor = null): array
    {
        // The order's own manual charges as editable rows — freight and fee rows out of
        // charge_lines, manual tax rows out of the tax_lines snapshot. Handing them back is what
        // stops the very next save wiping them: a save rebuilds both snapshots from what the form
        // posts, so a row the form never re-posts is a row that ceases to exist.
        $chargeRows = $order instanceof PurchaseOrder
            ? array_merge(
                PurchaseDocumentChargeLines::fromChargeLines($order->getChargeLineRows()),
                $this->purchaseTax->manualTaxChargeRows($order),
            )
            : [];

        $breakdown = $order instanceof PurchaseOrder ? $this->purchaseTax->frozen($order) : null;

        // The Ship From address panel — see _purchase_address_cards.html.twig. A vendor named by
        // `?vendor=` (a new order) seeds its default book address directly; a saved order reads
        // its own frozen snapshot, live until issue() freezes it (see
        // PurchaseOrder::freezeVendorSnapshot()). Renders nothing without a vendor to build it
        // against — the fresh, blank-vendor form has nothing to show yet.
        //
        // "Order To" is NOT here (#full-parity, 2026-09-15, corrected): it is where WE tell the
        // vendor to deliver, i.e. the delivery warehouse's own address, not a fact about the
        // vendor at all. The edit template reads it straight off `_deliverTo` (the resolved
        // warehouse), the same entity the "Deliver to warehouse" select already resolves.
        $addressVendor = $order?->getVendor() ?? $requestedVendor;
        $addresses = [];
        $addressBook = [];
        if ($addressVendor instanceof Vendor) {
            // A saved order's own snapshot wins once one exists — even a link-only one, which
            // getEffectiveShipFromAddress() already resolves live off its source. Until the first
            // save that creates one at all (no row has EVER been written for this document — the
            // `??` only reaches here then), the vendor's own current book address stands in, same
            // as a brand-new order shows: an order with nothing recorded yet must not render a
            // blank panel when the vendor plainly has an address on file.
            $addresses = [
                'Ship From' => $this->addressToRow(
                    ($order instanceof PurchaseOrder ? $order->getEffectiveShipFromAddress() : null) ?? $addressVendor->getShipFromAddress(),
                    $addressVendor,
                ),
            ];
            $addressBook = $this->addressBookRows($addressVendor);
        }

        $productOptions = $order instanceof PurchaseOrder
            ? $this->picker->options($this->lineProductIds($order))
            : $this->picker->options();

        return [
            'order' => $order,
            // Which vendor the select opens on when there is no saved order to read one from: the
            // one `?vendor=` named, or nobody. Null on the edit screen, where the order answers.
            'requestedVendor' => $requestedVendor,
            'addressVendor' => $addressVendor,
            'addresses' => $addresses,
            'addressBook' => $addressBook,
            'vendors' => $this->activeVendors(),
            'warehouses' => $this->warehouseOptions($order),
            'chargeRows' => $chargeRows,
            'taxLines' => $breakdown['lines'] ?? [],
            'perLineTax' => $breakdown['perLineTax'] ?? [],
            // The provinces a tax calculator could possibly match on. Still handed to the template,
            // but the province is no longer TYPED (queue item 37) — the screen prints the one
            // derived from the delivery warehouse and this is what turns a code into a name.
            'provinces' => RegionSeedData::PROVINCES['CA'] ?? [],
            // Purely so the screen can be honest about an empty Charges section rather than
            // implying an automatic charge might appear. Nothing is tagged today.
            'hasFeeCalculators' => $this->purchaseFees->hasCalculators(),
            // A new order names no products yet, so past the inline limit there is nothing to seed
            // the select with and search supplies everything. An existing one seeds what it names,
            // so each saved line still renders its own product rather than an empty select that
            // looks like data loss.
            'productOptions' => $productOptions,
            'productsRemote' => $this->picker->isRemote(),
            'lineRows' => $order instanceof PurchaseOrder ? $this->lineRows($order, $breakdown['perLineTax'] ?? []) : [],
            // The vendor's own rate card, keyed by product id, for the product-select's live
            // autofill (#full-parity, 2026-09-15) — `_purchase_line_row.html.twig`'s optionData
            // only reaches PLAIN product attributes (see product_field.html.twig's own
            // attribute(product, dataKey)), and a vendor's cost/their-SKU are facts about the
            // VENDOR-product pair, not the product alone, so they travel separately as JSON rather
            // than as a data-* attribute on an option that has no vendor context at all.
            'vendorRates' => $addressVendor instanceof Vendor
                ? array_map(
                    static fn (VendorPrice $rate): array => ['unitCost' => $rate->getUnitCost(), 'vendorSku' => $rate->getVendorSku()],
                    $this->vendorPrices->ratesFor($addressVendor, $productOptions),
                )
                : [],
        ];
    }

    /**
     * An order's own lines as the flat hashes _purchase_line_row.html.twig takes — see that
     * template's own docblock for the key vocabulary. Built here, once, rather than inline in the
     * template: the two row-level rules (#47's received floor, its billed-price lock) are guard
     * logic, not markup, and belong beside the entity they read.
     *
     * @param array<int, ?float> $perLineTax keyed by position, from the frozen tax breakdown
     *
     * @return list<array<string, mixed>>
     */
    private function lineRows(PurchaseOrder $order, array $perLineTax): array
    {
        // Sorted and reindexed exactly as PurchaseTaxBreakdown::compute() reads the same
        // collection: $perLineTax is keyed by POSITION in that order, not the collection's own
        // (possibly gapped, post-removal) internal keys.
        $lines = $order->getLines()->toArray();
        usort($lines, static fn (PurchaseOrderLine $a, PurchaseOrderLine $b): int => $a->getSortOrder() <=> $b->getSortOrder());

        $productIds = [];
        $products = [];
        foreach ($lines as $line) {
            $product = $line->getProduct();
            if ($product !== null && $product->getId() !== null) {
                $productIds[] = (int) $product->getId();
                $products[] = $product;
            }
        }
        $unitsByProductId = $this->lineUnitChoicesFor($productIds, $this->em);
        // The vendor's own rate card, beside the box an admin typed a cost into — the buy-side
        // twin of the sell side's `original_price` reference column, batched the same way
        // `unitsByProductId` is rather than one query per row.
        $vendorRateByProductId = $this->vendorPrices->unitCostsFor($order->getVendor(), $products);

        $rows = [];
        foreach (array_values($lines) as $index => $line) {
            $received = $line->hasReceipts();
            $priceSettled = $line->isPriceSettled();
            $product = $line->getProduct();

            $row = [
                'id' => $line->getId(),
                'productId' => $product?->getId(),
                'name' => $line->getName(),
                'sku' => $line->getSku(),
                'vendorSku' => $line->getVendorSku(),
                // A blank line-level location falls back to the order's own delivery warehouse —
                // the header names the DEFAULT, a line can still override it independently.
                'location' => $line->getLocation() ?: ($order->getWarehouse()?->getName() ?: ''),
                // The quantity box asks in the line's OWN unit — see AbstractProcurementController's
                // helpers and OrderController's own row for the mechanism this mirrors verbatim.
                'qty' => $line->getQuantityEntered(),
                'qtyRendered' => $line->getQuantityEntered(),
                'weight' => $line->getWeight(),
                'unitId' => (string) ($line->getUnitOfMeasure()?->getId() ?? ''),
                'units' => $unitsByProductId[(int) ($product?->getId() ?? 0)] ?? [],
                'unit' => $line->getUnit(),
                'unitLabel' => $line->getDisplayUnitLabel(),
                'baseUnitLabel' => $line->getBaseUnitLabel(),
                'baseQuantity' => $line->getQuantityOrdered(),
                'taxCode' => $line->getTaxCode(),
                'cost' => $line->getDisplayUnitCost(),
                'costRendered' => $line->getDisplayUnitCost(),
                'baseUnitCost' => $line->getBaseUnitRate(),
                'originalPrice' => $vendorRateByProductId[(int) ($product?->getId() ?? 0)] ?? null,
                'resolvedLineTotal' => $line->getLineTotal(),
                'subtotal' => $line->getSubtotal(),
                'batch' => $line->getBatch(),
                'tracksBatch' => $product?->isTracksBatchInbound() ?? false,
                'taxAmount' => $perLineTax[$index] ?? 0,
            ];

            if ($received) {
                $row['qtyMin'] = $line->getQuantityReceived();
                $row['qtyFloorHint'] = sprintf('%s received — the floor', $line->getQuantityReceived());
            }
            if ($priceSettled) {
                $row['costReadonly'] = true;
                $row['costLockedHint'] = sprintf('Billed %s — price settled', $line->getQuantityCharged());
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The warehouses the Deliver to select offers: the active ones, plus this order's own.
     *
     * The order's own is added because a building retired since the draft was raised is still where
     * these goods are going. Without it the select would have no option matching the saved value,
     * the browser would post the first one instead, and re-saving a draft to fix a typo in a
     * quantity would silently move the delivery AND re-derive the tax province off a different
     * building. A fact changing because a different row was deactivated is the shape queue item 37
     * exists to remove — the bill side learned this the same way (commit e82c9b73).
     *
     * @return list<Warehouse>
     */
    private function warehouseOptions(?PurchaseOrder $order): array
    {
        $rows = $this->activeWarehouses();

        if (!$order instanceof PurchaseOrder) {
            return $rows;
        }

        $current = $order->getWarehouse();

        return \in_array($current, $rows, true) ? $rows : [...$rows, $current];
    }

    /**
     * The products an order already names, to seed its select past the inline limit.
     *
     * @return list<int>
     */
    private function lineProductIds(PurchaseOrder $order): array
    {
        $ids = [];
        foreach ($order->getLines() as $line) {
            $product = $line->getProduct();
            if ($product instanceof ProductCore && $product->getId() !== null) {
                $ids[] = (int) $product->getId();
            }
        }

        return $ids;
    }

    #[Route('', name: 'admin_bundle_procurement_purchase_orders', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['q', 'status', 'vendor', 'warehouse', 'from', 'to', 'product']);
        $paging = $this->paging($request, 'number', 'desc');

        /** @var PurchaseOrderRepository $repo */
        $repo = $this->em->getRepository(PurchaseOrder::class);
        $result = $repo->search($filters, $paging['page'], $paging['limit'], $paging['sort'], $paging['dir']);

        return $this->render('@Procurement/purchase_orders.html.twig', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'vendors' => $this->activeVendors(),
            'warehouses' => $this->activeWarehouses(),
            'statuses' => PurchaseOrderStatus::cases(),
            'filters' => $filters,
            'page' => $paging['page'],
            'limit' => $paging['limit'],
            'pages' => max(1, (int) ceil($result['total'] / $paging['limit'])),
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    /**
     * What is due in, by date, per warehouse.
     *
     * Its own screen rather than a filter on the list because it answers a different question: the
     * list is "which orders exist", this is "what should I expect on the dock this week". An order
     * with no expected date sorts last rather than being dropped — "we do not know when this is
     * coming" is exactly the row a buyer needs to chase.
     */
    #[Route('/expected', name: 'admin_bundle_procurement_expected_arrivals', methods: ['GET'])]
    public function expectedArrivals(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['warehouse', 'through']);

        /** @var PurchaseOrderRepository $repo */
        $repo = $this->em->getRepository(PurchaseOrder::class);

        return $this->render('@Procurement/expected_arrivals.html.twig', [
            'rows' => $repo->expectedArrivals(
                $filters['warehouse'] !== '' ? (int) $filters['warehouse'] : null,
                $this->calendarDate($filters['through']),
            ),
            'warehouses' => $this->activeWarehouses(),
            'filters' => $filters,
        ]);
    }

    /**
     * Raise a purchase order — blank, or for a vendor named by `?vendor=` (queue item 51).
     *
     * `?vendor=` is READ here, which it was not before. The screen took no query parameter at all,
     * so `/purchase-orders/new?vendor=99999` rendered a blank form — and so did `?vendor=<a real
     * vendor>`, which is the same silent failure wearing the opposite hat: a drill-through that
     * named a vendor correctly was ignored just as quietly as one that named nobody. Both now say
     * what happened, which is what the standing rule asks for in each direction.
     */
    #[Route('/new', name: 'admin_bundle_procurement_purchase_order_new', methods: ['GET'])]
    public function form(Request $request): Response
    {
        $this->denyIfInactive();

        $requestedVendor = $this->requestedParent($request, 'vendor', Vendor::class, 'vendor');

        return $this->render('@Procurement/purchase_order/edit.html.twig', $this->formContext(null, $requestedVendor->entity()) + [
            'badParents' => $this->unresolvedParents($requestedVendor),
            'nextNumber' => $this->numbers->prefixFor(PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER) . '…',
        ] + $this->editFrameContext());
    }

    /**
     * Live auto-calculated fee preview while the vendor/warehouse/lines are still being typed —
     * copied from OrderController::feeLinesAjax() and adapted (#full-parity, 2026-09-15): a vendor
     * and its live currency stand in for Order's company, the delivery warehouse's province stands
     * in for the shipping address' province, and PurchaseFeeContext is built directly from the
     * request rather than through a preview entity — PurchaseFeeContext::fromPurchaseOrder() only
     * exists to read a real order's own fields, and every one of those fields is already sitting
     * right here in the query string, so building the throwaway order just to re-read them back off
     * it would be pure ceremony.
     */
    #[Route('/fee-lines', name: 'admin_bundle_procurement_purchase_order_fee_lines', methods: ['GET'])]
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
        $feeLines = $this->purchaseFees->calculate($context);
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
     * Live calculated-tax preview, copied from OrderController::taxBreakdownAjax() and adapted
     * (#full-parity, 2026-09-15): `PurchaseTaxBreakdown::computeRows()` is the same province-in,
     * rows-out rule `compute()` calls for a real save (see that class's own docblock — it is
     * deliberately document-agnostic for exactly this reason), so this reaches it directly with the
     * rows the browser is currently showing rather than building a throwaway PurchaseOrder to read
     * them back off. The one typed freight row's amount is folded in as a single preview charge line
     * at the document's live highest tax class, the same way a real save's freight row is taxed —
     * mirroring Order's own shipping-as-a-tax-context-line synthesis in its own AJAX endpoint.
     */
    #[Route('/tax-breakdown', name: 'admin_bundle_procurement_purchase_order_tax_breakdown', methods: ['GET'])]
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

        $breakdown = $this->purchaseTax->computeRows($province, $rows, $chargeLines);

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

    #[Route('/{id}', name: 'admin_bundle_procurement_purchase_order', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id, AuditLogRepository $auditLogRepository): Response
    {
        $this->denyIfInactive();

        $order = $this->orderOr404($id);

        return $this->render('@Procurement/purchase_order/detail.html.twig', [
            'order' => $order,
            'logs' => $auditLogRepository->findNarrativeForEntity('PurchaseOrder', $order->getId()),
            // `commercial_document_view.html.twig`'s own four required parameters.
            // PurchaseOrder has no real tab bar of its own — there is only one GET route for this
            // document — so `actionBarTemplate` points at a small partial whose only job is
            // printing the flash messages this screen's no-JS form needs rendered as real markup.
            'document' => $order,
            'slotPrefix' => 'admin_purchase_order_detail',
            'actionBarTemplate' => '@Procurement/_document_action_bar.html.twig',
            'actionBarContext' => [],
        ] + $this->totalsContext($order));
    }

    /**
     * A message an admin types onto this order's own timeline — the buy-side twin of
     * OrderController::addLog(), a REAL form submit rather than the sell side's AJAX one: this
     * document's whole convention is that every control other than the batch cell is a plain
     * post-and-redirect (#full-parity, 2026-09-13), and a "Send Message" button is exactly that
     * kind of control, not a reason to bring JS onto a screen that otherwise has none.
     */
    #[Route('/{id}/log/add', name: 'admin_bundle_procurement_purchase_order_log_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addLog(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $order = $this->orderOr404($id);

        $message = $this->nullable((string) $request->request->get('message', ''));
        if ($message !== null) {
            $order->queueActivityLogEntry()
                ->setUserName($this->actor()->displayName)
                ->setComment($message)
                ->setType($this->nullable((string) $request->request->get('type', '')) ?? 'For Internal')
                ->setRecipientNotified($request->request->getBoolean('notify_vendor'));
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_bundle_procurement_purchase_order', ['id' => $order->getId()]);
    }

    /** The buy-side twin of OrderController::deleteLog() — a real form submit, not AJAX. */
    #[Route('/{id}/log/delete/{logId}', name: 'admin_bundle_procurement_purchase_order_log_delete', requirements: ['id' => '\d+', 'logId' => '\d+'], methods: ['POST'])]
    public function deleteLog(int $id, int $logId): Response
    {
        $this->denyIfInactive();

        $order = $this->orderOr404($id);
        $log = $this->em->find(\App\Entity\AuditLog::class, $logId);
        // actorType === 'document' is not just a read-side filter here — it is what stops this
        // route from becoming a way to delete an arbitrary audit_log row by guessing its id (see
        // OrderController::deleteLog()'s identical guard).
        if ($log && $log->getEntityType() === 'PurchaseOrder' && $log->getEntityId() === $order->getId() && $log->getActorType() === 'document') {
            $this->em->remove($log);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_bundle_procurement_purchase_order', ['id' => $order->getId()]);
    }

    /**
     * The shared edit frame's own required parameters — `commercial_document_edit.html.twig`'s
     * `workspaceReady`, `formId` and `formAction`. PurchaseOrder never uses the
     * frame's customer-chooser step (a vendor is chosen inline, or via `?vendor=`, on the same
     * page), so `workspaceReady` is unconditionally true. `formAction` differs from the page's own
     * URL because both `/new` and `/{id}/edit` post to the one shared save endpoint below.
     *
     * @return array{workspaceReady: true, formId: string, formAction: string}
     */
    private function editFrameContext(): array
    {
        return [
            'workspaceReady' => true,
            'formId' => 'purchase-order-form',
            'formAction' => $this->generateUrl('admin_bundle_procurement_purchase_order_save'),
        ];
    }

    /**
     * The frozen tax breakdown, unpacked for a read-only screen.
     *
     * The SNAPSHOT, never a live recomputation — which is the whole reason it is frozen. A document
     * that recomputed its tax every time somebody looked at it would print one figure today and
     * another after a rate changed, while its own stored total said something else again.
     *
     * @return array<string, mixed>
     */
    private function totalsContext(PurchaseOrder $order): array
    {
        $breakdown = $this->purchaseTax->frozen($order);

        return [
            'taxLines' => $breakdown['lines'] ?? [],
            'perLineTax' => $breakdown['perLineTax'] ?? [],
            'perLineTaxLabel' => $breakdown['perLineTaxLabel'] ?? [],
        ];
    }

    /**
     * The purchase order as a document — on screen, and as the PDF with `?pdf=1`.
     *
     * One template for both, the way InvoiceController::document()/packingSlip() do it, so the copy
     * a vendor receives is byte-for-byte the one a buyer looked at before sending it. Three call
     * sites assembling their own context is how they drift, and the third here is send().
     */
    #[Route('/{id}/print', name: 'admin_bundle_procurement_purchase_order_print', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function document(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $order = $this->orderOr404($id);
        $isPdf = $request->query->get('pdf') === '1';
        $html = $this->renderView('@Procurement/purchase_order_document.html.twig', [
            'order' => $order,
            'is_pdf' => $isPdf,
        ] + $this->totalsContext($order));

        return $isPdf
            ? $this->pdfResponse($this->dompdf($html), sprintf('PurchaseOrder-%s.pdf', $order->getPoNumber()))
            : new Response($html);
    }

    /**
     * Email the purchase order to its vendor, with the PDF attached.
     *
     * Built from InvoiceController::send() rather than from anything new: the same
     * EmailTemplateRenderer for the copy, the same AppSettings::applyFromAddress() for the sender,
     * the same MailerInterface for the send — which means the same MailerLogSubscriber writes the
     * `email_log` row, and that row is how a send is verified here. Local mail failing is by design
     * in this environment; the log is the record either way.
     *
     * It does not go through EmailNotifier, for the two reasons the invoice's send does not: this
     * mail carries an ATTACHMENT, which EmailNotifier has no parameter for, and this caller has to
     * know whether the send succeeded — EmailNotifier deliberately swallows failures so that a
     * checkout never fails on a notification, and here a failed send must not be recorded on the
     * timeline as though the vendor had been told. The fallback-view half of EmailNotifier IS
     * reused; see the constants above.
     *
     * **A draft is refused.** `issue()` is what makes a purchase order real — its docblock says it
     * is "what was sent to the vendor", and it is what freezes the vendor address and payment term
     * onto the document. Emailing a draft would send paperwork whose snapshot has not been taken
     * and leave the order claiming nothing had been sent. This is a guard, not a second status
     * path: nothing here moves a status, and the message says which button to press instead.
     */
    #[Route('/{id}/send', name: 'admin_bundle_procurement_purchase_order_send', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function send(
        int $id,
        MailerInterface $mailer,
        EmailTemplateRenderer $emailTemplates,
        AppSettings $appSettings,
    ): Response {
        $this->denyIfInactive();

        $order = $this->orderOr404($id);
        $redirect = $this->redirectToRoute('admin_bundle_procurement_purchase_order', ['id' => $order->getId()]);

        if ($order->getStatusEnum() === PurchaseOrderStatus::Draft) {
            $this->addFlash('error', sprintf(
                'Purchase order %s is still a draft. Issue it to the vendor first — that is what freezes their address and terms onto the document.',
                $order->getPoNumber(),
            ));

            return $redirect;
        }

        if ($order->getStatusEnum() === PurchaseOrderStatus::Cancelled) {
            $this->addFlash('error', sprintf(
                'Purchase order %s was cancelled. Sending it would ask the vendor to fulfil an order we withdrew.',
                $order->getPoNumber(),
            ));

            return $redirect;
        }

        // `vendor.email` first, then an Active `vendor_contact.email` (#605). The vendor record's own
        // address still wins where somebody set one; the fallback exists because most of them do not
        // — 118 of 124 vendors on the old dev data had no `vendor.email`, so this refused for 95% of
        // the file even where the orders desk's address was written down elsewhere.
        $recipient = (string) ($order->getVendor()->getOrderEmail() ?? '');
        if ($recipient === '') {
            $this->addFlash('error', sprintf(
                'No email address on file for %s. Put one on the vendor record, or add a contact with an email on their Contacts panel, before sending them a purchase order.',
                $order->getVendorName() !== '' ? $order->getVendorName() : $order->getVendor()->getName(),
            ));

            return $redirect;
        }

        try {
            $pdf = $this->dompdf($this->renderView('@Procurement/purchase_order_document.html.twig', [
                'order' => $order,
                'is_pdf' => true,
            ] + $this->totalsContext($order)));

            // No link variables in the context at all, deliberately. Every admin link this app
            // sends is anchored to ADMIN_HOST behind a ROLE_ADMIN rule (#351/#223), and a vendor
            // has no account on it — the document IS the attachment, so there is nothing to link to.
            $context = ['purchase_order' => $order];
            $rendered = $emailTemplates->render(self::EMAIL_TEMPLATE_CODE, $context);
            $subject = $rendered?->subject ?? sprintf('Purchase order %s', $order->getPoNumber());
            $body = $rendered?->body ?? $this->renderView(self::EMAIL_FALLBACK_VIEW, $context);

            // FROM_SALES: the categories are sales/support/tech_support and there is no purchasing
            // one. All three resolve to the same sender since #474, so inventing a fourth would add
            // a core concept and change no address; this is outbound commercial correspondence
            // about a document, which is what FROM_SALES already covers on the sell side.
            $email = $appSettings->applyFromAddress(new Email(), AppSettings::FROM_SALES)
                ->to($recipient)
                ->subject($subject)
                ->html($body)
                ->attach($pdf, sprintf('PurchaseOrder-%s.pdf', $order->getPoNumber()), 'application/pdf');

            $mailer->send($email);

            // The entity's own named action, which writes its own timeline entry and flags the log
            // row vendorNotified — there is no second way to record this and no status to move,
            // because sending a copy changes nothing about the document.
            $order->recordSent($this->actor(), $recipient, $subject);
            $this->em->flush();

            $this->addFlash('success', sprintf('Purchase order %s sent to %s.', $order->getPoNumber(), $recipient));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to send email: ' . $e->getMessage());
        }

        return $redirect;
    }

    /**
     * The edit form — and a refusal, in full, for the two states that cannot use it (#47).
     *
     * Refused HERE and not only at the save, which is the defect this issue was raised about: this
     * route returned 200 and rendered a complete, fully populated form with a Save button on all
     * thirty-seven purchase orders, then refused at the save on thirty of them. Offering a control
     * that cannot succeed is the same defect class as a search box with no handler — the control is
     * the promise. The list screen and the detail page no longer offer the link either; this is the
     * backstop for a bookmark, a typed URL or a stale tab, and it says which order it is talking
     * about and why rather than 404ing.
     */
    #[Route('/{id}/edit', name: 'admin_bundle_procurement_purchase_order_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id, DocumentLockService $locks): Response
    {
        $this->denyIfInactive();

        $order = $this->orderOr404($id);

        if (!$order->canEditOnStatus()) {
            $this->addFlash('error', $order->editingRefusalReason());

            return $this->redirectToRoute('admin_bundle_procurement_purchase_order', ['id' => $order->getId()]);
        }

        // The EXPLICIT lock, asked separately from the status rule above — same pair, same order,
        // same reasoning as OrderController::edit() (#759). Refused on GET as well as POST, so the
        // edit screen never opens on a document that cannot be saved; caught locally rather than left
        // to DocumentLockedSubscriber so the refusal lands on the order's own detail page, where the
        // Unlock control the sentence names actually is.
        try {
            $locks->assertWritable($order, 'edited');
        } catch (DocumentLocked $locked) {
            $this->addFlash('error', $locked->getMessage());

            return $this->redirectToRoute('admin_bundle_procurement_purchase_order', ['id' => $order->getId()]);
        }

        return $this->render('@Procurement/purchase_order/edit.html.twig', $this->formContext($order) + [
            // Reached by its own id through a `\d+` route requirement — no parent id in the URL to
            // have been wrong. Explicit because `strict_variables` is on.
            'badParents' => [],
            'nextNumber' => null,
        ] + $this->editFrameContext());
    }

    /**
     * Create or update a purchase order.
     *
     * ## What may be edited, and when (#47)
     *
     * Ruled by borrowing the sell side, which turns out to be what Zoho, NetSuite and Dynamics all
     * do as well. `PurchaseOrder::canEditOnStatus()` carries the reasoning; in short, **Closed
     * and Cancelled are shut and nothing else is**. An Issued or Partially Received order stays
     * editable, because a live commitment to a vendor is exactly the thing that gets corrected — a
     * wrong price, a line they cannot supply, an extra case added over the phone.
     *
     * Before this, every state but Draft was refused outright, and the correction path for an issued
     * PO with a wrong price and one receipt against it was: nothing. Cancel is refused once anything
     * has arrived (deliberately, and unchanged here), so the only move left was closing it short.
     *
     * Two line-level rules survive into the open states, and they are driven by DIFFERENT events —
     * see PurchaseOrderLineEditGuard, which states both and is the only place either is decided:
     * receiving floors the QUANTITY, billing settles the PRICE.
     *
     * ## Lines are diffed, not rebuilt
     *
     * This used to drop every line and recreate the whole set from the post — the shape the sell
     * side had until #267 and abandoned for the same reason it is abandoned here, only with worse
     * consequences on this side. `goods_receipt_line.purchase_order_line_id` and
     * `vendor_bill_line.purchase_order_line_id` are both `ON DELETE SET NULL`, so a rebuild on an
     * order with receipts or bills against it would have silently cut every receipt and every bill
     * loose from the line it was recorded against — no error, no failed save, and the three-way
     * match quietly blind afterwards. A row now carries its own `lines[i][id]` and is UPDATED in
     * place; only rows the post did not carry are removed, and each column is written only when it
     * actually changed, so an untouched line keeps a stored precision this form cannot express.
     *
     * ## What is NOT editable past Draft
     *
     * The vendor and the delivery warehouse. `issue()` freezes the vendor's name and order-to
     * address onto the document precisely so that what was sent stays what was sent, and this save
     * re-snapshots the name on every write — so a vendor swap here would have quietly rewritten a
     * frozen record, and a warehouse swap would have stranded the `incoming` forecast on the
     * building the goods are no longer coming to. Both selects are disabled on the form past Draft
     * and both posted values are ignored here; the stored ones are kept.
     */
    #[Route('/save', name: 'admin_bundle_procurement_purchase_order_save', methods: ['POST'])]
    public function save(Request $request, DocumentLockService $locks): Response
    {
        $this->denyIfInactive();

        $id = $request->request->getInt('id', 0);
        $order = $id > 0 ? $this->orderOr404($id) : null;

        if ($order instanceof PurchaseOrder && !$order->canEditOnStatus()) {
            $this->addFlash('error', $order->editingRefusalReason());

            return $this->redirectToRoute('admin_bundle_procurement_purchase_order', ['id' => $order->getId()]);
        }

        if ($order instanceof PurchaseOrder) {
            try {
                $locks->assertWritable($order, 'edited');
            } catch (DocumentLocked $locked) {
                $this->addFlash('error', $locked->getMessage());

                return $this->redirectToRoute('admin_bundle_procurement_purchase_order', ['id' => $order->getId()]);
            }
        }

        // The charge rows, resolved at the door and BEFORE the document is touched.
        //
        // Two of the three controls on the charge bar are plain submits rather than JavaScript, so
        // this post may be asking to append a row or to drop one as part of saving. Both are
        // applied to the posted array first, so that what is normalised is what the admin will see
        // on the page that comes back.
        $chargesRaw = PurchaseDocumentChargeLines::withoutRemovedRow(
            $request->request->all('charge_lines'),
            $this->nullable((string) $request->request->get('remove_charge_line', '')),
        );

        // "No charge rows arrived" is ambiguous: it means either "the admin removed every charge
        // row" or "this post was never shown them". `charge_lines_present` is what tells the two
        // apart — the form always sends it, so its ABSENCE means the post came from somewhere that
        // has never heard of charges, and erasing a document's freight because a script re-saved it
        // would be losing data nobody asked to lose.
        //
        // The fallback re-posts what the form WOULD have posted, rather than keeping the old
        // snapshot verbatim: that way the freight class is still re-resolved against the lines this
        // save wrote, instead of a stale class surviving a change of goods.
        if (!$request->request->has('charge_lines_present') && $chargesRaw === [] && $order instanceof PurchaseOrder) {
            $chargesRaw = array_merge(
                PurchaseDocumentChargeLines::fromChargeLines($order->getChargeLineRows()),
                $this->purchaseTax->manualTaxChargeRows($order),
            );
        }
        if ($request->request->has('add_charge_line')) {
            $added = PurchaseDocumentChargeLines::rowFromAddLineChoice((string) $request->request->get('charge_line_type', ''));
            if ($added !== null) {
                $chargesRaw[] = $added;
            }
        }

        // Refused at the door rather than discovered halfway through: by the time the lines have
        // been rebuilt, some of the document is already written. The sell side learned this from a
        // fee calculator that flushes mid-save; the reasoning holds regardless.
        $chargeError = PurchaseDocumentChargeLines::errorFor($chargesRaw);
        if ($chargeError !== null) {
            $this->addFlash('error', $chargeError);

            return $order instanceof PurchaseOrder
                ? $this->redirectToRoute('admin_bundle_procurement_purchase_order_edit', ['id' => $order->getId()])
                : $this->redirectToRoute('admin_bundle_procurement_purchase_order_new');
        }

        $charges = PurchaseDocumentChargeLines::normalize($chargesRaw);

        // The counterparty and the destination are the DRAFT's to choose, and only the draft's.
        // Past that, `issue()` has frozen the vendor's name and order-to address onto the document
        // — the whole point of freezing — and `incoming` is being forecast against this warehouse
        // by IncomingStockReconciler. Taking the posted values here would rewrite the first and
        // strand the second, so an issued order keeps what it has and the form renders both as
        // read-only rather than offering a control that is going to be ignored.
        $isDraft = !$order instanceof PurchaseOrder || $order->getStatusEnum() === PurchaseOrderStatus::Draft;
        $vendor = $isDraft ? $this->vendorOr404($request->request->getInt('vendor_id', 0)) : $order->getVendor();
        $warehouse = $isDraft ? $this->warehouseOr404($request->request->getInt('warehouse_id', 0)) : $order->getWarehouse();

        // A row's ✕ (#635) — the buy-side twin of OrderController's own `remove_line`, and the same
        // shape VendorBillController::save() already reads. Dropped from the posted rows BEFORE
        // requestedLines() ever sees it, which is one honest way in with blanking an existing row's
        // quantity (see that method's own docblock): both simply leave the row out of what gets
        // matched, and an unmatched existing line is removed at the end of this method either way.
        $linesRaw = $request->request->all('lines');
        if ($request->request->has('remove_line')) {
            unset($linesRaw[$request->request->getInt('remove_line', -1)]);
        }

        /** @var array<int, PurchaseOrderLine> $existingLines */
        $existingLines = [];
        foreach ($order?->getLines() ?? [] as $existingLine) {
            $existingLines[(int) $existingLine->getId()] = $existingLine;
        }

        // Resolved to entities and base figures BEFORE a single column is written — before the new
        // order below is even created — so the guard can read exactly what this save intends and
        // refuse with nothing touched. Same arrangement the charge rows above already have, and the
        // same one VendorBillController makes for the over-billing guard.
        $reconciled = $this->lineReconciler->reconcile($existingLines, $linesRaw, $vendor);

        if ($order instanceof PurchaseOrder) {
            // PurchaseOrderLineEditGuard predates the reconciler and reads its own narrower shape —
            // adapted from the resolved lines rather than widened to take them directly, so this
            // guard (order-only; VendorBill has none) stays exactly what it was.
            $requestedForGuard = array_map(
                static fn (ResolvedPurchaseLine $line): array => [
                    'line' => $line->existingId !== null ? $existingLines[$line->existingId] : null,
                    'quantity' => (string) $line->baseQuantity,
                    'unitCost' => $line->unitCost,
                ],
                $reconciled['lines'],
            );
            $refusals = PurchaseOrderLineEditGuard::refusals($order, $requestedForGuard);
            if ($refusals !== []) {
                // Every reason, not the first. Somebody who reduced three received lines in one pass
                // should not have to re-key the form three times to be told three facts this request
                // already knew — which is the complaint the whole item started from.
                foreach ($refusals as $refusal) {
                    $this->addFlash('error', $refusal);
                }

                return $this->redirectToRoute('admin_bundle_procurement_purchase_order_edit', ['id' => $order->getId()]);
            }
        }

        if (!$order instanceof PurchaseOrder) {
            $order = (new PurchaseOrder())
                ->setPoNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER));
            $this->em->persist($order);
        }

        // The delivery warehouse AND the tax province, in one call (queue item 37) — and ONLY while
        // the order is a draft. `deriveTaxProvinceFrom()` throws off Draft by design: moving a
        // warehouse does not restate tax on an order the vendor already has. #47 reached the same
        // boundary from the other side and for the same reason, which is why the form renders the
        // warehouse read-only past Draft rather than offering a select whose value is refused.
        //
        // It happens HERE, before the tax breakdown at the end of this method reads
        // getTaxProvince(), so a draft's frozen province and its frozen tax_lines are written from
        // one value in one breath and cannot disagree.
        if ($isDraft) {
            $order->deriveTaxProvinceFrom($warehouse);
        }

        $order
            ->setVendor($vendor)
            // Re-snapshotted while still a draft, so a draft raised before a vendor was renamed
            // prints the current name. It freezes for good at issue(), which is why this is guarded
            // by $isDraft: past issue the stored snapshot is the record of what was sent.
            ->setVendorName($isDraft ? $vendor->getName() : $order->getVendorName())
            ->setCurrency($vendor->getCurrency())
            ->setDocumentDate($this->calendarDate((string) $request->request->get('document_date', '')) ?? (new \DateTimeImmutable())->format('Y-m-d'))
            ->setExpectedDate($this->calendarDate((string) $request->request->get('expected_date', '')))
            ->setPaymentTerm($this->nullable((string) $request->request->get('payment_term', '')) ?? $vendor->getPaymentTerm())
            // #655: `tax` is no longer posted. It is derived in recalculateTotals() from the tax
            // line snapshot written below, exactly as `sales_order.tax` is derived from its own.
            // A vendor's stated figure that our rates do not reproduce is typed as a manual tax
            // charge row, which becomes a TaxLine of SOURCE_MANUAL beside the calculated ones.
            ->setNotes($this->nullable((string) $request->request->get('notes', '')));

        // The Ship From address panel — editable in place, plus a plain <select> against the
        // vendor's own address book and a real "Load address" submit (#635's no-JS twin of the
        // sell side's live picker). That submit posts the chosen book entry under
        // `{type}_address_book_id`, which is shifted onto `{type}_address_id` here before the
        // shared applier reads it — the same field the hidden input always carries, so a save that
        // did not touch the picker still round-trips whichever entry is already linked.
        //
        // Order To is NOT applied here (#full-parity, 2026-09-15, corrected): it is the delivery
        // warehouse's own address, not a per-order snapshot the form edits — there is nothing to
        // apply from a request that no longer posts it.
        $addressType = PurchaseOrderAddress::TYPE_SHIP_FROM;
        if ($request->request->has('apply_' . $addressType . '_address')) {
            $request->request->set(
                $addressType . '_address_id',
                $request->request->get($addressType . '_address_book_id', ''),
            );
        }
        $this->applyPurchaseAddressFromRequest($order, $vendor, $request, $addressType);

        // The products this order names BEFORE the edit, so a line removed here still gets its
        // `incoming` forecast recomputed at the end. IncomingStockReconciler::reconcileForOrder()
        // walks the order's CURRENT lines, which is exactly the set a removed product has just left.
        $productsBefore = [];
        foreach ($order->getLines() as $existingLine) {
            $existingProduct = $existingLine->getProduct();
            if ($existingProduct instanceof ProductCore && $existingProduct->getId() !== null) {
                $productsBefore[(int) $existingProduct->getId()] = $existingProduct;
            }
        }

        /** @var array<int, true> $keptLineIds */
        $keptLineIds = $reconciled['keptIds'];
        $removedLines = [];

        foreach ($reconciled['lines'] as $resolved) {
            $line = $resolved->existingId !== null ? $existingLines[$resolved->existingId] : new PurchaseOrderLine();

            $line
                ->setProduct($resolved->product)
                ->setName($resolved->name)
                ->setSku($resolved->sku)
                ->setVendorSku($resolved->vendorSku)
                ->setSubtotal($resolved->subtotal)
                ->setTaxCode($resolved->taxCode)
                ->setUnit($resolved->unit)
                ->setWeight($resolved->weight)
                ->setBatch($resolved->batch)
                ->setLocation($resolved->location)
                ->setSortOrder($resolved->sortOrder);

            // Written only when they actually changed — see ResolvedPurchaseLine's own docblock for
            // why (a stored precision this form's boxes cannot round-trip).
            if ($resolved->writeQuantity) {
                $this->applyLineQuantity(
                    $line,
                    $resolved->enteredQuantity,
                    $resolved->baseQuantity,
                    $resolved->lineUnit,
                    $resolved->baseUnit,
                    static fn (string $base) => $line->setQuantityOrdered($base),
                );
            }
            if ($resolved->writeUnitCost) {
                $line->setUnitCost($resolved->unitCost);
            }

            $order->addLine($line);
            $this->em->persist($line);
        }

        // Only the rows this save did not carry are removed, and they are removed AFTER the kept
        // ones have been matched, so a line is never detached and re-created with a new primary key
        // — which is what would cut its receipts and bills loose. orphanRemoval on
        // PurchaseOrder::$lines turns the detach into the DELETE.
        foreach ($existingLines as $existingLineId => $existingLine) {
            if (!isset($keptLineIds[$existingLineId])) {
                $removedLines[] = $existingLine;
                $order->removeLine($existingLine);
            }
        }

        // Once here so the charge calculators have a subtotal to price a percentage off, and again
        // at the end once the charges and the tax breakdown are frozen onto the document. It is
        // idempotent — every figure it writes is derived from what is on the order at the time —
        // so the first call costs a recomputation and buys an accurate fee context.
        $order->recalculateTotals();

        // Freight rows take the document's highest goods tax class, resolved now rather than frozen
        // when the admin typed the row, so adding a taxable product afterwards still lifts it.
        $chargeLines = array_merge(
            PurchaseDocumentChargeLines::toFreightLines($charges, $order->getHighestTaxClass()),
            PurchaseDocumentChargeLines::toFeeLines($charges),
            // The seam. Nothing is tagged today, so this is an empty list — but the call is here,
            // in the save, so that the day a bundle registers a freight or brokerage calculator its
            // rows land on the document with no further change. Calculated rows are rebuilt every
            // save from their own calculator; only the admin-typed ones are round-tripped through
            // the form.
            $this->purchaseFees->calculate(PurchaseFeeContext::fromPurchaseOrder($order)),
        );
        $order->setChargeLines(PurchaseChargeLineSnapshot::encode($chargeLines));

        // Tax LAST, because it is taken on the goods and on the charges both — and the charges have
        // only just been settled.
        $order->setTaxLines($this->purchaseTax->toJson($this->purchaseTax->compute(
            $order,
            $chargeLines,
            $this->purchaseTax->manualTaxLinesFromCharges($charges),
        )));

        $order->recalculateTotals();

        // Everything below only applies to an order that is PAST Draft, which is the whole of what
        // #47 opened up. A draft expects nothing on any dock, has no derived status to recompute and
        // has no history worth a timeline entry — it is being written, not amended.
        if (!$isDraft) {
            // The derived status is a projection of what arrived MEASURED AGAINST WHAT WAS ORDERED,
            // and this save has just moved the second term. Reducing a Partially Received line to
            // exactly what turned up makes the order Received; raising a Received line makes it
            // Partially Received again — the buy-side twin of the sell side's *"a line can still be
            // added to it, which is precisely how it becomes Partially Invoiced again"*. Without
            // this the status would keep answering a question about the quantities it had before.
            $this->poStatus->recalculate($order);

            // One entry per amendment, through the entity's own named recorder — see
            // PurchaseOrder::recordAmendment(). The figures come from the document; what the
            // controller adds is the one thing the amended document can no longer show, which is
            // each line this save dropped.
            $order->recordAmendment($this->actor(), $this->removedLineDetail($removedLines));
        }

        $this->em->flush();

        if (!$isDraft) {
            // `incoming` is a forecast of what an open order still expects, so changing the ordered
            // quantities changes it. Recomputed rather than adjusted, exactly as the issue, close and
            // cancel actions do it — reconcileForOrder() walks the order's own lines, and the loop
            // beside it covers a product whose only line this save just removed, which that walk can
            // no longer see. Both are no-ops when the figure did not move.
            $this->incoming->reconcileForOrder($order);
            foreach ($productsBefore as $productId => $productBefore) {
                if (!$this->orderNamesProduct($order, $productId)) {
                    $this->incoming->reconcile($productBefore, $order->getWarehouse());
                }
            }
        }

        // A post from the charge bar's two plain submits, or from a "Load address" submit, is
        // mid-edit, not finished: the admin has just asked for a charge row, dropped one, or picked
        // a book address and wants to see the result before deciding this document is done. Landing
        // them on the read-only detail page would make them press Edit again every time. Everything
        // else lands where it always did.
        if ($request->request->has('add_charge_line')
            || $request->request->has('remove_charge_line')
            || $request->request->has('remove_line')
            || $request->request->has('apply_' . PurchaseOrderAddress::TYPE_SHIP_FROM . '_address')
        ) {
            $this->addFlash('success', sprintf('Purchase order %s saved and recalculated.', $order->getPoNumber()));

            return $this->redirectToRoute('admin_bundle_procurement_purchase_order_edit', ['id' => $order->getId()]);
        }

        // Two sentences, because they are two different events. A draft is on its way to being sent;
        // an amendment has already been sent, and the vendor is still working from the copy they
        // have — which is a thing the person who just changed it needs to be told, not a thing for
        // them to remember.
        $this->addFlash('success', $isDraft
            ? sprintf('Purchase order %s saved as a draft. Issue it when it goes to the vendor.', $order->getPoNumber())
            : sprintf(
                'Purchase order %s updated and is now %s. The vendor is still working from the copy they were sent —'
                . ' send them the amended one if this changes what you are expecting.',
                $order->getPoNumber(),
                $order->getStatus(),
            ));

        return $this->redirectToRoute('admin_bundle_procurement_purchase_order', ['id' => $order->getId()]);
    }

    /** Does this order still carry a line for the product with this id, after the save. */
    private function orderNamesProduct(PurchaseOrder $order, int $productId): bool
    {
        foreach ($order->getLines() as $line) {
            $product = $line->getProduct();
            if ($product instanceof ProductCore && (int) $product->getId() === $productId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each line this save dropped, BY NAME, for the amendment's timeline entry.
     *
     * A removed line is the one change the amended document itself can no longer show: the order
     * that comes back simply has one fewer row, and nothing on it says what the row was.
     *
     * A bill that charged for a removed line keeps its own row and loses only its attribution —
     * `vendor_bill_line.purchase_order_line_id` is ON DELETE SET NULL — so the entry names that case
     * explicitly when it happens. Removing a billed line is ALLOWED here: the owner's rule is that
     * receipts floor a line and bills settle its price, and a line that has not been received is
     * *"free to do whatever"*. What is not allowed is doing it silently.
     *
     * @param list<PurchaseOrderLine> $removedLines
     */
    private function removedLineDetail(array $removedLines): string
    {
        $detail = '';

        foreach ($removedLines as $removed) {
            $detail .= sprintf(
                ' Removed line: %s (%s ordered)%s.',
                $removed->getName(),
                $removed->getQuantityOrdered(),
                QuantityScale::compare($removed->getQuantityCharged(), 0) > 0
                    ? sprintf(
                        ' — %s unit(s) of it are on a vendor bill, which now charges against no order line',
                        $removed->getQuantityCharged(),
                    )
                    : '',
            );
        }

        return trim($detail);
    }

    #[Route('/{id}/issue', name: 'admin_bundle_procurement_purchase_order_issue', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function issue(int $id): Response
    {
        return $this->act($id, function (PurchaseOrder $order): string {
            $order->setStatus(PurchaseOrderStatus::Issued->value, $this->actor());

            return sprintf('Purchase order %s issued. Its goods are now expected in %s.', $order->getPoNumber(), $order->getWarehouse()->getName());
        });
    }

    #[Route('/{id}/close', name: 'admin_bundle_procurement_purchase_order_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function close(int $id, Request $request): Response
    {
        return $this->act($id, function (PurchaseOrder $order) use ($request): string {
            $outstanding = $order->getQuantityOutstanding();

            // The screen stopped OFFERING this on an order with nothing outstanding (queue item 51);
            // this is the same rule answering a post that did not come from the screen — a stale tab
            // left open while the last delivery was booked in, a bookmark, a hand-built request.
            //
            // Guarded here rather than in `PurchaseOrder::closeShort()` deliberately. The entity's
            // rule is about which STATUSES may close and it is right as it stands: the seeders and
            // the domain tests close orders through it directly and legitimately. What is refused
            // here is narrower and belongs to the screen — filing a write-off of nothing — and a
            // \DomainException is how every refusal on this controller becomes a sentence (see
            // act()), so this reads to the admin exactly as the from-state refusals do.
            if (QuantityScale::compare($outstanding, 0) <= 0) {
                throw new \DomainException(sprintf(
                    'Purchase order %s has nothing outstanding — everything ordered has arrived. '
                        . 'There is nothing to close short.',
                    $order->getPoNumber(),
                ));
            }

            $order->closeShort($this->actor(), (string) $request->request->get('reason', ''));

            return sprintf('Purchase order %s closed with %s unit(s) written off.', $order->getPoNumber(), $outstanding);
        });
    }

    #[Route('/{id}/cancel', name: 'admin_bundle_procurement_purchase_order_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request): Response
    {
        return $this->act($id, function (PurchaseOrder $order) use ($request): string {
            $reason = $this->nullable((string) $request->request->get('reason', ''));

            $order->setStatus(
                PurchaseOrderStatus::Cancelled->value,
                $this->actor(),
                $reason !== null ? sprintf('Purchase order cancelled: %s', $reason) : null,
            );

            return sprintf('Purchase order %s cancelled. It keeps its number.', $order->getPoNumber());
        });
    }

    /**
     * Freeze it (#759) — the buy-side counterpart of `EstimateController::lockEstimate()` etc.
     *
     * Deliberately NOT routed through {@see act()}: that helper also calls
     * `$this->incoming->reconcileForOrder()`, which is about stock expected against THIS order and
     * has nothing to do with locking it. Locking changes no quantity, so it does not belong on that
     * path.
     */
    #[Route('/{id}/lock', name: 'admin_bundle_procurement_purchase_order_lock', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function lock(int $id, Request $request, DocumentLockService $locks): Response
    {
        $this->denyIfInactive();

        $order = $this->orderOr404($id);

        $locks->lock($order, $this->actor(), (string) $request->request->get('reason', ''), $this->em);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Purchase order %s is locked. It can still be printed and cloned via receiving; nothing '
                . 'can edit or delete it until it is unlocked.',
            $order->getPoNumber(),
        ));

        return $this->redirectToRoute('admin_bundle_procurement_purchase_order', ['id' => $order->getId()]);
    }

    /** Release the freeze. ROLE_SUPER_ADMIN, which ROLE_TECH_SUPPORT inherits — see DocumentLockService. */
    #[Route('/{id}/unlock', name: 'admin_bundle_procurement_purchase_order_unlock', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unlock(int $id, DocumentLockService $locks): Response
    {
        $this->denyIfInactive();
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $order = $this->orderOr404($id);

        $locks->unlock($order, $this->em);
        $this->em->flush();

        $this->addFlash('success', sprintf('Purchase order %s is unlocked and can be edited again.', $order->getPoNumber()));

        return $this->redirectToRoute('admin_bundle_procurement_purchase_order', ['id' => $order->getId()]);
    }

    /**
     * Runs one named action and turns a refused transition into a sentence.
     *
     * `\DomainException` is what every action on the entity throws when the from-state is wrong, and
     * the message it carries is already written for a person — so it is shown rather than
     * translated, and never swallowed.
     *
     * @param callable(PurchaseOrder): string $action
     */
    private function act(int $id, callable $action): Response
    {
        $this->denyIfInactive();

        $order = $this->orderOr404($id);

        try {
            // The action first, then the flush, then the message. A refused transition throws
            // before anything is written, and nothing says it succeeded until it is on disk.
            $message = $action($order);
            // After the action, so the recount reads the status the action just set: issuing makes
            // the goods expected, closing short writes the remainder off, cancelling says nothing
            // was ever ordered. Recomputed rather than adjusted, so an action that changed nothing
            // expected — a close on an order that already had everything — writes nothing and logs
            // nothing.
            $this->incoming->reconcileForOrder($order);
            $this->em->flush();
            $this->addFlash('success', $message);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_purchase_order', ['id' => $order->getId()]);
    }

    private function orderOr404(int $id): PurchaseOrder
    {
        $order = $this->em->find(PurchaseOrder::class, $id);
        if (!$order instanceof PurchaseOrder) {
            throw $this->createNotFoundException('No such purchase order.');
        }

        return $order;
    }

    /** Rendered HTML as a PDF the browser offers to save. Same pair InvoiceController carries. */
    private function pdfResponse(string $pdf, string $filename): Response
    {
        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    private function dompdf(string $html): string
    {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
