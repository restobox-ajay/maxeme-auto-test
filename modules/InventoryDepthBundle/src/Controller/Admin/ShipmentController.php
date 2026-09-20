<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Controller\Admin;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\Inventory\OrderInventoryBucketResolver;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\Shipment;
use InventoryDepthBundle\Movement\InsufficientStockException;
use InventoryDepthBundle\Shipment\ShipmentAllocationPlanner;
use InventoryDepthBundle\Shipment\ShipmentException;
use InventoryDepthBundle\Shipment\ShipmentRequest;
use InventoryDepthBundle\Shipment\ShipmentService;
use InventoryDepthBundle\Shipment\ShipmentVoidService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The typed-form screens for `docs/plans/2026-09-14-shipment-dispatch.md` — v1's only surface, per
 * that plan's own ruling. List, record, view, void.
 *
 * ## The "new" form is one screen for both combined and single-invoice shipments
 *
 * There is no separate "combine shipments" screen. An admin adds one invoice by document number,
 * sees its unshipped lines, and can add another before submitting — the same `ShipmentRequest` this
 * builds either way, with `company` fixed to the first invoice added, exactly as
 * `ShipmentService::assertRequestIsShippable()`'s same-customer guard expects. Adding a second
 * invoice for a different company is refused here too, before the form is even shown a second time,
 * so the refusal an admin sees on this screen and the one `ship()` would raise anyway say the same
 * thing at the point it's actually useful to hear it.
 */
