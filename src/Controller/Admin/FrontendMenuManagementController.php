<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Contract\Menu\FrontendMenuItemInterface;
use App\Entity\CustomMenuItem;
use App\Entity\FrontendMenuItemStatus;
use App\Repository\BundleStatusRepository;
use App\Repository\CustomMenuItemRepository;
use App\Repository\FrontendMenuItemStatusRepository;
use App\Validation\Constraint\ValidCustomMenuItemRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;

#[Route('/admin/frontend-menu-management')]
final class FrontendMenuManagementController extends AbstractAdminController
{
    /** @param iterable<FrontendMenuItemInterface> $menuItems */
    public function __construct(
        #[AutowireIterator('app.frontend_menu_item')]
        private readonly iterable $menuItems,
    ) {}

    #[Route('', name: 'admin_frontend_menu_management_index', methods: ['GET'])]
    public function index(
        FrontendMenuItemStatusRepository $menuItemStatusRepo,
        BundleStatusRepository $bundleStatusRepo,
        CustomMenuItemRepository $customMenuItemRepo,
    ): Response {
        $rows = [];
        foreach ($this->menuItems as $item) {
            $status = $menuItemStatusRepo->ensureByKey($item->getKey());
            $rows[] = [
                'type' => 'core',
                'id' => $item->getKey(),
                'label' => $item->getLabel(),
                'route' => $item->getRoute(),
                'source' => $item->getSource(),
                'bundleActive' => $bundleStatusRepo->isActiveForInstance($item),
                'status' => $status->getStatus(),
                'sortOrder' => $status->getSortOrder(),
            ];
        }

        foreach ($customMenuItemRepo->findAllOrdered() as $customItem) {
            $rows[] = [
                'type' => 'custom',
                'id' => (string) $customItem->getId(),
                'label' => $customItem->getLabel(),
                'route' => null,
                'source' => 'Custom',
                'bundleActive' => true,
                'status' => $customItem->getStatus(),
                'sortOrder' => $customItem->getSortOrder(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['sortOrder'], $a['label']] <=> [$b['sortOrder'], $b['label']]);

        return $this->render('admin/frontend_menu_management/index.html.twig', [
            'rows' => $rows,
        ]);
    }

    #[Route('/{key}/toggle', name: 'admin_frontend_menu_management_toggle', methods: ['POST'])]
    public function toggle(string $key, Request $request, EntityManagerInterface $entityManager, FrontendMenuItemStatusRepository $menuItemStatusRepo): Response
    {
        $itemLabel = $this->labelForKey($key) ?? $key;

        $status = $menuItemStatusRepo->ensureByKey($key);
        $previousStatus = $status->getStatus();
        $newStatus = $previousStatus === FrontendMenuItemStatus::STATUS_ACTIVE
            ? FrontendMenuItemStatus::STATUS_INACTIVE
            : FrontendMenuItemStatus::STATUS_ACTIVE;

        $status->setStatus($newStatus);

        $entityManager->persist($status);
        $entityManager->flush();

        $this->addFlash('success', sprintf('"%s" is now %s.', $itemLabel, strtolower($newStatus)));

        return $this->redirectToRoute('admin_frontend_menu_management_index');
    }

    #[Route('/custom/create', name: 'admin_frontend_menu_management_custom_create', methods: ['GET', 'POST'])]
    public function createCustom(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $errors = $this->validateCustomRequest($request);
            if ($errors === []) {
                $customItem = new CustomMenuItem();
                $this->applyCustomRequest($customItem, $request);
                $entityManager->persist($customItem);
                $entityManager->flush();

                $this->addFlash('success', sprintf('"%s" was added to the menu.', $customItem->getLabel()));

                return $this->redirectToRoute('admin_frontend_menu_management_index');
            }

            $this->addFlash('error', implode(' ', $errors));

            return $this->render('admin/frontend_menu_management/custom_form.html.twig', [
                'mode' => 'Create',
                'item' => null,
                'formData' => $request->request->all(),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/frontend_menu_management/custom_form.html.twig', [
            'mode' => 'Create',
            'item' => null,
            'formData' => [],
        ]);
    }

    #[Route('/custom/{id}/update', name: 'admin_frontend_menu_management_custom_update', methods: ['GET', 'POST'])]
    public function updateCustom(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $customItem = $entityManager->find(CustomMenuItem::class, $id);
        if (!$customItem instanceof CustomMenuItem) {
            $this->addFlash('error', 'Custom menu item could not be found.');

            return $this->redirectToRoute('admin_frontend_menu_management_index');
        }

        if ($request->isMethod('POST')) {
            $errors = $this->validateCustomRequest($request);
            if ($errors === []) {
                $this->applyCustomRequest($customItem, $request);
                $customItem->touch();
                $entityManager->flush();

                $this->addFlash('success', sprintf('"%s" was updated.', $customItem->getLabel()));

                return $this->redirectToRoute('admin_frontend_menu_management_index');
            }

            $this->addFlash('error', implode(' ', $errors));

            return $this->render('admin/frontend_menu_management/custom_form.html.twig', [
                'mode' => 'Update',
                'item' => $customItem,
                'formData' => $request->request->all(),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/frontend_menu_management/custom_form.html.twig', [
            'mode' => 'Update',
            'item' => $customItem,
            'formData' => [],
        ]);
    }

    #[Route('/custom/{id}/toggle', name: 'admin_frontend_menu_management_custom_toggle', methods: ['POST'])]
    public function toggleCustom(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $customItem = $entityManager->find(CustomMenuItem::class, $id);
        if (!$customItem instanceof CustomMenuItem) {
            $this->addFlash('error', 'Custom menu item could not be found.');

            return $this->redirectToRoute('admin_frontend_menu_management_index');
        }

        $newStatus = $customItem->getStatus() === CustomMenuItem::STATUS_ACTIVE
            ? CustomMenuItem::STATUS_INACTIVE
            : CustomMenuItem::STATUS_ACTIVE;
        $customItem->setStatus($newStatus);
        $entityManager->flush();

        $this->addFlash('success', sprintf('"%s" is now %s.', $customItem->getLabel(), strtolower($newStatus)));

        return $this->redirectToRoute('admin_frontend_menu_management_index');
    }

    #[Route('/custom/{id}/delete', name: 'admin_frontend_menu_management_custom_delete', methods: ['POST'])]
    public function deleteCustom(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $customItem = $entityManager->find(CustomMenuItem::class, $id);
        if (!$customItem instanceof CustomMenuItem) {
            $this->addFlash('error', 'Custom menu item could not be found.');

            return $this->redirectToRoute('admin_frontend_menu_management_index');
        }

        $label = $customItem->getLabel();
        $entityManager->remove($customItem);
        $entityManager->flush();

        $this->addFlash('success', sprintf('"%s" was deleted.', $label));

        return $this->redirectToRoute('admin_frontend_menu_management_index');
    }

    /**
     * Persists a new left-to-right order for every menu row (core + custom) in one request,
     * driven by the admin drag-and-drop list on the index page.
     */
    #[Route('/reorder', name: 'admin_frontend_menu_management_reorder', methods: ['POST'])]
    public function reorder(Request $request, EntityManagerInterface $entityManager, FrontendMenuItemStatusRepository $menuItemStatusRepo): JsonResponse
    {
        /** @var list<array{type?: string, id?: string}> $order */
        $order = json_decode((string) $request->request->get('order', '[]'), true) ?: [];

        foreach ($order as $position => $entry) {
            $type = $entry['type'] ?? null;
            $id = $entry['id'] ?? null;
            if (!is_string($type) || !is_string($id) || $id === '') {
                continue;
            }

            if ($type === 'custom') {
                $customItem = $entityManager->find(CustomMenuItem::class, (int) $id);
                if ($customItem instanceof CustomMenuItem) {
                    $customItem->setSortOrder($position);
                }
            } elseif ($type === 'core') {
                $menuItemStatusRepo->ensureByKey($id)->setSortOrder($position);
            }
        }

        $entityManager->flush();

        return new JsonResponse(['ok' => true]);
    }

    private function labelForKey(string $key): ?string
    {
        foreach ($this->menuItems as $item) {
            if ($item->getKey() === $key) {
                return $item->getLabel();
            }
        }

        return null;
    }

    /** @return list<string> */
    private function validateCustomRequest(Request $request): array
    {
        $violations = Validation::createValidator()->validate(
            new \ArrayObject([
                'label' => trim((string) $request->request->get('label', '')),
                'url' => trim((string) $request->request->get('url', '')),
            ]),
            new ValidCustomMenuItemRequest(),
        );

        return array_map(static fn ($violation): string => (string) $violation->getMessage(), iterator_to_array($violations));
    }

    private function applyCustomRequest(CustomMenuItem $customItem, Request $request): void
    {
        $customItem
            ->setLabel(trim((string) $request->request->get('label', '')))
            ->setUrl(trim((string) $request->request->get('url', '')));
    }
}
