<?php

declare(strict_types=1);

namespace WooCommerceBundle\Controller\Admin;

use App\Repository\BundleStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Repository\WooCommerceConnectionRepository;
use WooCommerceBundle\Repository\WooCommerceOrderImportRepository;

/**
 * "All orders" (#739) — every WooCommerce order this app has ever seen, across every connection,
 * consolidated into one grid rather than split into a clean tab and a needs-attention tab. With
 * hundreds of orders per connection, the split itself was the friction: this sorts flagged/errored
 * rows first by default (WooCommerceOrderImportRepository::search()) and offers real filters
 * instead, so an admin narrows down rather than having to remember which tab to check.
 */
final class WooCommerceOrderController extends AbstractController
{
    public function __construct(
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    private function denyIfInactive(): void
    {
        if (!$this->bundleStatusRepo->isActive('WooCommerceBundle')) {
            throw new NotFoundHttpException('WooCommerce is not active.');
        }
    }

    #[Route('/admin/bundles/woocommerce/orders', name: 'admin_bundle_woocommerce_orders', methods: ['GET'])]
    public function index(
        Request $request,
        WooCommerceOrderImportRepository $imports,
        WooCommerceConnectionRepository $connections,
        EntityManagerInterface $em,
    ): Response {
        $this->denyIfInactive();

        $filters = $request->query->all('filters');
        $connectionId = (int) ($filters['connection_id'] ?? 0);
        $connection = $connectionId > 0 ? $em->find(WooCommerceConnection::class, $connectionId) : null;
        $connection = $connection instanceof WooCommerceConnection ? $connection : null;
        $status = (string) ($filters['status'] ?? '');

        return $this->render('@WooCommerce/orders.html.twig', [
            'orders' => $imports->search(['connection' => $connection, 'status' => $status]),
            'connections' => $connections->findAllOrderedByName(),
            'filters' => ['connection_id' => $connectionId, 'status' => $status],
        ]);
    }
}
