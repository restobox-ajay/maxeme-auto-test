<?php

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\EmailLog;
use App\Entity\ErrorLog;
use App\Service\BusinessDate;
use App\Service\Onboarding\OnboardingChecklistService;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class SystemController extends AbstractAdminController
{
    /** Same multi-format parsing as EstimateController::parseEstimateDateFilter(). */
    private function parseLogDateFilter(string $raw): ?\DateTimeImmutable
    {
        foreach (['Y-m-d', 'm/d/Y', 'm-d-Y', 'd/m/Y', 'd-m-Y'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $raw);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }

        return null;
    }

    /**
     * #426: a prelaunch checklist, accessible to every admin (unlike Error Log/Database
     * Console/Mail Queue, which stay Tech-Support-only). Every item comes from
     * OnboardingChecklistService — see OnboardingCheckInterface's docblock for how to add one.
     */
    #[Route('/onboarding', name: 'admin_onboarding', methods: ['GET'])]
    public function onboarding(OnboardingChecklistService $onboardingChecklist): Response
    {
        return $this->render('admin/system/onboarding.html.twig', [
            'checks' => $onboardingChecklist->run(),
        ]);
    }

    #[Route('/email-log', name: 'admin_email_log', methods: ['GET'])]
    public function emailLog(EntityManagerInterface $entityManager, \Symfony\Component\HttpFoundation\Request $request, BusinessDate $businessDate): Response
    {
        $schemaManager = $entityManager->getConnection()->createSchemaManager();
        if (!$schemaManager->tablesExist(['email_log'])) {
            $this->addFlash('error', 'The email log table is missing. Run migrations to create it (php bin/console doctrine:migrations:migrate).');
            return $this->render('admin/system/email_log.html.twig', ['logs' => [], 'total' => 0, 'page' => 1, 'limit' => 10]);
        }

        $page   = max(1, $request->query->getInt('page', 1));
        $limit  = $request->query->getInt('limit', 100);
        $search = $request->query->get('q', '');
        $sort = trim((string) $request->query->get('sort', 'id'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }

        $qb = $entityManager->getRepository(EmailLog::class)->createQueryBuilder('e');
        if ($search) {
            $qb->andWhere('e.templateCode LIKE :q OR e.recipient LIKE :q OR e.status LIKE :q OR e.body LIKE :q')
               ->setParameter('q', '%' . $search . '%');
        }

        $filterTo = trim((string) ($filters['to'] ?? ''));
        if ($filterTo !== '') {
            $qb->andWhere('e.recipient LIKE :filterTo')->setParameter('filterTo', '%' . $filterTo . '%');
        }

        $filterSubject = trim((string) ($filters['subject'] ?? ''));
        if ($filterSubject !== '') {
            $qb->andWhere('e.templateCode LIKE :filterSubject')->setParameter('filterSubject', '%' . $filterSubject . '%');
        }

        $filterBody = trim((string) ($filters['body'] ?? ''));
        if ($filterBody !== '') {
            $qb->andWhere('e.body LIKE :filterBody')->setParameter('filterBody', '%' . $filterBody . '%');
        }

        $filterStatus = trim((string) ($filters['status'] ?? ''));
        if ($filterStatus !== '') {
            $qb->andWhere('e.status = :filterStatus')->setParameter('filterStatus', $filterStatus);
        }

        $filterModule = trim((string) ($filters['module'] ?? ''));
        if ($filterModule !== '') {
            $pattern = match ($filterModule) {
                'password' => '%Password%',
                'invite' => '%Invitation%',
                'order' => '%Order%',
                'registration' => '%Register%',
                default => null,
            };
            if ($pattern !== null) {
                $qb->andWhere('e.templateCode LIKE :filterModule')->setParameter('filterModule', $pattern);
            }
        }

        $filterDate = trim((string) ($filters['date'] ?? ''));
        if ($filterDate !== '') {
            $parsedDate = $this->parseLogDateFilter($filterDate);
            if ($parsedDate instanceof \DateTimeImmutable) {
                // A recognised full date maps to exactly one day in the display timezone —
                // convert it to its UTC window rather than substring-matching the stored UTC
                // string, which would miss/misfire near the display timezone's midnight.
                [$from, $to] = $businessDate->localDayRangeUtc($parsedDate);
                $qb->andWhere('e.createdAt >= :filterDateFrom AND e.createdAt < :filterDateTo')
                    ->setParameter('filterDateFrom', $from, Types::DATETIME_IMMUTABLE)
                    ->setParameter('filterDateTo', $to, Types::DATETIME_IMMUTABLE);
            }
            // Anything that is not a whole calendar day is ignored, exactly as
            // EstimateController::index() ignores an unparseable createdAt — there is no fallback.
            //
            // There used to be one: CAST(e.createdAt AS string) LIKE '%input%'. It went for two
            // reasons. It substring-matched the *raw UTC* string, so '2026-08' meant August-in-UTC
            // and quietly mis-sorted the hours either side of each month boundary as seen from the
            // display timezone — the same class of bug the window conversion above exists to fix,
            // reintroduced on the branch next to it. And with the Date box now a date picker
            // (templates/admin/system/email_log.html.twig) partial input can only arrive by
            // hand-editing the URL, so there is no typing habit left to preserve.
        }

        $total = (int) (clone $qb)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        $sortMap = [
            'id' => 'e.id',
            'date' => 'e.createdAt',
            'module' => 'e.templateCode',
            'to' => 'e.recipient',
            'subject' => 'e.templateCode',
            'body' => 'e.body',
            'status' => 'e.status',
        ];
        $sortField = $sortMap[$sort] ?? $sortMap['id'];

        $rows  = $qb->select('e')
            ->orderBy($sortField, $dir)
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        $logs = array_map(static function (EmailLog $log): array {
            $subject = $log->getTemplateCode();
            $module = 'Other';
            $s = strtolower($subject);
            if (str_contains($s, 'password')) {
                $module = 'Password Reset';
            } elseif (str_contains($s, 'invitation') || str_contains($s, 'invite')) {
                $module = 'Invitation';
            } elseif (str_contains($s, 'order')) {
                $module = 'Order';
            } elseif (str_contains($s, 'register')) {
                $module = 'Registration';
            }

            return [
                'sentAt'    => $log->getCreatedAt(),
                'module'    => $module,
                'toAddress' => $log->getRecipient(),
                'subject'   => $subject,
                'body'      => $log->getBody() ?? '',
                'status'    => $log->getStatus(),
            ];
        }, $rows);

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html'  => $this->renderView('admin/system/_email_log_rows.html.twig', ['logs' => $logs]),
                'total' => $total, 'page' => $page, 'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ]);
        }

        return $this->render('admin/system/email_log.html.twig', [
            'logs' => $logs, 'total' => $total, 'page' => $page, 'limit' => $limit,
            'search' => (string) $search, 'currentSort' => $sort, 'currentDir' => strtolower($dir), 'filters' => $filters,
        ]);
    }

    #[Route('/error-log', name: 'admin_error_log', methods: ['GET'])]
    public function errorLog(EntityManagerInterface $entityManager, \Symfony\Component\HttpFoundation\Request $request): Response
    {
        // Super Admin (and Tech Support, which inherits it): the shop owner reviews errors too.
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $schemaManager = $entityManager->getConnection()->createSchemaManager();
        if (!$schemaManager->tablesExist(['error_log'])) {
            $this->addFlash('error', 'The error log table is missing. Run migrations to create it (php bin/console doctrine:migrations:migrate).');
            return $this->render('admin/system/error_log.html.twig', ['errors' => [], 'total' => 0, 'page' => 1, 'limit' => 10]);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 100);
        $search = $request->query->get('q', '');
        $sort = trim((string) $request->query->get('sort', 'id'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'desc'))) === 'asc' ? 'ASC' : 'DESC';

        $repo = $entityManager->getRepository(ErrorLog::class);
        $qb = $repo->createQueryBuilder('e');
        
        if ($search) {
            $qb->andWhere('e.message LIKE :q OR e.area LIKE :q OR e.level LIKE :q')
               ->setParameter('q', '%' . $search . '%');
        }

        $totalQuery = clone $qb;
        $total = $totalQuery->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        $sortMap = [
            'id' => 'e.id',
            'createdAt' => 'e.createdAt',
            'method' => 'e.level',
            'summary' => 'e.area',
            'message' => 'e.message',
        ];
        $sortField = $sortMap[$sort] ?? $sortMap['id'];

        $rows = $qb->select('e')
            ->orderBy($sortField, $dir)
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $errors = array_map(static function (ErrorLog $error): array {
            $decoded = json_decode($error->getMessage(), true);
            $decoded = is_array($decoded) ? $decoded : [];

            $method = (string) ($decoded['method'] ?? '');
            $url = (string) ($decoded['url'] ?? '');
            $summary = $url !== '' ? $method . ' ' . $url : $error->getArea();
            $message = (string) ($decoded['message'] ?? '');
            $file = (string) ($decoded['file'] ?? '');
            $line = (int) ($decoded['line'] ?? 0);

            return [
                'id' => (string) ($error->getId() ?? ''),
                'createdAt' => $error->getCreatedAt(),
                'method' => $method !== '' ? $method : $error->getLevel(),
                'summary' => $summary,
                'file' => $file,
                'line' => $line > 0 ? (string) $line : '',
                'message' => $message !== '' ? $message : $error->getMessage(),
                'metaData' => $error->getMessage(),
                'ipAddress' => $error->getIpAddress() ?? '',
                'userType' => $error->getUserType() ?? 'guest',
                'userId' => $error->getUserId(),
                'userEmail' => $error->getUserEmail() ?? '',
                'viewUrl' => '/admin/error-log/' . $error->getId(),
            ];
        }, $rows);

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'items' => $errors,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit),
            ]);
        }

        return $this->render('admin/system/error_log.html.twig', [
            'errors' => $errors,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'search' => (string) $search,
            'currentSort' => $sort,
            'currentDir' => strtolower($dir),
            'filters' => [],
        ]);
    }

    #[Route('/error-log/{id}', name: 'admin_error_detail', methods: ['GET'])]
    public function errorDetail(int $id, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $error = $entityManager->find(ErrorLog::class, $id);
        if (!$error instanceof ErrorLog) {
            $this->addFlash('error', 'Error log entry could not be found.');
            return $this->redirectToRoute('admin_error_log');
        }

        $decoded = json_decode($error->getMessage(), true);
        $decoded = is_array($decoded) ? $decoded : [];

        $data = [
            'id' => (string) ($error->getId() ?? ''),
            'createdAt' => $error->getCreatedAt(),
            'method' => (string) ($decoded['method'] ?? $error->getLevel()),
            'summary' => $error->getArea(),
            'file' => (string) ($decoded['file'] ?? ''),
            'line' => (int) ($decoded['line'] ?? 0),
            'message' => (string) ($decoded['message'] ?? $error->getMessage()),
            'trace' => (string) ($decoded['trace'] ?? ''),
            'metaData' => $error->getMessage(),
            'ipAddress' => $error->getIpAddress() ?? '',
            'userType' => $error->getUserType() ?? 'guest',
            'userId' => $error->getUserId(),
            'userEmail' => $error->getUserEmail() ?? '',
            'referrer' => $error->getReferrer() ?? '',
        ];

        return $this->render('admin/system/error_log_detail.html.twig', [
            'error' => $data,
        ]);
    }

    #[Route('/audit-log', name: 'admin_audit_log', methods: ['GET'])]
    public function auditLog(EntityManagerInterface $entityManager, \Symfony\Component\HttpFoundation\Request $request, BusinessDate $businessDate): Response
    {
        $schemaManager = $entityManager->getConnection()->createSchemaManager();
        if (!$schemaManager->tablesExist(['audit_log'])) {
            $this->addFlash('error', 'The audit log table is missing. Run migrations to create it (php bin/console doctrine:migrations:migrate).');
            return $this->render('admin/system/audit_log.html.twig', ['logs' => [], 'total' => 0, 'page' => 1, 'limit' => 100]);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 100);
        $search = $request->query->get('q', '');
        $sort = trim((string) $request->query->get('sort', 'id'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }

        $qb = $entityManager->getRepository(AuditLog::class)->createQueryBuilder('a');

        if ($search) {
            // entityId is an integer column: DQL has no CAST (see parseLogDateFilter's neighbouring
            // comment on why the email log dropped its own CAST-based fallback), so a partial-string
            // match on it isn't expressible here. An exact match on numeric input is what "entity ID
            // is searchable" can mean without one.
            $where = 'a.actorName LIKE :q OR a.area LIKE :q OR a.entityType LIKE :q OR a.summary LIKE :q';
            if (is_numeric($search)) {
                $where .= ' OR a.entityId = :qId';
            }
            $qb->andWhere($where)->setParameter('q', '%' . $search . '%');
            if (is_numeric($search)) {
                $qb->setParameter('qId', (int) $search);
            }
        }

        $filterActorType = trim((string) ($filters['actorType'] ?? ''));
        if ($filterActorType !== '') {
            $qb->andWhere('a.actorType = :filterActorType')->setParameter('filterActorType', $filterActorType);
        }

        $filterAction = trim((string) ($filters['action'] ?? ''));
        if ($filterAction !== '') {
            $qb->andWhere('a.action = :filterAction')->setParameter('filterAction', $filterAction);
        }

        $filterArea = trim((string) ($filters['area'] ?? ''));
        if ($filterArea !== '') {
            $qb->andWhere('a.area = :filterArea')->setParameter('filterArea', $filterArea);
        }

        $filterDate = trim((string) ($filters['date'] ?? ''));
        if ($filterDate !== '') {
            $parsedDate = $this->parseLogDateFilter($filterDate);
            if ($parsedDate instanceof \DateTimeImmutable) {
                // occurred_at is a UTC instant; the admin types a day in the display timezone, so it
                // has to go through the same window conversion as the Email Log's Date filter (and
                // for the same reason - see that filter's comment) rather than a raw column compare.
                [$from, $to] = $businessDate->localDayRangeUtc($parsedDate);
                $qb->andWhere('a.occurredAt >= :filterDateFrom AND a.occurredAt < :filterDateTo')
                    ->setParameter('filterDateFrom', $from, Types::DATETIME_IMMUTABLE)
                    ->setParameter('filterDateTo', $to, Types::DATETIME_IMMUTABLE);
            }
            // Unparseable/partial input is ignored, not fallen back on - same as the Email Log's Date
            // filter (parseLogDateFilter's docblock neighbour explains why there is no CAST fallback).
        }

        $total = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        $sortMap = [
            'id' => 'a.id',
            'occurredAt' => 'a.occurredAt',
            'actorName' => 'a.actorName',
            'actorType' => 'a.actorType',
            'area' => 'a.area',
            'entityType' => 'a.entityType',
            'entityId' => 'a.entityId',
            'action' => 'a.action',
            'summary' => 'a.summary',
        ];
        $sortField = $sortMap[$sort] ?? $sortMap['id'];

        $rows = $qb->select('a')
            ->orderBy($sortField, $dir)
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $logs = array_map(static function (AuditLog $log): array {
            return [
                'id' => $log->getId(),
                'occurredAt' => $log->getOccurredAt(),
                'actorName' => $log->getActorName(),
                'actorType' => $log->getActorType(),
                'area' => $log->getArea(),
                'entityType' => $log->getEntityType(),
                'entityId' => $log->getEntityId(),
                'action' => $log->getAction(),
                'summary' => $log->getSummary(),
                'dataBefore' => $log->getDataBefore(),
                'dataAfter' => $log->getDataAfter(),
            ];
        }, $rows);

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html' => $this->renderView('admin/system/_audit_log_rows.html.twig', ['logs' => $logs]),
                'total' => $total, 'page' => $page, 'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ]);
        }

        $distinctActions = array_column(
            $entityManager->createQueryBuilder()->select('DISTINCT a.action')->from(AuditLog::class, 'a')
                ->orderBy('a.action', 'ASC')->getQuery()->getScalarResult(),
            'action'
        );
        $distinctAreas = array_column(
            $entityManager->createQueryBuilder()->select('DISTINCT a.area')->from(AuditLog::class, 'a')
                ->orderBy('a.area', 'ASC')->getQuery()->getScalarResult(),
            'area'
        );

        return $this->render('admin/system/audit_log.html.twig', [
            'logs' => $logs, 'total' => $total, 'page' => $page, 'limit' => $limit,
            'search' => (string) $search, 'currentSort' => $sort, 'currentDir' => strtolower($dir),
            'filters' => $filters,
            'actionOptions' => $distinctActions,
            'areaOptions' => $distinctAreas,
        ]);
    }

    /**
     * A standalone, no-JavaScript-reachable page for one audit log row's before/after data.
     *
     * This replaces reconstructing the diff table client-side from data-before/data-after attributes
     * (JSON that the admin's own edits put there, so its shape is not something the page controls).
     * Rendering it server-side through Twig, which autoescapes by default, means the browser is never
     * asked to interpret that data as anything other than text - the defense doesn't depend on every
     * call site remembering to escape it. The "View" link in _audit_log_rows.html.twig points straight
     * here, so it works with scripting off; with scripting on the same URL is loaded into a sandboxed
     * iframe (see _audit_detail_modal.html.twig) instead of navigating away.
     */
    #[Route('/audit-log/{id}', name: 'admin_audit_detail', methods: ['GET'])]
    public function auditLogDetail(int $id, EntityManagerInterface $entityManager): Response
    {
        $log = $entityManager->find(AuditLog::class, $id);
        if (!$log instanceof AuditLog) {
            $this->addFlash('error', 'Audit log entry could not be found.');
            return $this->redirectToRoute('admin_audit_log');
        }

        $before = json_decode($log->getDataBefore() ?? '', true);
        $before = is_array($before) ? $before : [];
        $after = json_decode($log->getDataAfter() ?? '', true);
        $after = is_array($after) ? $after : [];

        $fieldNames = [];
        foreach (array_merge(array_keys($before), array_keys($after)) as $field) {
            if (!in_array($field, $fieldNames, true)) {
                $fieldNames[] = $field;
            }
        }

        $fields = array_map(static fn (string $field): array => [
            'name' => $field,
            'hasBefore' => array_key_exists($field, $before),
            'before' => $before[$field] ?? null,
            'hasAfter' => array_key_exists($field, $after),
            'after' => $after[$field] ?? null,
        ], $fieldNames);

        return $this->render('admin/system/audit_log_detail.html.twig', [
            'log' => $log,
            'fields' => $fields,
        ]);
    }

}
