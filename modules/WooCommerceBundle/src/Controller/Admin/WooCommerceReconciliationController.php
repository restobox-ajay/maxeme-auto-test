<?php

declare(strict_types=1);

namespace WooCommerceBundle\Controller\Admin;

use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Repository\ProductCoreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use WooCommerceBundle\Entity\WooCommerceProductMapping;
use WooCommerceBundle\Repository\WooCommerceProductMappingRepository;

/**
 * Reconciliation queue for auto-created products (#739): every WooCommerceProductMapping the SKU
 * resolver flagged because it had to mint a product rather than match an existing one. An admin
 * either confirms the auto-created product is the permanent one ("Keep") or points the mapping at a
 * product that already existed and this should have matched in the first place ("Merge").
 *
 * ## What a merge does and does not do
 *
 * Merging repoints the mapping's product and deactivates the auto-created one — it does NOT rewrite
 * any SalesOrderLine/InvoiceLine that already carries the auto-created product. Those documents are
 * a record of what happened at the time (the same principle OrderInvoicingService's own docblock
 * argues for its snapshots): the order really was billed against the item the resolver created, and
 * rewriting history to point at a different product would be editing a book that has already shipped
 * and been paid for. Only future orders resolve through the corrected mapping.
 */
final class WooCommerceReconciliationController extends AbstractController
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

    #[Route('/admin/bundles/woocommerce/reconciliation', name: 'admin_bundle_woocommerce_reconciliation', methods: ['GET'])]
    public function index(WooCommerceProductMappingRepository $mappings): Response
    {
        $this->denyIfInactive();

        return $this->render('@WooCommerce/reconciliation.html.twig', [
            'mappings' => $mappings->findAllFlagged(),
        ]);
    }

    #[Route('/admin/bundles/woocommerce/reconciliation/{id}/keep', name: 'admin_bundle_woocommerce_reconciliation_keep', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function keep(int $id, EntityManagerInterface $em): Response
    {
        $this->denyIfInactive();

        $mapping = $this->mappingOr404($id, $em);
        $mapping->setFlagged(false);
        $em->flush();

        $this->addFlash('success', sprintf('%s kept as its own product.', $mapping->getProduct()->getSku()));

        return $this->redirectToRoute('admin_bundle_woocommerce_reconciliation');
    }

    #[Route('/admin/bundles/woocommerce/reconciliation/{id}/merge', name: 'admin_bundle_woocommerce_reconciliation_merge', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function merge(int $id, Request $request, EntityManagerInterface $em, ProductCoreRepository $products): Response
    {
        $this->denyIfInactive();

        $mapping = $this->mappingOr404($id, $em);
        $targetSku = trim((string) $request->request->get('sku', ''));
        $target = $targetSku !== '' ? $products->findBySku($targetSku) : null;

        if (!$target instanceof ProductCore) {
            $this->addFlash('error', sprintf('No product found for SKU "%s". Nothing changed.', $targetSku));

            return $this->redirectToRoute('admin_bundle_woocommerce_reconciliation');
        }

        $autoCreated = $mapping->getProduct();
        if ($target->getId() === $autoCreated->getId()) {
            $this->addFlash('error', 'That is already the product on this mapping.');

            return $this->redirectToRoute('admin_bundle_woocommerce_reconciliation');
        }

        $mapping->setProduct($target)->setFlagged(false);
        // Taken out of circulation rather than deleted: past orders and invoices still reference
        // it by id, and deleting it would either cascade into history or force those rows to a
        // dangling reference. Deactivating leaves it out of every future pick list while keeping
        // every past document exactly as it was billed.
        $autoCreated->deactivate();
        $em->flush();

        $this->addFlash('success', sprintf(
            'Future %s orders now resolve to %s. %s was deactivated.',
            $mapping->getWooSku(),
            $target->getSku(),
            $autoCreated->getSku(),
        ));

        return $this->redirectToRoute('admin_bundle_woocommerce_reconciliation');
    }

    private function mappingOr404(int $id, EntityManagerInterface $em): WooCommerceProductMapping
    {
        $mapping = $em->find(WooCommerceProductMapping::class, $id);
        if (!$mapping instanceof WooCommerceProductMapping) {
            throw new NotFoundHttpException('No such mapping.');
        }

        return $mapping;
    }
}
