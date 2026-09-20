<?php

declare(strict_types=1);

namespace AdminMenuBundle\Controller\Admin;

use AdminMenuBundle\Entity\AdminCustomMenuItem;
use AdminMenuBundle\Repository\AdminCustomMenuItemRepository;
use AdminMenuBundle\Repository\AdminMenuItemStatusRepository;
use App\Contract\Menu\AdminMenuOverrideProviderInterface;
use App\Menu\Admin\AdminMenuCatalog;
use App\Menu\Admin\AdminMenuTreeBuilder;
use App\Validation\Constraint\ValidCustomMenuItemRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;

/**
 * Tech-Support-only drag-and-drop builder for the admin sidebar.
 *
 * Hiding, reordering or reparenting an entry hides/moves its link and nothing more — it is not a
 * permission: the route stays reachable by URL and keeps whatever access rules it already had.
 * Custom entries are plain label+url links with no such backing route, so they are fully
 * deletable rather than merely hideable.
 *
 * Lists core's own catalog AND every active bundle's structural items (its own top-level groups
 * and their screens — Warehouse, Vendors, Purchases, Inventory Depth and the rest) through
 * App\Menu\Admin\AdminMenuTreeBuilder::defaultNodes(), not App\Menu\Admin\AdminMenuCatalog alone.
 * Before this, a bundle-contributed group had no row on this screen at all, so nothing dragged,
 * hidden or reparented here could ever reach one — its position was whatever number that bundle's
 * own PHP happened to hardcode, permanently, regardless of what an admin set everything else to.
 * A bundle's items are persisted through the exact same AdminMenuItemStatusRepository core's own
 * keys use — there is no second storage to keep in sync — 'type' stays 'core' for both in the
 * reorder payload (AdminMenuTreeBuilder::defaultNodes() is the single source both draw from), and
 * only the Type column's label distinguishes "Core" from "Bundle" for a person reading the table.
 */
#[Route('/admin/bundles/admin-menu')]
final class AdminMenuConfigController extends AbstractController
{
    /** @param iterable<AdminMenuOverrideProviderInterface> $providers */
    public function __construct(
        #[AutowireIterator('app.admin_menu_override_provider')]
        private readonly iterable $providers,
        private readonly AdminMenuTreeBuilder $treeBuilder,
    ) {
    }

    #[Route('', name: 'admin_bundle_admin_menu_config', methods: ['GET'])]
    public function index(
        AdminMenuItemStatusRepository $itemStatusRepo,
        AdminCustomMenuItemRepository $customItemRepo,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        $defaultNodes = $this->treeBuilder->defaultNodes($this->providers);

        $rows = [];
        foreach ($defaultNodes as $node) {
            $status = $itemStatusRepo->ensureByKey($node->key);
            $effectiveParent = $status->hasParentOverride() ? $status->getEffectiveParent() : $node->parent;
            $groupLabel = $effectiveParent !== null ? ($defaultNodes[$effectiveParent]->label ?? null) : null;

            $rows[] = [
                'type' => 'core',
                'id' => $node->key,
                'label' => $node->label,
                'groupLabel' => $groupLabel,
                'parentKey' => $effectiveParent,
                'hasParentOverride' => $status->hasParentOverride(),
                'hidden' => $status->isHidden(),
                'sortOrder' => $status->getSortOrder() ?? $node->order,
                'isGroup' => $node->route === null,
                // Core's own catalog vs an active bundle's own contributed group/screen — cosmetic
                // only, read by the Type column so a person can tell them apart; both are
                // persisted identically through AdminMenuItemStatusRepository above.
                'source' => isset(AdminMenuCatalog::ITEMS[$node->key]) ? 'Core' : 'Bundle',
            ];
        }

        foreach ($customItemRepo->findAllOrdered() as $customItem) {
            $parentKey = $customItem->getParentKey();
            $rows[] = [
                'type' => 'custom',
                // 'id' is the stable itemKey the tree builder/reorder/reparent endpoints key on;
                // 'dbId' is the numeric primary key the edit/delete routes need instead.
                'id' => $customItem->getItemKey(),
                'dbId' => $customItem->getId(),
                'label' => $customItem->getLabel(),
                'url' => $customItem->getUrl(),
                'groupLabel' => $parentKey !== null ? ($defaultNodes[$parentKey]->label ?? null) : null,
                'parentKey' => $parentKey,
                'hasParentOverride' => true,
                'hidden' => false,
                'sortOrder' => $customItem->getSortOrder(),
                'isGroup' => false,
                'source' => 'Custom',
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['sortOrder'], $a['label']] <=> [$b['sortOrder'], $b['label']]);

        return $this->render('@AdminMenu/config.html.twig', [
            'rows' => $rows,
            'parentOptions' => $this->parentOptions(),
        ]);
    }

