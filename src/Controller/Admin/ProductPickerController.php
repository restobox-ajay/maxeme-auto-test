<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Product\ProductPicker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What `js-searchable-select` calls as an admin types into a product field that has no money in it.
 *
 * The catalog half of the three tiers, with nothing priced. The two endpoints that DO price their
 * results stay where their prices live and are unchanged by this:
 *
 *  - `admin_order_product_search` / `admin_estimate_product_search` resolve a CUSTOMER price from
 *    the company's price list;
 *  - `admin_bundle_procurement_product_search` resolves what a VENDOR charges us, off `vendor_price`.
 *
 * A screen with neither a company nor a vendor — Inventory Depth's lots and reorder levels — has no
 * price to show that would not be invented, and inventing one in a picker is worse than showing
 * none. So this returns id, SKU and name, and every screen that has a price to add keeps its own
 * endpoint and passes it to the partial as `searchUrl`.
 *
 * It lives in core for the same reason the partial does: `config/bundles.php` discovers modules by
 * glob, so a module's routes leave with its folder, and `path()` on a route that is no longer in
 * the collection is a 500 on whatever page called it. A field two bundles share cannot depend on a
 * third thing being installed. See App\Service\Product\ProductPicker.
 *
 * `/admin` is already gated by `access_control` (ROLE_ADMIN) and AdminHostSubscriber, so nothing
 * here re-checks the role.
 */
final class ProductPickerController extends AbstractController
{
    public function __construct(private readonly ProductPicker $picker)
    {
    }

    /**
     * Plain get() and a cast rather than getInt(), matching both other endpoints: getInt() raises a
     * BadRequestException on anything FILTER_VALIDATE_INT rejects — an empty string included — and
     * the widget sends empty params freely. An unparseable offset means "start at the beginning",
     * not HTTP 400.
     */
    #[Route('/admin/products/picker-search', name: 'admin_product_picker_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        return $this->json($this->picker->search(
            (string) $request->query->get('q', ''),
            (int) $request->query->get('offset', 0),
        ));
    }
}
