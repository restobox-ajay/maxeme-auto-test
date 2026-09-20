<?php

declare(strict_types=1);

namespace BarcodeBundle\Controller\Admin;

use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use BarcodeBundle\Barcode\BarcodeException;
use BarcodeBundle\Barcode\BarcodeRegistry;
use BarcodeBundle\Barcode\ProductLookup;
use BarcodeBundle\Entity\ProductBarcode;
use BarcodeBundle\Repository\ProductBarcodeRepository;
use BarcodeBundle\Symbology\Code128;
use BarcodeBundle\Symbology\Qr;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Attaching barcodes to products, and generating one for a product that has none (#607, #608).
 *
 * ## Two screens, and why the product one is not a modal on the list
 *
 * The list answers "what does this code mean" — you have a carton in your hand and a string on it.
 * The product screen answers "what does this product answer to" — you are setting up an item and
 * adding the three suppliers' part numbers for it. They are different questions asked by different
 * people at different times, and one screen doing both would be a filter box pretending to be a
 * form.
 *
 * ## Getting to a product without a 7,000-option select
 *
 * The list screen's "Open a product" box takes a SKU **or any barcode already attached to any
 * product** and goes to that product's screen. That is the same resolver the scan console uses, so
 * a hardware scanner works here too: point it at the carton, it types and presses Enter, and you
 * land on the product whose barcodes you were about to edit. A dropdown of every product in the
 * catalogue would be unusable at this scale and would need JavaScript to become usable, which is
 * not a trade this application makes.
 *
 * ## Generate mints, it does not bulk-assign
 *
 * The Generate button is per product and writes one row. There is deliberately no "generate for
 * everything that has none": that is the entire catalogue on day one, and it would put a number on
 * thousands of products that matches no label anywhere in the building — a decision that looks like
 * somebody's and is nobody's.
 */
#[Route('/admin/bundles/barcodes')]
final class BarcodeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BundleStatusRepository $bundleStatusRepo,
        private readonly ProductBarcodeRepository $barcodes,
        private readonly BarcodeRegistry $registry,
        private readonly ProductLookup $lookup,
    ) {
    }

    /**
     * 404 rather than 403: an Inactive bundle's screens should read as absent, not forbidden — the
     * same shape AbstractWarehouseOpsController and AbstractInventoryDepthController use, so all
     * three bundles behave the same way when one of them is switched off.
     *
     * `/admin` is already gated by `access_control` and AdminHostSubscriber, so nothing here
     * re-checks the role.
     */
    private function denyIfInactive(): void
    {
        if (!$this->bundleStatusRepo->isActive('BarcodeBundle')) {
            throw new NotFoundHttpException('Barcodes is not active.');
        }
    }

    /**
     * Filter state lives in URL GET params on every list screen. Standing requirement in this
     * codebase, and the reason a copied URL reproduces the exact result.
     *
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function filtersFromRequest(Request $request, array $keys): array
    {
        $raw = $request->query->all('filters');

        $filters = [];
        foreach ($keys as $key) {
            $value = $raw[$key] ?? '';
            // `?filters[x][]=y` makes this an array, and casting one to string raises a notice the
            // dev error handler turns into a 500 from nothing but a crafted URL. A non-scalar is not
            // a filter anyone typed, so it reads as absent.
            $filters[$key] = \is_scalar($value) ? trim((string) $value) : '';
        }

        return $filters;
    }

    /**
     * Page/limit/sort/dir, all from GET, all whitelisted.
     *
     * @return array{page: int, limit: int, sort: string, dir: string}
     */
    private function paging(Request $request, string $defaultSort, string $defaultDir): array
    {
        $dir = strtolower(trim((string) $request->query->get('dir', $defaultDir))) === 'asc' ? 'ASC' : 'DESC';

        return [
            'page' => max(1, $request->query->getInt('page', 1)),
            'limit' => max(1, min(500, $request->query->getInt('limit', 100))),
            'sort' => trim((string) $request->query->get('sort', $defaultSort)),
            'dir' => $dir,
        ];
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** Every barcode in the database, filtered and paged server-side like every other list screen. */
    #[Route('', name: 'admin_bundle_barcodes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['code', 'product', 'kind', 'party']);
        $paging = $this->paging($request, 'id', 'desc');

        $result = $this->barcodes->search(
            $filters,
            $paging['page'],
            $paging['limit'],
            $paging['sort'],
            $paging['dir'],
        );

        return $this->render('@Barcode/index.html.twig', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'page' => $paging['page'],
            'limit' => $paging['limit'],
            'pages' => (int) ceil($result['total'] / max(1, $paging['limit'])),
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
            'kinds' => ProductBarcode::kindLabels(),
        ]);
    }

    /**
     * The "Open a product" box: a SKU, or any barcode on any product.
     *
     * A GET, because it selects rather than writes and because that makes the result a URL a
     * supervisor can be sent. It redirects rather than rendering, so the product screen has exactly
     * one address.
     */
    #[Route('/open', name: 'admin_bundle_barcodes_open', methods: ['GET'])]
    public function open(Request $request): Response
    {
        $this->denyIfInactive();

        $code = trim((string) $request->query->get('code', ''));

        if ($code === '') {
            $this->addFlash('error', 'Type or scan a SKU or a barcode first.');

            return $this->redirectToRoute('admin_bundle_barcodes');
        }

        $products = $this->lookup->products($code);

        if ($products === []) {
            $this->addFlash('error', sprintf(
                '%s is not a SKU and is not a barcode on any product. Nothing was opened.',
                $code,
            ));

            return $this->redirectToRoute('admin_bundle_barcodes', ['filters' => ['code' => $code]]);
        }

        if (\count($products) > 1) {
            // The same refusal the scan console gives, for the same reason: picking one of two
            // would be right about half the time and silent about it.
            $this->addFlash('error', (string) $this->lookup->describeAmbiguity($code));

            return $this->redirectToRoute('admin_bundle_barcodes', ['filters' => ['code' => $code]]);
        }

        return $this->redirectToRoute('admin_bundle_barcodes_product', ['id' => $products[0]->getId()]);
    }

    /** One product's barcodes: what it answers to, and a form to add another. */
    #[Route('/product/{id}', name: 'admin_bundle_barcodes_product', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function product(int $id): Response
    {
        $this->denyIfInactive();

        $product = $this->productOr404($id);
        $rows = $this->barcodes->forProduct($product);

        return $this->render('@Barcode/product.html.twig', [
            'product' => $product,
            'rows' => $rows,
            'primary' => $this->barcodes->primaryFor($product),
            'kinds' => ProductBarcode::kindLabels(),
            // The preview is the same encoder the label sheet uses, so what is shown here is what
            // comes out of the printer.
            'previews' => $this->previews($rows),
        ]);
    }

    #[Route('/product/{id}/attach', name: 'admin_bundle_barcodes_attach', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function attach(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $product = $this->productOr404($id);

        try {
            $barcode = $this->registry->attach(
                $product,
                (string) $request->request->get('code', ''),
                (string) $request->request->get('kind', ProductBarcode::KIND_INTERNAL),
                $this->nullable((string) $request->request->get('party', '')),
                $this->nullable((string) $request->request->get('note', '')),
                $request->request->getBoolean('primary'),
            );

            $this->addFlash('success', sprintf(
                '%s is now on %s.%s',
                $barcode->getCode(),
                $product->getSku() ?: $product->getName(),
                $barcode->isPrimary() ? ' It is the one labels encode.' : '',
            ));
        } catch (BarcodeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_barcodes_product', ['id' => $id]);
    }

    /** Mints one internal EAN-13 in GS1's restricted-circulation range. See WarehouseOpsBundle\Barcode\Gtin. */
    #[Route('/product/{id}/generate', name: 'admin_bundle_barcodes_generate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function generate(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $product = $this->productOr404($id);

        try {
            $barcode = $this->registry->mintInternal($product, $this->nullable((string) $request->request->get('note', '')));

            $this->addFlash('success', sprintf(
                'Generated %s for %s. It is a valid EAN-13 in the range GS1 reserves for codes a'
                . ' business mints for itself, so it can never collide with a manufacturer\'s.',
                $barcode->getCode(),
                $product->getSku() ?: $product->getName(),
            ));
        } catch (BarcodeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_barcodes_product', ['id' => $id]);
    }

    #[Route('/{id}/primary', name: 'admin_bundle_barcodes_primary', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function primary(int $id): Response
    {
        $this->denyIfInactive();

        $barcode = $this->barcodeOr404($id);
        $product = $barcode->getProduct();

        try {
            $this->registry->makePrimary($barcode);
            $this->addFlash('success', sprintf('Labels for this product now encode %s.', $barcode->getCode()));
        } catch (BarcodeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_barcodes_product', ['id' => $product?->getId() ?? 0]);
    }

    /**
     * Takes a barcode off a product.
     *
     * Nothing is stranded by this: `product_barcode` is a leaf. No movement, receipt or pick task
     * refers to a barcode row — they refer to the product, which this does not touch. Deleting the
     * last barcode returns the product to encoding its SKU on labels, which is where it started.
     */
    #[Route('/{id}/delete', name: 'admin_bundle_barcodes_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id): Response
    {
        $this->denyIfInactive();

        $barcode = $this->barcodeOr404($id);
        $product = $barcode->getProduct();
        $code = $barcode->getCode();

        $this->registry->detach($barcode);

        $this->addFlash('success', sprintf('%s is no longer on this product.', $code));

        return $this->redirectToRoute('admin_bundle_barcodes_product', ['id' => $product?->getId() ?? 0]);
    }

    /**
     * An on-screen rendering of each code, in the two symbologies this application can print.
     *
     * Both are drawn from the same encoders the PDF sheet uses — Code128 and Qr — so the screen and
     * the printer cannot disagree about what a value looks like. Neither draws anything for a value
     * that cannot be encoded; the row says so instead.
     *
     * @param list<ProductBarcode> $rows
     *
     * @return array<int, array{bars: list<array{0: int, 1: int}>, modules: int, qr: list<list<bool>>}>
     */
    private function previews(array $rows): array
    {
        $previews = [];

        foreach ($rows as $row) {
            $code = $row->getCode();

            if (!Code128::isEncodable($code)) {
                continue;
            }

            $previews[$row->getId() ?? 0] = [
                'bars' => Code128::bars($code),
                'modules' => Code128::widthInModules($code),
                'qr' => Qr::matrix($code),
            ];
        }

        return $previews;
    }

    private function productOr404(int $id): ProductCore
    {
        $product = $this->em->find(ProductCore::class, $id);

        if (!$product instanceof ProductCore || $product->isDeleted()) {
            throw new NotFoundHttpException('No such product.');
        }

        return $product;
    }

    private function barcodeOr404(int $id): ProductBarcode
    {
        $barcode = $this->em->find(ProductBarcode::class, $id);

        if (!$barcode instanceof ProductBarcode) {
            throw new NotFoundHttpException('No such barcode.');
        }

        return $barcode;
    }
}