#[Route('/admin/bundles/inventory-depth')]
final class ShipmentController extends AbstractInventoryDepthController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        InventoryModeResolver $inventoryModes,
        private readonly ShipmentService $shipments,
        private readonly ShipmentVoidService $voids,
        private readonly ShipmentAllocationPlanner $planner,
        private readonly WarehouseFulfillmentRegionService $regions,
    ) {
        parent::__construct($em, $bundleStatusRepo, $inventoryModes);
    }

    #[Route('/shipments', name: 'admin_bundle_inventory_depth_shipments', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['number', 'company']);

        $qb = $this->em->getRepository(Shipment::class)->createQueryBuilder('s')
            ->innerJoin('s.company', 'c')->addSelect('c');

        if ($filters['number'] !== '') {
            $qb->andWhere('s.shipmentNumber LIKE :fnum')->setParameter('fnum', '%' . $filters['number'] . '%');
        }
        if ($filters['company'] !== '') {
            $qb->andWhere('c.name LIKE :fco')->setParameter('fco', '%' . $filters['company'] . '%');
        }

        $paging = $this->paging($request, 'shippedAt', 'desc');
        $total = (int) (clone $qb)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($total / $paging['limit']));
        $page = min($paging['page'], $pageCount);

        $orderExpr = match ($paging['sort']) {
            'number' => 's.shipmentNumber',
            'company' => 'c.name',
            default => 's.shippedAt',
        };

        /** @var list<Shipment> $rows */
        $rows = $qb->orderBy($orderExpr, $paging['dir'])
            ->addOrderBy('s.id', 'DESC')
            ->setFirstResult(($page - 1) * $paging['limit'])
            ->setMaxResults($paging['limit'])
            ->getQuery()
            ->getResult();

        return $this->render('@InventoryDepth/shipments.html.twig', [
            'rows' => $rows,
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'limit' => $paging['limit'],
            'pages' => $pageCount,
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    /**
     * `?invoice[]=1&invoice[]=2` names which invoices are on the form so far — a copied URL
     * reproduces the exact in-progress shipment, the same standing rule every list screen in this
     * bundle follows for filters. `?invoice_number=INV-...` is the "add an invoice" control: it
     * resolves the number, folds it into `invoice[]`, and redirects back to this same route without
     * it — a GET that changes what's on the form must not leave a resubmittable action behind.
     */
    #[Route('/shipments/new', name: 'admin_bundle_inventory_depth_shipment_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->denyIfInactive();

        $invoiceIds = $this->invoiceIdsFromQuery($request);

        $number = trim((string) $request->query->get('invoice_number', ''));
        if ($number !== '') {
            $found = $this->em->getRepository(Invoice::class)->findOneBy(['documentNumber' => $number]);
            if (!$found instanceof Invoice) {
                $this->addFlash('error', sprintf('No invoice numbered %s.', $number));
            } elseif ($found->isDraft()) {
                $this->addFlash('error', sprintf('Invoice %s is still a draft. Issue it before shipping against it.', $number));
            } elseif (!\in_array($found->getId(), $invoiceIds, true)) {
                $invoiceIds[] = $found->getId();
            }

            return $this->redirectToRoute('admin_bundle_inventory_depth_shipment_new', ['invoice' => $invoiceIds]);
        }

        /** @var array<int, Invoice> $loaded keyed by id, in the order added */
        $loaded = [];
        foreach ($invoiceIds as $id) {
            $invoice = $this->em->find(Invoice::class, $id);
            if ($invoice instanceof Invoice) {
                $loaded[$id] = $invoice;
            }
        }

        // The first invoice ADDED, not the lowest id, fixes the shipment's company — matching
        // ShipmentRequest::for()'s own "fixed at construction, not inferred" rule.
        $company = $loaded === [] ? null : reset($loaded)->getCompany();

        /** @var array<string, Warehouse>|null $warehousesByRegion built lazily, only if a tracked line needs it */
        $warehousesByRegion = null;

        $invoiceRows = [];
        $lines = [];
        foreach ($invoiceIds as $id) {
            $invoice = $loaded[$id] ?? null;
            $sameCompany = $invoice instanceof Invoice && $company instanceof Company
                && $invoice->getCompany()->getId() === $company->getId();

            $invoiceRows[] = [
                'id' => $id,
                'invoice' => $invoice,
                'included' => $sameCompany,
                'removeIds' => array_values(array_diff($invoiceIds, [$id])),
            ];

            if (!$sameCompany) {
                continue;
            }

            foreach ($invoice->getLines() as $line) {
                // A decimal string, exact throughout. A line with 0.4 outstanding used to round to
                // 0 here and vanish off the form entirely — see App\Service\QuantityScale.
                $remaining = $this->shipments->remainingToShip($line);
                if (QuantityScale::compare($remaining, 0) <= 0) {
                    continue;
                }

                // The step-2 breakdown this line offers, or null for a plain quantity box — see
                // ShipmentService's own docblock for why question 2's picking happens here, at ship
                // time, rather than being read off the invoice line.
                $breakdown = null;
                $product = $line->getProduct();

                if ($product instanceof ProductCore && $this->shipments->tracksOutbound($product)) {
                    $warehousesByRegion ??= $this->regions->warehousesByLowerRegionName();
                    $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse(
                        $line->getLocation(),
                        $invoice->getFulfillmentRegion(),
                        $warehousesByRegion,
                    );

                    if ($warehouse instanceof Warehouse) {
                        $policy = $product->getTrackingPolicy();
                        $breakdown = $policy instanceof TrackingPolicy && $policy->tracksLotsOutbound()
                            // The exact remainder, in scale units. It used to be floored to whole
                            // physical units because ship() refused a fractional pick for anything
                            // tracked; only a SERIAL is held to whole units now, so a lot line with
                            // 1.6 left is prefilled with 1.6 rather than with 1.
                            ? ['type' => 'lot', 'rows' => $this->planner->planLotAllocations($product, $warehouse, $remaining)]
                            : ['type' => 'serial', 'serials' => $this->planner->availableSerials($product, $warehouse)];
                    }
                }

                // `wholeUnits` is for the serial checkboxes only — how many boxes to pre-tick, and a
                // serial is one physical unit by definition. Everything a person reads or types is
                // `remaining`, the decimal.
                $lines[] = [
                    'invoice' => $invoice,
                    'line' => $line,
                    'remaining' => $remaining,
                    // Truncated toward zero, which is floor for a non-negative figure — the serial
                    // checkboxes only ever pre-tick whole units.
                    'wholeUnits' => (int) bcdiv($remaining, '1', 0),
                    'breakdown' => $breakdown,
                ];
            }
        }

        return $this->render('@InventoryDepth/shipment_new.html.twig', [
            'invoiceRows' => $invoiceRows,
            'company' => $company,
            'lines' => $lines,
        ]);
    }

    #[Route('/shipments/new', name: 'admin_bundle_inventory_depth_shipment_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->denyIfInactive();

        $invoiceIds = $this->invoiceIdsFromQuery($request, 'request');
        if ($invoiceIds === []) {
            $this->addFlash('error', 'Add at least one invoice before recording a shipment.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_shipment_new');
        }

        /** @var array<int, Invoice> $loaded */
        $loaded = [];
        foreach ($invoiceIds as $id) {
            $invoice = $this->em->find(Invoice::class, $id);
            if ($invoice instanceof Invoice) {
                $loaded[$id] = $invoice;
            }
        }

        if ($loaded === []) {
            $this->addFlash('error', 'None of the named invoices exist any more. Nothing was recorded.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_shipments');
        }

        // A draft holds nothing yet — see Invoice::isDraft()'s own docblock — so there is nothing on
        // it to ship. The picker in new() already refuses to add one, but this is the hard stop: it
        // also catches a draft added to the form before it was drafted, or a scripted POST that skips
        // the picker entirely.
        foreach ($loaded as $invoice) {
            if ($invoice->isDraft()) {
                $this->addFlash('error', sprintf(
                    'Invoice %s is still a draft. Issue it before shipping against it.',
                    $invoice->getDocumentNumber(),
                ));

                return $this->redirectToRoute('admin_bundle_inventory_depth_shipment_new', ['invoice' => $invoiceIds]);
            }
        }

        $company = reset($loaded)->getCompany();
        $notes = trim((string) $request->request->get('notes', ''));

        $shipmentRequest = ShipmentRequest::for($company, $this->actor(), $notes !== '' ? $notes : null, null, $this->operationKey($request));

        /** @var array<string, string> $quantities keyed by invoice_line id, posted as strings */
        $quantities = $request->request->all('lines');
        /** @var array<string, array{lot?: array<string, string>, serial?: list<string>}> $rawAllocations keyed by invoice_line id */
        $rawAllocations = $request->request->all('allocations');

        $added = 0;
        foreach ($loaded as $invoice) {
            if ($invoice->getCompany()->getId() !== $company->getId()) {
                continue;
            }

            foreach ($invoice->getLines() as $line) {
                $lineId = (string) $line->getId();
                $lineAllocations = $rawAllocations[$lineId] ?? null;

                // Serial-tracked: the checked serials ARE the quantity, never a separately typed
                // number — a serial identifies one unit, so there is nothing for a second figure to
                // disagree with.
                $serials = \is_array($lineAllocations) ? ($lineAllocations['serial'] ?? null) : null;
                if (\is_array($serials)) {
                    $allocations = [];
                    foreach ($serials as $serial) {
                        $serial = trim((string) $serial);
                        if ($serial === '') {
                            continue;
                        }

                        $allocations[] = ['lotId' => null, 'serial' => $serial, 'quantity' => '1'];
                    }

                    if ($allocations === []) {
                        continue;
                    }

                    $shipmentRequest->add($line, (string) \count($allocations), $allocations);
                    $added++;

                    continue;
                }

                // Lot-tracked: same reasoning as the serial branch above — the lot quantities are
                // the ONLY input the form renders for this line (see shipment_new.html.twig), so the
                // line's total is their sum, not a separately typed figure there is no box for.
                // Reading `lines[lineId]` here — which no lot-tracked row ever posts — used to make
                // this branch silently skip every lot-tracked line submitted through the real form.
                $lots = \is_array($lineAllocations) ? ($lineAllocations['lot'] ?? null) : null;
                if (\is_array($lots)) {
                    $allocations = [];
                    foreach ($lots as $lotId => $lotQty) {
                        $lotQty = trim((string) $lotQty);
                        // "Zero" means the same thing here as it does in ship(): nothing the
                        // NUMERIC(14, 4) column can hold. A figure below that precision is a blank
                        // box, and skipping it is what the form promises — refusing the whole
                        // submission over it would be a different promise.
                        if ($lotQty === '' || QuantityScale::compare($lotQty, 0) <= 0) {
                            continue;
                        }

                        $allocations[] = ['lotId' => (int) $lotId, 'serial' => null, 'quantity' => $lotQty];
                    }

                    if ($allocations === []) {
                        continue;
                    }

                    // Summed as exact decimal strings, never as floats: the request's line total has
                    // to match what assertRequestIsShippable() computes from the same allocations,
                    // and 0.1 + 0.2 as floats is not 0.3.
                    $total = QuantityScale::canonical(0);
                    foreach ($allocations as $allocation) {
                        $total = QuantityScale::add($total, $allocation['quantity']);
                    }

                    $shipmentRequest->add($line, $total, $allocations);
                    $added++;

                    continue;
                }

                // Neither tracked branch applies: the plain "Ship now" box (simple product, or a
                // tracked product this line's warehouse has no stock for at all).
                $raw = $quantities[$lineId] ?? '';
                $qty = trim((string) $raw);
                if ($qty === '' || QuantityScale::compare($qty, 0) <= 0) {
                    continue;
                }

                $shipmentRequest->add($line, $qty);
                $added++;
            }
        }

        if ($added === 0) {
            $this->addFlash('error', 'Enter a quantity for at least one line.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_shipment_new', ['invoice' => $invoiceIds]);
        }

        try {
            $shipment = $this->shipments->ship($shipmentRequest, $this->actor());
        } catch (ShipmentException|InsufficientStockException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_bundle_inventory_depth_shipment_new', ['invoice' => $invoiceIds]);
        }

        $this->addFlash('success', sprintf('Shipment %s recorded.', $shipment->getShipmentNumber()));

        return $this->redirectToRoute('admin_bundle_inventory_depth_shipment_show', ['id' => $shipment->getId()]);
    }

    #[Route('/shipments/{id}', name: 'admin_bundle_inventory_depth_shipment_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $this->denyIfInactive();

        $shipment = $this->em->find(Shipment::class, $id);
        if (!$shipment instanceof Shipment) {
            throw new NotFoundHttpException('No such shipment.');
        }

        // ShipmentLine stores the bare lot id (App\Entity code has no compile-time reference to
        // InventoryLot — the core/bundle boundary the whole session's earlier work rests on), so the
        // template needs this bundle to resolve it to a real label rather than printing the id raw.
        $lotLabels = [];
        foreach ($shipment->getLines() as $line) {
            $lotId = $line->getLotId();
            if ($lotId !== null && !isset($lotLabels[$lotId])) {
                $lot = $this->em->find(InventoryLot::class, $lotId);
                $lotLabels[$lotId] = $lot instanceof InventoryLot ? $lot->getLabel() : sprintf('Lot #%d (deleted)', $lotId);
            }
        }

        return $this->render('@InventoryDepth/shipment_show.html.twig', ['shipment' => $shipment, 'lotLabels' => $lotLabels]);
    }

    /**
     * POST and a plain form post, for the reason every destructive action in this bundle's screens
     * gives: reachable with JavaScript off, and not reachable by a crawler or a prefetch of a GET
     * link.
     */
    #[Route('/shipments/{id}/void', name: 'admin_bundle_inventory_depth_shipment_void', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function void(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $shipment = $this->em->find(Shipment::class, $id);
        if (!$shipment instanceof Shipment) {
            $this->addFlash('error', 'That shipment no longer exists.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_shipments');
        }

        $reason = trim((string) $request->request->get('reason', ''));

        try {
            $this->voids->void($shipment, $reason, $this->actor(), $this->operationKey($request));
        } catch (ShipmentException|InsufficientStockException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_bundle_inventory_depth_shipment_show', ['id' => $id]);
        }

        $this->addFlash('success', sprintf('Shipment %s voided.', $shipment->getShipmentNumber()));

        return $this->redirectToRoute('admin_bundle_inventory_depth_shipment_show', ['id' => $id]);
    }

    /** @return list<int> */
    private function invoiceIdsFromQuery(Request $request, string $bag = 'query'): array
    {
        $raw = $bag === 'request' ? $request->request->all('invoice') : $request->query->all('invoice');

        return array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
