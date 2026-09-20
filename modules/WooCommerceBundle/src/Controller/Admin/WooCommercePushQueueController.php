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
use WooCommerceBundle\Entity\WooCommercePushQueueItem;
use WooCommerceBundle\Repository\WooCommerceConnectionRepository;
use WooCommerceBundle\Repository\WooCommercePushQueueItemRepository;
use WooCommerceBundle\Service\WooCommercePushQueueProcessor;

/**
 * The Push Queue's own debugging screen (#739) — deliberately a peer of Connections/Orders, not a
 * drill-down under a Product Sync tab: it needed to be seen and force-pushed on its own for the
 * queue to be debuggable at all.
 */
final class WooCommercePushQueueController extends AbstractController
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

    #[Route('/admin/bundles/woocommerce/push-queue', name: 'admin_bundle_woocommerce_push_queue', methods: ['GET'])]
    public function index(
        Request $request,
        WooCommercePushQueueItemRepository $pushQueue,
        WooCommerceConnectionRepository $connections,
        EntityManagerInterface $em,
    ): Response {
        $this->denyIfInactive();

        $filters = $request->query->all('filters');
        $connectionId = (int) ($filters['connection_id'] ?? 0);
        $connection = $connectionId > 0 ? $em->find(WooCommerceConnection::class, $connectionId) : null;
        $connection = $connection instanceof WooCommerceConnection ? $connection : null;
        $status = (string) ($filters['status'] ?? '');

        return $this->render('@WooCommerce/push_queue.html.twig', [
            'items' => $pushQueue->search(['connection' => $connection, 'status' => $status]),
            'connections' => $connections->findAllOrderedByName(),
            'filters' => ['connection_id' => $connectionId, 'status' => $status],
        ]);
    }

    #[Route('/admin/bundles/woocommerce/push-queue/{id}/push', name: 'admin_bundle_woocommerce_push_queue_push_one', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pushOne(int $id, EntityManagerInterface $em, WooCommercePushQueueProcessor $processor): Response
    {
        $this->denyIfInactive();

        $item = $em->find(WooCommercePushQueueItem::class, $id);
        if (!$item instanceof WooCommercePushQueueItem) {
            throw new NotFoundHttpException('No such queue item.');
        }

        $processor->push($item);
        $em->flush();

        $this->addFlash($item->isFailed() ? 'error' : 'success', $item->isFailed()
            ? sprintf('Push failed: %s', $item->getErrorMessage())
            : 'Pushed.');

        return $this->redirectToRoute('admin_bundle_woocommerce_push_queue');
    }

    #[Route('/admin/bundles/woocommerce/push-queue/push-all', name: 'admin_bundle_woocommerce_push_queue_push_all', methods: ['POST'])]
    public function pushAll(EntityManagerInterface $em, WooCommercePushQueueItemRepository $pushQueue, WooCommercePushQueueProcessor $processor): Response
    {
        $this->denyIfInactive();

        $items = $pushQueue->findAllPending();
        $pushed = 0;
        $failed = 0;
        foreach ($items as $item) {
            $processor->push($item);
            $item->isFailed() ? $failed++ : $pushed++;
        }
        $em->flush();

        $this->addFlash($failed > 0 ? 'error' : 'success', sprintf('%d pushed, %d failed.', $pushed, $failed));

        return $this->redirectToRoute('admin_bundle_woocommerce_push_queue');
    }
}
