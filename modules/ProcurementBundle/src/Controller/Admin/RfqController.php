<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Entity\ProductCore;
use App\Repository\AuditLogRepository;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActorResolver;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\Rfq;
use ProcurementBundle\Entity\RfqLine;
use ProcurementBundle\Entity\RfqVendorReply;
use ProcurementBundle\Enum\RfqStatus;
use ProcurementBundle\Numbering\PurchaseDocumentNumberGenerator;
use ProcurementBundle\Repository\RfqRepository;
use ProcurementBundle\Rfq\RfqConversionService;
use ProcurementBundle\Product\ProductPicker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The RFQ (#637): raise a requirement, tender it to vendors, record what each one quoted, accept
 * one into a purchase order.
 *
 * Mirrors `Admin\EstimateController`'s shape as closely as the two-party-vs-many-party difference
 * allows — see `Rfq` and `RfqVendorReply`'s class docblocks for why the header and the priced quote
 * are two different classes here where Estimate is one.
 */
#[Route('/admin/bundles/procurement/rfqs')]
final class RfqController extends AbstractProcurementController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly PurchaseDocumentNumberGenerator $numbers,
        private readonly RfqRepository $rfqs,
        private readonly RfqConversionService $conversion,
        // The product field: this screen used to ask for a database id.
        private readonly ProductPicker $picker
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    /**
     * The products an RFQ already names, to seed its select past the inline limit.
     *
     * @return list<int>
     */
    private function lineProductIds(Rfq $rfq): array
    {
        $ids = [];
        foreach ($rfq->getLines() as $line) {
            $product = $line->getProduct();
            if ($product instanceof ProductCore && $product->getId() !== null) {
                $ids[] = (int) $product->getId();
            }
        }

        return $ids;
    }

    #[Route('', name: 'admin_bundle_procurement_rfqs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['q', 'status']);
        $paging = $this->paging($request, 'id');

        $result = $this->rfqs->search($filters, $paging['page'], $paging['limit'], $paging['sort'], $paging['dir']);

        return $this->render('@Procurement/rfqs.html.twig', [
            'rfqs' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'statuses' => RfqStatus::cases(),
            'page' => $paging['page'],
            'limit' => $paging['limit'],
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    #[Route('/new', name: 'admin_bundle_procurement_rfq_new', methods: ['GET'])]
    public function new(): Response
    {
        $this->denyIfInactive();

        return $this->render('@Procurement/rfq_edit.html.twig', [
            'rfq' => null,
            'warehouses' => $this->activeWarehouses(),
            'productOptions' => $this->picker->options(),
            'productsRemote' => $this->picker->isRemote(),
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_bundle_procurement_rfq_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyIfInactive();

        $rfq = $this->rfqOr404($id);
        if (!$rfq->getStatus()->allowsLineEditing()) {
            throw new NotFoundHttpException('This RFQ has already been sent and its requirement can no longer be edited.');
        }

        return $this->render('@Procurement/rfq_edit.html.twig', [
            'rfq' => $rfq,
            'warehouses' => $this->activeWarehouses(),
            'productOptions' => $this->picker->options($this->lineProductIds($rfq)),
            'productsRemote' => $this->picker->isRemote(),
        ]);
    }

    /**
     * Saves the requirement.
     *
     * ## Lines are rebuilt from the post, and rows are kept where they stand
     *
     * Every row the form renders comes back, including the blank spares, and a row is a line when
     * it names something and asks for a positive quantity. Rows are matched to existing lines BY
     * POSITION rather than removed and re-added wholesale, so editing a quantity does not hand the
     * line a new database id — `rfq_vendor_reply_line.rfq_line_id` points at these rows, and
     * re-creating them under a reply would orphan the quote against it (the FK is ON DELETE SET
     * NULL exactly so that losing the link never deletes money actually quoted).
     *
     * ## Removing a line without JavaScript
     *
     * `remove_line` carries the zero-based index of the row to drop, as the value of a real submit
     * button on that row — the same pairing the estimate and order line tables use, where the `x`
     * a script would handle sits beside a submit the server handles when there is no script. The
     * app works without JavaScript (`public/assets/js/app.js`), and a line table you can only add
     * to is not an editable line table.
     *
     * ## Still no money, and that is the point
     *
     * Not one field here is a price, a tax code, a currency or a total, and none is missing by
     * oversight: an RFQ names a requirement put to SEVERAL vendors, so there is no counterparty to
     * denominate anything in. The prices live on `RfqVendorReply`, one per vendor, and are entered
     * on that document's own screen — see RfqVendorReplyController.
     */
    #[Route('/save', name: 'admin_bundle_procurement_rfq_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyIfInactive();

        $id = $request->request->getInt('id', 0);
        $rfq = $id > 0 ? $this->rfqOr404($id) : null;

        if ($rfq instanceof Rfq && !$rfq->getStatus()->allowsLineEditing()) {
            $this->addFlash('error', sprintf('RFQ %s has already been sent and cannot be edited.', $rfq->getDocumentNumber()));

            return $this->redirectToRoute('admin_bundle_procurement_rfq', ['id' => $rfq->getId()]);
        }

        $warehouseId = $request->request->getInt('warehouse_id', 0);

        if (!$rfq instanceof Rfq) {
            $rfq = (new Rfq())->setDocumentNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_RFQ));
            $this->em->persist($rfq);
        }

        $rfq
            ->setWarehouse($warehouseId > 0 ? $this->warehouseOr404($warehouseId) : null)
            ->setDeliveryAddress($this->nullable((string) $request->request->get('delivery_address', '')))
            ->setNotes($this->nullable((string) $request->request->get('notes', '')));

        /** @var array<int, array<string, string>> $rows */
        $rows = $request->request->all('lines');

        // The row a Remove button asked to drop. `-1` rather than 0 for "none": row 0 is a real row
        // and getInt() answers 0 for an absent field, so a default of 0 would delete the first line
        // of every RFQ that was ever saved with the button not pressed.
        $removed = $request->request->has('remove_line') ? $request->request->getInt('remove_line', -1) : -1;

        /** @var list<RfqLine> $existing */
        $existing = $rfq->getLines()->toArray();
        $kept = [];
        $sortOrder = 0;

        foreach ($rows as $index => $row) {
            if ((int) $index === $removed) {
                continue;
            }

            $quantity = trim((string) ($row['quantity'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($quantity === '' || (float) $quantity <= 0 || $name === '') {
                continue;
            }

            $productId = $this->productIdFrom($row);
            $product = $productId > 0 ? $this->em->find(ProductCore::class, $productId) : null;

            $line = array_shift($existing) ?? null;
            if (!$line instanceof RfqLine) {
                $line = new RfqLine();
                $rfq->addLine($line);
                $this->em->persist($line);
            }

            $line
                ->setProduct($product instanceof ProductCore ? $product : null)
                ->setName($name)
                ->setSku($this->nullable((string) ($row['sku'] ?? '')))
                // Four decimals, matching the column since #645: a third of a case is 0.3333 and
                // two decimals lose it. The figure is what was typed, rounded to what the column
                // can hold, and nothing else on this document depends on it being whole.
                ->setQuantity(number_format((float) $quantity, 4, '.', ''))
                ->setNotes($this->nullable((string) ($row['notes'] ?? '')))
                ->setSortOrder($sortOrder++);

            $kept[] = $line;
        }

        // Whatever the post did not account for: rows removed, or blanked out until they stopped
        // being lines. orphanRemoval on the association deletes them on flush.
        foreach ($existing as $leftover) {
            $rfq->removeLine($leftover);
        }

        $this->em->flush();

        $this->addFlash('success', sprintf(
            'RFQ %s saved as a draft with %d requirement line(s).',
            $rfq->getDocumentNumber(),
            count($kept),
        ));

        // "Save & add more lines" lands back on the form with a fresh set of blank rows, which is
        // how a no-JS admin builds a long requirement: fill the spares, save, repeat. Removing a
        // line goes back there too — you are still editing.
        $action = (string) $request->request->get('action', '');

        return ($action === 'save_continue' || $removed >= 0)
            ? $this->redirectToRoute('admin_bundle_procurement_rfq_edit', ['id' => $rfq->getId()])
            : $this->redirectToRoute('admin_bundle_procurement_rfq', ['id' => $rfq->getId()]);
    }

    #[Route('/{id}', name: 'admin_bundle_procurement_rfq', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id, AuditLogRepository $auditLogs): Response
    {
        $this->denyIfInactive();

        $rfq = $this->rfqOr404($id);

        return $this->render('@Procurement/rfq_detail.html.twig', [
            'rfq' => $rfq,
            'vendors' => $this->activeVendors(),
            // The same Change History panel the quote detail and the order detail end with. What it
            // answers here is who altered the REQUIREMENT — the question every vendor was asked —
            // which the tender itself records only as its current state.
            'auditHistory' => $auditLogs->findForEntity('Rfq', $id),
        ]);
    }

    /**
     * Draft -> Sent. Creates one Invited reply per vendor selected on the detail screen, then calls
     * Rfq::send(), which refuses without at least one line, one reply and a warehouse.
     */
    #[Route('/{id}/send', name: 'admin_bundle_procurement_rfq_send', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function send(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $rfq = $this->rfqOr404($id);

        /** @var list<string> $vendorIds */
        $vendorIds = $request->request->all('vendor_ids');

        try {
            foreach ($vendorIds as $vendorId) {
                $vendor = $this->vendorOr404((int) $vendorId);

                $reply = (new RfqVendorReply())
                    ->setReplyNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_RFQ_REPLY))
                    ->setVendor($vendor)
                    ->setCurrency($vendor->getCurrency())
                    ->setDocumentDate((new \DateTimeImmutable())->format('Y-m-d'));

                $rfq->addReply($reply);
                $this->em->persist($reply);
            }

            $rfq->send();
            $this->em->flush();

            $this->addFlash('success', sprintf('RFQ %s sent to %d vendor(s).', $rfq->getDocumentNumber(), count($vendorIds)));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_rfq', ['id' => $rfq->getId()]);
    }

    #[Route('/{id}/cancel', name: 'admin_bundle_procurement_rfq_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id): Response
    {
        $this->denyIfInactive();

        $rfq = $this->rfqOr404($id);

        try {
            $rfq->cancel();
            $this->em->flush();
            $this->addFlash('success', sprintf('RFQ %s cancelled.', $rfq->getDocumentNumber()));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_rfq', ['id' => $rfq->getId()]);
    }

    #[Route('/{id}/reply/{replyId}/decline', name: 'admin_bundle_procurement_rfq_reply_decline', requirements: ['id' => '\d+', 'replyId' => '\d+'], methods: ['POST'])]
    public function declineReply(int $id, int $replyId, Request $request): Response
    {
        $this->denyIfInactive();

        $rfq = $this->rfqOr404($id);
        $reply = $this->replyOr404($rfq, $replyId);

        try {
            $reply->decline((string) $request->request->get('reason', ''));
            $this->em->flush();
            $this->addFlash('success', sprintf('%s marked as declined.', $reply->getVendorName()));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_rfq', ['id' => $rfq->getId()]);
    }

    /** Accepts this vendor's quote and converts it into a purchase order — see RfqConversionService. */
    #[Route('/{id}/reply/{replyId}/convert', name: 'admin_bundle_procurement_rfq_reply_convert', requirements: ['id' => '\d+', 'replyId' => '\d+'], methods: ['POST'])]
    public function convertReply(int $id, int $replyId): Response
    {
        $this->denyIfInactive();

        $rfq = $this->rfqOr404($id);
        $reply = $this->replyOr404($rfq, $replyId);

        try {
            $order = $this->conversion->convert($reply, $this->em, $this->actor()->displayName);
            $this->em->flush();

            $this->addFlash('success', sprintf('%s accepted. Purchase order %s raised as a draft — review and issue it.', $reply->getVendorName(), $order->getPoNumber()));

            return $this->redirectToRoute('admin_bundle_procurement_purchase_order', ['id' => $order->getId()]);
        } catch (\LogicException|\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_rfq', ['id' => $rfq->getId()]);
    }

    private function rfqOr404(int $id): Rfq
    {
        $rfq = $this->em->find(Rfq::class, $id);
        if (!$rfq instanceof Rfq) {
            throw new NotFoundHttpException('No such RFQ.');
        }

        return $rfq;
    }

    private function replyOr404(Rfq $rfq, int $replyId): RfqVendorReply
    {
        foreach ($rfq->getReplies() as $reply) {
            if ($reply->getId() === $replyId) {
                return $reply;
            }
        }

        throw new NotFoundHttpException('No such reply on this RFQ.');
    }
}
