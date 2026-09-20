<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ImportRun;
use App\Enum\ImportRunStatus;
use App\Service\Import\ImportLock;
use App\Service\Import\ImportQueueSpawner;
use App\Service\TextInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The unified admin view over every import, of any type — see
 * docs/plans/2026-09-18-unified-import-framework.md.
 *
 * List/detail stay open to any admin (ROLE_ADMIN, the baseline every AdminUser has) — this is
 * where an ordinary admin lands right after a routine product/vendor import, same as the old
 * per-feature progress pages any admin could already see; a role gate here would be a real
 * regression, not a security improvement. Only kill/process-queue (operator-level actions with
 * real consequences on a shared queue) are ROLE_TECH_SUPPORT, matching Error Log/DB Console.
 */
#[Route('/admin/imports')]
final class ImportLogController extends AbstractAdminController
{
    #[Route('', name: 'admin_import_log', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager, Request $request, ImportLock $lock): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 50);
        $filters = $request->query->all('filters');

        $qb = $entityManager->getRepository(ImportRun::class)->createQueryBuilder('r');

        // TextInput::nullableString() rather than a bare (string) cast: a tampered
        // ?filters[importType][]=x makes $filters['importType'] an array, and (string) on that is
        // an "Array to string conversion" warning this app's own #395 fix exists to avoid — same
        // reasoning as every other filters-from-query-string screen in this app.
        $filterType = TextInput::nullableString($filters['importType'] ?? null) ?? '';
        if ($filterType !== '') {
            $qb->andWhere('r.importType = :filterType')->setParameter('filterType', $filterType);
        }

        $filterStatus = TextInput::nullableString($filters['status'] ?? null) ?? '';
        if ($filterStatus !== '') {
            $qb->andWhere('r.status = :filterStatus')->setParameter('filterStatus', $filterStatus);
        }

        $filterMode = TextInput::nullableString($filters['mode'] ?? null) ?? '';
        if ($filterMode === 'validation') {
            $qb->andWhere('r.validationOnly = true');
        } elseif ($filterMode === 'executed') {
            $qb->andWhere('r.validationOnly = false');
        }

        $filterHasErrors = TextInput::nullableString($filters['hasErrors'] ?? null) ?? '';
        if ($filterHasErrors === '1') {
            $qb->andWhere('r.errorCount > 0');
        }

        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        $rows = $qb->select('r')
            ->orderBy('r.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $distinctTypes = array_column(
            $entityManager->createQueryBuilder()->select('DISTINCT r.importType')->from(ImportRun::class, 'r')
                ->orderBy('r.importType', 'ASC')->getQuery()->getScalarResult(),
            'importType',
        );

        return $this->render('admin/system/import_log.html.twig', [
            'runs' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'filters' => $filters,
            'typeOptions' => $distinctTypes,
            'statusOptions' => ImportRunStatus::cases(),
            'lockHolder' => $lock->getHolderInfo(),
            'queuedCount' => $entityManager->getRepository(ImportRun::class)->countQueued(),
        ]);
    }

    #[Route('/{id}', name: 'admin_import_log_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id, EntityManagerInterface $entityManager): Response
    {
        $run = $entityManager->find(ImportRun::class, $id);
        if (!$run instanceof ImportRun) {
            $this->addFlash('error', 'Import run could not be found.');

            return $this->redirectToRoute('admin_import_log');
        }

        return $this->render('admin/system/import_log_detail.html.twig', [
            'run' => $run,
        ]);
    }

    /**
     * Force-kills whatever process currently holds the import lock (D9 in the framework plan) —
     * always a last resort; the confirming copy on the button itself says why.
     */
    #[Route('/kill', name: 'admin_import_log_kill', methods: ['POST'])]
    public function kill(EntityManagerInterface $entityManager, ImportLock $lock): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        try {
            $lock->forceKillHolder();

            $running = $entityManager->getRepository(ImportRun::class)->findOneBy(['status' => ImportRunStatus::Started->value]);
            if ($running instanceof ImportRun) {
                $running->setStatus(ImportRunStatus::Completed);
                $running->setFinishedAt(new \DateTimeImmutable());
                $running->setError('Killed by admin.');
                $entityManager->flush();
            }

            $this->addFlash('success', 'Import process killed. The lock has been released.');
        } catch (\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_import_log');
    }

    /**
     * Manually (re-)starts the queue drain — the same detached spawn every import controller
     * triggers automatically after queueing; exposed here for the "stuck queue" recovery path
     * (D9): kill, then Process Queue.
     */
    #[Route('/process-queue', name: 'admin_import_log_process_queue', methods: ['POST'])]
    public function processQueue(ImportQueueSpawner $spawner): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        $spawner->spawn();
        $this->addFlash('success', 'Queue processing started.');

        return $this->redirectToRoute('admin_import_log');
    }
}
