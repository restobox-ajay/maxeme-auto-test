<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\UnitOfMeasure;
use App\Repository\UnitOfMeasureRepository;
use App\Service\Uom\UnitOfMeasureRefusal;
use App\Service\Uom\UnitOfMeasureService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The global measurement system (#601, phase 1 #643).
 *
 * Core, not a bundle screen, for the reason the owner ruled the whole of UoM into core: the base
 * unit is what every quantity in the app is denominated in, and that cannot be switchable. An
 * instance with no inventory bundle installed still has products, and what their numbers mean
 * belongs beside them.
 *
 * A small screen on purpose. The interesting work is what a product points at, which lives on the
 * product form, and what a product's packaging ladder says, which lives on Packaging Units.
 *
 * ## Why the list, the create and the edit are three pages
 *
 * They were one, and it is the same defect the tracking policy screen had, for the same reason. The
 * create form sat inside the `.content-frame` that `app.css:7764` clamps to `calc(100vh - 3rem)`
 * whenever a top-level `.table-card` uses the scroll-region model — a clamp that assumes **the grid
 * IS the page**. The grid card is the only child of that frame with `flex: 1 1 auto; min-height: 0`,
 * so it absorbs whatever the form leaves, and `.table-card` is `overflow: hidden`, so what does not
 * fit is clipped with no scrollbar. On the tracking policy screen, measured, that left the grid 44px
 * of 852 and put every data row past the clip.
 *
 * So the form is off the list page. Weakening the clamp was the alternative and it is the wrong
 * trade: it would cost every real grid in the application its sticky header and single scroll region
 * to accommodate the two screens that should not have had a form on them.
 *
 * **The freeze is unaffected, which is the point.** {@see UnitOfMeasureService} is still the only
 * way to a write and {@see self::save()} is still the only caller; the new pages are GET renderers
 * and post to that same action. A unit that is referenced is refused a restatement and a deletion
 * through the new routes exactly as it was through the old one — there is no second write path to
 * route around the guard, because splitting the routes added no writer.
 */
#[Route('/admin')]
final class UnitOfMeasureController extends AbstractController
{
    private const PAGE_SIZES = [20, 100, 200, 500];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UnitOfMeasureRepository $units,
        private readonly UnitOfMeasureService $service,
    ) {
    }

    #[Route('/product/units-of-measure', name: 'admin_product_unit_of_measure_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // This used to open with $this->units->ensureSeeded(), which CREATED the four shipped units
        // on a GET. It does not any more: App\Service\ReferenceData\Seeders\UnitOfMeasureSeeder owns
        // them and runs once, on the first admin login. This action reads.
        //
        // `?edit={id}` used to re-render this page with the form populated. Kept as a redirect: the
        // old URL is in browser histories and bookmarks, and a link that opened an editor should not
        // quietly become a list.
        $editId = $request->query->getInt('edit', 0);
        if ($editId > 0) {
            return $this->redirectToRoute('admin_product_unit_of_measure_edit', ['id' => $editId]);
        }

        /** @var array<string, string> $rawFilters */
        $rawFilters = (array) $request->query->all('filters');
        $filters = [
            'code' => trim((string) ($rawFilters['code'] ?? '')),
            'name' => trim((string) ($rawFilters['name'] ?? '')),
            'family' => trim((string) ($rawFilters['family'] ?? '')),
        ];

        $limit = \in_array($request->query->getInt('limit', 100), self::PAGE_SIZES, true)
            ? $request->query->getInt('limit', 100)
            : 100;
        $page = max(1, $request->query->getInt('page', 1));
        $sort = (string) $request->query->get('sort', 'code');
        $dir = strtolower((string) $request->query->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $result = $this->units->page($filters, $sort, $dir, $page, $limit);

        // One query per referencing column for the whole page, not one per row — see
        // UnitOfMeasureService::referenceCountsForPage().
        $references = $this->service->referenceCountsForPage($result['rows']);

        $rows = [];
        foreach ($result['rows'] as $unit) {
            $rows[] = ['unit' => $unit, 'references' => $references[(int) $unit->getId()] ?? []];
        }

        return $this->render('admin/unit_of_measure/index.html.twig', [
            'rows' => $rows,
            'total' => $result['total'],
            'families' => UnitOfMeasure::families(),
            'filters' => $filters,
            'currentSort' => $sort,
            'currentDir' => $dir,
            'page' => $page,
            'limit' => $limit,
            'pages' => max(1, (int) ceil($result['total'] / $limit)),
            'pageSizes' => self::PAGE_SIZES,
        ]);
    }

    /** The create page. Same form as {@see self::edit()}, with nothing to populate it from. */
    #[Route('/product/units-of-measure/new', name: 'admin_product_unit_of_measure_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('admin/unit_of_measure/form.html.twig', [
            'editing' => null,
            'editingReferences' => [],
            'families' => UnitOfMeasure::families(),
        ]);
    }

    /**
     * The edit page.
     *
     * Reads the reference counts so the form can say, before anything is typed, which fields the
     * freeze has already closed and what is holding them — `table.column (count)`, the same
     * vocabulary {@see UnitOfMeasureService} uses when it refuses. Saying it here does not enforce
     * it; {@see self::save()} calling the service is what enforces it, and it refuses a post that
     * reaches it by any other means.
     */
    #[Route('/product/units-of-measure/{id}/edit', name: 'admin_product_unit_of_measure_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $unit = $this->em->find(UnitOfMeasure::class, $id);
        if (!$unit instanceof UnitOfMeasure) {
            $this->addFlash('error', 'That unit could not be found.');

            return $this->redirectToRoute('admin_product_unit_of_measure_index');
        }

        return $this->render('admin/unit_of_measure/form.html.twig', [
            'editing' => $unit,
            'editingReferences' => $this->service->referenceCounts($unit),
            'families' => UnitOfMeasure::families(),
        ]);
    }

    /**
     * Add or re-state a unit — the freeze is applied here, by the service, not in this method.
     *
     * The controller's whole job is turning a form post into arguments and a refusal into a flash:
     * every rule that decides whether the write may happen lives in
     * {@see UnitOfMeasureService}, so there is no way to reach the write by posting from somewhere
     * else.
     */
    #[Route('/product/units-of-measure/save', name: 'admin_product_unit_of_measure_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $id = $request->request->getInt('id', 0);
        $code = (string) $request->request->get('code', '');
        $name = (string) $request->request->get('name', '');
        $family = (string) $request->request->get('family', UnitOfMeasure::FAMILY_QUANTITY);
        $factor = (string) $request->request->get('factor_to_family_base', '1');
        $precision = (string) $request->request->get('rounding_precision', '1');

        $unit = $id > 0 ? $this->em->find(UnitOfMeasure::class, $id) : null;

        try {
            if ($unit instanceof UnitOfMeasure) {
                $this->service->update($unit, $code, $name, $family, $factor, $precision);
            } else {
                $unit = $this->service->add($code, $name, $family, $factor, $precision);
            }
        } catch (UnitOfMeasureRefusal $refusal) {
            $this->addFlash('error', $refusal->getMessage());

            // Back to the form that was posted, not to the list. With the form on its own page a
            // refusal that lands on the grid leaves the admin reading why the edit was rejected with
            // no field in front of them to change.
            return $id > 0
                ? $this->redirectToRoute('admin_product_unit_of_measure_edit', ['id' => $id])
                : $this->redirectToRoute('admin_product_unit_of_measure_new');
        }

        $this->addFlash('success', sprintf('Unit "%s" saved.', $unit->getCode()));

        return $this->redirectToRoute('admin_product_unit_of_measure_index');
    }

    #[Route('/product/units-of-measure/{id}/delete', name: 'admin_product_unit_of_measure_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $unit = $this->em->find(UnitOfMeasure::class, $id);
        if (!$unit instanceof UnitOfMeasure) {
            $this->addFlash('error', 'That unit could not be found.');

            return $this->redirectToRoute('admin_product_unit_of_measure_index');
        }

        $code = $unit->getCode();

        try {
            // Refused rather than cascaded or nulled. A row whose unit vanished would be
            // denominated in nothing, which is the same silent restatement the freeze refuses
            // head-on.
            $this->service->delete($unit);
        } catch (UnitOfMeasureRefusal $refusal) {
            $this->addFlash('error', $refusal->getMessage());

            return $this->redirectToRoute('admin_product_unit_of_measure_index');
        }

        $this->addFlash('success', sprintf('Unit "%s" was deleted.', $code));

        return $this->redirectToRoute('admin_product_unit_of_measure_index');
    }
}
