<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActorResolver;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Movement\InsufficientStockException;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\GoodsReceiptLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorReturn;
use ProcurementBundle\Entity\VendorReturnLine;
use ProcurementBundle\Numbering\PurchaseDocumentNumberGenerator;
use ProcurementBundle\Repository\VendorReturnRepository;
use ProcurementBundle\VendorReturn\VendorReturnShipService;
use ProcurementBundle\Product\ProductPicker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vendor returns (#638): goods physically going back to a supplier. Mirrors
 * `Admin\SalesReturnController`'s shape — raise, authorise, ship, decline, close — direction
 * reversed throughout. See `VendorReturn`'s class docblock for the naming difference (Shipped, not
 * Received) and why.
 */
#[Route('/admin/bundles/procurement/vendor-returns')]
final class VendorReturnController extends AbstractProcurementController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly PurchaseDocumentNumberGenerator $numbers,
        private readonly VendorReturnRepository $returns,
        private readonly VendorReturnShipService $shipService,
        // The product field: this screen used to ask for a database id.
        private readonly ProductPicker $picker
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    /**
     * The products a receipt names, to seed the return form's selects past the inline limit.
     *
     * A vendor return raised with no receipt behind it seeds nothing and searches for everything,
     * which is the correct answer rather than a gap: there is no shortlist to offer.
     *
     * @return list<int>
     */
    private function receiptProductIds(?GoodsReceipt $receipt): array
    {
        if (!$receipt instanceof GoodsReceipt) {
            return [];
        }

        $ids = [];
        foreach ($receipt->getLines() as $line) {
            $product = $line->getProduct();
            if ($product instanceof ProductCore && $product->getId() !== null) {
                $ids[] = (int) $product->getId();
            }
        }

        return $ids;
    }

    #[Route('', name: 'admin_bundle_procurement_vendor_returns', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['q', 'status']);
        $paging = $this->paging($request, 'id');

        $result = $this->returns->search($filters, $paging['page'], $paging['limit'], $paging['sort'], $paging['dir']);

        return $this->render('@Procurement/vendor_returns.html.twig', [
            'returns' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'page' => $paging['page'],
            'limit' => $paging['limit'],
        ]);
    }

    #[Route('/new', name: 'admin_bundle_procurement_vendor_return_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->denyIfInactive();

        // Both parents through the three-state read (queue item 51). Before this, `?receipt=abc`
        // and `?vendor=abc` threw a raw 400 out of `getInt()`; `?receipt=99999` rendered the blank
        // standalone form with nothing said; and `?vendor=99999` 404'd the whole screen through
        // `vendorOr404()` — three different answers to one question, none of them the right one.
        // A missing parent is not a missing SCREEN: the screen is fine, the link is wrong.
        $requestedReceipt = $this->requestedParent($request, 'receipt', GoodsReceipt::class, 'goods receipt');
        $requestedVendor = $this->requestedParent($request, 'vendor', Vendor::class, 'vendor');

        $receipt = $requestedReceipt->entity();

        return $this->render('@Procurement/vendor_return_edit.html.twig', $this->formContext(
            null,
            $receipt,
            // The receipt names the vendor when there is one; `?vendor=` only answers on its own.
            // Unchanged precedence — what changed is that a `?vendor=` that resolved to nothing is
            // now reported instead of silently becoming "no vendor named".
            $receipt instanceof GoodsReceipt ? $receipt->getVendor() : $requestedVendor->entity(),
        ) + [
            'badParents' => $this->unresolvedParents($requestedReceipt, $requestedVendor),
        ]);
    }

    /**
     * Edit a return that is still a request. The save below has always had an update branch, gated
     * correctly on `VendorReturnStatus::allowsLineEditing()` — and nothing could ever reach it: no
     * edit route existed and the form hard-coded `id=0`, so every post created a new return and a
     * request naming the wrong product could only be declined and retyped. This is the missing
     * caller, shaped exactly like `admin_bundle_procurement_purchase_order_edit` and its siblings.
     */
    #[Route('/{id}/edit', name: 'admin_bundle_procurement_vendor_return_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyIfInactive();

        $vendorReturn = $this->returnOr404($id);

        // The same refusal the save gives, given before the admin types rather than after.
        if (!$vendorReturn->getStatus()->allowsLineEditing()) {
            $this->addFlash('error', sprintf('Return %s is %s and cannot be edited.', $vendorReturn->getDocumentNumber(), $vendorReturn->getStatus()->value));

            return $this->redirectToRoute('admin_bundle_procurement_vendor_return', ['id' => $vendorReturn->getId()]);
        }

        return $this->render('@Procurement/vendor_return_edit.html.twig', $this->formContext(
            $vendorReturn,
            $vendorReturn->getGoodsReceipt(),
            $vendorReturn->getVendor(),
        ) + [
            // Reached by its own id through a `\d+` route requirement — no parent id in the URL to
            // have been wrong. Explicit because `strict_variables` is on.
            'badParents' => [],
        ]);
    }

    /**
     * One context for the create and the edit screen, so the two cannot drift.
     *
     * @return array<string, mixed>
     */
    private function formContext(?VendorReturn $vendorReturn, ?GoodsReceipt $receipt, ?Vendor $vendor): array
    {
        $productIds = $this->receiptProductIds($receipt);
        foreach ($vendorReturn?->getLines() ?? [] as $line) {
            if ($line->getProduct()->getId() !== null) {
                $productIds[] = (int) $line->getProduct()->getId();
            }
        }

        return [
            'return' => $vendorReturn,
            'receipt' => $receipt,
            'vendor' => $vendor,
            'vendors' => $this->activeVendors(),
            // Seeded from the receipt being returned against and from what the document already
            // names, so its lines name their products even past the inline limit.
            'productOptions' => $this->picker->options(array_values(array_unique($productIds))),
            'productsRemote' => $this->picker->isRemote(),
        ];
    }

    #[Route('/save', name: 'admin_bundle_procurement_vendor_return_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyIfInactive();

        $id = $request->request->getInt('id', 0);
        $vendorReturn = $id > 0 ? $this->returnOr404($id) : null;

        if ($vendorReturn instanceof VendorReturn && !$vendorReturn->getStatus()->allowsLineEditing()) {
            $this->addFlash('error', sprintf('Return %s has already been authorised and cannot be edited.', $vendorReturn->getDocumentNumber()));

            return $this->redirectToRoute('admin_bundle_procurement_vendor_return', ['id' => $vendorReturn->getId()]);
        }

        $vendor = $this->vendorOr404($request->request->getInt('vendor_id', 0));
        $receiptId = $this->optionalId($request, 'goods_receipt_id');
        $receipt = $receiptId > 0 ? $this->em->find(GoodsReceipt::class, $receiptId) : null;

        if (!$vendorReturn instanceof VendorReturn) {
            $vendorReturn = (new VendorReturn())->setDocumentNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_VENDOR_RETURN));
            $this->em->persist($vendorReturn);
        }

        $vendorReturn
            ->setVendor($vendor)
            ->setGoodsReceipt($receipt)
            // The order these goods were bought on. DERIVED from the receipt rather than asked for
            // a second time, byte-for-byte the sell side's `Admin\SalesReturnController::save()`,
            // which sets `sales_return.sales_order_id` from `$invoice->getSalesOrder()`. A return
            // raised with no receipt behind it names no order, exactly as an RMA raised with no
            // invoice behind it names none — and that is a real case, not a gap: the docblock on
            // `VendorReturn::$goodsReceipt` says so at length.
            ->setPurchaseOrder($receipt?->getPurchaseOrder())
            ->setReason($this->nullable((string) $request->request->get('reason', '')))
            ->setNotes($this->nullable((string) $request->request->get('notes', '')));

        foreach ($vendorReturn->getLines()->toArray() as $existing) {
            $vendorReturn->removeLine($existing);
        }

        /** @var array<int, array<string, string>> $rows */
        $rows = $request->request->all('lines');
        $sortOrder = 0;

        foreach ($rows as $row) {
            $quantity = trim((string) ($row['quantity'] ?? ''));
            $productId = $this->productIdFrom($row);
            if ($quantity === '' || (float) $quantity <= 0 || $productId <= 0) {
                continue;
            }

            $product = $this->productOr404($productId);

            $line = (new VendorReturnLine())
                ->setProduct($product)
                ->setName($this->nullable((string) ($row['name'] ?? '')) ?? $product->getName())
                ->setSku($this->nullable((string) ($row['sku'] ?? '')) ?? $product->getSku())
                ->setQuantity(number_format((float) $quantity, 2, '.', ''))
                ->setReason($this->nullable((string) ($row['reason'] ?? '')))
                ->setSortOrder($sortOrder++);

            // Which delivery this row came off. The form was ALWAYS built from the receipt — it
            // loops `receipt.lines` and transcribes product, name, SKU and quantity per row — and
            // then dropped the one thing only it knew: the receipt line's own id. Without it a
            // return against a vendor who delivered the same SKU three times cannot say which of
            // the three is going back. `SalesReturnLine::$invoiceLine` is the sell-side twin and it
            // is written.
            //
            // Checked against the receipt the document names rather than trusted: a posted id
            // belonging to some other delivery would otherwise record provenance that reads as a
            // fact, and a receipt cleared on this save must not leave its lines attached.
            $receiptLine = $this->receiptLineFrom($row, $receipt);
            if ($receiptLine instanceof GoodsReceiptLine) {
                $line->setGoodsReceiptLine($receiptLine);
            }

            $vendorReturn->addLine($line);
            $this->em->persist($line);
        }

        $this->em->flush();

        $this->addFlash('success', sprintf('Return %s saved as a request.', $vendorReturn->getDocumentNumber()));

        return $this->redirectToRoute('admin_bundle_procurement_vendor_return', ['id' => $vendorReturn->getId()]);
    }

    #[Route('/{id}', name: 'admin_bundle_procurement_vendor_return', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        $this->denyIfInactive();

        return $this->render('@Procurement/vendor_return_detail.html.twig', [
            'return' => $this->returnOr404($id),
            'warehouses' => $this->activeWarehouses(),
        ]);
    }

    #[Route('/{id}/action/{action}', name: 'admin_bundle_procurement_vendor_return_action', requirements: ['id' => '\d+', 'action' => 'authorise|decline|close'], methods: ['POST'])]
    public function performAction(int $id, string $action, Request $request): Response
    {
        $this->denyIfInactive();

        $vendorReturn = $this->returnOr404($id);

        try {
            match ($action) {
                'authorise' => $vendorReturn->authorise(),
                'decline' => $vendorReturn->decline((string) $request->request->get('reason', '')),
                'close' => $vendorReturn->close(),
                default => throw new \DomainException(sprintf('"%s" is not an action a vendor return supports.', $action)),
            };

            $this->em->flush();
            $this->addFlash('success', sprintf('Return %s: %s.', $vendorReturn->getDocumentNumber(), $action));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_vendor_return', ['id' => $vendorReturn->getId()]);
    }

    /**
     * The stock-moving action. Separate from performAction() because it needs a warehouse and a
     * real service (VendorReturnShipService), not just a named entity method — the identical split
     * SalesReturnController makes between its generic action dispatch and its own receive() route.
     */
    #[Route('/{id}/ship', name: 'admin_bundle_procurement_vendor_return_ship', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ship(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $vendorReturn = $this->returnOr404($id);
        $warehouseId = $request->request->getInt('warehouse_id', 0);

        if ($warehouseId <= 0) {
            $this->addFlash('error', 'Say which warehouse the goods are shipping from.');

            return $this->redirectToRoute('admin_bundle_procurement_vendor_return', ['id' => $vendorReturn->getId()]);
        }

        try {
            $warehouse = $this->warehouseOr404($warehouseId);
            $this->shipService->ship($vendorReturn, $warehouse, $this->actor()->displayName);
            $this->addFlash('success', sprintf('Return %s shipped from %s.', $vendorReturn->getDocumentNumber(), $warehouse->getName()));
        } catch (\DomainException|InsufficientStockException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_vendor_return', ['id' => $vendorReturn->getId()]);
    }

    /**
     * The posted receipt line, but only when it really belongs to the receipt this return names.
     *
     * @param array<string, string> $row
     */
    private function receiptLineFrom(array $row, ?GoodsReceipt $receipt): ?GoodsReceiptLine
    {
        if (!$receipt instanceof GoodsReceipt) {
            return null;
        }

        $id = (int) ($row['goods_receipt_line_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $line = $this->em->find(GoodsReceiptLine::class, $id);

        return $line instanceof GoodsReceiptLine && $line->getReceipt() === $receipt ? $line : null;
    }

    private function returnOr404(int $id): VendorReturn
    {
        $vendorReturn = $this->em->find(VendorReturn::class, $id);
        if (!$vendorReturn instanceof VendorReturn) {
            throw new NotFoundHttpException('No such vendor return.');
        }

        return $vendorReturn;
    }
}
