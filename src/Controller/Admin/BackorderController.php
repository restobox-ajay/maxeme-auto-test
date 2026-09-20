<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\QuantityScale;
use App\Entity\BackorderFulfillmentEntry;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use App\Repository\BackorderFulfillmentEntryRepository;
use App\Service\Inventory\BackorderReleaseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The backorder fulfilment queue (#548): what is waiting for stock, and the screen an admin uses to
 * hand arriving stock out.
 *
 * Manual is the default mode. A SKU with `auto_release_on_restock` set never needs this screen, but
 * its episodes still appear here — already Complete, resolved by 'System' — because the requirement
 * is one place to look regardless of which mode handled it.
 */
#[Route('/admin')]
final class BackorderController extends AbstractAdminController
{
    /**
     * The queue, in the grid model every other admin list uses (#621).
     *
     * ## The default is still Open, and that is why `status` is absent rather than empty
     *
     * This screen opened on the outstanding work before it had a filter bar, and it still does: a
     * request naming no status at all means Open. The All tab therefore carries `filters[status]=`
     * EXPLICITLY — the empty string is a choice somebody made, the missing key is nobody having
     * chosen — so that a bare `/admin/backorders` is the worklist and cannot drift into being a
     * history the day somebody adds a link to it without the query string.
     *
     * `?complete=1`, which is what the old Include-resolved button used, is still honoured and
     * still means "show me both": there are links to this screen in the wild carrying it, and a URL
     * that used to work and now silently shows half the rows is the worst kind of change.
     */
    #[Route('/backorders', name: 'admin_backorder_index', methods: ['GET'])]
    public function index(Request $request, BackorderFulfillmentEntryRepository $entries, EntityManagerInterface $entityManager): Response
    {
        $filters = $this->filters($request);
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(500, $request->query->getInt('limit', 100)));

        $result = $entries->search($filters, $page, $limit);

        $rows = [];
        foreach ($result['rows'] as $entry) {
            $line = $entry->getLine();
            $warehouse = $entry->getWarehouse();

            $rows[] = [
                'id' => $entry->getId(),
                'status' => $entry->getStatus(),
                'orderId' => $entry->getOrder()->getId(),
                'orderNumber' => $entry->getOrder()->getOrderNumber(),
                'company' => $entry->getOrder()->getCompany()?->getName() ?? '-',
                'sku' => $line->getSku() ?: ($entry->getProduct()->getSku() ?: '-'),
                'name' => $line->getName(),
                'productId' => $entry->getProduct()->getId(),
                'warehouse' => $warehouse?->getName() ?? '-',
                // Read through the line, never copied onto the entry, so the two cannot disagree.
                'backordered' => $line->getBackorderedUnits(),
                'ordered' => QuantityScale::canonical($line->getQuantity()),
                'restockEta' => $line->getRestockEta(),
                'createdAt' => $entry->getCreatedAt(),
                'completedAt' => $entry->getCompletedAt(),
                'availableToRelease' => $this->availableToRelease($entry, $entityManager),
            ];
        }

        return $this->render('admin/backorder/index.html.twig', [
            'rows' => $rows,
            'filters' => $filters,
            'warehouses' => $entityManager->getRepository(Warehouse::class)->findBy([], ['name' => 'ASC']),
            'statuses' => [BackorderFulfillmentEntry::STATUS_OPEN, BackorderFulfillmentEntry::STATUS_COMPLETE],
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'pages' => max(1, (int) ceil($result['total'] / $limit)),
        ]);
    }

    /**
     * The six filter values, all from GET, all read as text.
     *
     * A non-scalar is read as absent rather than cast: `?filters[status][]=x` makes the value an
     * array, and casting one to string raises a notice the dev error handler turns into a 500 from
     * nothing but a crafted URL. The same reading AbstractProcurementController::filtersFromRequest()
     * takes, for the same reason.
     *
     * @return array{status: string, order: string, customer: string, sku: string, warehouse: string, etaBy: string}
     */
    private function filters(Request $request): array
    {
        $raw = $request->query->all('filters');

        $filters = [];
        foreach (['status', 'order', 'customer', 'sku', 'warehouse', 'etaBy'] as $key) {
            $value = $raw[$key] ?? null;
            $filters[$key] = \is_scalar($value) ? trim((string) $value) : '';
        }

        if (!\array_key_exists('status', $raw)) {
            // Nobody has chosen: the worklist default. See the docblock above on why an EXPLICIT
            // empty string is left alone here.
            $filters['status'] = $request->query->getBoolean('complete')
                ? ''
                : BackorderFulfillmentEntry::STATUS_OPEN;
        }

        return $filters;
    }

    #[Route('/backorders/{id}', name: 'admin_backorder_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, BackorderFulfillmentEntryRepository $entries, EntityManagerInterface $entityManager): Response
    {
        $order = $entityManager->find(SalesOrder::class, $id);
        if (!$order instanceof SalesOrder) {
            $this->addFlash('error', 'Order could not be found.');

            return $this->redirectToRoute('admin_backorder_index');
        }

        $rows = [];
        foreach ($entries->openForOrder($order) as $entry) {
            $line = $entry->getLine();
            $available = $this->availableToRelease($entry, $entityManager);
            $outstanding = $line->getBackorderedUnits();

            $rows[] = [
                'entryId' => $entry->getId(),
                'lineId' => $line->getId(),
                'sku' => $line->getSku() ?: ($entry->getProduct()->getSku() ?: '-'),
                'name' => $line->getName(),
                'warehouse' => $entry->getWarehouse()?->getName() ?? '-',
                'ordered' => QuantityScale::canonical($line->getQuantity()),
                'backordered' => $outstanding,
                'fulfilled' => self::atLeastZero(QuantityScale::sub($line->getQuantity(), $outstanding)),
                'available' => $available,
                'restockEta' => $line->getRestockEta(),
                // What the form offers by default: everything that can be covered right now, and
                // never more. The admin may type less; typing more is rejected server-side.
                'suggested' => QuantityScale::compare($outstanding, $available) <= 0 ? $outstanding : self::atLeastZero($available),
            ];
        }

        return $this->render('admin/backorder/show.html.twig', [
            'order' => [
                'id' => $order->getId(),
                'number' => $order->getOrderNumber(),
                'company' => $order->getCompany()?->getName() ?? '-',
                'status' => $order->getStatus(),
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * Assign stock to waiting lines.
     *
     * Every submitted amount is re-checked against the LIVE outstanding quantity and the live
     * releasable stock, never trusted from the form — availability moves between the page load and
     * the submit, and the same posture is why CheckoutController re-checks stock inside its own
     * transaction. The clamp lives in BackorderReleaseService::applyRelease(), which is also what
     * the automatic path runs through, so a race cannot release more here than it would there.
     */
    #[Route('/backorders/{id}/release', name: 'admin_backorder_release', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function release(
        int $id,
        Request $request,
        BackorderFulfillmentEntryRepository $entries,
        BackorderReleaseService $releaseService,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $order = $entityManager->find(SalesOrder::class, $id);
        if (!$order instanceof SalesOrder) {
            $this->addFlash('error', 'Order could not be found.');

            return $this->redirectToRoute('admin_backorder_index');
        }

        // No CSRF check of its own: CsrfProtectionSubscriber verifies every state-changing request
        // deny-by-default, and the form states its token with {{ csrf_field() }} like every other
        // form in this app. A second, per-form token here would be a second mechanism to keep in
        // step with the first.

        /** @var array<array-key, mixed> $amounts */
        $amounts = $request->request->all('release');
        $resolvedBy = $this->getUser()?->getUserIdentifier() ?? BackorderReleaseService::SYSTEM_ACTOR;

        // Read once per (product, warehouse) and track it locally across this loop, the same way
        // BackorderReleaseService::releaseAutomatically() tracks $spare across its own loop.
        // applyRelease() only mutates the SalesOrderLine — the bucket reconciliation that actually
        // moves units out of `backordered` doesn't run until the flush() below — so a second entry
        // in this same submission against the same product+warehouse must draw on what the first
        // entry left, not on the still-unflushed getStockAvailableToRelease() figure, or one order
        // with two entries on one SKU could release the same physical stock twice.
        $spareByInventoryId = [];

        $releasedTotal = QuantityScale::canonical(0);
        foreach ($entries->openForOrder($order) as $entry) {
            $raw = $amounts[(string) $entry->getId()] ?? null;
            $requested = QuantityScale::canonical(\is_string($raw) ? $raw : 0);
            if (QuantityScale::compare($requested, 0) <= 0) {
                continue;
            }

            $inventory = $this->inventoryFor($entry, $entityManager);
            if (!$inventory instanceof ProductInventory) {
                continue;
            }

            $inventoryId = $inventory->getId();
            if (!array_key_exists($inventoryId, $spareByInventoryId)) {
                // Re-read now, not from the rendered page: this is the number the release is
                // allowed to draw on, and it may have moved since the form was drawn.
                $spareByInventoryId[$inventoryId] = $inventory->getStockAvailableToRelease();
            }

            $spare = self::atLeastZero($spareByInventoryId[$inventoryId]);
            $toRelease = QuantityScale::compare($requested, $spare) <= 0 ? $requested : $spare;

            $released = $releaseService->applyRelease($entry->getLine(), $toRelease, $resolvedBy, $entityManager);
            $spareByInventoryId[$inventoryId] = QuantityScale::sub($spareByInventoryId[$inventoryId], $released);
            $releasedTotal = QuantityScale::add($releasedTotal, $released);
        }

        if (QuantityScale::compare($releasedTotal, 0) <= 0) {
            $this->addFlash('warning', 'Nothing was released — there is no stock available for these lines right now.');

            return $this->redirectToRoute('admin_backorder_show', ['id' => $id]);
        }

        // One flush for the lot, so the releases and the bucket reconciliation they trigger commit
        // together.
        $entityManager->flush();

        $this->addFlash('success', sprintf('Released %s unit(s) to order %s.', QuantityScale::trim($releasedTotal), $order->getOrderNumber()));

        return $this->redirectToRoute('admin_backorder_show', ['id' => $id]);
    }

    /**
     * Stock this entry's product has in this entry's warehouse that no real hold is standing on.
     *
     * getStockAvailableToRelease() and not getAvailableQuantity(), for the reason spelled out on
     * ProductInventory: the promise this entry represents is already subtracted from availability,
     * so measuring against it would report zero for a restock that arrived precisely to cover it.
     */
    private function availableToRelease(BackorderFulfillmentEntry $entry, EntityManagerInterface $entityManager): string
    {
        return $this->inventoryFor($entry, $entityManager)?->getStockAvailableToRelease() ?? QuantityScale::canonical(0);
    }

    private static function atLeastZero(string $quantity): string
    {
        $quantity = QuantityScale::canonical($quantity);

        return QuantityScale::compare($quantity, 0) > 0 ? $quantity : QuantityScale::canonical(0);
    }

    private function inventoryFor(BackorderFulfillmentEntry $entry, EntityManagerInterface $entityManager): ?ProductInventory
    {
        $warehouse = $entry->getWarehouse();
        if (!$warehouse instanceof Warehouse) {
            return null;
        }

        return $entityManager->getRepository(ProductInventory::class)->findOneBy([
            'product' => $entry->getProduct(),
            'warehouse' => $warehouse,
        ]);
    }
}
