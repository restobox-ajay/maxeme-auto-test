<?php

declare(strict_types=1);

namespace Number1RimImportBundle\Controller\Admin;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCategory;
use Doctrine\ORM\EntityManagerInterface;
use Number1RimImportBundle\Service\RimImportConfig;
use Number1RimImportBundle\Service\RimSyncStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One screen: config form (API URL/client ID/key, category, fulfillment region, "deactivate
 * missing" toggle) plus a "Save & Sync Now" button. See RIM_API_IMPORT_PLAN.md §6.
 *
 * "Save & Sync Now" does NOT run the sync in this HTTP request — a full sync can take minutes
 * (image downloads dominate, not the product upsert itself), which is far too slow to hold an
 * HTTP request open for. Instead it spawns `bin/console number1-rim-import:sync` as a detached
 * background process (the exact same command the server crontab runs) and redirects immediately;
 * this page then polls status() until the run finishes. RimSyncStatus is the single source of
 * truth both this controller and the command itself read/write, so status is accurate regardless
 * of whether a sync was triggered by this button or by cron.
 */
#[Route('/admin/bundles/number1-rim-import')]
final class RimImportController extends AbstractController
{
    #[Route('', name: 'admin_bundle_number1_rim_import_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        RimImportConfig $config,
        RimSyncStatus $status,
    ): Response {
        if ($request->isMethod('POST')) {
            $values = [
                RimImportConfig::KEY_API_URL => trim((string) $request->request->get('api_url', '')),
                RimImportConfig::KEY_API_CLIENT_ID => trim((string) $request->request->get('api_client_id', '')),
                RimImportConfig::KEY_API_KEY => trim((string) $request->request->get('api_key', '')),
                RimImportConfig::KEY_CATEGORY_ID => trim((string) $request->request->get('category_id', '')),
                RimImportConfig::KEY_REGION_ID => trim((string) $request->request->get('region_id', '')),
                RimImportConfig::KEY_DEACTIVATE_MISSING => $request->request->get('deactivate_missing') === 'yes' ? '1' : '0',
            ];
            $config->save($entityManager, $values);

            if ($request->request->get('action') === 'sync') {
                if ($status->isRunning()) {
                    $this->addFlash('error', 'A sync is already running — please wait for it to finish.');
                } else {
                    $this->spawnSync();
                    $this->addFlash('success', 'Sync started in the background. This page will update automatically as it runs.');

                    // The spawned process needs to boot its own kernel before it writes
                    // status=running — the very next GET (right after this redirect) can easily
                    // beat it there. `synced=1` tells the template's JS to start polling
                    // immediately regardless of what RimSyncStatus currently says, instead of
                    // only polling when the file already happens to say "running".
                    return $this->redirectToRoute('admin_bundle_number1_rim_import_index', ['synced' => 1]);
                }
            } else {
                $this->addFlash('success', 'Settings saved.');
            }

            // Redirect (not render) after POST — avoids resubmitting the sync trigger on refresh,
            // and means the GET below always reflects RimSyncStatus fresh rather than a snapshot
            // taken before a background sync even started.
            return $this->redirectToRoute('admin_bundle_number1_rim_import_index');
        }

        $raw = $config->raw();
        $currentStatus = $status->read();

        return $this->render('@Number1RimImport/admin/rim_import/index.html.twig', [
            'syncStatus' => $currentStatus['status'],
            'syncStartedAt' => $currentStatus['startedAt'],
            'syncFinishedAt' => $currentStatus['finishedAt'],
            'syncMessage' => $currentStatus['message'],
            'result' => $currentStatus['result'],
            'apiUrl' => $raw['api_url'],
            'apiClientId' => $raw['api_client_id'],
            'apiKey' => $raw['api_key'],
            'categoryId' => $raw['category_id'],
            'regionId' => $raw['region_id'],
            'deactivateMissing' => $raw['deactivate_missing'],
            'categories' => $entityManager->getRepository(ProductCategory::class)->findBy([], ['name' => 'ASC']),
            'regions' => $entityManager->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC']),
        ]);
    }

    /** Polled by the config screen while a sync is running. */
    #[Route('/status', name: 'admin_bundle_number1_rim_import_status', methods: ['GET'])]
    public function status(RimSyncStatus $status): JsonResponse
    {
        return new JsonResponse($status->read());
    }

    private function spawnSync(): void
    {
        $projectDir = $this->getParameter('kernel.project_dir');
        $environment = $this->getParameter('kernel.environment');

        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';

        $process = new Process([
            $phpBinary,
            $projectDir . '/bin/console',
            'number1-rim-import:sync',
            '--env=' . $environment,
            '--no-interaction',
        ]);
        $process->setWorkingDirectory($projectDir);
        $process->setTimeout(null);
        // Process::__destruct() calls stop() on a still-running process unless this option is
        // set — without it, the child gets killed the moment $process falls out of scope at the
        // end of this method (almost immediately), before it can even boot its own kernel. This
        // is the documented, correct way to let a subprocess outlive the request that spawned it.
        $process->setOptions(['create_new_console' => true]);
        // No output pipe is ever read back here — without this, a long-running command's stdout
        // can fill the OS pipe buffer and block, since nothing is on the other end reading it.
        // The command's real output is the RimSyncStatus file, not console text.
        $process->disableOutput();
        $process->start();
    }
}
