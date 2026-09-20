<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\Controller\Admin;

use App\Entity\CustomFieldDefinition;
use App\Entity\ProductCategory;
use App\Repository\CustomFieldDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Number1CategoryProductPageBundle\Service\CategoryPageConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One screen: which real ProductCategory fills each of the three fixed roles (Tire/Wheel/
 * Accessories), plus — per product custom field — which category it's scoped to (if any) and
 * whether/how it shows as a Wheel page filter. See CATEGORY_PAGE_FILTER_PLAN.md §3d.
 */
#[Route('/admin/bundles/number1-category-product-page')]
final class CategoryProductPageConfigController extends AbstractController
{
    #[Route('', name: 'admin_bundle_number1_category_product_page_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        CategoryPageConfig $config,
        CustomFieldDefinitionRepository $definitionRepo,
    ): Response {
        if ($request->isMethod('POST')) {
            $roleCategoryIds = [];
            $viewConfigByRole = [];
            foreach (CategoryPageConfig::ROLE_DEFAULT_NAMES as $role => $defaultName) {
                $roleCategoryIds[$role] = (int) $request->request->get('role_category_' . $role, 0);

                $gridAvailable = $request->request->get('view_grid_available_' . $role) === '1';
                $listAvailable = $request->request->get('view_list_available_' . $role) === '1';
                if (!$gridAvailable && !$listAvailable) {
                    // Never let the form save a page with no usable view at all.
                    $gridAvailable = true;
                }

                $viewConfigByRole[$role] = [
                    'gridAvailable' => $gridAvailable,
                    'listAvailable' => $listAvailable,
                    'defaultView' => (string) $request->request->get('view_default_' . $role, CategoryPageConfig::DEFAULT_VIEW),
                ];
            }
            $config->saveCategoryRoles($entityManager, $roleCategoryIds);
            $config->saveViewConfig($entityManager, $viewConfigByRole);

            $definitions = $definitionRepo->findByObjectType(CustomFieldDefinition::OBJECT_TYPE_PRODUCT);
            $categoriesById = $this->categoriesById($entityManager);
            $wheelFacets = [];

            foreach ($definitions as $definition) {
                $id = $definition->getId();
                if ($id === null) {
                    continue;
                }

                $categoryId = (int) $request->request->get('field_category_' . $id, 0);
                $definition->setCategory($categoryId > 0 ? ($categoriesById[$categoryId] ?? null) : null);

                if ($request->request->get('field_facet_' . $id) === '1') {
                    $label = trim((string) $request->request->get('field_facet_label_' . $id, ''));
                    $wheelFacets[] = [
                        'slug' => $definition->getSlug(),
                        'label' => $label !== '' ? $label : $definition->getLabel(),
                        'advanced' => $request->request->get('field_facet_advanced_' . $id) === '1',
                    ];
                }
            }

            $entityManager->flush();
            $config->saveWheelFacets($entityManager, $wheelFacets);

            $this->addFlash('success', 'Settings saved.');

            return $this->redirectToRoute('admin_bundle_number1_category_product_page_index');
        }

        $resolvedCategories = $config->resolveCategories($entityManager);
        $wheelFacetSlugs = array_column($config->wheelFacets(), null, 'slug');
        $viewConfigByRole = [];
        foreach (CategoryPageConfig::ROLE_DEFAULT_NAMES as $role => $defaultName) {
            $viewConfigByRole[$role] = $config->viewConfig($role);
        }

        return $this->render('@Number1CategoryProductPage/admin/config/index.html.twig', [
            'roles' => CategoryPageConfig::ROLE_DEFAULT_NAMES,
            'resolvedCategories' => $resolvedCategories,
            'categories' => $entityManager->getRepository(ProductCategory::class)->findBy([], ['name' => 'ASC']),
            'definitions' => $definitionRepo->findByObjectType(CustomFieldDefinition::OBJECT_TYPE_PRODUCT),
            'wheelFacetSlugs' => $wheelFacetSlugs,
            'viewConfigByRole' => $viewConfigByRole,
        ]);
    }

    /** @return array<int, ProductCategory> */
    private function categoriesById(EntityManagerInterface $entityManager): array
    {
        $byId = [];
        foreach ($entityManager->getRepository(ProductCategory::class)->findAll() as $category) {
            if ($category->getId() !== null) {
                $byId[$category->getId()] = $category;
            }
        }

        return $byId;
    }
}
