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
use WooCommerceBundle\Entity\WooCommerceSyncRun;
use WooCommerceBundle\Repository\WooCommerceConnectionRepository;
use WooCommerceBundle\Repository\WooCommercePushQueueItemRepository;
use WooCommerceBundle\Repository\WooCommerceSyncRunRepository;
use WooCommerceBundle\Service\WooCommerceBulkResyncService;

/**
 * Bulk Resync's admin screen (#739): trigger a run (with a store selector — a blunt "resync
 * everything" is a real, occasionally-needed action, and picking one store is the common case, not
 * the exception), see the run history, and drill into a run's currently-failing rows.
 */
final class WooCommerceBulkResyncController extends AbstractController
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

    #[Route('/admin/bundles/woocommerce/bulk-resync', name: 'admin_bundle_woocommerce_bulk_resync', methods: ['GET'])]
    public function index(WooCommerceSyncRunRepository $runs, WooCommerceConnectionRepository $connections): Response
    {
        $this->denyIfInactive();

        return $this->render('@WooCommerce/bulk_resync.html.twig', [
            'runs' => $runs->findAllNewestFirst(),
            'connections' => $connections->findAllOrderedByName(),
        ]);
    }

    #[Route('/admin/bundles/woocommerce/bulk-resync/run', name: 'admin_bundle_woocommerce_bulk_resync_run', methods: ['POST'])]
    public function run(Request $request, WooCommerceBulkResyncService $resync, EntityManagerInterface $em): Response
    {
        $this->denyIfInactive();

        $connectionId = $request->request->getInt('connection_id', 0);
        $connection = $connectionId > 0 ? $em->find(WooCommerceConnection::class, $connectionId) : null;
        $connection = $connection instanceof WooCommerceConnection ? $connection : null;

        $triggeredBy = $this->getUser()?->getUserIdentifier() ?? 'System';
        $syncRun = $resync->run($connection, $triggeredBy);

        $this->addFlash('success', sprintf(
            'Queued %d product(s) for resync%s.',
            $syncRun->getQueuedCount(),
            $connection instanceof WooCommerceConnection ? sprintf(' on %s', $connection->getName()) : ' across every store',
        ));

        return $this->redirectToRoute('admin_bundle_woocommerce_bulk_resync');
    }

    #[Route('/admin/bundles/woocommerce/bulk-resync/{id}', name: 'admin_bundle_woocommerce_bulk_resync_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id, EntityManagerInterface $em, WooCommercePushQueueItemRepository $pushQueue): Response
    {
        $this->denyIfInactive();

        $syncRun = $em->find(WooCommerceSyncRun::class, $id);
        if (!$syncRun instanceof WooCommerceSyncRun) {
            throw new NotFoundHttpException('No such sync run.');
        }

        return $this->render('@WooCommerce/bulk_resync_detail.html.twig', [
            'run' => $syncRun,
            'failures' => $pushQueue->search(['connection' => $syncRun->getConnection(), 'status' => 'Failed']),
        ]);
    }
}
