<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Controller\Admin;

use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\WarehouseLocation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WarehouseOpsBundle\Label\LabelCatalog;
use WarehouseOpsBundle\Label\LabelSheetRenderer;
use WarehouseOpsBundle\Label\LabelSpec;
use WarehouseOpsBundle\Label\LabelTemplate;

/**
 * Printing labels (#552).
 *
 * ## Printing writes nothing
 *
 * No movement rows, no counters, no print log. Printing is not moving, and a reprint that had to be
 * justified is a reprint nobody does — a smudged label is the most common reason anyone opens this
 * screen. Both routes here are therefore side-effect free, and the POST is a POST only because the
 * response is a PDF built from a form.
 *
 * ## The preview is the same HTML the PDF is made from
 *
 * Not a separate rendering. A preview that came from different code would be a preview that lied,
 * and the one thing a label screen has to get right is that what you see is what comes out of the
 * printer.
 */
#[Route('/admin/bundles/warehouse-ops/labels')]
final class LabelController extends AbstractWarehouseOpsController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        private readonly LabelCatalog $catalog,
        private readonly LabelSheetRenderer $renderer,
    ) {
        parent::__construct($em, $bundleStatusRepo);
    }

    #[Route('', name: 'admin_bundle_warehouse_ops_labels', methods: ['GET'])]
    public function form(Request $request): Response
    {
        $this->denyIfInactive();

        // Read from the names the form's own fields carry, rather than from filters[...]. The print
        // form POSTs `kind`, `warehouse_id` and `product_id` to another route, so a filters[...]
        // reading could never be produced by anything on this screen and the preselection was dead
        // in every case. Named this way, a link into the screen (from a bin or a lot) preselects it.
        // `?kind[]=x` makes the value an array, and casting one to string is a 500 from nothing but
        // a crafted URL — a non-scalar is not something anyone chose, so it reads as absent.
        $queryString = static function (mixed $value): string {
            return \is_scalar($value) ? trim((string) $value) : '';
        };

        $filters = [
            'kind' => $queryString($request->query->all()['kind'] ?? ''),
            'warehouse' => $queryString($request->query->all()['warehouse_id'] ?? ''),
            'product' => $queryString($request->query->all()['product_id'] ?? ''),
        ];

        return $this->render('@WarehouseOps/labels.html.twig', [
            'filters' => $filters,
            'warehouses' => $this->activeWarehouses(),
            'products' => $this->dimensionalProducts(),
            'templates' => LabelTemplate::presets(),
            'symbologies' => LabelTemplate::symbologies(),
            'kinds' => [
                LabelSpec::KIND_PRODUCT => "Product — encodes its primary barcode, or the SKU when it has none",
                LabelSpec::KIND_BIN => 'Bin — encodes the bin code',
                LabelSpec::KIND_LOT => 'Lot — encodes the lot id, prints code and expiry',
                LabelSpec::KIND_SERIAL => 'Serial — encodes the serial itself',
            ],
        ]);
    }

    /**
     * Renders the sheet. `?preview=1` returns the HTML instead of the PDF, from the same call.
     */
    #[Route('/print', name: 'admin_bundle_warehouse_ops_labels_print', methods: ['POST'])]
    public function print(Request $request): Response
    {
        $this->denyIfInactive();

        $specs = $this->specs($request);
        $template = $this->template($request);

        if ($specs === []) {
            $this->addFlash('error', 'Nothing matched that selection, so there was nothing to print.');

            return $this->redirectToRoute('admin_bundle_warehouse_ops_labels');
        }

        $specs = $this->catalog->repeat($specs, $request->request->getInt('copies', 1));

        if ($request->request->getBoolean('preview')) {
            return new Response($this->renderer->html($specs, $template));
        }

        return new Response($this->renderer->pdf($specs, $template), 200, [
            'Content-Type' => 'application/pdf',
            // inline, not attachment: the operator sends it to the label printer from the viewer,
            // and a download that has to be found in a folder first is a step nobody wants at a
            // packing bench.
            'Content-Disposition' => sprintf('inline; filename="labels-%s.pdf"', date('Ymd-His')),
        ]);
    }

    /** @return list<LabelSpec> */
    private function specs(Request $request): array
    {
        $kind = (string) $request->request->get('kind', LabelSpec::KIND_BIN);

        return match ($kind) {
            LabelSpec::KIND_BIN => $this->binSpecs($request),
            LabelSpec::KIND_PRODUCT => $this->productSpecs($request),
            LabelSpec::KIND_LOT => $this->forProduct($request, fn (ProductCore $p): array => $this->catalog->lotsFor($p)),
            LabelSpec::KIND_SERIAL => $this->forProduct($request, fn (ProductCore $p): array => $this->catalog->serialsFor($p)),
            default => [],
        };
    }

    /** @return list<LabelSpec> */
    private function binSpecs(Request $request): array
    {
        $binIds = array_filter(array_map('intval', (array) $request->request->all('bins')));

        if ($binIds !== []) {
            $specs = [];
            foreach ($binIds as $id) {
                $bin = $this->binOrNull($id);
                if ($bin instanceof WarehouseLocation) {
                    $specs[] = $this->catalog->forBin($bin);
                }
            }

            return $specs;
        }

        // Nothing ticked means the whole warehouse, which is what labelling new racking is.
        $warehouseId = $request->request->getInt('warehouse_id', 0);

        return $warehouseId > 0 ? $this->catalog->binsFor($this->warehouseOr404($warehouseId)) : [];
    }

    /** @return list<LabelSpec> */
    private function productSpecs(Request $request): array
    {
        $product = $this->em->find(ProductCore::class, $request->request->getInt('product_id', 0));

        return $product instanceof ProductCore ? [$this->catalog->forProduct($product)] : [];
    }

    /**
     * @param callable(ProductCore): list<LabelSpec> $builder
     *
     * @return list<LabelSpec>
     */
    private function forProduct(Request $request, callable $builder): array
    {
        $product = $this->em->find(ProductCore::class, $request->request->getInt('product_id', 0));

        return $product instanceof ProductCore ? $builder($product) : [];
    }

    /**
     * The geometry, and the symbology printed on it.
     *
     * The symbology is applied last and to both branches, so a custom 40 x 20 mm die-cut and a
     * shipped Avery preset both honour the choice — #608's "let the template choose", where the
     * template is the geometry plus what is printed on it.
     */
    private function template(Request $request): LabelTemplate
    {
        $symbology = (string) $request->request->get('symbology', LabelTemplate::CODE128);
        $key = (string) $request->request->get('template', 'avery-5160');

        if ($key !== 'custom') {
            return LabelTemplate::byKey($key)->withSymbology($symbology);
        }

        return $this->customTemplate($request)->withSymbology($symbology);
    }

    private function customTemplate(Request $request): LabelTemplate
    {
        return LabelTemplate::custom(
            (float) $request->request->get('page_width', 210),
            (float) $request->request->get('page_height', 297),
            (float) $request->request->get('margin_top', 0),
            (float) $request->request->get('margin_left', 0),
            $request->request->getInt('columns', 1),
            $request->request->getInt('rows', 1),
            (float) $request->request->get('label_width', 100),
            (float) $request->request->get('label_height', 50),
            (float) $request->request->get('column_gap', 0),
            (float) $request->request->get('row_gap', 0),
        );
    }

    /** @return list<ProductCore> */
    private function dimensionalProducts(): array
    {
        /** @var list<ProductCore> $rows */
        $rows = $this->em->getRepository(ProductCore::class)->findBy(
            ['inventoryMode' => ProductCore::INVENTORY_MODE_DIMENSIONAL, 'deleted' => false],
            ['sku' => 'ASC'],
        );

        return $rows;
    }
}
