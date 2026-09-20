<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Repository\BundleStatusRepository;
use App\Service\DocumentActorResolver;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Product\ProductPicker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What `js-searchable-select` calls as an admin types into a purchase screen's product field.
 *
 * The buy-side counterpart of `OrderController::searchOrderProducts()`, and deliberately its own
 * endpoint rather than a reuse of it: that one resolves a CUSTOMER price from a company's price
 * list, and a purchase screen has no company and wants the opposite number — what this vendor
 * charges us, off the `vendor_price` rate card (#637). Same paging and the same `hasMore` contract;
 * different money.
 *
 * `vendor_id` is optional, and a purchase order in its create form genuinely has no vendor chosen
 * yet. Read with a plain get() and cast rather than getInt(), because the picker sends
 * `vendor_id=""` in exactly that case and getInt() raises a BadRequestException on anything
 * FILTER_VALIDATE_INT rejects — turning "no vendor picked yet" into an HTTP 400. The sell side hit
 * the same trap with company_id and records it there too. No vendor simply means the results carry
 * no cost, which is what a null vendor gives the picker below.
 */
#[Route('/admin/bundles/procurement/products')]
final class ProductSearchController extends AbstractProcurementController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly ProductPicker $picker,
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    #[Route('/search', name: 'admin_bundle_procurement_product_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $this->denyIfInactive();

        $vendorId = (int) $request->query->get('vendor_id', 0);
        $vendor = $vendorId > 0 ? $this->em->find(Vendor::class, $vendorId) : null;

        return $this->json($this->picker->search(
            (string) $request->query->get('q', ''),
            $vendor instanceof Vendor ? $vendor : null,
            (int) $request->query->get('offset', 0),
        ));
    }
}
