<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\CompanyListScope;
use App\Service\DocumentActorResolver;
use App\Service\QuantityScale;
use BarcodeBundle\Barcode\ProductLookup;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\InsufficientStockException;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\ShortDatedReceipt;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Receiving\ReceiptVoidService;
use ProcurementBundle\Receiving\ReceivingException;
use ProcurementBundle\Receiving\ReceivingRequest;
use ProcurementBundle\Receiving\CaptureRequirement;
use ProcurementBundle\Receiving\CaptureRequirements;
use ProcurementBundle\Receiving\MinimumShelfLife;
use ProcurementBundle\Receiving\ReceivingService;
use ProcurementBundle\Receiving\SerialList;
use ProcurementBundle\Repository\PurchaseOrderRepository;
use ProcurementBundle\Repository\GoodsReceiptRepository;
use App\Http\RequestedParent;
use ProcurementBundle\Product\ProductPicker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receiving (#555): the screen goods actually arrive through.
 *
 * It builds a ReceivingRequest and hands it to ReceivingService, which calls #550's
 * StockMovementService. **This controller never touches inventory**, which is the same discipline
 * that keeps the adjustment screen, the scanner and receiving on one implementation of "stock
 * entered" instead of three.
 *
 * Two ways in, both supported deliberately:
 *
 *  - **against a purchase order**, which pre-fills the outstanding quantities and attributes each
 *    line to the PO row it came from;
 *  - **standalone**, for goods nobody raised a PO for. Refusing those would mean the warehouse
 *    cannot record what is physically on the dock, which is worse than the missing paperwork — so
 *    they are recorded and flagged on the three-way match instead.
 */
#[Route('/admin/bundles/procurement/receiving')]
final class ReceivingController extends AbstractProcurementController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly ReceivingService $receiving,
        private readonly ReceiptVoidService $voids,
        // What each product needs captured, read from BOTH requirement models at once. The same
        // object ReceivingService refuses on, so this screen cannot ask for something different
        // from what booking in enforces (item 67).
        private readonly CaptureRequirements $captures,
        private readonly ProductLookup $lookup,
        // The product field: this screen used to ask for a database id.
        private readonly ProductPicker $picker,
        // The same object ReceivingService measures with, so the console's warning at the dock and
        // the refusal at Book it in cannot disagree about which dates are short (item 68).
        private readonly MinimumShelfLife $shelfLife,
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    #[Route('', name: 'admin_bundle_procurement_receipts', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['q', 'vendor', 'warehouse', 'unordered', 'short_dated']);
        $paging = $this->paging($request, 'received', 'desc');

        /** @var GoodsReceiptRepository $repo */
        $repo = $this->em->getRepository(GoodsReceipt::class);
        $result = $repo->search($filters, $paging['page'], $paging['limit'], $paging['sort'], $paging['dir']);

        return $this->render('@Procurement/receipts.html.twig', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'vendors' => $this->activeVendors(),
            'warehouses' => $this->activeWarehouses(),
            'filters' => $filters,
            'page' => $paging['page'],
            'limit' => $paging['limit'],
            'pages' => max(1, (int) ceil($result['total'] / $paging['limit'])),
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    /**
     * The receiving bay: Choose PO by default, or straight to a screen when the URL already names
     * one (#792).
     *
     * Three screens share this one route, exactly as the mockup's three hash routes (`#choose`,
     * `#po=`, `#nopo`) share one page: `?po=` goes straight to receiving against that order, `?nopo=1`
     * goes straight to the no-PO screen, and neither gives you the Choose PO screen — a searchable
     * list of open orders plus the no-PO path, which is where a receiver with no delivery in hand
     * yet belongs. The rules for each product are handed to the with-PO/without-PO template so the
     * form can mark the fields a receiver has to fill — the enforcement is still ReceivingService's,
     * because a `required` attribute is a hint and not a guarantee.
     */
    #[Route('/new', name: 'admin_bundle_procurement_receive', methods: ['GET'])]
    public function form(Request $request): Response
    {
        $this->denyIfInactive();

        // `?po=` through the three-state read, not `getInt()` (queue item 51). `getInt()` threw a
        // raw 400 out of Symfony on `?po=abc` and on `?po=`, and answered a blank standalone form
        // with no warning at all on `?po=99999` — the two failures the standing rule forbids, from
        // one call.
        $requestedOrder = $this->requestedParent($request, 'po', PurchaseOrder::class, 'purchase order');
        $order = $requestedOrder->entity();

        if (!$order instanceof PurchaseOrder && !$request->query->getBoolean('nopo')) {
            return $this->renderChoose($request, $this->unresolvedParents($requestedOrder));
        }

        return $this->renderForm(
            $order,
            $this->unresolvedParents($requestedOrder),
            [],
            $order instanceof PurchaseOrder ? 0 : self::BLANK_LINE_ROWS,
            // Received by defaults to whoever is signed in (#792) — carried as an ordinary header
            // value so a fresh GET and a re-rendered POST go through the exact same template code.
            ['received_by' => (string) ($this->getUser()?->getUserIdentifier() ?? '')],
        );
    }

    /**
     * Choose PO (#792): a searchable list of the orders this delivery could be against, and the
     * no-PO path beside it.
     *
     * `?q=` is a plain GET filter rather than the mockup's client-side search — this screen has to
     * work with scripting off, and a GET filter is a URL a receiver can search from twice. It
     * matches the PO number or the vendor name, case-insensitively, the same two fields the mockup's
     * own JS search reads.
     */
    private function renderChoose(Request $request, array $badParents): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        /** @var PurchaseOrderRepository $orders */
        $orders = $this->em->getRepository(PurchaseOrder::class);
        $openOrders = $orders->openForReceiving();

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $openOrders = array_values(array_filter(
                $openOrders,
                static fn (PurchaseOrder $candidate): bool => str_contains(mb_strtolower($candidate->getPoNumber()), $needle)
                    || str_contains(mb_strtolower((string) $candidate->getVendorName()), $needle),
            ));
        }

        return $this->render('@Procurement/receive_choose.html.twig', [
            'badParents' => $badParents,
            'openOrders' => $openOrders,
            'q' => $q,
        ]);
    }

    /**
     * The receiving bay form, rendered from whatever state it is in.
     *
     * Called from the GET route with no draft at all, and from the POST route's *Add more lines*
     * branch with everything the receiver has typed so far plus room for more. One method so that
     * the second case cannot render a subtly different screen from the first — which is how a form
     * that grows rows ends up losing a column nobody noticed was only on the fresh copy.
     *
     * @param list<array<string, mixed>> $badParents
     * @param array<int, array<string, string>> $draft  rows as posted, keyed by row index
     * @param array<string, string>             $header the packing slip, receiver and notes as posted
     */
    private function renderForm(?PurchaseOrder $order, array $badParents, array $draft, int $spareRows, array $header = []): Response
    {
        $warehouse = $order?->getWarehouse();
        $bins = $warehouse !== null
            ? $this->em->getRepository(WarehouseLocation::class)->findBy(
                ['warehouse' => $warehouse, 'status' => 'Active'],
                ['sortKey' => 'ASC', 'code' => 'ASC'],
            )
            : $this->em->getRepository(WarehouseLocation::class)->findBy(['status' => 'Active'], ['sortKey' => 'ASC', 'code' => 'ASC']);

        return $this->render('@Procurement/receive.html.twig', [
            'order' => $order,
            'badParents' => $badParents,
            'vendors' => $this->activeVendors(),
            'warehouses' => $this->activeWarehouses(),
            'bins' => $bins,
            // Received by is a searchable field (#792) that still posts free text — the datalist
            // suggests active staff and defaulting to the signed-in user, but it does not constrain
            // the value to that list, because `received_by` has always been a plain string and a
            // receipt taken by someone with no admin account (a contractor, a driver) is legitimate.
            'staffIdentifiers' => $this->activeStaffIdentifiers(),
            // What each product on this screen needs captured. Keyed by product id, and read by the
            // row to decide which identity boxes to render at all: a batch box and a serial box on a
            // product that tracks neither are two boxes that mean nothing, and the walkthrough found
            // both typed against exactly such a product.
            'captures' => $this->capturesForForm($order, $draft),
            'draft' => $draft,
            // The same rows as ENTITIES, for the one question `draft` cannot answer: the shared
            // product field evaluates `selected.id`, and `draft` holds the strings that were posted
            // because it is a redisplay. Resolved here rather than in the template, once, beside the
            // capture requirements that already needed the same lookup.
            'draftProducts' => $this->draftProducts($draft),
            // The packing slip, the receiver's name, the notes and — on the blank path — the vendor
            // and warehouse. Carried through *Add more lines* and through a refusal for the same
            // reason the rows are: a form that loses half of itself when you ask it for another row
            // is a form nobody presses the button on twice.
            'header' => $header,
            // Rows the order's own lines do not account for — the ones *Add more lines* produced,
            // plus the blank-entry path's opening set.
            'spareRows' => $spareRows,
            'firstSpareIndex' => $order instanceof PurchaseOrder ? $order->getLines()->count() : 0,
            'serialLimit' => SerialList::LIMIT,
            // The blank-row entry path and every spare row: when the screen is receiving against a
            // purchase order its FIXED rows are the order's lines and the product is already named.
            'productOptions' => $this->picker->options(),
            'productsRemote' => $this->picker->isRemote(),
        ]);
    }

    /**
     * Scanning goods IN (#607, #609).
     *
     * ## The problem this solves, stated exactly
     *
     * At a goods-in dock you are holding somebody ELSE'S box. Before #607 the only product identifier
     * in this database was `product_core.sku`, so the scan console could read a label this business
     * printed and could not read the one the vendor printed — which is the one actually on the
     * carton. The typed form has the mirror of the same problem: off a purchase order it offers a
     * bare numeric "Product ID" box, so recording a substituted item means looking its id up first.
     *
     * With `product_barcode` in place, a scanned UPC, EAN, case GTIN or supplier part number lands on
     * the product. That is the difference between this screen being usable on a dock and not.
     *
     * ## It writes nothing. The Book it in button posts to the form's own route
     *
     * Every scan changes the URL and only the URL. The confirmation POSTs `lines[N][...]` to
     * `admin_bundle_procurement_receive_submit` — the SAME route, with the SAME field names, that
     * the typed form posts to — so a scanned delivery and a typed one build the same ReceivingRequest
     * and reach stock through the same ReceivingService and the same StockMovementService. There is
     * no second implementation of "goods arrived" here to drift out of step with the first.
     *
     * ## The substitution case, which is the reason this matters beyond convenience
     *
     * A scanned product that IS on the purchase order is bound to its `purchase_order_line_id`, so it
     * settles that line's outstanding quantity. A scanned product that is NOT on the order is
     * recorded on the same receipt with no order line at all — which `goods_receipt_line` has
     * always supported and the typed form has always allowed. So a vendor who ships a different item
     * can be recorded truthfully instead of not at all, and the three-way match flags it rather than
     * the warehouse hiding it.
     *
     * ## No JavaScript
     *
     * A hardware wedge scanner types into the focused field and presses Enter, which is a plain form
     * submit. The tally is in GET parameters, so the back button undoes a mis-scan and the URL can be
     * handed to a supervisor. A camera sits beside this field; it never replaces it.
     */
    #[Route('/scan', name: 'admin_bundle_procurement_receive_scan', methods: ['GET'])]
    public function scanForm(Request $request): Response
    {
        $this->denyIfInactive();

        // Resolving the PO is step one, not a form sitting among four others further down the
        // page — the same door a printed PO's own barcode (or its number, typed) already opens on
        // the reference systems' own receiving flows. `?po=` (a database id) stays the canonical
        // way in via the open-order list; this is the second way, for a document that names itself
        // by its PO NUMBER rather than an id nobody printed on paper.
        $scannedPoNumber = trim((string) $request->query->get('po_number', ''));
        if ($scannedPoNumber !== '') {
            $matchedOrder = $this->em->getRepository(PurchaseOrder::class)->findOneBy(['poNumber' => $scannedPoNumber]);
            if ($matchedOrder instanceof PurchaseOrder) {
                return $this->redirectToRoute('admin_bundle_procurement_receive_scan', ['po' => $matchedOrder->getId()]);
            }

            $this->addFlash('error', sprintf('No purchase order found for "%s". Pick one from the list below, or check the number against the paperwork.', $scannedPoNumber));
        }

        $requestedOrder = $this->requestedOrder($request);
        $order = $requestedOrder->entity();

        // The console's OTHER four ids, through the same three-state read as `?po=` (queue item 51,
        // second pass). Every one of them was still `getInt()`, so `?warehouse=abc`, `?vendor=abc`,
        // `?bin=abc`, `?pending=abc` and `?pending_q=abc` each threw a raw 400 out of Symfony on the
        // one screen in this application somebody drives with a wedge scanner in one hand — and
        // `?warehouse=99999` opened a console silently bound to no warehouse, which is a whole
        // delivery counted into nowhere. Fixing `?po=` alone left four doors open on the same room.
        $requestedVendor = $this->requestedParent($request, 'vendor', Vendor::class, 'vendor');
        $requestedWarehouse = $this->requestedParent($request, 'warehouse', Warehouse::class, 'warehouse');
        $requestedPending = $this->requestedParent($request, 'pending', ProductCore::class, 'product');

        // An order decides its own warehouse; `?warehouse=` is only consulted when there is no order
        // to ask. The notice is still raised either way, because a link naming a warehouse that does
        // not exist is broken whether or not this screen needed the answer.
        $warehouse = $order?->getWarehouse() ?? $requestedWarehouse->entity();
        $slip = $this->slipFromRequest($request);

        /** @var PurchaseOrderRepository $orders */
        $orders = $this->em->getRepository(PurchaseOrder::class);

        $bins = $warehouse instanceof Warehouse
            ? $this->em->getRepository(WarehouseLocation::class)->findBy(
                ['warehouse' => $warehouse, 'status' => 'Active'],
                ['sortKey' => 'ASC', 'code' => 'ASC'],
            )
            : [];
        $requestedBin = $this->requestedBin($request, $bins);

        // The unit the console is standing in front of, waiting to be told what identifies it.
        $pending = $requestedPending->entity();

        $lines = $this->scanLines($order, $slip);

        return $this->render('@Procurement/receive_scan.html.twig', [
            'order' => $order,
            'badParents' => $this->unresolvedParents(
                $requestedOrder,
                $requestedVendor,
                $requestedWarehouse,
                $requestedBin,
                $requestedPending,
            ),
            'openOrders' => $orders->openForReceiving(),
            'vendors' => $this->activeVendors(),
            'warehouses' => $this->activeWarehouses(),
            'vendorId' => $requestedVendor->entity()?->getId() ?? 0,
            'warehouseId' => $warehouse?->getId() ?? 0,
            'bins' => $bins,
            'binId' => $requestedBin->entity()?->getId() ?? 0,
            'lines' => $lines,
            'slip' => $slip,
            'pending' => $pending,
            'pendingNeeds' => $pending instanceof ProductCore ? $this->captures->forProduct($pending) : null,
            'pendingQuantity' => $this->scannedQuantity($request),
            'scannedTotal' => $this->slipTotal($lines),
        ]);
    }

    /**
     * Which shelf everything on the slip is going to, out of the bins this warehouse actually has.
     *
     * Membership, not existence, because existence is the wrong question here: bin codes are per
     * warehouse and `?bin=7` carried over from another warehouse's console names a real row that
     * this delivery cannot go into. Answering "that bin doesn't exist" would be false, and silently
     * dropping it — which is what `getInt()` plus a select that has no matching option did — books
     * the pallet into the warehouse and no bin with nothing said.
     *
     * @param list<WarehouseLocation> $bins
     *
     * @return RequestedParent<WarehouseLocation>
     */
    private function requestedBin(Request $request, array $bins): RequestedParent
    {
        $requestedId = RequestedParent::requestedIdIn($request->query->all(), 'bin');

        if ($requestedId === null) {
            return RequestedParent::none('bin');
        }

        foreach ($bins as $bin) {
            if ((string) $bin->getId() === $requestedId) {
                return RequestedParent::of($bin, $requestedId, 'bin');
            }
        }

        return RequestedParent::notOneOf($requestedId, 'bin', 'a bin in the warehouse this delivery is going to');
    }

    /**
     * How many of the pending unit were scanned at once.
     *
     * A COUNT and not an id, so there is no third state and nothing to report: `?pending_q=abc` is
     * one unit, the same as no parameter at all. What it may not be is a raw 400, which is what
     * `getInt()` made of it — so it is read by indexing the bag like every other parameter on this
     * screen, and clamped rather than validated.
     */
    private function scannedQuantity(Request $request): int
    {
        $raw = $request->query->all()['pending_q'] ?? null;

        return max(1, is_scalar($raw) ? (int) $raw : 1);
    }

    /** A posted id, read the way every other id on this screen is — see scanStep(). */
    private function postedId(Request $request, string $key): int
    {
        $raw = $request->request->all()[$key] ?? null;

        return max(0, is_scalar($raw) ? (int) $raw : 0);
    }

    /**
     * One scan on the receiving screen. Resolves it and puts the result back into the URL.
     *
     * Every refusal names the value it refused and says why, and none of them writes anything:
     *
     *  - an empty scan
     *  - a code that is neither a SKU nor a barcode on any product
     *  - a code that is a barcode on TWO products, which is a real case — two vendors using one part
     *    number for two different things — and is named rather than guessed between, because a guess
     *    at goods-in books stock against the wrong product about half the time
     *  - a product that has been deleted
     */
    #[Route('/scan', name: 'admin_bundle_procurement_receive_scan_step', methods: ['POST'])]
    public function scanStep(Request $request): Response
    {
        $this->denyIfInactive();

        $slip = $this->slipFromRequest($request);

        // Indexed out of the bag rather than read through getInt(), for the reason every other read
        // on this screen is: getInt() throws BadRequestException on input it dislikes, and a scan
        // that arrives with a malformed hidden field must re-render the console rather than replace
        // it with a raw 400. These four are carried straight back into the redirect, so the cast is
        // the whole of the validation they need.
        $state = [
            'po' => $this->postedId($request, 'po'),
            'vendor' => $this->postedId($request, 'vendor'),
            'warehouse' => $this->postedId($request, 'warehouse'),
            'bin' => $this->postedId($request, 'bin'),
        ];

        $code = trim((string) $request->request->get('code', ''));

        // ── The console is holding a unit it has already identified the PRODUCT of, and is waiting
        //    to be told what identifies the UNIT (item 67). Everything typed while it is waiting is
        //    that identity, not another product — which is exactly how a receiver works a dock with
        //    a wedge scanner: scan the carton, scan the label on it, scan the next carton.
        $pendingId = $this->postedId($request, 'pending');
        if ($pendingId > 0) {
            return $this->captureIdentity($request, $state, $slip, $pendingId, $code);
        }

        if ($code === '') {
            $this->addFlash('error', 'Nothing was scanned.');

            return $this->redirectToRoute('admin_bundle_procurement_receive_scan', $this->scanState($state, $slip));
        }

        $products = $this->lookup->products($code);

        if (\count($products) > 1) {
            $this->addFlash('error', (string) $this->lookup->describeAmbiguity($code));

            return $this->redirectToRoute('admin_bundle_procurement_receive_scan', $this->scanState($state, $slip));
        }

        if ($products === []) {
            $this->addFlash('error', sprintf(
                '%s is not a SKU and is not a barcode on any product. Nothing was recorded — put the code on the'
                . ' product on the Barcodes screen first, and it will scan from then on.',
                $code,
            ));

            return $this->redirectToRoute('admin_bundle_procurement_receive_scan', $this->scanState($state, $slip));
        }

        $product = $products[0];
        $productId = $product->getId() ?? 0;

        $quantity = $request->request->getInt('quantity', 1);
        $quantity = $quantity > 0 ? min($quantity, 100000) : 1;

        $order = $this->requestedOrder($request)->entity();
        $onOrder = $order instanceof PurchaseOrder && $this->orderLineFor($order, $product) instanceof PurchaseOrderLine;
        $substitution = $order instanceof PurchaseOrder && !$onOrder
            ? ' It is NOT on this purchase order, so it will be recorded on the receipt with no order line — which is what a substitution is.'
            : '';

        // ── Does this product need an identity captured? The console used to refuse serials outright
        //    and send receivers to the typed form, "where the field is there and is enforced" — which
        //    was false, and left serialised stock with no way onto the shelf at all (item 67). It
        //    asks now, from the same object the typed form and ReceivingService read.
        $needs = $this->captures->forProduct($product);

        if ($needs->capturesSerial() || ($needs->capturesLot() && !isset($slip['lot'][$productId]))) {
            $state['pending'] = $productId;
            $state['pending_q'] = $needs->capturesSerial() ? 1 : $quantity;

            $this->addFlash('success', sprintf(
                '%s.%s %s',
                $this->lookup->describe($code, $product),
                $substitution,
                $needs->capturesSerial()
                    ? 'Every unit of this product carries its own serial, so this is ONE unit — scan or type the serial on it next.'
                    : sprintf('Units of this product are identified by the batch they came from — scan or type the batch code on the carton next. It applies to all %d.', $quantity),
            ));

            return $this->redirectToRoute('admin_bundle_procurement_receive_scan', $this->scanState($state, $slip));
        }

        $slip['count'][$productId] = ($slip['count'][$productId] ?? 0) + $quantity;

        $this->addFlash('success', sprintf(
            '%s — %d on the slip.%s',
            $this->lookup->describe($code, $product),
            $slip['count'][$productId],
            $substitution,
        ));

        return $this->redirectToRoute('admin_bundle_procurement_receive_scan', $this->scanState($state, $slip));
    }

    /**
     * The second half of one scan: what identifies the unit the console is already holding.
     *
     * Reached only while a product is pending, so an empty box keeps it pending rather than losing
     * the carton. Three ways out, and each of them is a thing the receiver SAYS:
     *
     *  - the identity, scanned or typed;
     *  - *no identity on these units*, which books them in unidentified — #573's warehouse
     *    mid-transition case, on the tracking worklist, and now a declaration rather than a blank;
     *  - *that was not it*, which drops the unit off the slip so a mis-scan costs nothing.
     *
     * @param array<string, int>                                                                  $state
     * @param array{count: array<int, int>, serials: array<int, list<string>>, lot: array<int, string>, expiry: array<int, string>, unidentified: array<int, int>} $slip
     */
    private function captureIdentity(Request $request, array $state, array $slip, int $pendingId, string $code): Response
    {
        $product = $this->em->find(ProductCore::class, $pendingId);

        if (!$product instanceof ProductCore) {
            $this->addFlash('error', 'That product no longer exists, so nothing was added to the slip.');

            return $this->redirectToRoute('admin_bundle_procurement_receive_scan', $this->scanState($state, $slip));
        }

        $label = $product->getSku() ?: $product->getName();
        $needs = $this->captures->forProduct($product);
        $action = (string) $request->request->get('action', '');
        $quantity = max(1, $request->request->getInt('pending_q', 1));

        if ($action === 'abandon') {
            $this->addFlash('success', sprintf('%s was dropped off the slip. Nothing was recorded for it.', $label));

            return $this->redirectToRoute('admin_bundle_procurement_receive_scan', $this->scanState($state, $slip));
        }

        if ($action === 'unidentified') {
            $slip['count'][$pendingId] = ($slip['count'][$pendingId] ?? 0) + $quantity;
            $slip['unidentified'][$pendingId] = 1;

            $this->addFlash('success', sprintf(
                '%s — %d on the slip with NO %s captured. They will be booked in unidentified and put on the tracking worklist, which is where stock waiting for its paperwork lives.',
                $label,
                $slip['count'][$pendingId],
                $needs->capturesSerial() ? 'serial' : 'batch code',
            ));

            return $this->redirectToRoute('admin_bundle_procurement_receive_scan', $this->scanState($state, $slip));
        }

        if ($code === '') {
            $this->addFlash('error', sprintf(
                'Nothing was scanned, and %s still needs its %s. Scan it, or say there is none on these units.',
                $label,
                $needs->capturesSerial() ? 'serial' : 'batch code',
            ));

            $state['pending'] = $pendingId;
            $state['pending_q'] = $quantity;

            return $this->redirectToRoute('admin_bundle_procurement_receive_scan', $this->scanState($state, $slip));
        }

        if ($needs->capturesSerial()) {
            foreach ($slip['serials'] as $serials) {
                foreach ($serials as $seen) {
                    if (mb_strtolower($seen) === mb_strtolower($code)) {
                        $this->addFlash('error', sprintf(
                            'Serial %s is already on this slip. A serial identifies one unit, so scanning it twice is one unit counted twice — nothing was added. Scan the serial on the next carton.',
                            $code,
                        ));

                        $state['pending'] = $pendingId;
                        $state['pending_q'] = $quantity;

                        return $this->redirectToRoute('admin_bundle_procurement_receive_scan', $this->scanState($state, $slip));
                    }
                }
            }

            $slip['serials'][$pendingId][] = $code;

            $this->addFlash('success', sprintf(
                '%s — serial %s captured. %d unit(s) of it on the slip. Scan the next carton.',
                $label,
                $code,
                \count($slip['serials'][$pendingId]),
            ));

            return $this->redirectToRoute('admin_bundle_procurement_receive_scan', $this->scanState($state, $slip));
        }

        // A batch, and the expiry that rides on it when the policy says the batch must carry one.
        $expiry = $this->calendarDate((string) $request->request->get('expiry', ''));

        if ($needs->capturesExpiry() && $expiry === null) {
            $this->addFlash('error', sprintf(
                '%s is on a tracking policy that says the batch must carry an expiry date, and none was given for batch %s. Expiry is the LAST USABLE DAY and it is a property of the batch — a batch booked in without one reads as "does not expire" everywhere it appears afterwards.',
                $label,
                $code,
            ));

            $state['pending'] = $pendingId;
            $state['pending_q'] = $quantity;

            return $this->redirectToRoute('admin_bundle_procurement_receive_scan', $this->scanState($state, $slip));
        }

        $slip['lot'][$pendingId] = $code;
        if ($expiry !== null) {
            $slip['expiry'][$pendingId] = $expiry;
        }
        $slip['count'][$pendingId] = ($slip['count'][$pendingId] ?? 0) + $quantity;

        // ── A bad date, said HERE (items 68, 69), with the carton still in the receiver's hands.
        //
        //    Booking in warns too — it is the same MinimumShelfLife object the service asks, so
        //    neither screen can let through what the other would stop, and neither can word it
        //    differently. But the console is the one moment the pallet can still be refused, and a
        //    receiver who is told at Book it in has already put the goods away. This does not block
        //    the scan: the units go on the slip, and the decision is made once, with a reason, on
        //    the form the refusal lands on.
        //
        //    The headline distinguishes the two findings, because "already expired" and "12 days
        //    short of 90" call for different answers from the person holding the box. Which one it
        //    is comes off the finding rather than being decided twice.
        $finding = $expiry !== null
            ? $this->shelfLife->findingFor($product, new \DateTimeImmutable($expiry), new \DateTimeImmutable())
            : null;
        if ($finding !== null) {
            $this->addFlash('info', sprintf(
                '%s — %s: %s. The pallet is still in front of you: refuse it, or book it in and say why. Booking it in will ask for a reason and record who gave it.',
                $finding->isAlreadyExpired() ? 'EXPIRED ON ARRIVAL' : 'SHORT-DATED',
                $label,
                $finding->describe(),
            ));
        }

        $this->addFlash('success', sprintf(
            '%s — batch %s captured, %d on the slip.%s One batch per product per slip: a delivery carrying two batches of one product is two slips, or the typed form.',
            $label,
            $code,
            $slip['count'][$pendingId],
            $expiry !== null ? sprintf(' Last usable day %s.', $expiry) : '',
        ));

        return $this->redirectToRoute('admin_bundle_procurement_receive_scan', $this->scanState($state, $slip));
    }

    /**
     * What the scan screen shows and submits: one row per scanned product.
     *
     * Each row carries the purchase order line it settles when the product is on the order, and no
     * order line at all when it is not. That second case is the substitution, and it is a first-class
     * receipt line rather than an error — `goods_receipt_line.purchase_order_line_id` has always
     * been nullable, and refusing to record what is physically on the dock is worse than the missing
     * paperwork.
     *
     * A row also carries whatever identity the console captured for it (item 67): a list of serials,
     * each of which is one unit, and/or the batch code and expiry that apply to the counted units.
     * `unidentified` is the receiver having said there is none — those units still go on the shelf
     * and still land on the tracking worklist, which is what that flag has always meant.
     *
     * @param array{count: array<int, int>, serials: array<int, list<string>>, lot: array<int, string>, expiry: array<int, string>, unidentified: array<int, int>} $slip
     *
     * @return list<array{product: ProductCore, quantity: int, serials: list<string>, lot: ?string, expiry: ?string, unidentified: bool, needs: CaptureRequirement, orderLine: PurchaseOrderLine|null, outstanding: string|null}>
     */
    private function scanLines(?PurchaseOrder $order, array $slip): array
    {
        $productIds = array_unique(array_merge(
            array_keys($slip['count']),
            array_keys($slip['serials']),
        ));

        $lines = [];

        foreach ($productIds as $productId) {
            $product = $this->em->find(ProductCore::class, $productId);
            if (!$product instanceof ProductCore) {
                continue;
            }

            $orderLine = $order instanceof PurchaseOrder ? $this->orderLineFor($order, $product) : null;

            $lines[] = [
                'product' => $product,
                'quantity' => $slip['count'][$productId] ?? 0,
                'serials' => $slip['serials'][$productId] ?? [],
                'lot' => $slip['lot'][$productId] ?? null,
                'expiry' => $slip['expiry'][$productId] ?? null,
                'unidentified' => ($slip['unidentified'][$productId] ?? 0) === 1,
                'needs' => $this->captures->forProduct($product),
                'orderLine' => $orderLine,
                'outstanding' => $orderLine?->getQuantityOutstanding(),
            ];
        }

        return $lines;
    }

    /**
     * Units on the slip: the counted ones plus one for every serial captured.
     *
     * A serial IS a unit — one serial, one row, quantity 1 — so the two halves are added rather than
     * one of them standing for the other. Getting this wrong is how a console says 70 and books 0.
     *
     * @param list<array{quantity: int, serials: list<string>}> $lines
     */
    private function slipTotal(array $lines): int
    {
        $total = 0;

        foreach ($lines as $line) {
            $total += $line['quantity'] + \count($line['serials']);
        }

        return $total;
    }

    /**
     * The order line this product settles, or null.
     *
     * The first line for the product that is **still outstanding**, not simply the first line
     * carrying it. Two lines for one product is ordinary — two price breaks, two delivery dates, two
     * cost centres — and a receiver settling the second carton reaches this through a spare row,
     * which is exactly what *Add more lines* is for. Crediting the first line whether or not it was
     * already complete meant a PO of 10 + 5, received 10 then 5, read 15 against line one and
     * nothing against line two, and stayed Partially Received with every unit on the shelf.
     *
     * This is GitHub #660's shape on the way IN, and `$allocatedUnits` is the half of it that a
     * per-line outstanding check alone does not fix: `quantity_received` is credited by
     * ReceivingService AFTER this whole loop, so two rows of ONE submission both read the
     * pre-submission state and would both pick the same line. The caller therefore tallies what each
     * row has already spoken for, in hundredths, keyed by object identity — which holds whether or
     * not the line has an id yet, and means "already consumed by this operation" rather than
     * "already on disk".
     *
     * Falling back to the first matching line when every one of them is complete is deliberate:
     * over-receipt is recorded and flagged, never refused, and returning null instead would file the
     * extra units as a substitution against no order line at all.
     *
     * @param array<int, string> $allocatedUnits already bound to a line by THIS submission
     */
    private function orderLineFor(PurchaseOrder $order, ProductCore $product, array $allocatedUnits = []): ?PurchaseOrderLine
    {
        $carryingTheProduct = null;

        foreach ($order->getLines() as $line) {
            if ($line->getProduct() !== $product) {
                continue;
            }

            $carryingTheProduct ??= $line;

            $outstanding = QuantityScale::sub(
                $line->getQuantityOutstanding(),
                $allocatedUnits[spl_object_id($line)] ?? QuantityScale::canonical(0),
            );

            if (QuantityScale::compare($outstanding, 0) > 0) {
                return $line;
            }
        }

        return $carryingTheProduct;
    }

    /**
     * The whole slip, from whichever side of the request carries it.
     *
     * It lives in the URL, where anybody can type, so every value is checked on the way in and a
     * malformed one is DROPPED rather than carried into the confirmation: a product id that names
     * nothing, a non-numeric quantity, a date that is not a date. That is the same treatment the
     * tally alone has always had, extended to the identity the console now captures beside it.
     *
     * Five short keys because they are URL parameters a receiver can see and a supervisor can be
     * handed: `q` counted, `s` serials, `b` batch, `e` expiry, `u` unidentified.
     *
     * @return array{count: array<int, int>, serials: array<int, list<string>>, lot: array<int, string>, expiry: array<int, string>, unidentified: array<int, int>}
     */
    private function slipFromRequest(Request $request): array
    {
        $bag = $request->isMethod('POST') ? $request->request : $request->query;

        $slip = ['count' => [], 'serials' => [], 'lot' => [], 'expiry' => [], 'unidentified' => []];

        foreach ($bag->all('q') as $productId => $quantity) {
            if (!ctype_digit((string) $productId) || !\is_scalar($quantity) || !ctype_digit(trim((string) $quantity))) {
                continue;
            }

            $units = (int) trim((string) $quantity);
            if ($units > 0) {
                $slip['count'][(int) $productId] = min($units, 100000);
            }
        }

        foreach ($bag->all('s') as $productId => $serials) {
            if (!ctype_digit((string) $productId) || !\is_array($serials)) {
                continue;
            }

            foreach ($serials as $serial) {
                if (!\is_scalar($serial)) {
                    continue;
                }

                $value = trim((string) $serial);
                if ($value !== '' && \count($slip['serials'][(int) $productId] ?? []) < SerialList::LIMIT) {
                    $slip['serials'][(int) $productId][] = mb_substr($value, 0, 120);
                }
            }
        }

        foreach ($bag->all('b') as $productId => $code) {
            if (ctype_digit((string) $productId) && \is_scalar($code) && trim((string) $code) !== '') {
                $slip['lot'][(int) $productId] = mb_substr(trim((string) $code), 0, 64);
            }
        }

        foreach ($bag->all('e') as $productId => $date) {
            if (!ctype_digit((string) $productId) || !\is_scalar($date)) {
                continue;
            }

            $calendar = $this->calendarDate((string) $date);
            if ($calendar !== null) {
                $slip['expiry'][(int) $productId] = $calendar;
            }
        }

        foreach ($bag->all('u') as $productId => $flag) {
            if (ctype_digit((string) $productId) && \is_scalar($flag) && (string) $flag === '1') {
                $slip['unidentified'][(int) $productId] = 1;
            }
        }

        return $slip;
    }

    /**
     * The slip and the console's settings, flattened back into redirect parameters.
     *
     * Empty keys are dropped so the URL stays something a receiver can read. `pending` is part of
     * $state rather than the slip because it is where the console IS, not what it has counted — a
     * mis-scan abandons it and nothing on the slip changes.
     *
     * @param array<string, int>                                                                  $state
     * @param array{count: array<int, int>, serials: array<int, list<string>>, lot: array<int, string>, expiry: array<int, string>, unidentified: array<int, int>} $slip
     *
     * @return array<string, mixed>
     */
    private function scanState(array $state, array $slip): array
    {
        $params = array_filter($state, static fn (mixed $v): bool => $v !== 0 && $v !== [] && $v !== null);

        foreach ([
            'q' => $slip['count'],
            's' => $slip['serials'],
            'b' => $slip['lot'],
            'e' => $slip['expiry'],
            'u' => $slip['unidentified'],
        ] as $key => $values) {
            if ($values !== []) {
                $params[$key] = $values;
            }
        }

        return $params;
    }

    /**
     * The purchase order the scan console is counting against, as three states (queue item 51).
     *
     * The GET half is a create screen like any other and gets the same treatment: `?po=abc` used to
     * throw a raw 400 out of `getInt()` on a screen a receiver reaches with a wedge scanner in one
     * hand, and `?po=99999` used to open the console silently unbound to anything, so a whole
     * delivery could be counted against no order at all.
     *
     * The POST half — one scan, which re-renders its own URL — reads the same field out of the
     * request body. It goes through the same reader so that a scan cannot resolve differently from
     * the page it was typed on; `all()` is indexed rather than `getInt()` called for the same
     * reason as everywhere else here, which is that `getInt()` throws on input it dislikes.
     */
    private function requestedOrder(Request $request): RequestedParent
    {
        if (!$request->isMethod('POST')) {
            return $this->requestedParent($request, 'po', PurchaseOrder::class, 'purchase order');
        }

        $requestedId = RequestedParent::requestedIdIn($request->request->all(), 'po');

        if ($requestedId === null) {
            return RequestedParent::none('purchase order');
        }

        if (!CompanyListScope::isIdShaped($requestedId)) {
            return RequestedParent::unresolved($requestedId, 'purchase order');
        }

        $order = $this->em->find(PurchaseOrder::class, (int) $requestedId);

        return $order instanceof PurchaseOrder
            ? RequestedParent::of($order, $requestedId, 'purchase order')
            : RequestedParent::unresolved($requestedId, 'purchase order');
    }

    /**
     * Book a delivery in.
     *
     * Everything is handed to ReceivingService whole so that a receipt and its movements are never
     * half-written: either the whole truck goes in or none of it does, and a refusal names the line
     * and says what is missing.
     */
    #[Route('/new', name: 'admin_bundle_procurement_receive_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        $this->denyIfInactive();

        $orderId = $request->request->getInt('purchase_order_id', 0);
        $order = $orderId > 0 ? $this->em->find(PurchaseOrder::class, $orderId) : null;

        $draft = $this->draftRows($request);
        $fixedRows = $order instanceof PurchaseOrder ? $order->getLines()->count() : 0;
        $spareRows = max(0, \count($draft) - $fixedRows);
        $header = [
            'packing_slip' => (string) $request->request->get('packing_slip', ''),
            'received_by' => (string) $request->request->get('received_by', ''),
            'notes' => (string) $request->request->get('notes', ''),
            'vendor_id' => (string) $request->request->getInt('vendor_id', 0),
            'warehouse_id' => (string) $request->request->getInt('warehouse_id', 0),
        ];

        // ── *Add more lines*, the no-JS repeatable-row mechanism this bundle already uses twice.
        //
        // The RFQ form and the vendor bill form both do this as `Save & add more lines`: post, save
        // the draft, land back on the form with another set of spares. A goods receipt HAS NO DRAFT
        // — saving one books stock through the movement service — so this is the same gesture with
        // the save taken out: everything typed comes straight back, plus room for more, and nothing
        // is written until *Book it in*. Item 67's first part, which is why the walkthrough could not
        // enter ten serials on a screen that rendered one row.
        if ((string) $request->request->get('action', '') === 'add_lines') {
            return $this->renderForm($order, [], $draft, $spareRows + self::BLANK_LINE_ROWS, $header);
        }

        $packingSlip = $this->nullable((string) $request->request->get('packing_slip', ''));
        $receivedBy = $this->nullable((string) $request->request->get('received_by', '')) ?? $this->getUser()?->getUserIdentifier();
        $notes = $this->nullable((string) $request->request->get('notes', ''));
        $key = $this->operationKey($request);

        if ($order instanceof PurchaseOrder) {
            $receiving = ReceivingRequest::againstPurchaseOrder($order, $packingSlip, $receivedBy, $notes, null, $key);
        } else {
            $vendorId = $request->request->getInt('vendor_id', 0);
            $warehouseId = $request->request->getInt('warehouse_id', 0);
            if ($vendorId <= 0 || $warehouseId <= 0) {
                $this->addFlash('error', 'A receipt with no purchase order still needs a vendor and a warehouse — otherwise nobody can say whose goods these are or where they went.');

                return $this->renderForm(null, [], $draft, max(self::BLANK_LINE_ROWS, $spareRows), $header);
            }

            $receiving = ReceivingRequest::unordered(
                $this->vendorOr404($vendorId),
                $this->warehouseOr404($warehouseId),
                $packingSlip,
                $receivedBy,
                $notes,
                null,
                $key,
            );
        }

        /** @var array<int, array<string, string>> $rows */
        $rows = $request->request->all('lines');
        $rowNumber = 0;

        // Already bound to each order line by THIS submission, keyed by object identity
        // and handed to orderLineFor(). Two rows naming one product — a second batch, a second bin,
        // the second carton against a two-line order — have to settle two different lines, and
        // nothing on disk can tell them apart yet because the credit happens after this loop.
        $allocated = [];

        foreach ($rows as $row) {
            ++$rowNumber;

            $quantity = trim((string) ($row['quantity'] ?? ''));

            // Bulk serial entry: a column of serials in one box, one per line (item 67). Each one
            // becomes its OWN receipt line of exactly one unit, because that is what a serial is —
            // the same rule ReceivingService has always enforced on a line that names one, reached
            // now by a control that can actually produce seventy of them. A single `serial` box is
            // still read and still works: the scan console posts one per scanned unit.
            $serials = SerialList::parse($row['serials'] ?? null);
            $single = $this->nullable((string) ($row['serial'] ?? ''));
            if ($single !== null) {
                array_unshift($serials, $single);
            }

            if ($serials === [] && ($quantity === '' || QuantityScale::compare($quantity, 0) <= 0)) {
                // A blank row is a row nobody filled in, not an error. Every line on a PO appears
                // on this form whether or not it turned up, so most submissions have some.
                continue;
            }

            $orderLineId = (int) ($row['purchase_order_line_id'] ?? 0);
            $orderLine = $orderLineId > 0 ? $this->em->find(PurchaseOrderLine::class, $orderLineId) : null;

            $productId = $this->productIdFrom($row);
            $product = $orderLine instanceof PurchaseOrderLine
                ? $orderLine->getProduct()
                : ($productId > 0 ? $this->em->find(ProductCore::class, $productId) : null);

            if (!$product instanceof ProductCore) {
                $this->addFlash('error', 'A receipt line has to name a product that still exists. A line whose product has been deleted can only be recorded as a note.');

                return $this->renderForm($order, [], $draft, max(self::BLANK_LINE_ROWS, $spareRows), $header);
            }

            // A SPARE row names a product rather than an order line, because *Add more lines* cannot
            // know which of the order's lines a receiver means until they pick the product. Bind it
            // to the order line carrying that product when there is one — the first such line STILL
            // OUTSTANDING, which is exactly the rule the scan console already applies to a scanned
            // carton — and leave it unbound when there is none, which is what a substitution is.
            if (!$orderLine instanceof PurchaseOrderLine && $order instanceof PurchaseOrder) {
                $orderLine = $this->orderLineFor($order, $product, $allocated);
            }

            // What this row has spoken for, so a later row cannot pick a line this one just filled.
            // A serial is one unit, which is why the two halves are counted the way the receipt will
            // count them rather than by reading the quantity box alone.
            if ($orderLine instanceof PurchaseOrderLine) {
                $units = $serials !== [] ? (string) \count($serials) : QuantityScale::canonical($quantity);
                $allocated[spl_object_id($orderLine)] = QuantityScale::add(
                    $allocated[spl_object_id($orderLine)] ?? QuantityScale::canonical(0),
                    $units,
                );
            }

            $binId = (int) ($row['location_id'] ?? 0);
            $bin = $binId > 0 ? $this->em->find(WarehouseLocation::class, $binId) : null;
            $lotCode = $this->nullable((string) ($row['lot_code'] ?? ''));
            $expiry = $this->expiry((string) ($row['expiry'] ?? ''));
            $unitCost = $this->nullable((string) ($row['unit_cost'] ?? ''));
            // The receiver saying these units carry no code — #573's warehouse-mid-transition case,
            // as a positive act. It excuses a blank identity the tracking POLICY captures; it
            // excuses nothing a receiving RULE demands.
            $unidentified = (string) ($row['unidentified'] ?? '') !== '';
            // The SAME declaration, aimed at the expiry alone (#792): Lot Skip (f-none, above) and
            // Expiry Skip (f-noexp) are independent boxes on the row, because a batch code and its
            // expiry are independent facts about the carton — a receiver can have one and not the
            // other. See ReceivingService::assertLineIsBookable()'s `$expiryDeclared`.
            $expiryUnidentified = (string) ($row['expiry_unidentified'] ?? '') !== '';
            // Why goods with less shelf life than the minimum are being accepted (item 68). A
            // plain text box on the row beside the expiry date, filled in only when the receiver
            // has been told the date is short — the refusal names the line, and everything typed
            // comes back on the re-render, so the reason is added to the form that was refused
            // rather than retyped into a fresh one. Blank is absent, not an empty reason.
            $shortDatedReason = $this->nullable((string) ($row['short_dated_reason'] ?? ''));
            $where = sprintf('Line %d (%s)', $rowNumber, $product->getSku() ?: $product->getName());

            if ($serials !== []) {
                $refusal = $this->serialBlockRefusal($where, $serials, $quantity);
                if ($refusal !== null) {
                    $this->addFlash('error', $refusal);

                    return $this->renderForm($order, [], $draft, max(self::BLANK_LINE_ROWS, $spareRows), $header);
                }

                foreach ($serials as $serial) {
                    $receiving->add($product, '1.00', $orderLine, $lotCode, $expiry, $serial, $bin, $unitCost, false, $shortDatedReason, $expiryUnidentified);
                }

                continue;
            }

            $receiving->add(
                $product,
                $quantity,
                $orderLine instanceof PurchaseOrderLine ? $orderLine : null,
                $lotCode,
                $expiry,
                null,
                $bin instanceof WarehouseLocation ? $bin : null,
                $unitCost,
                $unidentified,
                $shortDatedReason,
                $expiryUnidentified,
            );
        }

        try {
            $receipt = $this->receiving->receive($receiving, $this->getUser()?->getUserIdentifier());
        } catch (ReceivingException|InsufficientStockException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            // Re-rendered, not redirected. A redirect discards the form, and this screen now carries
            // blocks of seventy pasted serials — throwing those away to report a missing expiry date
            // would make the refusal more expensive than the mistake. Everything typed comes back.
            return $this->renderForm($order, [], $draft, max(self::BLANK_LINE_ROWS, $spareRows), $header);
        }

        $this->addFlash('success', sprintf(
            'Receipt %s recorded %s unit(s)%s. Stock was written by the same movement service the adjustment screen uses, in the same transaction.',
            $receipt->getReceiptNumber(),
            $receipt->getTotalQuantity(),
            $receipt->getMovementGroup() !== null ? sprintf(' as movement group #%d', $receipt->getMovementGroup()->getId()) : '',
        ));

        // The short-dated overrides this receipt carries (item 68), said out loud on the way past
        // rather than only sitting on the document. Somebody accepted goods the minimum shelf life
        // would have turned away, and the person who did it should see it written down in the same
        // breath as the confirmation.
        $shortDated = $receipt->getShortDated();
        if ($shortDated !== []) {
            $this->addFlash('info', sprintf(
                '%d line(s) were accepted short-dated and logged as exceptions on %s: %s. They are on the receipt with the reason and who gave it.',
                \count($shortDated),
                $receipt->getReceiptNumber(),
                implode('; ', array_map(
                    static fn (ShortDatedReceipt $row): string => sprintf(
                        '%s expires %s, %d day(s) short of %d',
                        $row->getReceiptLine()->getSku() ?? $row->getReceiptLine()->getName(),
                        $row->getExpiry()->format('Y-m-d'),
                        $row->getShortfallDays(),
                        $row->getMinimumDays(),
                    ),
                    $shortDated,
                )),
            ));
        }

        // Said out loud rather than left to be discovered: a `simple` product has no bin/lot/serial
        // identity, so those lines landed in the warehouse's unspecified row rather than a named bin.
        $notStocked = $receipt->getLinesNotStocked();
        if ($notStocked !== []) {
            $this->addFlash('info', sprintf(
                '%d line(s) were recorded without a specific bin: %s are on simple inventory, which tracks no location. Stock was still received — switch the product to dimensional to track it by bin.',
                \count($notStocked),
                implode(', ', array_map(static fn ($line): string => $line->getSku() ?? $line->getName(), $notStocked)),
            ));
        }

        return $this->redirectToRoute('admin_bundle_procurement_receipt', ['id' => $receipt->getId()]);
    }

    #[Route('/{id}', name: 'admin_bundle_procurement_receipt', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        $this->denyIfInactive();

        $receipt = $this->em->find(GoodsReceipt::class, $id);
        if (!$receipt instanceof GoodsReceipt) {
            throw $this->createNotFoundException('No such receipt.');
        }

        return $this->render('@Procurement/receipt_detail.html.twig', ['receipt' => $receipt]);
    }

    /**
     * Withdraws a receipt (#613).
     *
     * **Void, never delete.** A receipt is a document: it keeps its number, keeps its lines and
     * keeps the movement group that says what reached stock, and the void adds a second group that
     * says what came back out. That is the same shape a cancelled purchase order and a voided vendor
     * bill already have, and it is why this route is `/void` rather than `/delete`.
     *
     * Everything is ReceiptVoidService's, whole, for the reason submit() hands everything to
     * ReceivingService: the stock, the un-crediting and the stamp are one transaction or none of
     * them, and a refusal names the row it refused on.
     */
    #[Route('/{id}/void', name: 'admin_bundle_procurement_receipt_void', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function void(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $receipt = $this->em->find(GoodsReceipt::class, $id);
        if (!$receipt instanceof GoodsReceipt) {
            throw $this->createNotFoundException('No such receipt.');
        }

        $reason = trim((string) $request->request->get('reason', ''));

        try {
            $this->voids->void($receipt, $reason, $this->getUser()?->getUserIdentifier(), $this->operationKey($request));
        } catch (ReceivingException|InsufficientStockException|\InvalidArgumentException|\LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_bundle_procurement_receipt', ['id' => $id]);
        }

        $this->addFlash('success', sprintf(
            'Receipt %s voided. %s unit(s) came back off stock through movement group %s, and every purchase_order_line.quantity_received it credited was reduced by what this receipt booked.',
            $receipt->getReceiptNumber(),
            $receipt->getTotalQuantity(),
            $receipt->getVoidMovementGroup() !== null ? '#' . $receipt->getVoidMovementGroup()->getId() : '(none — nothing on it had reached stock)',
        ));

        return $this->redirectToRoute('admin_bundle_procurement_receipt', ['id' => $id]);
    }

    /**
     * How many spare rows a fresh blank-entry form opens with, and how many *Add more lines* adds.
     *
     * Three, not five. The receipt form is the widest table in the bundle — eight columns, one of
     * them a whole serial block — and five empty copies of it pushed the submit button off a laptop
     * screen. Adding is one click and costs a page load, which is the trade the no-JS baseline makes
     * everywhere else in this bundle.
     */
    private const BLANK_LINE_ROWS = 3;

    /**
     * Active staff identifiers, for the Received by field's search suggestions (#792).
     *
     * `received_by` itself stays a plain string column — it always has, and a receipt taken by
     * someone with no admin account here (a contractor on the dock, a driver) is a real receipt —
     * so this only SUGGESTS, through a `<datalist>`, and the field still accepts anything typed.
     *
     * @return list<string>
     */
    private function activeStaffIdentifiers(): array
    {
        /** @var list<AdminUser> $users */
        $users = $this->em->getRepository(AdminUser::class)->findBy(['status' => 'Active'], ['email' => 'ASC']);

        return array_values(array_unique(array_map(
            static fn (AdminUser $user): string => $user->getUserIdentifier(),
            $users,
        )));
    }

    /**
     * The capture requirement for every product this screen can render a row for.
     *
     * The order's own products, plus any product a spare row already names — a substitution typed
     * into an added row needs its requirement statement as much as an order line does, and it is not
     * on the order to be found.
     *
     * @param array<int, array<string, string>> $draft
     *
     * @return array<int, CaptureRequirement>
     */
    private function capturesForForm(?PurchaseOrder $order, array $draft): array
    {
        $products = [];

        if ($order instanceof PurchaseOrder) {
            foreach ($order->getLines() as $line) {
                $product = $line->getProduct();
                if ($product instanceof ProductCore) {
                    $products[] = $product;
                }
            }
        }

        foreach ($this->draftProducts($draft) as $product) {
            $products[] = $product;
        }

        return $this->captures->forProducts($products);
    }

    /**
     * The product each DRAFT row names, resolved to entities and keyed by ROW INDEX.
     *
     * One answer to "which product is this spare row about", read both by the capture requirements
     * above and by the row template's product field. The template cannot work it out for itself:
     * `draft` is a redisplay of posted strings by contract — see {@see self::draftRows()} — and
     * handing a string to a partial that evaluates `selected.id` is a 500 on every re-render of a
     * spare row somebody had already chosen a product on.
     *
     * A row naming a product that has been deleted since it was posted is simply absent here. The
     * submit path refuses that by name; a form redisplay is not where it gets said.
     *
     * @param array<int, array<string, string>> $draft
     *
     * @return array<int, ProductCore>
     */
    private function draftProducts(array $draft): array
    {
        $products = [];

        foreach ($draft as $index => $row) {
            $productId = $this->productIdFrom($row);
            if ($productId <= 0) {
                continue;
            }

            $product = $this->em->find(ProductCore::class, $productId);
            if ($product instanceof ProductCore) {
                $products[$index] = $product;
            }
        }

        return $products;
    }

    /**
     * The rows a submission carries, cleaned up and re-indexed, for re-rendering the form.
     *
     * Everything is kept as the strings it was posted as. This is a redisplay, not a parse: a
     * receiver who typed a date the wrong way round gets their own characters back to correct,
     * rather than a silently normalised or silently emptied box.
     *
     * @return array<int, array<string, string>>
     */
    private function draftRows(Request $request): array
    {
        /** @var array<int|string, mixed> $rows */
        $rows = $request->request->all('lines');

        $draft = [];
        foreach ($rows as $index => $row) {
            if (!\is_array($row) || !ctype_digit((string) $index)) {
                continue;
            }

            $clean = [];
            foreach ($row as $key => $value) {
                if (\is_scalar($value)) {
                    $clean[(string) $key] = (string) $value;
                }
            }

            $draft[(int) $index] = $clean;
        }

        // By POSTED index, not by arrival order: the row index is what binds a redisplayed value to
        // the purchase order line it belongs to, so re-indexing would shuffle a receiver's typing
        // onto other products' rows.
        ksort($draft);

        return $draft;
    }

    /**
     * Why this block of serials cannot be turned into receipt lines, or null if it can.
     *
     * Checked HERE, on the screen the column was pasted into, rather than left to ReceivingService:
     * these three refusals are about the block as a block — how many, which repeat, and whether the
     * count matches the quantity beside it — and the service sees one flattened line at a time.
     * `assertSerialsAreDistinct()` still guards the same ground for everything that reaches it,
     * including the scan console and the seeders, because a validation only the form performs is a
     * validation the next caller skips.
     *
     * @param list<string> $serials
     */
    private function serialBlockRefusal(string $where, array $serials, string $quantity): ?string
    {
        if (\count($serials) > SerialList::LIMIT) {
            return sprintf(
                '%s: %d serials were entered and one receipt line takes at most %d. Nothing has been booked in — split the delivery across more than one receipt, which is also how the paperwork will read.',
                $where,
                \count($serials),
                SerialList::LIMIT,
            );
        }

        $duplicates = SerialList::duplicates($serials);
        if ($duplicates !== []) {
            return sprintf(
                '%s: serial %s appears more than once in the block of serials. A serial identifies one unit, so the same value twice is two units claiming to be one. Nothing has been booked in — check the list against the cartons. (%s)',
                $where,
                $duplicates[0],
                \count($duplicates) === 1 ? 'one repeated value' : sprintf('%d repeated values: %s', \count($duplicates), implode(', ', $duplicates)),
            );
        }

        // The quantity box is the receiver's own count off the packing slip, so a disagreement with
        // the serials they pasted is exactly the discrepancy worth stopping for: one of the two is
        // the delivery and the other is a miscount. Naming both numbers is the whole value — a
        // silent overwrite of one by the other is how a delivery goes quietly short.
        $counted = \count($serials);
        if ($quantity !== '' && QuantityScale::compare($quantity, $counted) !== 0) {
            return sprintf(
                '%s: the quantity says %s and %d serial(s) were entered. A serial is one unit, so these have to agree. Nothing has been booked in — correct whichever is wrong, or clear the quantity and the serials will count themselves.',
                $where,
                rtrim(rtrim($quantity, '0'), '.') ?: $quantity,
                $counted,
            );
        }

        return null;
    }

    private function expiry(string $value): ?\DateTimeImmutable
    {
        $value = $this->calendarDate($value);

        return $value === null ? null : new \DateTimeImmutable($value);
    }
}
