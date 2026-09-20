<?php

namespace App\Controller\Admin;

use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Service\CustomFieldRenderer;
use App\Validation\Constraint\ValidCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;

#[Route('/admin/category')]
final class CategoryController extends AbstractAdminController
{
    #[Route('/index', name: 'admin_category_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager, Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 100);
        $search = trim((string) $request->query->get('q', ''));
        $rawFilters = $request->query->all('filters');
        if (!is_array($rawFilters)) {
            $rawFilters = [];
        }
        $filters = [
            'id' => trim((string) ($rawFilters['id'] ?? '')),
            'name' => trim((string) ($rawFilters['name'] ?? '')),
            'parent' => trim((string) ($rawFilters['parent'] ?? '')),
            'status' => trim((string) ($rawFilters['status'] ?? '')),
        ];
        $categoryRows = $this->categoryRowsFromDatabase($entityManager);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $categoryRows = array_values(array_filter($categoryRows, static function (array $row) use ($needle): bool {
                $name = mb_strtolower((string) ($row['name'] ?? ''));
                $parent = mb_strtolower((string) ($row['parent'] ?? ''));

                return str_contains($name, $needle) || str_contains($parent, $needle);
            }));
        }

        if ($filters['id'] !== '') {
            $needle = mb_strtolower($filters['id']);
            $categoryRows = array_values(array_filter($categoryRows, static fn (array $row): bool => str_contains(mb_strtolower((string) ($row['id'] ?? '')), $needle)));
        }
        if ($filters['name'] !== '') {
            $needle = mb_strtolower($filters['name']);
            $categoryRows = array_values(array_filter($categoryRows, static fn (array $row): bool => str_contains(mb_strtolower((string) ($row['name'] ?? '')), $needle)));
        }
        if ($filters['parent'] !== '') {
            $needle = mb_strtolower($filters['parent']);
            $categoryRows = array_values(array_filter($categoryRows, static fn (array $row): bool => str_contains(mb_strtolower((string) ($row['parent'] ?? '')), $needle)));
        }
        if ($filters['status'] !== '') {
            $categoryRows = array_values(array_filter($categoryRows, static fn (array $row): bool => ($row['status'] ?? '') === $filters['status']));
        }

        $total = count($categoryRows);
        $page = max(1, $page);
        $limit = max(1, $limit);
        $pageRows = array_slice($categoryRows, ($page - 1) * $limit, $limit);
        $categoryRows = $this->withRepeatedParentContext($pageRows, $categoryRows);

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html' => $this->renderView('admin/product/_category_rows.html.twig', ['categories' => $categoryRows]),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ]);
        }

        return $this->render('admin/product/categories.html.twig', [
            'categories' => $categoryRows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'search' => $search,
            'filters' => $filters,
            'currentSort' => 'hierarchy',
            'currentDir' => 'asc',
        ]);
    }

    #[Route('/create', name: 'admin_category_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, CustomFieldRenderer $customFieldRenderer): Response
    {
        if ($request->isMethod('POST')) {
            $category = new ProductCategory();
            $this->applyCategoryRequest($category, $request, $entityManager);
            $errors = $this->validateCategory($category);
            $parent = $entityManager->find(ProductCategory::class, (int) $request->request->get('parent_id'));
            $category->setParent($parent instanceof ProductCategory ? $parent : null);

            if ($errors === []) {
                $entityManager->persist($category);
                $entityManager->flush();
                $customFieldRenderer->saveFromRequest('product_category', $category, $request);
                $entityManager->flush();
                $this->addFlash('success', sprintf('Category "%s" was created successfully.', $category->getName()));
                return $this->redirectToRoute('admin_category_index');
            }

            $this->addFlash('error', implode(' ', $errors));

            return $this->render('admin/product/category_form.html.twig', [
                'mode' => 'Create',
                'category' => $this->categoryToRow($category),
                'categories' => $this->categoryRowsFromDatabase($entityManager),
                'customFieldFragment' => $customFieldRenderer->renderFields('product_category', null, 'add'),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/product/category_form.html.twig', [
            'mode' => 'Create',
            'category' => ['id' => '', 'name' => '', 'parent' => '(none)', 'parentId' => null, 'status' => 'Visible'],
            'categories' => $this->categoryRowsFromDatabase($entityManager),
            'customFieldFragment' => $customFieldRenderer->renderFields('product_category', null, 'add'),
        ]);
    }

    #[Route('/update/{id}', name: 'admin_category_update', methods: ['GET', 'POST'])]
    public function update(int $id, Request $request, EntityManagerInterface $entityManager, CustomFieldRenderer $customFieldRenderer): Response
    {
        $category = $entityManager->find(ProductCategory::class, $id);
        if (!$category instanceof ProductCategory) {
            $this->addFlash('error', 'Category could not be found.');
            return $this->redirectToRoute('admin_category_index');
        }

        if ($request->isMethod('POST')) {
            $this->applyCategoryRequest($category, $request, $entityManager);
            $errors = $this->validateCategory($category);
            $parent = $entityManager->find(ProductCategory::class, (int) $request->request->get('parent_id'));
            $category->setParent($parent instanceof ProductCategory && $parent->getId() !== $category->getId() ? $parent : null);

            if ($errors === []) {
                $entityManager->flush();
                $customFieldRenderer->saveFromRequest('product_category', $category, $request);
                $entityManager->flush();
                $this->addFlash('success', sprintf('Category "%s" was updated successfully.', $category->getName()));
                return $this->redirectToRoute('admin_category_index');
            }

            $this->addFlash('error', implode(' ', $errors));

            return $this->render('admin/product/category_form.html.twig', [
                'mode' => 'Update',
                'category' => $this->categoryToRow($category),
                'categories' => $this->categoryRowsFromDatabase($entityManager),
                'customFieldFragment' => $customFieldRenderer->renderFields('product_category', $category, 'edit'),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/product/category_form.html.twig', [
            'mode' => 'Update',
            'category' => $this->categoryToRow($category),
            'categories' => $this->categoryRowsFromDatabase($entityManager),
            'customFieldFragment' => $customFieldRenderer->renderFields('product_category', $category, 'edit'),
        ]);
    }

    #[Route('/toggle/{id}', name: 'admin_category_toggle', methods: ['POST'])]
    public function toggle(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $category = $entityManager->find(ProductCategory::class, $id);
        if (!$category instanceof ProductCategory) {
            return new JsonResponse(['ok' => false, 'message' => 'Category could not be found.'], Response::HTTP_NOT_FOUND);
        }

        $category->setStatus($category->getStatus() === 'Visible' ? 'Hidden' : 'Visible');
        $entityManager->flush();

        return new JsonResponse([
            'ok' => true,
            'status' => $category->getStatus(),
            'message' => sprintf('Category "%s" is now %s.', $category->getName(), $category->getStatus()),
        ]);
    }

    #[Route('/delete/{id}', name: 'admin_category_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $category = $entityManager->find(ProductCategory::class, $id);
        if ($category instanceof ProductCategory) {
            $productCount = $entityManager->getRepository(ProductCore::class)->count(['category' => $category]);
            if ($productCount > 0) {
                return new JsonResponse([
                    'ok' => false,
                    'message' => sprintf(
                        'Category "%s" cannot be deleted because it is assigned to %d product%s. Please move or edit those products first.',
                        $category->getName(),
                        $productCount,
                        $productCount === 1 ? '' : 's'
                    ),
                ], Response::HTTP_CONFLICT);
            }

            $childCount = $entityManager->getRepository(ProductCategory::class)->count(['parent' => $category]);
            if ($childCount > 0) {
                return new JsonResponse([
                    'ok' => false,
                    'message' => sprintf(
                        'Category "%s" cannot be deleted because it has %d subcategor%s. Please delete or move them first.',
                        $category->getName(),
                        $childCount,
                        $childCount === 1 ? 'y' : 'ies'
                    ),
                ], Response::HTTP_CONFLICT);
            }

            $name = $category->getName();
            $entityManager->remove($category);
            $entityManager->flush();

            return new JsonResponse(['ok' => true, 'message' => sprintf('Category "%s" was deleted successfully.', $name)]);
        }

        return new JsonResponse(['ok' => false, 'message' => 'Category could not be found.'], Response::HTTP_NOT_FOUND);
    }

    private function applyCategoryRequest(ProductCategory $category, Request $request, EntityManagerInterface $entityManager): void
    {
        $category
            ->setName(trim((string) $request->request->get('name', '')))
            ->setStatus((string) $request->request->get('status', 'Visible'));
    }

    /**
     * The row list is depth-first ordered, so a category's whole subtree is always contiguous —
     * the only place a child can end up separated from its parent is right at a page boundary.
     * When that happens, the child's page shows the "Parent category" column but not the parent's
     * own standalone row, losing the context a reader gets from seeing the parent listed. Repeat it
     * atop the page when the page's first row's parent isn't already present on the page.
     *
     * @param list<array<string, string>> $pageRows all rows for this page, after slicing
     * @param list<array<string, string>> $allRows the full, unsliced, filtered row list
     * @return list<array<string, string>>
     */
    private function withRepeatedParentContext(array $pageRows, array $allRows): array
    {
        if ($pageRows === []) {
            return $pageRows;
        }

        $parentId = $pageRows[0]['parentId'] ?? '';
        if ($parentId === '') {
            return $pageRows;
        }

        foreach ($pageRows as $row) {
            if (($row['id'] ?? '') === $parentId) {
                return $pageRows;
            }
        }

        foreach ($allRows as $row) {
            if (($row['id'] ?? '') === $parentId) {
                array_unshift($pageRows, $row);
                break;
            }
        }

        return $pageRows;
    }

    /**
     * Ported to a symfony/validator constraint for #308, following ValidCompany's #309 pattern:
     * ValidCategoryValidator runs the same rule this method used to run by hand, so a future fix
     * is inherited by create() and update() without either changing anything.
     *
     * @return list<string>
     */
    private function validateCategory(ProductCategory $category): array
    {
        $violations = Validation::createValidator()->validate($category, new ValidCategory());

        return array_map(static fn ($violation): string => (string) $violation->getMessage(), iterator_to_array($violations));
    }
}
