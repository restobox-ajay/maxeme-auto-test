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
use Doctrine\ORM\EntityManagerInterface;
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

    /**
     * One entry. Its route parameter is `entry`, as `id` is the filter's record id (one record's
     * history), carried back to the list. The Details link once sent the record id here instead
     * (/activity/{record id}?record=ClientNote); such a link opens that record's history.
     */
    #[Route('/{entry}', name: 'show', requirements: ['entry' => '\d+'], methods: ['GET'])]
    public function show(int $entry, Request $request, EntityManagerInterface $entityManager): Response
    {
        $log = $entityManager->find(AuditLog::class, $entry);
        if ($log === null) {
            $record = trim((string) $request->query->get('record', ''));
            if ($record !== '' && !$request->query->has('id')) {
                return $this->redirectToRoute('maxeme_activity_log_index', ['record' => $record, 'id' => $entry]);
            }

            throw $this->createNotFoundException('No such Activity Log entry.');
        }

        return $this->render('maxeme/activity_log/show.html.twig', [
            'entry' => $log,
            'changes' => ActivityLog::changes($log, all: true),
            'back' => ActivityLogFilter::fromRequest($request)->toQuery(),
        ]);
    }
}
