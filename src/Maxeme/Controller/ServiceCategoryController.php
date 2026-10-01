<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\ServiceCategoryData;
use App\Maxeme\Entity\ServiceCategory;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Repository\ServiceCategoryRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Parts & Services › Service Categories: wholesale's product categories for services (a tree:
 * name, parent, Visible / Hidden), plus the colour its services have on the calendar. The list
 * is in tree order; a category is deleted only while it has no subcategory and no service.
 */
#[Route('/admin/service-categories', name: 'maxeme_service_category_')]
final class ServiceCategoryController extends AbstractMaxemeController
{
    /** The column search boxes (filters[field]). */
    private const FILTERS = ['name', 'parent', 'status'];

    public function __construct(
        private readonly ServiceCategoryRepository $categories,
        private readonly RecordWriter $records,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[RequiresPermission(Permission::SERVICE_VIEW)]
    public function index(Request $request): Response
    {
        $raw = $request->query->all()['filters'] ?? [];
        $filters = [];
        foreach (self::FILTERS as $field) {
            $filters[$field] = is_array($raw) && is_string($raw[$field] ?? null) ? trim($raw[$field]) : '';
        }

        $contains = static fn (?string $value, string $needle): bool => $needle === '' || str_contains(mb_strtolower((string) $value), mb_strtolower($needle));
        $rows = array_values(array_filter(
            $this->categories->findTree(),
            static fn (ServiceCategory $category): bool => $contains($category->getName(), $filters['name'])
                && $contains($category->getParent()?->getPath() ?? '(none)', $filters['parent'])
                && ($filters['status'] === '' || $category->getStatus()->value === $filters['status']),
        ));

        return $this->render('maxeme/service_category/index.html.twig', [
            'categories' => $rows,
            'serviceCounts' => $this->categories->serviceCounts(),
            'filters' => $filters,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function new(Request $request): Response
    {
        return $this->form(new ServiceCategory(), $request, 'Add Service Category', '"%s" was created.');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function edit(#[MapEntity] ServiceCategory $category, Request $request): Response
    {
        return $this->form($category, $request, sprintf('Edit Service Category: %s', $category->getName()), '"%s" was saved.');
    }

    /** The list's Visible / Hidden switch (core app.js .js-category-status). */
    #[Route('/{id}/toggle', name: 'toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function toggle(#[MapEntity] ServiceCategory $category, EntityManagerInterface $entityManager): JsonResponse
    {
        $category->setStatus($category->getStatus()->toggled());
        $entityManager->flush();

        return $this->json([
            'ok' => true,
            'status' => $category->getStatus()->value,
            'message' => sprintf('"%s" is now %s.', $category->getName(), $category->getStatus()->value),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function delete(#[MapEntity] ServiceCategory $category, EntityManagerInterface $entityManager): JsonResponse
    {
        $services = $entityManager->getRepository(ServiceItem::class)->count(['category' => $category, 'active' => true]);
        if ($services > 0) {
            return $this->json(['message' => sprintf('"%s" has %d service%s. Move them to another category first.', $category->getName(), $services, $services === 1 ? '' : 's')], Response::HTTP_CONFLICT);
        }
        $children = $this->categories->countChildren($category);
        if ($children > 0) {
            return $this->json(['message' => sprintf('"%s" has %d subcategor%s. Delete or move them first.', $category->getName(), $children, $children === 1 ? 'y' : 'ies')], Response::HTTP_CONFLICT);
        }

        // Deleted services (soft-deleted) may still point at it; they keep their history, not the category.
        $entityManager->createQueryBuilder()->update(ServiceItem::class, 's')->set('s.category', 'NULL')->where('s.category = :category')->setParameter('category', $category)->getQuery()->execute();
        $entityManager->remove($category);
        $entityManager->flush();

        return $this->json(['message' => sprintf('"%s" was deleted.', $category->getName())]);
    }

    private function form(ServiceCategory $category, Request $request, string $title, string $saved): Response
    {
        $data = ServiceCategoryData::fromEntity($category);
        $errors = [];

        if ($request->isMethod('POST')) {
            $data = ServiceCategoryData::fromRequest($request);
            $errors = $this->records->validate($data);
            $parent = $data->parentId !== null ? $this->categories->find((int) $data->parentId) : null;
            if ($data->parentId !== null && $parent === null) {
                $errors['parentId'] = 'Choose a parent category from the list.';
            }
            if ($errors === []) {
                try {
                    $category->setParent($parent);
                } catch (\InvalidArgumentException $e) {
                    $errors['parentId'] = $e->getMessage();
                }
            }
            if ($errors === []) {
                $this->records->save($category, $data);
                $this->addFlash('success', sprintf($saved, $category->getName()));

                return $this->redirectToRoute('maxeme_service_category_index');
            }
        }

        return $this->render('maxeme/service_category/form.html.twig', [
            'title' => $title,
            'category' => $category,
            'data' => $data,
            'errors' => $errors,
            // A category cannot sit under itself or its own subcategories.
            'parents' => array_values(array_filter($this->categories->findTree(), static function (ServiceCategory $candidate) use ($category): bool {
                for ($ancestor = $candidate; $ancestor !== null; $ancestor = $ancestor->getParent()) {
                    if ($ancestor === $category) {
                        return false;
                    }
                }

                return true;
            })),
            'statuses' => ServiceCategoryData::statuses(),
        ], new Response('', $errors !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
