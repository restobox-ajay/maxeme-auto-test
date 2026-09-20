<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActorResolver;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorPrice;
use ProcurementBundle\Repository\VendorPriceRepository;
use ProcurementBundle\Product\ProductPicker;
use ProcurementBundle\VendorPricing\VendorPriceUpserter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The vendor price list (#637): what a vendor charges for a product, maintained directly rather
 * than raised, issued or converted — the buy-side mirror of `Admin\PriceListController`, not of any
 * document in this bundle.
 *
 * This is deliberately the smaller half of #637 and is built first, per the issue's own
 * recommendation: it pays off on every purchase order (PurchaseOrderController::save() resolves a
 * blank line cost from here), where the RFQ only earns its keep on the occasions purchasing
 * actually tenders.
 */
#[Route('/admin/bundles/procurement/vendor-prices')]
final class VendorPriceController extends AbstractProcurementController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly VendorPriceRepository $prices,
        // The product field: this screen used to ask for a database id.
        private readonly ProductPicker $picker,
        private readonly VendorPriceUpserter $upserter,
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    #[Route('', name: 'admin_bundle_procurement_vendor_prices', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['q', 'vendor_id']);
        $paging = $this->paging($request, 'vendor');

        $result = $this->prices->search($filters, $paging['page'], $paging['limit'], $paging['sort'], $paging['dir']);

        $vendorId = (int) ($filters['vendor_id'] ?: 0);
        $vendor = $vendorId > 0 ? $this->em->find(Vendor::class, $vendorId) : null;

        return $this->render('@Procurement/vendor_prices.html.twig', [
            'prices' => $result['rows'],
            'total' => $result['total'],
            'vendor' => $vendor,
            'vendors' => $this->activeVendors(),
            'filters' => $filters,
            'page' => $paging['page'],
            'limit' => $paging['limit'],
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    #[Route('/new', name: 'admin_bundle_procurement_vendor_price_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->denyIfInactive();

        // `?vendor_id=` through the three-state read (queue item 51). `?vendor_id=abc` threw a raw
        // 400 out of `getInt()`, and `?vendor_id=99999` 404'd the whole Add a price screen through
        // `vendorOr404()` — which is the obscure failure with a different number on it. The screen
        // exists and renders; it is the LINK that named a vendor nobody has.
        $requestedVendor = $this->requestedParent($request, 'vendor_id', Vendor::class, 'vendor');

        return $this->render('@Procurement/vendor_price_edit.html.twig', [
            'price' => null,
            'vendor' => $requestedVendor->entity(),
            'badParents' => $this->unresolvedParents($requestedVendor),
            'vendors' => $this->activeVendors(),
            'productOptions' => $this->picker->options(),
            'productsRemote' => $this->picker->isRemote(),
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_bundle_procurement_vendor_price_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyIfInactive();

        $price = $this->priceOr404($id);

        return $this->render('@Procurement/vendor_price_edit.html.twig', [
            'price' => $price,
            'vendor' => $price->getVendor(),
            // Reached by its own id through a `\d+` route requirement — no parent id in the URL to
            // have been wrong. Explicit because `strict_variables` is on.
            'badParents' => [],
            'vendors' => $this->activeVendors(),
            'productOptions' => $this->picker->options([(int) $price->getProduct()->getId()]),
            'productsRemote' => $this->picker->isRemote(),
        ]);
    }

    #[Route('/save', name: 'admin_bundle_procurement_vendor_price_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyIfInactive();

        $id = $request->request->getInt('id', 0);
        $vendor = $this->vendorOr404($request->request->getInt('vendor_id', 0));
        $product = $this->productOr404($this->productIdFrom($request->request->all()));

        // Editing a known row goes straight to it by id; a new one resolves (and reuses, if one
        // already exists) by the (vendor, product) pair the unique index enforces — the same
        // resolution VendorSheetImportRowExecutor uses on the import side, through the same class.
        $price = $id > 0 ? $this->priceOr404($id) : $this->upserter->forPair($vendor, $product);

        $price
            ->setVendor($vendor)
            ->setProduct($product)
            ->setVendorSku($this->nullable((string) $request->request->get('vendor_sku', '')))
            ->setUnitCost(number_format((float) $request->request->get('unit_cost', '0'), 4, '.', ''))
            ->setCurrency((string) $request->request->get('currency', $vendor->getCurrency()))
            // Not getInt(): $orderMultiple is a canonical decimal string ("1.0000", #601/#625's
            // narrow stand-in), and Symfony's getInt() 400s on exactly the value this form's own
            // <input> pre-fills from it — every existing row, via filter_var(FILTER_VALIDATE_INT)
            // rejecting "1.0000" as not a clean integer. setOrderMultiple() already accepts and
            // canonicalizes a raw string the same way setUnitCost()/setAvailableQuantity() do above.
            ->setOrderMultiple((string) $request->request->get('order_multiple', '1'))
            ->setAvailableQuantity($this->nullable((string) $request->request->get('available_quantity', '')))
            ->setIsActive($request->request->getBoolean('is_active', true))
            ->setNotes($this->nullable((string) $request->request->get('notes', '')));

        $this->em->flush();

        $this->addFlash('success', sprintf('Price for %s from %s saved.', $product->getSku() ?: $product->getName(), $vendor->getName()));

        return $this->redirectToRoute('admin_bundle_procurement_vendor_prices', ['filters' => ['vendor_id' => (string) $vendor->getId()]]);
    }

    #[Route('/{id}/delete', name: 'admin_bundle_procurement_vendor_price_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id): Response
    {
        $this->denyIfInactive();

        $price = $this->priceOr404($id);
        $vendorId = $price->getVendor()->getId();

        $this->em->remove($price);
        $this->em->flush();

        $this->addFlash('success', 'Price removed.');

        return $this->redirectToRoute('admin_bundle_procurement_vendor_prices', ['filters' => ['vendor_id' => (string) $vendorId]]);
    }

    private function priceOr404(int $id): VendorPrice
    {
        $price = $this->em->find(VendorPrice::class, $id);
        if (!$price instanceof VendorPrice) {
            throw new NotFoundHttpException('No such vendor price.');
        }

        return $price;
    }
}
