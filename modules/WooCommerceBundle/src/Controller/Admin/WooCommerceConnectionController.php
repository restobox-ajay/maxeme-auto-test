<?php

declare(strict_types=1);

namespace WooCommerceBundle\Controller\Admin;

use App\Entity\PriceList;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Repository\WooCommerceConnectionRepository;

/**
 * WooCommerce connections (#739) — every store this app talks to, its own credentials, tier
 * mapping, fulfilling warehouse and availability buffer. Reached from the core connector directory
 * via ConnectorTypeProviderInterface::getManageRouteName().
 */
final class WooCommerceConnectionController extends AbstractController
{
    public function __construct(
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    /**
     * 404 rather than 403: an Inactive bundle's screens should read as absent, not forbidden — the
     * same shape BarcodeController uses. `/admin` is already gated by access_control and
     * AdminHostSubscriber, so nothing here re-checks the role.
     */
    private function denyIfInactive(): void
    {
        if (!$this->bundleStatusRepo->isActive('WooCommerceBundle')) {
            throw new NotFoundHttpException('WooCommerce is not active.');
        }
    }

    #[Route('/admin/bundles/woocommerce/connections', name: 'admin_bundle_woocommerce_connections', methods: ['GET'])]
    public function index(WooCommerceConnectionRepository $connections): Response
    {
        $this->denyIfInactive();

        return $this->render('@WooCommerce/connections.html.twig', [
            'connections' => $connections->findAllOrderedByName(),
        ]);
    }

    #[Route('/admin/bundles/woocommerce/connections/new', name: 'admin_bundle_woocommerce_connection_new', methods: ['GET'])]
    public function new(EntityManagerInterface $em): Response
    {
        $this->denyIfInactive();

        return $this->render('@WooCommerce/connection_edit.html.twig', [
            'connection' => null,
            'warehouses' => $em->getRepository(Warehouse::class)->findBy([], ['name' => 'ASC']),
            'priceLists' => $em->getRepository(PriceList::class)->findBy(['status' => 'Active'], ['name' => 'ASC']),
        ]);
    }

    #[Route('/admin/bundles/woocommerce/connections/{id}/edit', name: 'admin_bundle_woocommerce_connection_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id, EntityManagerInterface $em): Response
    {
        $this->denyIfInactive();

        return $this->render('@WooCommerce/connection_edit.html.twig', [
            'connection' => $this->connectionOr404($id, $em),
            'warehouses' => $em->getRepository(Warehouse::class)->findBy([], ['name' => 'ASC']),
            'priceLists' => $em->getRepository(PriceList::class)->findBy(['status' => 'Active'], ['name' => 'ASC']),
        ]);
    }

    #[Route('/admin/bundles/woocommerce/connections/save', name: 'admin_bundle_woocommerce_connection_save', methods: ['POST'])]
    public function save(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyIfInactive();

        $id = $request->request->getInt('id', 0);
        $connection = $id > 0 ? $this->connectionOr404($id, $em) : new WooCommerceConnection();

        $warehouseId = $request->request->getInt('default_warehouse_id', 0);
        $warehouse = $warehouseId > 0 ? $em->find(Warehouse::class, $warehouseId) : null;

        $priceListId = $request->request->getInt('default_price_list_id', 0);
        $priceList = $priceListId > 0 ? $em->find(PriceList::class, $priceListId) : null;

        $connection
            ->setName((string) $request->request->get('name', ''))
            ->setSlug((string) $request->request->get('slug', ''))
            ->setStoreUrl((string) $request->request->get('store_url', ''))
            ->setConsumerKey((string) $request->request->get('consumer_key', ''))
            ->setWebhookSecret((string) $request->request->get('webhook_secret', ''))
            // A checkbox that is unchecked sends no field at all, so the absent-value default must
            // be false — not true — or unchecking "Active" on the form would never take effect.
            ->setActive($request->request->getBoolean('active', false))
            ->setDefaultWarehouse($warehouse instanceof Warehouse ? $warehouse : null)
            ->setDefaultPriceList($priceList instanceof PriceList ? $priceList : null)
            ->setAvailabilityBuffer($request->request->getInt('availability_buffer', 0));

        // The secret field is left blank on re-render (never echoed back), so only overwrite it
        // when a new value was actually typed — the same "blank means unchanged" convention the
        // rest of this app uses for a password-shaped field on an edit form.
        $consumerSecret = (string) $request->request->get('consumer_secret', '');
        if ($consumerSecret !== '') {
            $connection->setConsumerSecret($consumerSecret);
        }

        if ($connection->getId() === null) {
            $em->persist($connection);
        }

        $em->flush();

        $this->addFlash('success', sprintf('%s saved.', $connection->getName()));

        return $this->redirectToRoute('admin_bundle_woocommerce_connections');
    }

    private function connectionOr404(int $id, EntityManagerInterface $em): WooCommerceConnection
    {
        $connection = $em->find(WooCommerceConnection::class, $id);
        if (!$connection instanceof WooCommerceConnection) {
            throw new NotFoundHttpException('No such WooCommerce connection.');
        }

        return $connection;
    }
}
