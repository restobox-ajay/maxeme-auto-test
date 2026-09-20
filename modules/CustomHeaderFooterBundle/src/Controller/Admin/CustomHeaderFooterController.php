<?php

declare(strict_types=1);

namespace CustomHeaderFooterBundle\Controller\Admin;

use App\Repository\BundleStatusRepository;
use CustomHeaderFooterBundle\Bundle\CustomHeaderFooterBundleDescriptor;
use CustomHeaderFooterBundle\Service\CustomHeaderFooterStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/bundles/custom-header-footer')]
final class CustomHeaderFooterController extends AbstractController
{
    #[Route('', name: 'admin_bundle_custom_header_footer_index', methods: ['GET'])]
    public function index(
        BundleStatusRepository $bundleStatusRepo,
        CustomHeaderFooterBundleDescriptor $descriptor,
        CustomHeaderFooterStore $store,
    ): Response {
        return $this->render('@CustomHeaderFooter/admin/index.html.twig', [
            'active' => $bundleStatusRepo->isActive($descriptor->getSource()),
            'headerHtml' => $store->getHeaderHtml(),
            'footerHtml' => $store->getFooterHtml(),
        ]);
    }

    #[Route('/save', name: 'admin_bundle_custom_header_footer_save', methods: ['POST'])]
    public function save(Request $request, CustomHeaderFooterStore $store): Response
    {
        $store->saveHeaderHtml((string) $request->request->get('header_html', ''));
        $store->saveFooterHtml((string) $request->request->get('footer_html', ''));

        $this->addFlash('success', 'Custom header and footer HTML updated.');

        return $this->redirectToRoute('admin_bundle_custom_header_footer_index');
    }
}