    #[Route('/{key}/toggle', name: 'admin_bundle_admin_menu_toggle', methods: ['POST'], requirements: ['key' => '.+'])]
    public function toggle(string $key, EntityManagerInterface $entityManager, AdminMenuItemStatusRepository $itemStatusRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        $node = $this->treeBuilder->defaultNodes($this->providers)[$key] ?? null;
        if ($node === null) {
            $this->addFlash('error', 'Unknown menu item.');

            return $this->redirectToRoute('admin_bundle_admin_menu_config');
        }

        $status = $itemStatusRepo->ensureByKey($key);
        $status->setHidden(!$status->isHidden());
        $entityManager->flush();

        $this->addFlash('success', sprintf('"%s" is now %s.', $node->label, $status->isHidden() ? 'hidden' : 'visible'));

        return $this->redirectToRoute('admin_bundle_admin_menu_config');
    }

    /**
     * Posted `parent`: '' clears the override (core and bundle keys revert to their own default
     * parent; custom items go to the top level), AdminMenuItemStatus::ROOT_PARENT explicitly moves
     * a key to the top level, anything else is a target key. Reparenting under a hidden/
     * non-existent/non-group key is not validated here — App\Menu\Admin\AdminMenuTreeBuilder
     * ignores an invalid override defensively at render time, so a stale or bad value here can
     * never break the sidebar, only fail to move this one entry until corrected.
     */
    #[Route('/{key}/reparent', name: 'admin_bundle_admin_menu_reparent', methods: ['POST'], requirements: ['key' => '.+'])]
    public function reparent(
        string $key,
        Request $request,
        EntityManagerInterface $entityManager,
        AdminMenuItemStatusRepository $itemStatusRepo,
        AdminCustomMenuItemRepository $customItemRepo,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        $posted = trim((string) $request->request->get('parent', ''));

        if (isset($this->treeBuilder->defaultNodes($this->providers)[$key])) {
            $itemStatusRepo->ensureByKey($key)->setParentKey($posted === '' ? null : $posted);
            $entityManager->flush();
        } elseif (($customItem = $customItemRepo->findByKey($key)) !== null) {
            $customItem->setParentKey($posted === '' ? null : $posted)->touch();
            $entityManager->flush();
        } else {
            $this->addFlash('error', 'Unknown menu item.');

            return $this->redirectToRoute('admin_bundle_admin_menu_config');
        }

        $this->addFlash('success', 'Parent updated.');

        return $this->redirectToRoute('admin_bundle_admin_menu_config');
    }

