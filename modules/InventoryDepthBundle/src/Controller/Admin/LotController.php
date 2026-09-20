<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Controller\Admin;

use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\Product\ProductPicker;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Delete\ReferenceCounter;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use InventoryDepthBundle\Repository\InventoryLotRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lot management and the expiring-soon view (#550).
 *
 * Every listing here shows **code and expiry together**, always. The code alone is ambiguous:
 * vendors reuse batch codes across production runs with different dates, which is exactly why
 * `inventory_lot.code` is not unique per product. A screen that showed the code by itself would be
 * showing the person holding the box something that does not identify what they are holding.
 *
 * ## Editing a lot edits the lot (#590)
 *
 * The form used to hardcode `id=0`, so every save took the create branch and a corrected expiry
 * date **forked the batch in two**: a second `inventory_lot` row with the right date, and every
 * `inventory_detail.lot_id` still pointing at the old wrong-dated one. The picker's
 * earliest-expiry ordering kept using the date nobody could see any more, and nothing warned.
 *
 * So the list carries an Edit link per row, `?edit=<id>` fills the form from that row, and the
 * hidden `id` is that row's id. An `id` that names no lot is refused rather than quietly falling
 * through to create — a stale Edit link must not mint a batch.
 *
 * `product_id` is read **only on the create branch**. A lot's product is its identity as much as
 * its row is: detail rows, movements and the availability sum all hang off the pair, and moving a
 * lot to another product would silently reassign every one of them. Correcting the product is a
 * new lot and a stock move, not a form field.
 *
 * Duplicates are **reported, never merged** — see InventoryLotRepository::duplicateGroups().
 *
 * ## Deleting a lot nothing has ever pointed at (#613)
 *
 * The forked rows this bundle now reports are the reason a delete exists at all: the fork's twin is
 * a real `inventory_lot` row that no stock, no movement and no document has ever named, and until
 * now the only thing that could be done with it was to leave it there confusing every later reader
 * of the batch code.
 *
 * Refused, never cascaded, and never merged. Every column naming a lot — `inventory_detail.lot_id`,
 * `goods_receipt_line.lot_id`, `transfer_order_line.lot_id` — is `ON DELETE SET NULL`, so issuing
 * the DELETE on a lot in use would succeed and quietly untrack the stock that was in it, which is
 * precisely the fork this issue's predecessor was about with the arrow reversed. ReferenceCounter
 * does the asking and the refusal names the tables.
 */
#[Route('/admin/bundles/inventory-depth/lots')]
final class LotController extends AbstractInventoryDepthController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        InventoryModeResolver $inventoryModes,
        private readonly ReferenceCounter $references,
        private readonly ProductPicker $products,
        private readonly InventoryDetailRepository $details,
    ) {
        parent::__construct($em, $bundleStatusRepo, $inventoryModes);
    }

    /**
     * The Order/Invoice line's lot picker (2026-09-14 lot/serial/expiry plan): every lot of one
     * product that still has stock, earliest expiry first, with what is actually left of each —
     * the same `availableForLot()` subtraction the save-time check enforces, so what the dropdown
     * shows and what a save is measured against cannot disagree.
     *
     * Deliberately open to any signed-in admin rather than gated to this bundle's own screens: the
     * order and invoice forms that call this live in core, not here.
     */
    #[Route('/available', name: 'admin_bundle_inventory_depth_lots_available', methods: ['GET'])]
    public function available(Request $request): JsonResponse
    {
        $this->denyIfInactive();

        $productId = $request->query->getInt('product_id', 0);
        $product = $productId > 0 ? $this->em->find(ProductCore::class, $productId) : null;

        if (!$product instanceof ProductCore) {
            return new JsonResponse([]);
        }

        return new JsonResponse(array_map(
            fn (InventoryLot $lot): array => [
                'id' => $lot->getId(),
                'label' => $lot->getLabel(),
                'available' => $this->details->availableForLot($lot),
            ],
            $this->lots()->withAvailableStock($product),
        ));
    }

    #[Route('', name: 'admin_bundle_inventory_depth_lots', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['product', 'code', 'source', 'expiringWithin', 'status']);

        $qb = $this->em->getRepository(InventoryLot::class)->createQueryBuilder('l')
            ->innerJoin('l.product', 'p')->addSelect('p');

        if ($filters['product'] !== '') {
            $qb->andWhere('p.sku LIKE :fp OR p.name LIKE :fp')->setParameter('fp', '%' . $filters['product'] . '%');
        }
        if ($filters['code'] !== '') {
            $qb->andWhere('l.code LIKE :fc')->setParameter('fc', '%' . $filters['code'] . '%');
        }
        if ($filters['source'] !== '') {
            $qb->andWhere('l.source LIKE :fs')->setParameter('fs', '%' . $filters['source'] . '%');
        }
        if ($filters['status'] !== '' && \in_array($filters['status'], InventoryLot::statuses(), true)) {
            $qb->andWhere('l.status = :fstatus')->setParameter('fstatus', $filters['status']);
        }

        // The expiring-soon view is this list with a configurable window, not a separate screen —
        // so the window is a GET param like every other filter and a copied URL reproduces it.
        $window = $filters['expiringWithin'];
        if ($window !== '' && ctype_digit($window)) {
            $qb->andWhere('l.expiry IS NOT NULL')
                ->andWhere('l.expiry <= :fthrough')
                ->setParameter('fthrough', (new \DateTimeImmutable())->modify('+' . (int) $window . ' days')->format('Y-m-d'));
        }

        $paging = $this->paging($request, 'expiry', 'asc');
        $total = (int) (clone $qb)->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($total / $paging['limit']));
        $page = min($paging['page'], $pageCount);

        $orderExpr = match ($paging['sort']) {
            'code' => 'l.code',
            'product' => 'p.sku',
            'received' => 'l.receivedAt',
            default => 'l.expiry',
        };

        /** @var list<InventoryLot> $rows */
        $rows = $qb->orderBy($orderExpr, $paging['dir'])
            ->addOrderBy('l.id', 'ASC')
            ->setFirstResult(($page - 1) * $paging['limit'])
            ->setMaxResults($paging['limit'])
            ->getQuery()
            ->getResult();

        $editId = $request->query->getInt('edit', 0);
        $editing = $editId > 0 ? $this->em->find(InventoryLot::class, $editId) : null;

        return $this->render('@InventoryDepth/lots.html.twig', [
            'rows' => $rows,
            'editing' => $editing instanceof InventoryLot ? $editing : null,
            // Whole-table, not this page's rows: a fork is only visible next to its twin, and the
            // twin is regularly on another page or behind another filter.
            'duplicateGroups' => $this->lots()->duplicateGroups(),
            'filters' => $filters,
            // The create form's product field (#8). Nothing is seeded when the catalog is past the
            // inline limit and no lot is being edited, because tier 2's select is supposed to be
            // empty until the admin searches — and a lot's product cannot be changed on an edit
            // anyway, so `editing` renders a disabled name instead of this field.
            'productOptions' => $this->products->options(),
            'productsRemote' => $this->products->isRemote(),
            'today' => new \DateTimeImmutable(),
            'total' => $total,
            'page' => $page,
            'limit' => $paging['limit'],
            'pages' => $pageCount,
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    #[Route('/save', name: 'admin_bundle_inventory_depth_lot_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyIfInactive();

        $id = $request->request->getInt('id', 0);
        $code = trim((string) $request->request->get('code', ''));

        $lot = $id > 0 ? $this->em->find(InventoryLot::class, $id) : null;

        // A named id that resolves to nothing is a stale Edit link or a hand-typed URL. Falling
        // through to create here is exactly the fork this issue is about, one step removed.
        if ($id > 0 && !$lot instanceof InventoryLot) {
            $this->addFlash('error', 'That lot no longer exists. Nothing was saved.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_lots');
        }

        if ($code === '') {
            $this->addFlash('error', 'A lot needs a code.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_lots', $id > 0 ? ['edit' => $id] : []);
        }

        if (!$lot instanceof InventoryLot) {
            $productId = $this->productIdFrom($request->request->all());
            if ($productId <= 0) {
                // Refused by name rather than by 404. The field is marked required, but native
                // validation is a browser feature and this bundle's whole premise is that the
                // screens work without one — so the server states the rule too, the way the empty
                // code below it already does. A 404 page for a form somebody can simply fix is the
                // wrong answer to a missing field.
                $this->addFlash('error', 'A lot belongs to a product — choose one. Nothing was saved.');

                return $this->redirectToRoute('admin_bundle_inventory_depth_lots');
            }

            $product = $this->productOr404($productId);
            // Through the resolver, never the raw column: with this bundle Inactive a stored
            // `dimensional` must read as simple everywhere (#566).
            if (!$this->inventoryModes->isDimensional($product)) {
                $this->addFlash('error', sprintf('%s is on simple inventory — switch it to dimensional before giving it lots.', $product->getName()));

                return $this->redirectToRoute('admin_bundle_inventory_depth_lots');
            }

            // Deliberately NOT checked for an existing (product, code): two production runs sharing
            // a batch code with different expiry dates are two different lots, and collapsing them
            // would lose the earlier one's date. The row id is the identity.
            $lot = (new InventoryLot())->setProduct($product);
            $this->em->persist($lot);
        }

        $expiry = trim((string) $request->request->get('expiry', ''));
        $received = trim((string) $request->request->get('received_at', ''));

        // Parsed BEFORE anything is written. On an edit the row already exists and is managed, so a
        // half-applied save — new code, old date — would be a worse lie than a refusal.
        try {
            $expiryDate = $expiry === '' ? null : new \DateTimeImmutable($expiry);
            $receivedDate = $received === '' ? null : new \DateTimeImmutable($received);
        } catch (\Exception) {
            $this->addFlash('error', 'The expiry or received date was not a date this system understands. Nothing was saved.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_lots', $id > 0 ? ['edit' => $id] : []);
        }

        $lot
            ->setCode($code)
            ->setSource($this->nullable((string) $request->request->get('source', '')))
            ->setExpiry($expiryDate)
            ->setReceivedAt($receivedDate)
            ->setStatus((string) $request->request->get('status', InventoryLot::STATUS_AVAILABLE));

        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Lot %s saved (inventory_lot row %d %s).',
            $lot->getLabel(),
            $lot->getId(),
            $id > 0 ? 'updated' : 'created',
        ));

        // Reported, never merged. Said here because this is the moment somebody still knows whether
        // two runs really do share a batch code — and because the alternative, refusing, would make
        // the genuine case unenterable.
        $twins = $this->lots()->othersSharingCode($lot);
        if ($twins !== []) {
            $sharing = [...$twins, $lot];
            usort($sharing, static fn (InventoryLot $a, InventoryLot $b): int => ($a->getId() ?? 0) <=> ($b->getId() ?? 0));

            $this->addFlash('error', sprintf(
                '%s now has %d lots coded %s: %s. Nothing was merged — the rows are exactly as they were. Check the expiry dates and decide which one the stock belongs to.',
                $lot->getProduct()->getSku() ?: $lot->getProduct()->getName(),
                \count($sharing),
                $lot->getCode(),
                implode(', ', array_map(
                    static fn (InventoryLot $one): string => sprintf(
                        'row %d (%s)',
                        $one->getId(),
                        $one->getExpiry()?->format('Y-m-d') ?? 'no expiry',
                    ),
                    $sharing,
                )),
            ));
        }

        return $this->redirectToRoute('admin_bundle_inventory_depth_lots');
    }

    /**
     * Deletes a lot nothing has ever pointed at, and refuses every other one by name.
     *
     * POST and a plain form post, for the reason BinController::delete() gives: a destructive action
     * on a GET link is reachable by a crawler and by a prefetch, and the row's Delete has to work
     * with JavaScript off.
     */
    #[Route('/{id}/delete', name: 'admin_bundle_inventory_depth_lot_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $this->denyIfInactive();

        $lot = $this->em->find(InventoryLot::class, $id);

        if (!$lot instanceof InventoryLot) {
            $this->addFlash('error', 'That lot no longer exists. Nothing was deleted.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_lots');
        }

        $holders = $this->references->holdersOfLot($id);
        if ($holders !== []) {
            $this->addFlash('error', sprintf(
                'Lot %s cannot be deleted — %s still name it. Every one of those columns is ON DELETE SET NULL, so deleting the row would untrack that stock rather than refuse. Correct the batch by editing row %d instead.',
                $lot->getLabel(),
                ReferenceCounter::describe($holders),
                $id,
            ));

            return $this->redirectToRoute('admin_bundle_inventory_depth_lots', ['edit' => $id]);
        }

        $label = $lot->getLabel();

        $this->em->remove($lot);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Lot %s deleted (inventory_lot row %d). No stock, no movement and no document named it.',
            $label,
            $id,
        ));

        return $this->redirectToRoute('admin_bundle_inventory_depth_lots');
    }

    private function lots(): InventoryLotRepository
    {
        /** @var InventoryLotRepository $repo */
        $repo = $this->em->getRepository(InventoryLot::class);

        return $repo;
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
