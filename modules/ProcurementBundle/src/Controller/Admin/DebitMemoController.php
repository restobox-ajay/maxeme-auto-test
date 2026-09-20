<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\PaymentMethod;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActorResolver;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\InsufficientStockException;
use ProcurementBundle\DebitMemo\DebitMemoStockService;
use ProcurementBundle\Entity\DebitMemo;
use ProcurementBundle\Entity\DebitMemoApplication;
use ProcurementBundle\Entity\DebitMemoLine;
use ProcurementBundle\Entity\DebitMemoRefund;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Entity\VendorReturn;
use ProcurementBundle\Enum\DebitMemoStatus;
use ProcurementBundle\Numbering\PurchaseDocumentNumberGenerator;
use ProcurementBundle\Product\ProductPicker;
use ProcurementBundle\Repository\DebitMemoRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Debit memos (#638): what a vendor owes back to us, applied against vendor bills or refunded.
 * Mirrors `Admin\CreditMemoController`'s shape exactly — see `DebitMemo`'s class docblock for the
 * entity-level reasoning this controller drives.
 *
 * ## The standalone restock path, which used to be reported here as unbuilt
 *
 * `DebitMemo::$restock` exists, mirroring `CreditMemo::$restock`, for the standalone case (a memo
 * with no linked `VendorReturn` whose issuing IS the goods leaving). This docblock used to say that
 * path was "reported rather than half-built", and that report sat here long enough to become the
 * defect it was describing: the flag was mapped, saved, offered on the form, and read by NOTHING —
 * `isRestock()` had no caller anywhere in the repository — so the warehouse went on showing units
 * that had physically left, silently, with a manual adjustment as the only correction and no trail
 * back to the memo that caused it.
 *
 * It is built now, in `ProcurementBundle\DebitMemo\DebitMemoStockService`, which `issue` below
 * delegates to unconditionally. See that class for why it is shaped like `VendorReturnShipService`
 * (one transaction around the transition and the movement) rather than like
 * `CreditMemoRestockSubscriber` (an event dispatched after the flush): the sell side's restock is a
 * receipt and cannot fail, the buy side's is a withdrawal and can.
 *
 * The consequence for this controller is that issuing a restock memo needs a warehouse — the
 * building the goods left — posted alongside the action, exactly as shipping a vendor return does.
 * The memo-linked case is unaffected: `assertRestockAndReturnAreExclusive()` still refuses `$restock`
 * on a memo carrying a `VendorReturn`, because that return's own `ship()` already moved the stock.
 */
#[Route('/admin/bundles/procurement/debit-memos')]
final class DebitMemoController extends AbstractProcurementController
{
    private const FALLBACK_METHODS = ['Bank Transfer', 'Check', 'Credit Card', 'Cash', 'E-Transfer'];

    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly PurchaseDocumentNumberGenerator $numbers,
        private readonly DebitMemoRepository $memos,
        private readonly DebitMemoStockService $stock,
        // A memo line may now name a product, which is what makes a restock memo able to say what
        // went back. Same picker every other purchase screen's line table uses.
        private readonly ProductPicker $picker,
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    #[Route('', name: 'admin_bundle_procurement_debit_memos', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['q', 'status', 'vendor']);
        $paging = $this->paging($request, 'id');

        $result = $this->memos->search($filters, $paging['page'], $paging['limit'], $paging['sort'], $paging['dir']);

        return $this->render('@Procurement/debit_memos.html.twig', [
            'memos' => $result['rows'],
            'total' => $result['total'],
            'vendors' => $this->activeVendors(),
            // The tabs and the Status column's <select> both render from this, so neither can come
            // to offer a status the other does not.
            'statuses' => DebitMemoStatus::cases(),
            'filters' => $filters,
            'page' => $paging['page'],
            'limit' => $paging['limit'],
            'pages' => max(1, (int) ceil($result['total'] / $paging['limit'])),
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    /**
     * `?vendor_return=` raises the memo against a return, `?bill=` against a bill, `?vendor=` for a
     * vendor with neither behind it. The bill form is the mirror of the sell side's `?invoice=`
     * (`Admin\CreditMemoController::new()`): the bill's lines are transcribed into the editor and
     * `debit_memo.vendor_bill_id` records where they came from — the provenance column that was
     * mapped and documented as "raised from BILL-123" but written by nothing.
     */
    #[Route('/new', name: 'admin_bundle_procurement_debit_memo_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->denyIfInactive();

        // All three parents through the three-state read (queue item 51). This screen had every
        // variant of the defect at once: any of the three spelled `abc` threw a raw 400 out of
        // `getInt()`; `?vendor_return=99999` and `?bill=99999` rendered the blank form with nothing
        // said; and `?vendor=99999` 404'd the screen. Worse, an unresolvable `?bill=` was
        // indistinguishable from no `?bill=` at all by the time the `??` chain below had run — so
        // the memo would have been raised against nobody, from a link that named somebody.
        $requestedReturn = $this->requestedParent($request, 'vendor_return', VendorReturn::class, 'vendor return');
        $requestedBill = $this->requestedParent($request, 'bill', VendorBill::class, 'bill');
        $requestedVendor = $this->requestedParent($request, 'vendor', Vendor::class, 'vendor');

        $vendorReturn = $requestedReturn->entity();
        $bill = $requestedBill->entity();

        // Precedence unchanged: the return names the vendor, then the bill, then `?vendor=` on its
        // own. What changed is that a parent that resolved to nothing no longer vanishes into the
        // next `??`.
        $vendor = $vendorReturn?->getVendor()
            ?? $bill?->getVendor()
            ?? $requestedVendor->entity();

        return $this->render('@Procurement/debit_memo_edit.html.twig', $this->formContext(null, $vendorReturn, $bill, $vendor) + [
            'badParents' => $this->unresolvedParents($requestedReturn, $requestedBill, $requestedVendor),
        ]);
    }

    /**
     * Edit a draft memo. The save below has always had an update branch, correctly gated on
     * `isDraft()` — and nothing could ever reach it, because no edit route existed and the form
     * hard-coded `id=0`, so every post was a create and a draft with the wrong vendor or the wrong
     * quantity could only be abandoned and retyped. This is the missing caller, shaped exactly like
     * `admin_bundle_procurement_bill_edit` and its three siblings.
     */
    #[Route('/{id}/edit', name: 'admin_bundle_procurement_debit_memo_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyIfInactive();

        $memo = $this->memoOr404($id);

        // The same refusal the save gives, given before the admin types rather than after: an
        // issued memo is a statement already made to the vendor.
        if (!$memo->isDraft()) {
            $this->addFlash('error', sprintf('Debit memo %s is %s and cannot be edited.', $memo->getDocumentNumber(), $memo->getStatus()->value));

            return $this->redirectToRoute('admin_bundle_procurement_debit_memo', ['id' => $memo->getId()]);
        }

        return $this->render('@Procurement/debit_memo_edit.html.twig', $this->formContext($memo, $memo->getVendorReturn(), $memo->getVendorBill(), $memo->getVendor()) + [
            // Reached by its own id through a `\d+` route requirement — no parent id in the URL to
            // have been wrong. Explicit because `strict_variables` is on.
            'badParents' => [],
        ]);
    }

    /**
     * One context for both the create and the edit screen, so the two cannot drift — the shape
     * `VendorBillController::formContext()` already uses here.
     *
     * @return array<string, mixed>
     */
    private function formContext(?DebitMemo $memo, ?VendorReturn $vendorReturn, ?VendorBill $bill, ?Vendor $vendor): array
    {
        return [
            'memo' => $memo,
            'vendorReturn' => $vendorReturn,
            'bill' => $bill,
            'vendor' => $vendor,
            'vendors' => $this->activeVendors(),
            // Seeded with the products the document (or the bill it is raised from) already names,
            // so their lines stay readable past the picker's inline limit.
            'productOptions' => $this->picker->options($this->namedProductIds($memo, $bill)),
            'productsRemote' => $this->picker->isRemote(),
        ];
    }

    /**
     * The products this form should be able to name without a search: the memo's own, plus the
     * bill's when it is being raised from one.
     *
     * @return list<int>
     */
    private function namedProductIds(?DebitMemo $memo, ?VendorBill $bill): array
    {
        $ids = [];

        foreach ($memo?->getLines() ?? [] as $line) {
            $product = $line->getProduct();
            if ($product instanceof ProductCore && $product->getId() !== null) {
                $ids[] = (int) $product->getId();
            }
        }

        foreach ($bill?->getLines() ?? [] as $line) {
            $product = $line->getProduct();
            if ($product instanceof ProductCore && $product->getId() !== null) {
                $ids[] = (int) $product->getId();
            }
        }

        return array_values(array_unique($ids));
    }

    #[Route('/save', name: 'admin_bundle_procurement_debit_memo_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyIfInactive();

        $id = $request->request->getInt('id', 0);
        $memo = $id > 0 ? $this->memoOr404($id) : null;

        if ($memo instanceof DebitMemo && !$memo->isDraft()) {
            $this->addFlash('error', sprintf('Debit memo %s has been issued and cannot be edited.', $memo->getDocumentNumber()));

            return $this->redirectToRoute('admin_bundle_procurement_debit_memo', ['id' => $memo->getId()]);
        }

        $vendor = $this->vendorOr404($request->request->getInt('vendor_id', 0));
        $vendorReturnId = $this->optionalId($request, 'vendor_return_id');
        $vendorBillId = $this->optionalId($request, 'vendor_bill_id');

        if (!$memo instanceof DebitMemo) {
            $memo = (new DebitMemo())->setDocumentNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_DEBIT_MEMO));
            // Today's date only on the way in. Re-stamping it on every save would mean editing a
            // draft's quantity silently re-dated the document, which is not what was edited.
            $memo->setDocumentDate((new \DateTimeImmutable())->format('Y-m-d'));
            $this->em->persist($memo);
        }

        $memo
            ->setVendor($vendor)
            ->setCurrency($vendor->getCurrency())
            ->setReason($this->nullable((string) $request->request->get('reason', '')))
            // Provenance: the bill this memo was raised from. See the class docblock on DebitMemo —
            // `vendor_bill_id` is "raised from BILL-123", and `debit_memo_application` is where the
            // money actually lands. The two are different questions and this is the first one.
            ->setVendorBill($vendorBillId > 0 ? $this->em->find(VendorBill::class, $vendorBillId) : null)
            ->setVendorReturn($vendorReturnId > 0 ? $this->em->find(VendorReturn::class, $vendorReturnId) : null)
            ->setRestock($request->request->getBoolean('restock', false));

        foreach ($memo->getLines()->toArray() as $existing) {
            $memo->removeLine($existing);
        }

        /** @var array<int, array<string, string>> $rows */
        $rows = $request->request->all('lines');
        $sortOrder = 0;

        foreach ($rows as $row) {
            $quantity = trim((string) ($row['quantity'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($quantity === '' || (float) $quantity <= 0 || $name === '') {
                continue;
            }

            $unitCost = trim((string) ($row['unit_cost'] ?? '0'));

            $line = (new DebitMemoLine())
                ->setName($name)
                ->setSku($this->nullable((string) ($row['sku'] ?? '')))
                ->setQuantity(number_format((float) $quantity, 2, '.', ''))
                ->setUnitCost(number_format((float) $unitCost, 4, '.', ''))
                ->setSubtotal(number_format((float) $quantity * (float) $unitCost, 2, '.', ''))
                ->setSortOrder($sortOrder++);

            // The billed row this line disputes, and the product it disputes it for — byte-for-byte
            // the sell side's `Admin\CreditMemoController::save()`, which reads `invoice_line_id`
            // off the posted row and takes the product from it. `debit_memo_line.vendor_bill_line_id`
            // was mapped and written by nothing; this is the line the buy-side mirror dropped.
            //
            // Checked against the memo's own bill rather than trusted: a posted id naming some other
            // vendor's bill line would otherwise write provenance that reads as a fact.
            $billLineId = (int) ($row['vendor_bill_line_id'] ?? 0);
            if ($billLineId > 0) {
                $billLine = $this->em->find(VendorBillLine::class, $billLineId);
                if ($billLine instanceof VendorBillLine && $billLine->getBill() === $memo->getVendorBill()) {
                    $line->setVendorBillLine($billLine);
                    $line->setProduct($billLine->getProduct());
                }
            }

            // A memo line may still name a product on its own — a standalone restock memo has no
            // bill behind it, and its lines are the only statement of what physically went back.
            if (!$line->getProduct() instanceof ProductCore) {
                $productId = $this->productIdFrom($row);
                if ($productId > 0) {
                    $line->setProduct($this->productOr404($productId));
                }
            }

            $memo->addLine($line);
            $this->em->persist($line);
        }

        $memo->recalculateTotals();
        $this->em->flush();

        $this->addFlash('success', sprintf('Debit memo %s saved as a draft.', $memo->getDocumentNumber()));

        return $this->redirectToRoute('admin_bundle_procurement_debit_memo', ['id' => $memo->getId()]);
    }

    #[Route('/{id}', name: 'admin_bundle_procurement_debit_memo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        $this->denyIfInactive();

        $memo = $this->memoOr404($id);

        return $this->render('@Procurement/debit_memo_detail.html.twig', [
            'memo' => $memo,
            'openBills' => $this->openBillsForVendor($memo->getVendor()),
            'methods' => $this->refundMethods(),
            // Only asked for on a restock memo, and only while it is still a draft — see the issue
            // form on that template.
            'warehouses' => $this->activeWarehouses(),
            // Whether issuing this memo actually moved stock, which decides whether Void is a
            // one-click post or has to pass through the confirmation screen first.
            'movedStock' => $this->stock->restockGroupFor($memo) instanceof InventoryMovementGroup,
            // And, once voided, what became of the goods — so a person looking at the memo can see
            // it without going to find the product in the inventory ledger.
            'voidGroup' => $this->stock->voidGroupFor($memo),
        ]);
    }

    /**
     * Issue goes through `DebitMemoStockService`, not straight to the entity, because issuing a
     * memo that says the goods physically went back is a stock-moving act — the same split
     * `VendorReturnController` makes between its generic action dispatch and its own `ship()`.
     * `InsufficientStockException` is caught beside `DomainException` for the same reason it is
     * there: a refused withdrawal is a message to the admin, not a 500, and the service's
     * transaction has already left the memo a draft.
     */
    /**
     * The confirmation in front of voiding a memo that took stock out to the vendor.
     *
     * A separate GET screen rather than a dialog, because this app works with JavaScript off and
     * because the choice on it is the whole point: it states, in real terms, which product, how
     * many units, which warehouse, and which bucket each option lands them in. "An adjustment will
     * be created" is not something a person can consent to.
     *
     * A memo that moved no stock never reaches this — it has nothing to confirm, and putting a
     * confirmation in front of that would be noise.
     */
    #[Route('/{id}/void', name: 'admin_bundle_procurement_debit_memo_void_confirm', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function confirmVoid(int $id): Response
    {
        $this->denyIfInactive();

        $memo = $this->memoOr404($id);
        $restock = $this->stock->restockGroupFor($memo);

        if (!$restock instanceof InventoryMovementGroup) {
            return $this->redirectToRoute('admin_bundle_procurement_debit_memo', ['id' => $memo->getId()]);
        }

        return $this->render('@Procurement/debit_memo_void.html.twig', [
            'memo' => $memo,
            'rows' => $this->stock->plannedOutcome($restock),
            'dispositionBackInStock' => DebitMemoStockService::DISPOSITION_BACK_IN_STOCK,
            'dispositionWrittenOff' => DebitMemoStockService::DISPOSITION_WRITTEN_OFF,
            'dispositionGone' => DebitMemoStockService::DISPOSITION_GONE,
        ]);
    }

    #[Route('/{id}/action/{action}', name: 'admin_bundle_procurement_debit_memo_action', requirements: ['id' => '\d+', 'action' => 'issue|void'], methods: ['POST'])]
    public function performAction(int $id, string $action, Request $request): Response
    {
        $this->denyIfInactive();

        $memo = $this->memoOr404($id);
        $warehouseId = $request->request->getInt('warehouse_id', 0);

        try {
            match ($action) {
                'issue' => $this->stock->issue(
                    $memo,
                    $warehouseId > 0 ? $this->warehouseOr404($warehouseId) : null,
                    $this->actor()->displayName,
                ),
                // Void goes through the service too, because voiding a memo that took stock out has
                // to say what became of the goods. A memo that moved nothing still voids on a bare
                // post, exactly as it always did.
                'void' => $this->stock->void(
                    $memo,
                    $this->nullable((string) $request->request->get('disposition', '')),
                    $this->actor()->displayName,
                ),
                default => throw new \DomainException(sprintf('"%s" is not an action a debit memo supports.', $action)),
            };

            $this->em->flush();
            $this->addFlash('success', sprintf('Debit memo %s: %s.', $memo->getDocumentNumber(), $action));
        } catch (\DomainException|InsufficientStockException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_debit_memo', ['id' => $memo->getId()]);
    }

    #[Route('/{id}/apply', name: 'admin_bundle_procurement_debit_memo_apply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function apply(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $memo = $this->memoOr404($id);
        $billId = $request->request->getInt('vendor_bill_id', 0);
        $amount = (string) $request->request->get('amount', '0');

        try {
            $bill = $this->em->find(VendorBill::class, $billId);
            if (!$bill instanceof VendorBill) {
                throw new \DomainException('No such vendor bill.');
            }

            $memo->applyTo($bill, number_format((float) $amount, 2, '.', ''));
            $this->em->flush();
            $this->addFlash('success', sprintf('$%s applied to %s.', $amount, $bill->getBillNumber()));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_debit_memo', ['id' => $memo->getId()]);
    }

    #[Route('/{id}/application/{applicationId}/withdraw', name: 'admin_bundle_procurement_debit_memo_application_withdraw', requirements: ['id' => '\d+', 'applicationId' => '\d+'], methods: ['POST'])]
    public function withdrawApplication(int $id, int $applicationId): Response
    {
        $this->denyIfInactive();

        $memo = $this->memoOr404($id);
        $application = $this->em->find(DebitMemoApplication::class, $applicationId);

        try {
            if (!$application instanceof DebitMemoApplication) {
                throw new \DomainException('No such application.');
            }

            $memo->withdrawApplication($application);
            $this->em->flush();
            $this->addFlash('success', 'Application withdrawn.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_debit_memo', ['id' => $memo->getId()]);
    }

    #[Route('/{id}/refund', name: 'admin_bundle_procurement_debit_memo_refund', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refund(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $memo = $this->memoOr404($id);

        try {
            // Who paid it out. The sell-side twin records exactly this on its own refunds
            // (`Admin\CreditMemoController::refund()`), and `debit_memo_refund.user_id` was the
            // mapped column the procurement mirror simply left out — so a refund on this side had
            // no answer to "who did that?". Nullable for CreditMemoRefund's stated reason: a console
            // command or a fixture writes one with nobody signed in.
            $recordedBy = $this->getUser();

            $refund = (new DebitMemoRefund())
                ->setUser($recordedBy instanceof AdminUser ? $recordedBy : null)
                ->setMethod((string) $request->request->get('method', ''))
                ->setAmount(number_format((float) $request->request->get('amount', '0'), 2, '.', ''))
                ->setComment($this->nullable((string) $request->request->get('comment', '')));

            $refundedAt = $this->calendarDate((string) $request->request->get('refunded_at', ''));
            if ($refundedAt !== null) {
                $refund->setRefundedAt(new \DateTimeImmutable($refundedAt));
            }

            $memo->recordRefund($refund);
            $this->em->flush();
            $this->addFlash('success', 'Refund recorded.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_debit_memo', ['id' => $memo->getId()]);
    }

    #[Route('/{id}/refund/{refundId}/delete', name: 'admin_bundle_procurement_debit_memo_refund_delete', requirements: ['id' => '\d+', 'refundId' => '\d+'], methods: ['POST'])]
    public function deleteRefund(int $id, int $refundId): Response
    {
        $this->denyIfInactive();

        $memo = $this->memoOr404($id);
        $refund = $this->em->find(DebitMemoRefund::class, $refundId);

        try {
            if (!$refund instanceof DebitMemoRefund) {
                throw new \DomainException('No such refund.');
            }

            $memo->voidRefund($refund);
            $this->em->flush();
            $this->addFlash('success', 'Refund removed.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_procurement_debit_memo', ['id' => $memo->getId()]);
    }

    /** @return list<VendorBill> */
    private function openBillsForVendor(Vendor $vendor): array
    {
        /** @var list<VendorBill> $bills */
        $bills = $this->em->getRepository(VendorBill::class)->createQueryBuilder('b')
            ->andWhere('b.vendor = :vendor')
            ->andWhere('b.status != :void')
            ->setParameter('vendor', $vendor)
            ->setParameter('void', \ProcurementBundle\Enum\VendorBillStatus::Void)
            ->orderBy('b.billNumber', 'DESC')
            ->getQuery()
            ->getResult();

        return $bills;
    }

    /** @return list<string> */
    private function refundMethods(): array
    {
        $names = array_map(
            static fn (PaymentMethod $method): string => $method->getName(),
            $this->em->getRepository(PaymentMethod::class)->findBy([], ['name' => 'ASC']),
        );

        return $names === [] ? self::FALLBACK_METHODS : array_values($names);
    }

    private function memoOr404(int $id): DebitMemo
    {
        $memo = $this->em->find(DebitMemo::class, $id);
        if (!$memo instanceof DebitMemo) {
            throw new NotFoundHttpException('No such debit memo.');
        }

        return $memo;
    }
}
