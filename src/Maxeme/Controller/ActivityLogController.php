<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Entity\AuditLog;
use App\Maxeme\Audit\ActivityLog;
use App\Maxeme\Audit\ActivityLogFilter;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Security\StaffRole;
use App\Maxeme\Service\StaffAccountService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Logs › Activity Log: every action by every role, with what it changed; ?record=&id= is one record's history. */
#[Route('/admin/logs/activity', name: 'maxeme_activity_log_')]
#[RequiresPermission(Permission::LOG_VIEW)]
final class ActivityLogController extends AbstractController
{
    public function __construct(
        private readonly ActivityLog $log,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, StaffAccountService $accounts): Response
    {
        $filter = ActivityLogFilter::fromRequest($request);

        return $this->render('maxeme/activity_log/index.html.twig', [
            'page' => $this->log->page($filter, ListQuery::fromRequest($request, array_keys(ActivityLog::SORTS), 'desc')),
            'filter' => $filter,
            'byRole' => $this->log->countByRole($filter),
            'roles' => StaffRole::cases(),
            'users' => $accounts->activeAccounts(),
            'areas' => $this->log->distinct('area'),
            'actions' => $this->log->distinct('action'),
            'records' => array_values(array_filter($this->log->distinct('entityType'))),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(#[MapEntity] AuditLog $entry, Request $request): Response
    {
        return $this->render('maxeme/activity_log/show.html.twig', [
            'entry' => $entry,
            'changes' => ActivityLog::changes($entry, all: true),
            'back' => ActivityLogFilter::fromRequest($request)->toQuery(),
        ]);
    }
}
