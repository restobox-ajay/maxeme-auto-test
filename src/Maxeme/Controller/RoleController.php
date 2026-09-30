<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Authorization\RoleAccessReport;
use App\Maxeme\Security\Permission;
use App\Maxeme\Security\StaffRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Config › Roles & Access: every role, then one role's grants and each route it can or cannot
 * reach. Read-only (RoleAccessReport says why).
 */
#[Route('/admin/roles', name: 'maxeme_role_')]
#[RequiresPermission(Permission::ROLE_VIEW)]
final class RoleController extends AbstractController
{
    public function __construct(
        private readonly RoleAccessReport $report,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $rows = [];
        foreach (StaffRole::cases() as $role) {
            $rows[] = [
                'role' => $role,
                'levels' => $this->report->levels($role),
                'accountCount' => count($this->report->accounts($role)),
                'allowedRoutes' => $this->report->allowedRouteCount($role),
            ];
        }

        return $this->render('maxeme/role/index.html.twig', [
            'rows' => $rows,
            'areas' => Permission::AREAS,
            'routeCount' => $this->report->routeCount(),
        ]);
    }

    #[Route('/{key}', name: 'show', requirements: ['key' => '[a-z0-9_]+'], methods: ['GET'])]
    public function show(string $key, Request $request): Response
    {
        $role = StaffRole::fromPermissionKey($key) ?? throw $this->createNotFoundException();
        $onlyAllowed = $request->query->getBoolean('allowed');

        $routes = $this->report->routes($role);
        if ($onlyAllowed) {
            $routes = array_filter(array_map(
                static fn (array $rows): array => array_values(array_filter($rows, static fn (array $row): bool => $row['allowed'])),
                $routes,
            ));
        }

        return $this->render('maxeme/role/show.html.twig', [
            'role' => $role,
            'roles' => StaffRole::cases(),
            'permissions' => $this->report->permissions($role),
            'levels' => $this->report->levels($role),
            'areas' => Permission::AREAS + ['public' => 'Public (no sign-in)'],
            'routes' => $routes,
            'onlyAllowed' => $onlyAllowed,
            'allowedRoutes' => $this->report->allowedRouteCount($role),
            'routeCount' => $this->report->routeCount(),
            'accounts' => $this->report->accounts($role),
        ]);
    }
}