    /**
     * Persists a new left-to-right order for every menu row (core + bundle + custom) in one
     * request, driven by the admin drag-and-drop list on the index page — same shape as
     * App\Controller\Admin\FrontendMenuManagementController::reorder(). 'core' covers both core's
     * own catalog and an active bundle's structural items: both persist through the same
     * AdminMenuItemStatusRepository, keyed generically by string, so the two need no separate type.
     */
    #[Route('/reorder', name: 'admin_bundle_admin_menu_reorder', methods: ['POST'])]
    public function reorder(
        Request $request,
        EntityManagerInterface $entityManager,
        AdminMenuItemStatusRepository $itemStatusRepo,
        AdminCustomMenuItemRepository $customItemRepo,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        /** @var list<array{type?: string, id?: string}> $order */
        $order = json_decode((string) $request->request->get('order', '[]'), true) ?: [];
        $defaultNodes = $this->treeBuilder->defaultNodes($this->providers);

        foreach ($order as $position => $entry) {
            $type = $entry['type'] ?? null;
            $id = $entry['id'] ?? null;
            if (!is_string($type) || !is_string($id) || $id === '') {
                continue;
            }

            if ($type === 'core' && isset($defaultNodes[$id])) {
                $itemStatusRepo->ensureByKey($id)->setSortOrder($position);
            } elseif ($type === 'custom') {
                $customItemRepo->findByKey($id)?->setSortOrder($position);
            }
        }

        $entityManager->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/custom/create', name: 'admin_bundle_admin_menu_custom_create', methods: ['GET', 'POST'])]
    public function createCustom(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        if ($request->isMethod('POST')) {
            $errors = $this->validateCustomRequest($request);
            if ($errors === []) {
                $customItem = new AdminCustomMenuItem();
                $this->applyCustomRequest($customItem, $request);
                $entityManager->persist($customItem);
                $entityManager->flush();

                $this->addFlash('success', sprintf('"%s" was added to the menu.', $customItem->getLabel()));

                return $this->redirectToRoute('admin_bundle_admin_menu_config');
            }

            $this->addFlash('error', implode(' ', $errors));

            return $this->render('@AdminMenu/custom_form.html.twig', [
                'mode' => 'Create',
                'item' => null,
                'formData' => $request->request->all(),
                'parentOptions' => $this->parentOptions(),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('@AdminMenu/custom_form.html.twig', [
            'mode' => 'Create',
            'item' => null,
            'formData' => [],
            'parentOptions' => $this->parentOptions(),
        ]);
    }

    #[Route('/custom/{id}/update', name: 'admin_bundle_admin_menu_custom_update', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function updateCustom(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        $customItem = $entityManager->find(AdminCustomMenuItem::class, $id);
        if (!$customItem instanceof AdminCustomMenuItem) {
            $this->addFlash('error', 'Custom menu item could not be found.');

            return $this->redirectToRoute('admin_bundle_admin_menu_config');
        }

        if ($request->isMethod('POST')) {
            $errors = $this->validateCustomRequest($request);
            if ($errors === []) {
                $this->applyCustomRequest($customItem, $request);
                $customItem->touch();
                $entityManager->flush();

                $this->addFlash('success', sprintf('"%s" was updated.', $customItem->getLabel()));

                return $this->redirectToRoute('admin_bundle_admin_menu_config');
            }

            $this->addFlash('error', implode(' ', $errors));

            return $this->render('@AdminMenu/custom_form.html.twig', [
                'mode' => 'Update',
                'item' => $customItem,
                'formData' => $request->request->all(),
                'parentOptions' => $this->parentOptions(),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('@AdminMenu/custom_form.html.twig', [
            'mode' => 'Update',
            'item' => $customItem,
            'formData' => [],
            'parentOptions' => $this->parentOptions(),
        ]);
    }

    #[Route('/custom/{id}/delete', name: 'admin_bundle_admin_menu_custom_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteCustom(int $id, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        $customItem = $entityManager->find(AdminCustomMenuItem::class, $id);
        if (!$customItem instanceof AdminCustomMenuItem) {
            $this->addFlash('error', 'Custom menu item could not be found.');

            return $this->redirectToRoute('admin_bundle_admin_menu_config');
        }

        $label = $customItem->getLabel();
        $entityManager->remove($customItem);
        $entityManager->flush();

        $this->addFlash('success', sprintf('"%s" was deleted.', $label));

        return $this->redirectToRoute('admin_bundle_admin_menu_config');
    }

    /**
     * Every valid reparent target — a top-level group with no route of its own, core or bundle
     * alike. See App\Menu\Admin\AdminMenuTreeBuilder::isValidParentTarget() for the same rule
     * enforced again, defensively, at render time.
     *
     * @return list<array{key: string, label: string}>
     */
    private function parentOptions(): array
    {
        $options = [];
        foreach ($this->treeBuilder->defaultNodes($this->providers) as $node) {
            if ($node->parent === null && $node->route === null) {
                $options[] = ['key' => $node->key, 'label' => $node->label];
            }
        }

        return $options;
    }

    /** Reuses core's label+url validation verbatim — a custom admin menu item and a custom frontend menu item are the same shape. @return list<string> */
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

    private function applyCustomRequest(AdminCustomMenuItem $customItem, Request $request): void
    {
        $parent = trim((string) $request->request->get('parent', ''));

        $customItem
            ->setLabel(trim((string) $request->request->get('label', '')))
            ->setUrl(trim((string) $request->request->get('url', '')))
            ->setParentKey($parent === '' ? null : $parent);
    }
}
